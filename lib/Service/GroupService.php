<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

use OCA\UserGroupAdmin\Service\IShardingAdapter;
use OCA\UserGroupAdmin\Db\Group;
use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Activity\IManager as IActivityManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class GroupService {
	public function __construct(
		private GroupMapper           $groupMapper,
		private GroupMemberMapper     $memberMapper,
		private IGroupManager         $groupManager,
		private IUserManager          $userManager,
		private GroupSyncService      $syncService,
		private IShardingAdapter      $shardingService,
		private INotificationManager  $notificationManager,
		private IActivityManager      $activityManager,
		private LoggerInterface       $logger,
	) {}

	// ── Group CRUD ────────────────────────────────────────────────────────────

	public function createGroup(
		string $owner,
		string $gid,
		string $description = '',
		bool   $private     = false,
		bool   $open        = false,
	): Group {
		if ($this->groupMapper->existsByGid($gid)) {
			throw new \RuntimeException("Group '{$gid}' already exists");
		}

		$group = new Group();
		$group->setGid($gid);
		$group->setOwner($owner);
		$group->setDescription($description);
		$group->setPrivate($private);
		$group->setOpen($open);
		$group->setHidden(false);
		$this->groupMapper->insert($group);

		// Auto-add owner as accepted member.
		$this->addMember($gid, $owner, GroupMember::STATUS_ACCEPTED);

		$this->syncService->pushGroupToAllSilos($group, $this->memberMapper->findByGid($gid));
		$this->publishActivity('group_created', ['gid' => $gid], $owner, $owner);

		return $group;
	}

	public function createHiddenGroup(string $gid): Group {
		if ($this->groupMapper->existsByGid($gid)) {
			return $this->groupMapper->findByGid($gid);
		}

		$group = new Group();
		$group->setGid($gid);
		$group->setOwner(Group::HIDDEN_OWNER);
		$group->setHidden(true);
		$this->groupMapper->insert($group);
		$this->syncService->pushGroupToAllSilos($group, []);

		return $group;
	}

	/** @throws \RuntimeException if not found or caller is not owner */
	public function updateGroup(
		string  $callerUid,
		string  $gid,
		?string $description,
		?bool   $private,
		?bool   $open,
		?string $storageGrant,
		?string $storageGrantTotal = null,
	): Group {
		$group = $this->getGroupForOwner($callerUid, $gid);

		if ($description       !== null) $group->setDescription($description);
		if ($private           !== null) $group->setPrivate($private);
		if ($open              !== null) $group->setOpen($open);
		if ($storageGrant      !== null) $group->setStorageGrant($storageGrant);
		if ($storageGrantTotal !== null) $group->setStorageGrantTotal($storageGrantTotal);

		$this->groupMapper->update($group);
		$this->syncService->pushGroupToAllSilos($group, $this->memberMapper->findByGid($gid));

		return $group;
	}

	/** @throws \RuntimeException if not found or caller is not owner */
	public function deleteGroup(string $callerUid, string $gid): void {
		$group = $this->getGroupForOwner($callerUid, $gid);

		$this->memberMapper->deleteByGid($gid);
		$this->groupMapper->deleteByGid($gid);
		$ncGroup = $this->groupManager->get($gid);
		if ($ncGroup !== null) {
			$ncGroup->delete();
		}

		$this->syncService->deleteGroupOnAllSilos($gid);
		$this->publishActivity('group_deleted', ['gid' => $gid], $callerUid, $callerUid);
	}

	// ── Membership ────────────────────────────────────────────────────────────

	/**
	 * Invite an existing NC user to a group, or let a user request to join.
	 * Returns the new GroupMember.
	 *
	 * @throws \RuntimeException on validation failure
	 */
	public function inviteOrRequest(string $callerUid, string $gid, string $targetUid): GroupMember {
		$group = $this->getGroup($gid);

		try {
			$existing = $this->memberMapper->findByGidUid($gid, $targetUid);
			if ($existing->getStatus() === GroupMember::STATUS_ACCEPTED) {
				throw new \RuntimeException('User is already a member');
			}
			// Re-invite a declined user: reset to pending.
			$existing->setStatus(GroupMember::STATUS_PENDING);
			$this->memberMapper->update($existing);
			$this->syncService->pushMemberToAllSilos($existing);
			$this->sendInvitationNotification($gid, $callerUid, $targetUid);
			return $existing;
		} catch (DoesNotExistException) {}

		$isOwner = $group->getOwner() === $callerUid;

		$owner = $group->getOwner();

		if ($isOwner) {
			// Owner is inviting someone: STATUS_PENDING until they accept.
			[$accept, $decline] = $this->makeTokens();
			$m = $this->addMember($gid, $targetUid, GroupMember::STATUS_PENDING, $accept, $decline);
			$this->sendInvitationNotification($gid, $callerUid, $targetUid);
			$this->publishActivity('member_invited',      ['gid' => $gid, 'uid' => $targetUid], $callerUid, $callerUid);
			$this->publishActivity('invitation_received', ['gid' => $gid, 'inviter' => $callerUid], $callerUid, $targetUid);
		} elseif ($group->getOpen()) {
			// Open group: anyone can join immediately.
			$m = $this->addMember($gid, $callerUid, GroupMember::STATUS_ACCEPTED);
			$user = $this->userManager->get($callerUid);
			if ($user !== null) {
				$this->groupManager->get($gid)?->addUser($user);
			}
			$this->publishActivity('member_joined', ['gid' => $gid], $callerUid, $callerUid, $owner);
		} else {
			// User is requesting to join: STATUS_OPEN until owner approves.
			[$accept, $decline] = $this->makeTokens();
			$m = $this->addMember($gid, $callerUid, GroupMember::STATUS_OPEN, $accept, $decline);
			$this->sendJoinRequestNotification($gid, $callerUid, $owner);
			$this->publishActivity('join_requested',        ['gid' => $gid],                    $callerUid, $callerUid);
			$this->publishActivity('join_request_received', ['gid' => $gid, 'uid' => $callerUid], $callerUid, $owner);
		}

		$this->syncService->pushMemberToAllSilos($m);
		return $m;
	}

	/**
	 * Owner approves a join request, or a member accepts an invitation.
	 */
	public function acceptMembership(string $callerUid, string $gid, string $targetUid): void {
		$group = $this->getGroup($gid);
		$member = $this->memberMapper->findByGidUid($gid, $targetUid);

		$isOwner = $group->getOwner() === $callerUid;
		$isSelf  = $callerUid === $targetUid;

		// Owner approves a join request OR member accepts an invitation.
		if (!$isOwner && !$isSelf) {
			throw new \RuntimeException('Not authorised');
		}

		$member->setStatus(GroupMember::STATUS_ACCEPTED);
		$member->setAcceptToken('');
		$member->setDeclineToken('');
		$this->memberMapper->update($member);

		$user = $this->userManager->get($targetUid);
		if ($user !== null) {
			$this->groupManager->get($gid)?->addUser($user);
		}
		$owner = $group->getOwner();
		$this->syncService->pushMemberToAllSilos($member);
		$this->dismissInvitationNotification($gid, $targetUid);
		$this->dismissJoinRequestNotification($gid, $targetUid, $owner);
		if ($isOwner) {
			// Owner approved a join request.
			$this->publishActivity('join_approved',          ['gid' => $gid, 'uid' => $targetUid], $callerUid, $callerUid);
			$this->publishActivity('join_approval_received', ['gid' => $gid],                       $callerUid, $targetUid);
		} else {
			// Member accepted an invitation.
			$this->publishActivity('member_joined', ['gid' => $gid], $callerUid, $callerUid, $owner);
		}
	}

	public function removeMember(string $callerUid, string $gid, string $targetUid): void {
		$group = $this->getGroup($gid);
		$isOwner = $group->getOwner() === $callerUid;
		$isSelf  = $callerUid === $targetUid;

		if (!$isOwner && !$isSelf) {
			throw new \RuntimeException('Not authorised');
		}
		if ($targetUid === $group->getOwner() && !$isOwner) {
			throw new \RuntimeException('Owner cannot be removed by non-owner');
		}

		$owner = $group->getOwner();
		$this->memberMapper->deleteByGidUid($gid, $targetUid);
		$user = $this->userManager->get($targetUid);
		if ($user !== null) {
			$this->groupManager->get($gid)?->removeUser($user);
		}
		$this->syncService->removeMemberOnAllSilos($gid, $targetUid);
		$this->dismissInvitationNotification($gid, $targetUid);
		$this->dismissJoinRequestNotification($gid, $targetUid, $owner);
		if ($isSelf) {
			$this->publishActivity('member_left', ['gid' => $gid], $callerUid, $callerUid, $owner);
		} else {
			$this->publishActivity('member_removed',      ['gid' => $gid, 'uid' => $targetUid], $callerUid, $callerUid);
			$this->publishActivity('member_removed_from', ['gid' => $gid],                       $callerUid, $targetUid);
		}
	}

	// ── Queries ───────────────────────────────────────────────────────────────

	public function getGroup(string $gid): Group {
		try {
			return $this->groupMapper->findByGid($gid);
		} catch (DoesNotExistException) {
			throw new \RuntimeException("Group '{$gid}' not found");
		}
	}

	/** @return Group[] groups owned by or with accepted membership for $uid */
	public function listGroupsForUser(string $uid): array {
		$owned   = $this->groupMapper->findByOwner($uid);
		$member  = $this->groupMapper->findByMember($uid);
		// Merge, dedup by gid.
		$all = [];
		foreach (array_merge($owned, $member) as $g) {
			$all[$g->getGid()] = $g;
		}
		return array_values($all);
	}

	/** @return GroupMember[] */
	public function listMembers(string $gid): array {
		return $this->memberMapper->findByGid($gid);
	}

	/** @return Group[] open non-hidden groups the user hasn't joined */
	public function searchJoinable(string $uid, string $search = '', int $limit = 50): array {
		return $this->groupMapper->searchJoinable($uid, $search, $limit);
	}

	/** @return Group[] groups where the current user has a pending invitation */
	public function listPendingInvitations(string $uid): array {
		return $this->groupMapper->findPendingInvitationsForUser($uid);
	}

	/** @return array[] ['uid' => string, 'displayName' => string] across all silos */
	public function searchUsers(string $query, int $limit = 20): array {
		return $this->syncService->searchUsersAcrossInstances($query, $limit);
	}

	public function isMasterOnlyOperation(): bool {
		return $this->shardingService->isMaster();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function getGroupForOwner(string $uid, string $gid): Group {
		$group = $this->getGroup($gid);
		if ($group->getOwner() !== $uid && !$this->groupManager->isAdmin($uid)) {
			throw new \RuntimeException('Only the group owner can perform this operation');
		}
		return $group;
	}

	private function addMember(
		string $gid,
		string $uid,
		int    $status,
		string $acceptToken  = '',
		string $declineToken = '',
		string $email        = '',
	): GroupMember {
		$m = new GroupMember();
		$m->setGid($gid);
		$m->setUid($uid);
		$m->setStatus($status);
		$m->setAcceptToken($acceptToken);
		$m->setDeclineToken($declineToken);
		$m->setInvitationEmail($email);
		$this->memberMapper->insert($m);
		return $m;
	}

	/** @return string[] [acceptToken, declineToken] */
	private function makeTokens(): array {
		return [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
	}

	/** Publish an activity event to one or more affected users. */
	private function publishActivity(string $subject, array $params, string $author, string ...$affectedUsers): void {
		foreach ($affectedUsers as $uid) {
			try {
				$event = $this->activityManager->generateEvent();
				$event->setApp('user_group_admin')
					->setType('group_membership')
					->setAuthor($author)
					->setAffectedUser($uid)
					->setObject('group', 0, $params['gid'] ?? '')
					->setSubject($subject, $params);
				$this->activityManager->publish($event);
			} catch (\Throwable $e) {
				$this->logger->warning('user_group_admin: failed to publish activity: ' . $e->getMessage());
			}
		}
	}

	private function sendInvitationNotification(string $gid, string $inviterUid, string $inviteeUid): void {
		$n = $this->notificationManager->createNotification();
		$n->setApp('user_group_admin')
			->setUser($inviteeUid)
			->setDateTime(new \DateTime())
			->setObject('group_invitation', $gid . '/' . $inviteeUid)
			->setSubject('group_invitation', ['gid' => $gid, 'inviter' => $inviterUid]);
		$this->notificationManager->notify($n);
	}

	private function dismissInvitationNotification(string $gid, string $uid): void {
		$n = $this->notificationManager->createNotification();
		$n->setApp('user_group_admin')
			->setUser($uid)
			->setObject('group_invitation', $gid . '/' . $uid);
		$this->notificationManager->markProcessed($n);
	}

	private function sendJoinRequestNotification(string $gid, string $requesterUid, string $ownerUid): void {
		$n = $this->notificationManager->createNotification();
		$n->setApp('user_group_admin')
			->setUser($ownerUid)
			->setDateTime(new \DateTime())
			->setObject('group_join_request', $gid . '/' . $requesterUid)
			->setSubject('join_request', ['gid' => $gid, 'requester' => $requesterUid]);
		$this->notificationManager->notify($n);
	}

	private function dismissJoinRequestNotification(string $gid, string $requesterUid, string $ownerUid): void {
		$n = $this->notificationManager->createNotification();
		$n->setApp('user_group_admin')
			->setUser($ownerUid)
			->setObject('group_join_request', $gid . '/' . $requesterUid);
		$this->notificationManager->markProcessed($n);
	}
}
