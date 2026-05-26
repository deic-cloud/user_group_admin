<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Controller;

use OCA\UserGroupAdmin\Service\IShardingAdapter;
use OCA\UserGroupAdmin\Db\Group;
use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCA\UserGroupAdmin\Service\GroupSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;

/**
 * Shared-secret-gated endpoints for master→silo group synchronisation.
 * When running on master, write operations are relayed to all silos so that
 * silo→master→silos propagation works (silos only know the master's URL).
 */
class InternalController extends Controller {
	public function __construct(
		string                    $appName,
		IRequest                  $request,
		private GroupMapper       $groupMapper,
		private GroupMemberMapper $memberMapper,
		private IGroupManager     $groupManager,
		private IUserManager      $userManager,
		private GroupSyncService  $syncService,
		private IShardingAdapter  $shardingService,
		private INotificationManager $notificationManager,
		private IConfig           $config,
	) {
		parent::__construct($appName, $request);
	}

	private function checkSecret(): ?JSONResponse {
		$secret = (string)$this->config->getSystemValue('files_sharding_shared_secret', '');
		if ($secret === '' || $this->request->getHeader('Authorization') !== 'Bearer ' . $secret) {
			return new JSONResponse(['message' => 'Unauthorized'], 401);
		}
		return null;
	}

	/**
	 * Upsert a group and its full member list on this silo.
	 * Replaces all existing member rows for the group.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function syncGroup(string $group, string $members): JSONResponse {
		if ($err = $this->checkSecret()) return $err;

		$groupData   = json_decode($group,   true);
		$membersData = json_decode($members, true);
		if (!is_array($groupData)) {
			return new JSONResponse(['message' => 'Invalid group payload'], 400);
		}

		$this->upsertGroup($groupData);

		// Replace member list.
		$gid = $groupData['gid'];
		$this->memberMapper->deleteByGid($gid);
		foreach ($membersData as $rawMember) {
			$mData = is_string($rawMember) ? json_decode($rawMember, true) : $rawMember;
			if (is_array($mData)) {
				$this->upsertMember($mData);
				$this->handleMemberNotification($gid, $mData);
			}
		}

		$this->syncNcGroupMembers($gid);

		// Relay to all silos when running on master.
		if ($this->shardingService->isMaster()) {
			$groupObj = $this->groupMapper->findByGid($gid);
			$memberObjs = $this->memberMapper->findByGid($gid);
			$this->syncService->pushGroupToAllSilos($groupObj, $memberObjs);
		}

		return new JSONResponse(['success' => true]);
	}

	/**
	 * Delete a group from this silo.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteGroup(string $gid): JSONResponse {
		if ($err = $this->checkSecret()) return $err;

		$this->memberMapper->deleteByGid($gid);
		$this->groupMapper->deleteByGid($gid);
		$ncGroup = $this->groupManager->get($gid);
		$ncGroup?->delete();

		if ($this->shardingService->isMaster()) {
			$this->syncService->deleteGroupOnAllSilos($gid);
		}

		return new JSONResponse(['success' => true]);
	}

	/**
	 * Upsert a single membership record on this silo.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function syncMember(string $gid, string $member): JSONResponse {
		if ($err = $this->checkSecret()) return $err;

		$mData = json_decode($member, true);
		if (!is_array($mData)) {
			return new JSONResponse(['message' => 'Invalid member payload'], 400);
		}

		$this->upsertMember($mData);
		$this->syncNcGroupMembers($gid);
		$this->handleMemberNotification($gid, $mData);

		if ($this->shardingService->isMaster()) {
			$memberObj = $this->memberMapper->findByGidUid($gid, $mData['uid'] ?? '');
			$this->syncService->pushMemberToAllSilos($memberObj);
		}

		return new JSONResponse(['success' => true]);
	}

	/**
	 * Search local NC users by display name or uid (called cross-silo with shared secret).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function searchUsers(string $q = ''): JSONResponse {
		if ($err = $this->checkSecret()) return $err;
		if (strlen($q) < 2) return new JSONResponse([]);
		$users = $this->userManager->search($q, 20);
		return new JSONResponse(array_map(
			fn ($u) => ['uid' => $u->getUID(), 'displayName' => $u->getDisplayName()],
			$users,
		));
	}

	/**
	 * Remove a single member from a group on this silo.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function deleteMember(string $gid, string $uid): JSONResponse {
		if ($err = $this->checkSecret()) return $err;

		$this->memberMapper->deleteByGidUid($gid, $uid);
		$ncGroup = $this->groupManager->get($gid);
		$user    = $this->userManager->get($uid);
		if ($ncGroup !== null && $user !== null) {
			$ncGroup->removeUser($user);
		}
		$this->dismissInvitationNotification($gid, $uid);
		$owner = '';
		try { $owner = $this->groupMapper->findByGid($gid)->getOwner(); } catch (\Throwable) {}
		if ($owner !== '') $this->dismissJoinRequestNotification($gid, $uid, $owner);

		if ($this->shardingService->isMaster()) {
			$this->syncService->removeMemberOnAllSilos($gid, $uid);
		}

		return new JSONResponse(['success' => true]);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function upsertGroup(array $data): void {
		$gid = $data['gid'] ?? '';
		if ($gid === '') return;

		$isNew = false;
		try {
			$group = $this->groupMapper->findByGid($gid);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			$group = new Group();
			$group->setGid($gid);
			$isNew = true;
		}

		$group->setOwner($data['owner'] ?? '');
		$group->setDescription($data['description'] ?? '');
		$group->setPrivate((bool)($data['private'] ?? false));
		$group->setOpen((bool)($data['open'] ?? false));
		$group->setHidden((bool)($data['hidden'] ?? false));
		$group->setStorageGrant($data['storage_grant'] ?? '');

		if ($isNew) {
			$this->groupMapper->insert($group);
			$this->groupManager->createGroup($gid);
		} else {
			$this->groupMapper->update($group);
		}
	}

	private function upsertMember(array $data): void {
		$gid = $data['gid'] ?? '';
		$uid = $data['uid'] ?? '';
		if ($gid === '' || $uid === '') return;

		try {
			$m = $this->memberMapper->findByGidUid($gid, $uid);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			$m = new GroupMember();
			$m->setGid($gid);
			$m->setUid($uid);
		}

		$m->setStatus((int)($data['status'] ?? GroupMember::STATUS_ACCEPTED));
		$m->setInvitationEmail($data['invitation_email'] ?? '');
		$m->setStorageUsed((int)($data['storage_used'] ?? 0));

		if ($m->getId() === null) {
			$this->memberMapper->insert($m);
		} else {
			$this->memberMapper->update($m);
		}
	}

	/** Reconcile NC's built-in group membership with our uga_group_members table. */
	private function syncNcGroupMembers(string $gid): void {
		$ncGroup = $this->groupManager->get($gid);
		if ($ncGroup === null) return;

		$acceptedUids = array_map(
			fn ($m) => $m->getUid(),
			array_filter(
				$this->memberMapper->findByGid($gid),
				fn ($m) => $m->getStatus() === GroupMember::STATUS_ACCEPTED
					&& $m->getUid() !== GroupMember::EXTERNAL_UID,
			),
		);

		foreach ($acceptedUids as $uid) {
			$user = $this->userManager->get($uid);
			if ($user !== null && !$ncGroup->inGroup($user)) {
				$ncGroup->addUser($user);
			}
		}
	}

