<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Group;

use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Group\Backend\ABackend;
use OCP\Group\Backend\IAddToGroupBackend;
use OCP\Group\Backend\ICountUsersBackend;
use OCA\UserGroupAdmin\Db\Group as GroupEntity;
use OCP\Group\Backend\ICreateNamedGroupBackend;
use OCP\Group\Backend\IDeleteGroupBackend;
use OCP\Group\Backend\IGroupDetailsBackend;
use OCP\Group\Backend\INamedBackend;
use OCP\Group\Backend\IRemoveFromGroupBackend;
use OCP\Group\Backend\ISearchableGroupBackend;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Nextcloud group backend backed by the uga_groups / uga_group_members tables.
 *
 * Write operations (createGroup, deleteGroup, addToGroup, removeFromGroup) are
 * used directly on the master; on silos the tables are populated by the master
 * via the internal sync endpoint and these methods should not be called from
 * the UI.
 */
class GroupBackend extends ABackend implements
	INamedBackend,
	ICreateNamedGroupBackend,
	IDeleteGroupBackend,
	IAddToGroupBackend,
	IRemoveFromGroupBackend,
	IGroupDetailsBackend,
	ICountUsersBackend,
	ISearchableGroupBackend {

	public function __construct(
		private GroupMapper       $groupMapper,
		private GroupMemberMapper $memberMapper,
		private IUserManager      $userManager,
	) {}

	public function getBackendName(): string {
		return 'UserGroupAdmin';
	}

	// ── ICreateNamedGroupBackend ──────────────────────────────────────────────

	public function createGroup(string $name): ?string {
		if ($this->groupMapper->existsByGid($name)) {
			return null;
		}
		// Bare creation — owner/settings are set by GroupService immediately after.
		$group = new GroupEntity();
		$group->setGid($name);
		$group->setOwner('');
		$this->groupMapper->insert($group);
		return $name;
	}

	// ── IDeleteGroupBackend ───────────────────────────────────────────────────

	public function deleteGroup(string $gid): bool {
		$this->memberMapper->deleteByGid($gid);
		$this->groupMapper->deleteByGid($gid);
		return true;
	}

	// ── IAddToGroupBackend ────────────────────────────────────────────────────

	public function addToGroup(string $uid, string $gid): bool {
		try {
			$this->memberMapper->findByGidUid($gid, $uid);
			return false; // already a member
		} catch (DoesNotExistException) {}

		$m = new GroupMember();
		$m->setGid($gid);
		$m->setUid($uid);
		$m->setStatus(GroupMember::STATUS_ACCEPTED);
		$this->memberMapper->insert($m);
		return true;
	}

	// ── IRemoveFromGroupBackend ───────────────────────────────────────────────

	public function removeFromGroup(string $uid, string $gid): bool {
		$this->memberMapper->deleteByGidUid($gid, $uid);
		return true;
	}

	// ── GroupInterface core ───────────────────────────────────────────────────

	public function inGroup($uid, $gid): bool {
		return $this->memberMapper->isMember($gid, $uid);
	}

	public function getUserGroups($uid): array {
		return array_map(
			fn ($g) => $g->getGid(),
			$this->groupMapper->findByMember($uid),
		);
	}

	public function getGroups(string $search = '', int $limit = -1, int $offset = 0): array {
		return array_map(
			fn ($g) => $g->getGid(),
			$this->groupMapper->search($search, $limit, $offset),
		);
	}

	public function groupExists($gid): bool {
		return $this->groupMapper->existsByGid($gid);
	}

	public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0): array {
		$members = $this->memberMapper->findByGid($gid, GroupMember::STATUS_ACCEPTED);
		$uids = array_map(fn ($m) => $m->getUid(), $members);
		if ($search !== '') {
			$uids = array_values(array_filter($uids, fn ($u) => stripos($u, $search) !== false));
		}
		if ($offset > 0) {
			$uids = array_slice($uids, $offset);
		}
		if ($limit > 0) {
			$uids = array_slice($uids, 0, $limit);
		}
		return $uids;
	}

	// ── IGroupDetailsBackend ──────────────────────────────────────────────────

	public function getGroupDetails(string $gid): array {
		try {
			$g = $this->groupMapper->findByGid($gid);
			return ['displayName' => $g->getDescription() ?: $g->getGid()];
		} catch (DoesNotExistException) {
			return ['displayName' => $gid];
		}
	}

	// ── ICountUsersBackend ────────────────────────────────────────────────────

	public function countUsersInGroup(string $gid, string $search = ''): int {
		return count($this->usersInGroup($gid, $search));
	}

	// ── ISearchableGroupBackend ───────────────────────────────────────────────

	/** @return array<string, IUser> */
	public function searchInGroup(string $gid, string $search = '', int $limit = -1, int $offset = 0): array {
		$uids = $this->usersInGroup($gid, $search, $limit, $offset);
		$result = [];
		foreach ($uids as $uid) {
			$user = $this->userManager->get($uid);
			if ($user !== null) {
				$result[$uid] = $user;
			}
		}
		return $result;
	}
}
