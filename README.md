# user_group_admin — User-Managed Groups

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
| `storage_grant` | bigint | Grant size in bytes (0 = none) |

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

## Build

The frontend (Vue 2) must be built with webpack via `build/frontend-legacy`. The app's entry point is registered in `build/frontend-legacy/webpack.modules.cjs`:

```js
user_group_admin: {
    main: path.join(__dirname, 'apps/user_group_admin/src', 'main.js'),
},
```

A symlink must exist at `build/frontend-legacy/apps/user_group_admin` → `../../apps/user_group_admin`.

**Always do a full webpack build** (no `MODULE=` flag) to avoid `splitChunks` producing oversized bundles with wrong initialisation order:

```bash
cd /home/claude/code/nextcloud/build/frontend-legacy
node node_modules/webpack/bin/webpack.js --node-env production
```

Copy the built bundle into the app:

```bash
mkdir -p /home/claude/code/nextcloud/apps/user_group_admin/js
cp /home/claude/code/nextcloud/dist/user_group_admin-main.js* \
   /home/claude/code/nextcloud/apps/user_group_admin/js/
# Fix source map reference
sed -i 's|sourceMappingURL=user_group_admin-main.js.map|sourceMappingURL=main.js.map|' \
   /home/claude/code/nextcloud/apps/user_group_admin/js/main.js
```

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

After deploying JavaScript changes, reload PHP-FPM to clear OPcache:

```bash
service php8.3-fpm reload
```