	private function handleMemberNotification(string $gid, array $data): void {
		$uid    = $data['uid'] ?? '';
		$status = (int)($data['status'] ?? GroupMember::STATUS_ACCEPTED);

		if ($uid === '' || $uid === GroupMember::EXTERNAL_UID) return;

		$owner = '';
		try {
			$owner = $this->groupMapper->findByGid($gid)->getOwner();
		} catch (\Throwable) {}

		// Always clear stale notifications first.
		$this->dismissInvitationNotification($gid, $uid);
		if ($owner !== '') $this->dismissJoinRequestNotification($gid, $uid, $owner);

		if ($status === GroupMember::STATUS_PENDING) {
			$n = $this->notificationManager->createNotification();
			$n->setApp('user_group_admin')
				->setUser($uid)
				->setDateTime(new \DateTime())
				->setObject('group_invitation', $gid . '/' . $uid)
				->setSubject('group_invitation', ['gid' => $gid, 'inviter' => $owner]);
			$this->notificationManager->notify($n);
		} elseif ($status === GroupMember::STATUS_OPEN && $owner !== '') {
			$n = $this->notificationManager->createNotification();
			$n->setApp('user_group_admin')
				->setUser($owner)
				->setDateTime(new \DateTime())
				->setObject('group_join_request', $gid . '/' . $uid)
				->setSubject('join_request', ['gid' => $gid, 'requester' => $uid]);
			$this->notificationManager->notify($n);
		}
	}

	private function dismissInvitationNotification(string $gid, string $uid): void {
		$n = $this->notificationManager->createNotification();
		$n->setApp('user_group_admin')
			->setUser($uid)
			->setObject('group_invitation', $gid . '/' . $uid);
		$this->notificationManager->markProcessed($n);
	}

	private function dismissJoinRequestNotification(string $gid, string $requesterUid, string $ownerUid): void {
		$n = $this->notificationManager->createNotification();
		$n->setApp('user_group_admin')
			->setUser($ownerUid)
			->setObject('group_join_request', $gid . '/' . $requesterUid);
		$this->notificationManager->markProcessed($n);
	}
}
