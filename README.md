# user_group_admin — User-managed groups

Let users create and manage their own Nextcloud groups, with optional storage grants allocated to members.

**Author:** Frederik Orellana, Technical University of Denmark (fror@dtu.dk) — developed for the ScienceData cloud platform.  
**License:** AGPL-3.0

## Overview

`user_group_admin` extends Nextcloud's group system so ordinary users can:

- Create groups and invite members — existing Nextcloud users by username, or **external partners by email** (the platform's most-used collaboration feature; see [External collaborators](#external-collaborators-email-invitations))
- Allow open-join groups (no invite required)
- Keep private groups (hidden from non-members)
- Allocate a **storage grant** from their own quota to the group — usage consumed by the grant is billed to the group owner, not the members
- Cross-silo sync: group and membership changes are propagated to all registered silos by the app's own `GroupSyncService` (authenticated with the `files_sharding` shared secret)

In a ScienceData sharded deployment the master holds the authoritative group registry; silo nodes mirror it so that group membership is available locally for DAV and sharing operations.

## Requirements

- Nextcloud 34+
- PHP 8.2+
- Node.js 18+ and webpack (for frontend build)
- `files_sharding` (optional) — required for cross-silo group sync

## Installation

```bash
occ app:enable user_group_admin
```

Migrations run automatically and create two tables:
- `uga_groups` — group metadata (owner, description, visibility, storage grant + committed pool, pending owner)
- `uga_group_members` — member roster: per-member status, plus pending email-invitation data

## Features

### Members and invitations

Group owners add **existing Nextcloud users** by username. The invitee sees a pending invitation and can accept or decline; accepted memberships sync immediately to all silos. The member list shows each member's **full name and username** — resolved across silos via the master directory — so an owner is never in doubt about who they admit or remove.

### External collaborators (email invitations)

Seamless collaboration with people outside the platform is ScienceData's most-used and most-defining feature. A group owner can invite **anyone by email address** — whether or not they already have an account here.

- **Invite.** In the group's *Add member* box the owner types an email and clicks *Invite via email*. A pending membership is created with **`uid` = the email address** — so a group can hold any number of pending email invitations at once — and an email with accept / decline links is sent. Pending invitations appear in the member list marked *"Email invitation sent"*, and the owner can cancel any single one.
- **Accept — two paths, chosen automatically by whether the email already belongs to an account:**
  - *Already has an account here* → the accept link shows **"Log in to accept"**. After logging in (the account's email must match the invitation), the pending `uid=email` membership is **swapped to the person's real uid** — no second account is created.
  - *New person* → the accept link shows a **"Create your account"** form; a lightweight external account is created with `uid` = the email address, assigned to the inviting owner's silo, and added to the group.
- **Quota.** An external account gets Nextcloud's default quota. It is deliberately **not** a member of any institutional domain group (e.g. `dtu.dk`), so it receives none of that institution's home top-up or grant folder — only what the group it joined provides. Its footprint is thus scoped to the collaboration.
- **Responsibility and lifecycle.** The owner of the inviting group vouches for the external collaborator and is responsible for verifying their identity and their actions. The account is therefore bound to its **creating group** (recorded at signup): when the collaborator is removed from that group, the external account is **disabled** — regardless of any other groups they may have joined in the meantime.

### Open-join groups

When a group is set to open-join, any user can join without an invitation. The owner can still remove members.

### Private groups

Private groups are not visible to non-members in search or group listings.

### Storage grant

A group owner can allocate a fixed storage amount (e.g. 10 GB) to the group. Members of the group can use storage up to that grant without it counting against their own free quota. The consumed storage is billed to the group owner by `files_accounting`.

The grant is configured in the group's Settings tab:

> **Storage grant** — Allocate storage from your own quota to group members.  
> Amount: [dropdown: None / 1 GB / 5 GB / 10 GB / 50 GB / 100 GB / 500 GB / 1 TB]

**Two limits, both enforced by `GrantQuotaWrapper` as a hard write-stop** (507 past the ceiling; existing data untouched):

- **`storage_grant`** — the *per-member* allocation: each member's grant subfolder (`.uga_grants/{gid}/`) is capped at this, independent of their personal quota.
- **`storage_grant_total`** — the *committed pool*: the owner's total commitment across the whole group. A member's grant free space is `min(per-member remaining, pool remaining)`, where pool usage aggregates every accepted member's recorded `storage_used` (refreshed daily by the `GrantFolderUsage` job) plus the current member's live usage. Unset (`0`) → no pool cap, per-member behaviour only (no regression). Because the aggregate is day-granular and a silo may not hold every member's row, the pool cap is a conservative backstop against over-commitment, not a to-the-byte guarantee — it never *falsely* blocks.

### Home-directory top-up (self-service)

Separately from the grant *folder*, a group owner can allocate extra free quota on their members' **own home directories**, drawn from their own assigned quota — the "OneDrive alternative" option. It's set from the same group **Settings** tab ("Home-directory top-up", e.g. `100 GB`, empty to remove) and is stored/enforced by `files_accounting` (`files_accounting_topup`), which raises each member's effective free quota (and native hard-stop). The control calls the `files_accounting` `grouptopup` OCS endpoint, which authorises the **group owner** (not just admins); on a silo the write is forwarded to the master. Hidden gracefully if `files_accounting` is not installed.

### Ownership transfer

Group ownership — and with it the billing responsibility for the group's grant folder and home top-ups — can be handed to another member, so a group is never stranded when its owner (e.g. a departing PI) leaves.

- **Consent by default.** The owner offers ownership to an active member from the group's **Settings** tab ("Transfer ownership"). The recipient gets a bell notification and an **Accept / Decline** banner on the group, showing the committed amount they'd take on. Ownership changes only when they accept — it can't be assigned silently, because the owner's own quota backs the group's sponsored storage.
- **Soft warning, no block.** If the committed pool is close to or over the proposed owner's quota, the offer surfaces a warning but never blocks; if members later exhaust it, the owner simply arranges a larger quota.
- **Domain owner & admin.** Besides the current owner, an administrator or the institution's **domain owner** (owner of the members' `schacHomeOrganization` group, e.g. `dtu.dk`) may initiate a transfer — and may **force** it without consent, so IT can reassign a departed PI's group without full admin rights.
- **No-orphan.** If an owner's account is deleted, their groups are reassigned automatically to the domain owner, or to the `HIDDEN_OWNER` sentinel (ownerless → unbilled, never deleted).

Because grant folders are stored per-member, a transfer moves **no data** — it is a metadata + billing-pointer change, propagated to all silos; `files_accounting` re-points billing automatically since it reads the group's `owner`.

### Cross-silo sync

When a group or membership changes, the app's `GroupSyncService` propagates it to every registered silo (and the master) — authenticated with the `files_sharding` shared secret — via the app's own internal endpoints (`/internal/groups/{gid}/members/sync`, `/internal/groups/{gid}/members/{uid}/delete`, and group upsert/delete). Each node keeps a local mirror so membership is available for DAV and sharing without a round-trip to the master.

## Architecture

### Custom group backend

`user_group_admin` registers a custom group backend (`OCA\UserGroupAdmin\Group\GroupBackend`). Every group this app manages is resolved through it — membership comes from `uga_group_members` (only `status = accepted` rows count) — and Nextcloud aggregates these with any other group backends. The app also mirrors accepted memberships into core NC groups so ordinary group shares work. One consequence: because this backend already reports a user as in-group, core's `UserAddedEvent` / `UserRemovedEvent` do **not** fire for these groups, so membership changes are signalled by the app's own `GroupMembersChangedEvent`.

### DB schema

**`uga_groups`** (primary key `gid`)

| Column | Type | Description |
|--------|------|-------------|
| `gid` | varchar | Group identifier |
| `owner` | varchar | User ID of the owner (or the `uga_hidden_owner` sentinel) |
| `description` | varchar | Free-text description, shown as the group's friendly name |
| `private` | bool | Hidden from non-members in search / listings |
| `open` | bool | Open-join: anyone may join without an invitation |
| `hidden` | bool | Hidden system group (e.g. per-domain billing groups) |
| `storage_grant` | varchar | Per-member grant size (e.g. `10 GB`; empty = none) |
| `storage_grant_total` | varchar | Committed-pool cap for the whole group (empty = no pool cap) |
| `grant_sync_hide` | bool | Hide the grant folder from desktop sync clients |
| `pending_owner` | varchar | Proposed new owner awaiting consent during a transfer (empty = none) |

**`uga_group_members`** (unique on `(gid, uid)`)

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `gid` | varchar | Group identifier |
| `uid` | varchar | Member user ID. For a pending **email** invitation this is the invited email (distinct per invite), swapped to the real uid if the invitee logs in |
| `status` | smallint | `-1` pending · `0` join-requested · `1` accepted · `2` declined |
| `accept_token` / `decline_token` | varchar | One-time tokens for the email accept / decline links |
| `invitation_email` | varchar | The email for external invitations; empty for ordinary members — the "is external" marker |
| `storage_used` | bigint | Last recorded grant-folder usage (refreshed by the `GrantFolderUsage` job) |

### Sharding adapter

When `files_sharding` is present, write operations (create group, add member, remove member, delete group) are wrapped to also POST to each registered silo's internal API so the mirror stays current.

### OCS API

All endpoints under `/ocs/v2.php/apps/user_group_admin/api/v1/`. Authentication via Nextcloud session or admin token.

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/groups` | List the caller's groups (owned or member) |
| `GET` | `/groups/search?q=` | Search joinable (open) groups |
| `GET` | `/invitations` | The caller's pending invitations |
| `POST` | `/groups` | Create a group |
| `GET` | `/groups/{gid}` | Group details |
| `PUT` | `/groups/{gid}` | Update settings (description, visibility, storage grant, sync-hide) |
| `DELETE` | `/groups/{gid}` | Delete a group (owner only) |
| `GET` | `/groups/{gid}/members` | List members (with resolved display names) |
| `POST` | `/groups/{gid}/members` | Invite/add an existing user, or request to join |
| `POST` | `/groups/{gid}/members/external` | Invite an external collaborator by email |
| `PUT` | `/groups/{gid}/members/{uid}` | Accept an invitation / approve a join request |
| `DELETE` | `/groups/{gid}/members/{uid}` | Remove a member or cancel an email invitation |
| `PUT` | `/groups/{gid}/owner` | Offer ownership (`uid`); admin/domain owner may `force=1` |
| `PUT` | `/groups/{gid}/owner/pending` | Accept a pending ownership offer |
| `DELETE` | `/groups/{gid}/owner/pending` | Decline a pending ownership offer |

**External-signup pages** (public, not OCS): `GET /signup?token=` renders the create-account form, or *"log in to accept"* when the email already has an account; `GET /signup/accept?token=` (session-authed) completes acceptance for a logged-in existing user; `GET /signup/decline?token=` declines.

## Build

The frontend is built by the app's **own webpack** (`apps/user_group_admin/webpack.config.js`, `splitChunks: false` so each entry is a self-contained bundle). This keeps the app installable standalone — **no core NC UI rebuild**:

```bash
cd apps/user_group_admin
npm ci          # first time only
npm run build   # → apps/user_group_admin/js/{main,files-navigation,files-navigation-init}.js
```

Nextcloud loads the app's JS from **`apps/user_group_admin/js/`** (via `Util::addScript`). **Do NOT copy the bundle into the core `/dist/`.** A `/dist/user_group_admin-*.js` file *shadows* the shipped `js/` and, because a core `/dist/` entry only exists after a full NC UI build, it breaks app-store installability — if one exists, `rm` it. Commit the built `js/` so git/app-store installs ship it (PHP-only changes need no build).

## Deployment

```bash
# Master
rsync -av --delete apps/user_group_admin/ master:/var/www/nextcloud/apps/user_group_admin/

# Silo1
rsync -av --delete apps/user_group_admin/ silo1:/var/www/nextcloud/apps/user_group_admin/

# Silo2
rsync -av --delete apps/user_group_admin/ silo2:/var/www/nextcloud/apps/user_group_admin/

# Enable on each node (runs migrations automatically)
occ app:enable user_group_admin
```

Deploy the whole app dir (including the committed `js/`); there is **no `/dist/` step**. After deploying, reload the web/PHP layer to clear OPcache — `service php8.3-fpm reload` on the pods, `service apache24 restart` on the FreeBSD boxes (mod_php). A JS change also needs a browser hard-refresh (the `?v=` asset hash is global, not per-app).

### Domain grouping (SAML)

On login, `EnsureDomainGroupListener` ensures a **hidden** group named after the user's home organisation (the domain part of the `user@homeorg` UID — our SAML `uid_mapping` is eppn, matching the old `schacHomeOrganization`) exists and the user is a member. This gives `files_accounting`'s per-domain billing rollup something to group on, and ports the old ScienceData user_saml behaviour without patching user_saml. The group carries the `HIDDEN_OWNER` sentinel until an admin assigns the institution's real owner at onboarding; ownerless hidden groups are never billed. Bare (non-`@`) UIDs — local accounts — are skipped.
