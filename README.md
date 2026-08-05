# user_group_admin — User-managed groups

Let users create and manage their own Nextcloud groups, with optional storage grants allocated to members.

**Author:** Frederik Orellana, Technical University of Denmark (fror@dtu.dk) — developed for the ScienceData cloud platform.  
**License:** AGPL-3.0

## Overview

`user_group_admin` extends Nextcloud's group system so ordinary users can:

- Create groups and invite members (invite/accept workflow)
- Allow open-join groups (no invite required)
- Keep private groups (hidden from non-members)
- Allocate a **storage grant** from their own quota to the group — usage consumed by the grant is billed to the group owner, not the members
- Cross-silo sync: group membership changes are propagated to all registered silos via the `files_sharding` internal API

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
- `user_group_admin_groups` — group metadata (name, owner, type, storage grant)
- `user_group_admin_members` — member roster with invitation state

## Features

### Invite workflow

Group owners invite users by username. Invited users see a pending invitation in their interface and can accept or decline. Accepted memberships are immediately synced to all silos.

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

When a group is created, updated, or deleted on the master, `files_sharding` propagates the change to all registered silos via `POST /internal/users/{userId}/update` and related endpoints. Silos store a local mirror so group membership is available without a round-trip to the master.

## Architecture

### Custom group backend

`user_group_admin` registers a custom `IGroupBackend` with Nextcloud. Groups whose names match the app's prefix are resolved through this backend rather than the default database backend. The backend reads from `user_group_admin_groups` and `user_group_admin_members`.

### DB schema

**`user_group_admin_groups`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `gid` | varchar | Group identifier |
| `owner` | varchar | User ID of group owner |
| `type` | varchar | `invite` / `open` / `private` |
| `storage_grant` | bigint | Per-member grant size in bytes (0 = none) |
| `storage_grant_total` | varchar | Committed-pool cap for the whole group (hard write-stop; empty = no pool cap) |
| `pending_owner` | varchar | Proposed new owner awaiting consent during an ownership transfer (empty = none) |

**`user_group_admin_members`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | int (PK) | Auto-increment |
| `gid` | varchar | Group identifier |
| `uid` | varchar | Member user ID |
| `state` | varchar | `invited` / `member` |

### Sharding adapter

When `files_sharding` is present, write operations (create group, add member, remove member, delete group) are wrapped to also POST to each registered silo's internal API so the mirror stays current.

### OCS API

All endpoints under `/ocs/v2.php/apps/user_group_admin/api/v1/`. Authentication via Nextcloud session or admin token.

| Method | URL | Description |
|--------|-----|-------------|
| `GET` | `/groups` | List groups (owner or member) |
| `POST` | `/groups` | Create group |
| `DELETE` | `/groups/{gid}` | Delete group (owner only) |
| `GET` | `/groups/{gid}/members` | List members |
| `POST` | `/groups/{gid}/members` | Invite or add member |
| `DELETE` | `/groups/{gid}/members/{uid}` | Remove member |
| `POST` | `/groups/{gid}/accept` | Accept invitation |
| `PUT` | `/groups/{gid}` | Update group settings (type, storage grant) |
| `PUT` | `/groups/{gid}/owner` | Offer ownership (`uid`); admin/domain owner may `force=1` (no consent) |
| `PUT` | `/groups/{gid}/owner/pending` | Accept a pending ownership offer |
| `DELETE` | `/groups/{gid}/owner/pending` | Decline a pending ownership offer |

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
