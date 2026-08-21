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
		private \OCP\EventDispatcher\IEventDispatcher $eventDispatcher,
		private \OCP\IConfig $config,
	) {}

	/** Signal that a group's accepted membership changed (see GroupMembersChangedEvent). */
	private function membersChanged(string $gid): void {
		try {
			$this->eventDispatcher->dispatchTyped(new \OCA\UserGroupAdmin\Event\GroupMembersChangedEvent($gid));
		} catch (\Throwable $e) {
			$this->logger->warning('user_group_admin: membersChanged dispatch failed: ' . $e->getMessage());
		}
	}

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

	/**
	 * Ensure the hidden domain group $gid exists and $uid is an accepted member.
	 * The port of the old user_saml schacHomeOrganization / UID-domain grouping — it
	 * lands in user_group_admin (uga_groups) so the domain billing rollup has something
	 * to group on. Idempotent. The group keeps the HIDDEN_OWNER sentinel until an admin
	 * onboards the institution (assigns a real owner + top-up/grant); ownerless hidden
	 * groups are never billed.
	 */
	public function ensureDomainGroupMembership(string $gid, string $uid): void {
		// #1 External collaborator accounts are NOT institution-grouped. Their uid is an
		// email, so the "domain" is merely the mail provider (e.g. gmail.com), not an
		// institution — domain grouping is for SAML/institutional logins only, as on the
		// old service. Detected via the synced member row's invitation_email (which is
		// node-independent, unlike the 'external_group' user-value that only exists on the
		// node where signup ran).
		foreach ($this->memberMapper->findByUid($uid, false) as $m) {
			if ($m->getInvitationEmail() !== '') {
				return;
			}
		}

		// #2 Only adopt a group as the institution's domain group if it is a genuine
		// hidden domain group. If a real user has already created a group of this exact
		// name, do NOT populate it with institutional members — that would let a
		// name-squatter own an institution's group and see/control its roster and grants.
		// Leave it untouched for an admin to resolve.
		if ($this->groupMapper->existsByGid($gid)) {
			$existing = $this->groupMapper->findByGid($gid);
			if (!$existing->getHidden()) {
				$this->logger->warning(
					"user_group_admin: domain '{$gid}' collides with a user-created group owned by "
					. "'{$existing->getOwner()}'; not domain-grouping '{$uid}' (needs admin resolution)"
				);
				return;
			}
		}

		$this->createHiddenGroup($gid); // idempotent (returns existing hidden group)
		if (!$this->memberMapper->isMember($gid, $uid)) {
			$this->addMember($gid, $uid, GroupMember::STATUS_ACCEPTED);
			$this->syncService->pushGroupToAllSilos(
				$this->groupMapper->findByGid($gid),
				$this->memberMapper->findByGid($gid),
			);
		}
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
		?bool   $grantSyncHide    = null,
	): Group {
		$group = $this->getGroupForOwner($callerUid, $gid);

		if ($description       !== null) $group->setDescription($description);
		if ($private           !== null) $group->setPrivate($private);
		if ($open              !== null) $group->setOpen($open);
		if ($storageGrant      !== null) $group->setStorageGrant($storageGrant);
		if ($storageGrantTotal !== null) $group->setStorageGrantTotal($storageGrantTotal);

		$syncHideChanged = $grantSyncHide !== null && $grantSyncHide !== $group->getGrantSyncHide();
		if ($grantSyncHide !== null) $group->setGrantSyncHide($grantSyncHide);

		$this->groupMapper->update($group);
		$this->syncService->pushGroupToAllSilos($group, $this->memberMapper->findByGid($gid));

		// Propagate sync-hide change to all accepted members
		if ($syncHideChanged) {
			$accepted = $this->memberMapper->findByGid($gid, GroupMember::STATUS_ACCEPTED);
			foreach ($accepted as $member) {
				$uid = $member->getUid();
				// Recompute: hide if ANY of this user's grant groups has grantSyncHide=true
				$userGroups = $this->groupMapper->findGrantGroupsForMember($uid);
				$anyHide = false;
				foreach ($userGroups as $g) {
					if ($g->getGrantSyncHide()) { $anyHide = true; break; }
				}
				$this->shardingService->setGrantSyncHide($uid, $anyHide);
			}
		}

		return $group;
	}

	/** @throws \RuntimeException if not found or caller is not owner */
	public function deleteGroup(string $callerUid, string $gid): void {
		$group = $this->getGroupForOwner($callerUid, $gid);

		// Clear any pending invitation / join-request / ownership-offer notifications
		// for this group's members before the rows disappear, so nobody is left with a
		// notification pointing at a deleted group.
		$this->dismissAllGroupNotifications($group, $this->memberMapper->findByGid($gid));

		$this->memberMapper->deleteByGid($gid);
		$this->groupMapper->deleteByGid($gid);
		$ncGroup = $this->groupManager->get($gid);
		if ($ncGroup !== null) {
			$ncGroup->delete();
		}

		$this->syncService->deleteGroupOnAllSilos($gid);
		$this->publishActivity('group_deleted', ['gid' => $gid], $callerUid, $callerUid);
	}

	// ── Ownership transfer ──────────────────────────────────────────────────────
	// The owner is the party billed for a group's top-ups and grant folders, so
	// ownership can't be handed over silently: a normal transfer records a pending
	// offer and only takes effect once the recipient accepts. An administrator may
	// force it (no consent), and the no-orphan path (reassignOwnedGroupsOnUserDeletion)
	// hands off automatically when an owner's account is deleted.
	// Because grant folders live per-member (GrantFolderManager), a transfer moves
	// no data — it is a metadata + billing-pointer change, propagated to all silos.

	/**
	 * Offer ownership of $gid to $newOwnerUid (consent flow), or — as an admin or
	 * the institution's domain owner — force it immediately with $force.
	 *
	 * @return array{group: array, warning: ?string, committed: ?string}
	 * @throws \RuntimeException on auth / validation failure
	 */
	public function transferOwnership(string $callerUid, string $gid, string $newOwnerUid, bool $force = false): array {
		$group         = $this->getGroup($gid);
		$isAdmin       = $this->groupManager->isAdmin($callerUid);
		$isOwner       = $group->getOwner() === $callerUid;
		$isDomainOwner = $this->isDomainOwner($callerUid, $gid);

		if (!$isOwner && !$isAdmin && !$isDomainOwner) {
			throw new \RuntimeException('Only the group owner, an administrator, or the domain owner can transfer ownership');
		}
		// Don't require a *local* user: the new owner may live on another silo.
		// Realness is enforced by the accepted-membership check below (consent
		// path); a forced transfer takes responsibility for the uid.
		if ($newOwnerUid === '') {
			throw new \RuntimeException('No proposed owner given');
		}
		if ($newOwnerUid === $group->getOwner()) {
			throw new \RuntimeException('That user already owns this group');
		}

		// Informed consent, no hard block: the owner's own quota is the backstop —
		// if members later exhaust it, writes stop and the owner arranges a larger
		// quota. We only surface the committed amount + a soft warning when it is
		// close to or over the proposed owner's quota.
		$committed = $this->committedHuman($group);
		$warning   = $this->ownershipWarning($group, $newOwnerUid);

		if ($force) {
			if (!$isAdmin && !$isDomainOwner) {
				throw new \RuntimeException('Only an administrator or the domain owner can transfer ownership without the recipient’s consent');
			}
			$this->applyOwnership($group, $newOwnerUid, $callerUid);
			return ['group' => $group->toArray(), 'warning' => $warning, 'committed' => $committed];
		}

		// Consent flow: the proposed owner must already be an active member, so
		// they can see the group and act on the offer. (Force bypasses this.)
		try {
			if ($this->memberMapper->findByGidUid($gid, $newOwnerUid)->getStatus() !== GroupMember::STATUS_ACCEPTED) {
				throw new \RuntimeException('The proposed owner must be an active member of the group');
			}
		} catch (DoesNotExistException) {
			throw new \RuntimeException('The proposed owner must be an active member of the group');
		}

		// Record the offer and notify the proposed owner (with what they'd sponsor).
		$group->setPendingOwner($newOwnerUid);
		$this->groupMapper->update($group);
		$this->syncService->pushGroupToAllSilos($group, $this->memberMapper->findByGid($gid));
		$this->sendOwnershipOfferNotification($gid, $callerUid, $newOwnerUid, $committed);
		$this->publishActivity('ownership_offered',        ['gid' => $gid, 'uid' => $newOwnerUid], $callerUid, $callerUid);
		$this->publishActivity('ownership_offer_received', ['gid' => $gid, 'inviter' => $callerUid], $callerUid, $newOwnerUid);
		return ['group' => $group->toArray(), 'warning' => $warning, 'committed' => $committed];
	}

	/**
	 * True if $callerUid owns the institutional domain group (the schacHomeOrganization
	 * group, named after the UID domain) of any accepted member of $gid — i.e. they are
	 * the responsible party for that institution's users. HIDDEN_OWNER never qualifies.
	 */
	private function isDomainOwner(string $callerUid, string $gid): bool {
		if ($callerUid === '' || $callerUid === Group::HIDDEN_OWNER) {
			return false;
		}
		$domains = [];
		foreach ($this->memberMapper->findByGid($gid, GroupMember::STATUS_ACCEPTED) as $m) {
			$at = strpos($m->getUid(), '@');
			if ($at !== false && $at > 0) {
				$d = substr($m->getUid(), $at + 1);
				if ($d !== '' && $d !== $gid) {
					$domains[$d] = true;
				}
			}
		}
		foreach (array_keys($domains) as $domain) {
			try {
				if ($this->groupMapper->findByGid($domain)->getOwner() === $callerUid) {
					return true;
				}
			} catch (DoesNotExistException) {}
		}
		return false;
	}

	/** The group's committed grant pool as a human string, or null if none. */
	private function committedHuman(Group $group): ?string {
		$t = trim((string)$group->getStorageGrantTotal());
		return $t !== '' ? $t : null;
	}

	/** Soft, non-blocking warning if the committed pool is close to / over the proposed owner's quota. */
	private function ownershipWarning(Group $group, string $newOwnerUid): ?string {
		$committed = (int)\OCP\Util::computerFileSize((string)$group->getStorageGrantTotal());
		if ($committed <= 0) {
			return null;
		}
		$user = $this->userManager->get($newOwnerUid);
		if ($user === null) {
			return null; // cross-silo / unknown quota — can't compare (soft check only)
		}
		$q = strtolower(trim((string)$user->getQuota()));
		if ($q === '' || $q === 'none' || $q === 'default' || $q === 'unlimited') {
			return null; // unlimited / unset — no signal
		}
		$quota = \OCP\Util::computerFileSize($user->getQuota());
		if ($quota === false || $quota <= 0) {
			return null;
		}
		$c = \OCP\Util::humanFileSize($committed);
		$qh = \OCP\Util::humanFileSize((int)$quota);
		if ($committed >= $quota) {
			return "The group's committed storage ({$c}) exceeds {$newOwnerUid}'s quota ({$qh}); they may need a larger quota.";
		}
		if ($committed >= 0.8 * $quota) {
			return "The group's committed storage ({$c}) is close to {$newOwnerUid}'s quota ({$qh}).";
		}
		return null;
	}

	/**
	 * The proposed owner (or an admin) accepts or declines a pending transfer.
	 *
	 * @throws \RuntimeException on auth / validation failure
	 */
	public function respondOwnership(string $callerUid, string $gid, bool $accept): void {
		$group   = $this->getGroup($gid);
		$pending = $group->getPendingOwner();
		if ($pending === '') {
			throw new \RuntimeException('No pending ownership transfer for this group');
		}
		$isAdmin = $this->groupManager->isAdmin($callerUid);
		if ($callerUid !== $pending && !$isAdmin && !$this->isDomainOwner($callerUid, $gid)) {
			throw new \RuntimeException('Only the proposed owner can respond to this transfer');
		}

		$oldOwner = $group->getOwner();
		if ($accept) {
			$this->applyOwnership($group, $pending, $callerUid);
			$this->publishActivity('ownership_accepted', ['gid' => $gid, 'uid' => $pending], $callerUid, $oldOwner);
		} else {
			$group->setPendingOwner('');
			$this->groupMapper->update($group);
			$this->syncService->pushGroupToAllSilos($group, $this->memberMapper->findByGid($gid));
			$this->publishActivity('ownership_declined', ['gid' => $gid, 'uid' => $pending], $callerUid, $oldOwner);
		}
		$this->dismissOwnershipNotification($gid, $pending);
	}

	/**
	 * A user is being deleted — hand off any groups they own so nothing is
	 * orphaned. Reassign to the institution's domain owner when one resolves,
	 * otherwise to the HIDDEN_OWNER sentinel (ownerless → unbilled, never deleted).
	 */
	public function reassignOwnedGroupsOnUserDeletion(string $uid): void {
		foreach ($this->groupMapper->findByOwner($uid) as $group) {
			$gid       = $group->getGid();
			$successor = $this->resolveDomainOwner($uid, $gid);
			// A pending offer against this group is now stale (owner changed) — clear it.
			if ($group->getPendingOwner() !== '') {
				$this->dismissOwnershipNotification($gid, $group->getPendingOwner());
			}
			$group->setOwner($successor);
			$group->setPendingOwner('');
			$this->groupMapper->update($group);
			try {
				$this->syncService->pushGroupToAllSilos($group, $this->memberMapper->findByGid($gid));
			} catch (\Throwable $e) {
				$this->logger->warning('user_group_admin: failed to propagate ownership reassignment for ' . $gid . ': ' . $e->getMessage());
			}
			$this->logger->info("user_group_admin: reassigned group {$gid} from deleted owner {$uid} to {$successor}");
		}
	}

	private function applyOwnership(Group $group, string $newOwnerUid, string $actor): void {
		$gid           = $group->getGid();
		$previousOwner = $group->getOwner();
		$group->setOwner($newOwnerUid);
		$group->setPendingOwner('');
		$this->groupMapper->update($group);

		// Ensure the new owner is an accepted member.
		try {
			$m = $this->memberMapper->findByGidUid($gid, $newOwnerUid);
			if ($m->getStatus() !== GroupMember::STATUS_ACCEPTED) {
				$m->setStatus(GroupMember::STATUS_ACCEPTED);
				$m->setAcceptToken('');
				$m->setDeclineToken('');
				$this->memberMapper->update($m);
			}
		} catch (DoesNotExistException) {
			$this->addMember($gid, $newOwnerUid, GroupMember::STATUS_ACCEPTED);
		}
		$u = $this->userManager->get($newOwnerUid);
		if ($u !== null) {
			$this->groupManager->get($gid)?->addUser($u);
		}

		$this->syncService->pushGroupToAllSilos($group, $this->memberMapper->findByGid($gid));
		$this->membersChanged($gid);
		$this->publishActivity('ownership_transferred', ['gid' => $gid, 'uid' => $newOwnerUid], $actor, $newOwnerUid);
		if ($previousOwner !== '' && $previousOwner !== Group::HIDDEN_OWNER && $previousOwner !== $newOwnerUid) {
			$this->publishActivity('ownership_transferred_from', ['gid' => $gid, 'uid' => $newOwnerUid], $actor, $previousOwner);
		}
	}

	/** Resolve a successor owner from the departing user's domain group, or HIDDEN_OWNER. */
	private function resolveDomainOwner(string $departingUid, string $gid): string {
		$at = strpos($departingUid, '@');
		if ($at !== false && $at > 0) {
			$domain = substr($departingUid, $at + 1);
			if ($domain !== '' && $domain !== $gid && preg_match('/^[a-zA-Z0-9_.\-]+$/', $domain)) {
				try {
					$owner = $this->groupMapper->findByGid($domain)->getOwner();
					if ($owner !== '' && $owner !== Group::HIDDEN_OWNER
						&& $owner !== $departingUid
						&& $this->userManager->get($owner) !== null) {
						return $owner;
					}
				} catch (DoesNotExistException) {}
			}
		}
		return Group::HIDDEN_OWNER;
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
			$this->membersChanged($gid);
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
		$this->membersChanged($gid);
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

	public function removeMember(string $callerUid, string $gid, string $targetUid, string $email = ''): void {
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
		// External collaborators are tied to the group that created them: that group's
		// owner vouched for (and is legally responsible for) the person, so removal from
		// the CREATING group disables the account — regardless of other memberships.
		//
		// The creating-group membership is exactly the row that still carries an
		// invitation_email (set at signup, kept thereafter). Existing users who accepted
		// an invite have it cleared, and memberships added later to other groups never
		// had it — so neither is ever disabled. We read it from the member row (which
		// syncs to every node) rather than a user-value that only exists on the node
		// where signup happened to run.
		$isCreatingGroup = false;
		try {
			$row = $this->memberMapper->findByGidUid($gid, $targetUid);
			$isCreatingGroup = $row->getInvitationEmail() !== '' && $row->getStatus() === GroupMember::STATUS_ACCEPTED;
		} catch (DoesNotExistException) {
			// no local row (already gone / pending elsewhere) — nothing to disable
		}

		$this->memberMapper->deleteByGidUid($gid, $targetUid);
		$user = $this->userManager->get($targetUid);
		if ($user !== null) {
			$this->groupManager->get($gid)?->removeUser($user);
		}
		$this->syncService->removeMemberOnAllSilos($gid, $targetUid);
		if ($isCreatingGroup && $user !== null) {
			$user->setEnabled(false); // removed from the group that vouches for it
		}
		$this->membersChanged($gid);
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
		// Merge, dedup by gid. Skip hidden groups: domain/institution groups
		// (e.g. dtu.dk, gmail.com) that users are auto-joined to must not clutter
		// their "My groups" list — same as SAML-provisioned domain groups.
		$all = [];
		foreach (array_merge($owned, $member) as $g) {
			if ($g->getHidden()) {
				continue;
			}
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

	/**
	 * Publish an activity event to one or more affected users, delivering each to the
	 * node where that user actually lives. No separate topology: an affected user who
	 * is local (self-actions and same-node recipients — the common case) is published
	 * here; a recipient on another node is handed to the existing group-sync star, and
	 * whichever node the user is local on publishes it (see InternalController::publishActivity).
	 */
	private function isUserHomeHere(string $uid): bool {
		if ($uid === '') {
			return false;
		}
		// Master holds the whole directory, so mere existence is meaningless there —
		// use files_sharding residency. Users with no residency record (e.g. admin,
		// whose home is master) fall back to local existence. Silos hold only their
		// own users, so local existence == home.
		if ($this->shardingService->isMaster()) {
			$server = $this->shardingService->getUserServer($uid);
			if ($server !== null) {
				return $this->shardingService->isSelf($server);
			}
		}
		return $this->userManager->get($uid) !== null;
	}

	private function publishActivity(string $subject, array $params, string $author, string ...$affectedUsers): void {
		$ts = time();
		foreach ($affectedUsers as $uid) {
			if ($uid !== '' && !$this->isUserHomeHere($uid)) {
				$this->syncService->deliverActivity($subject, $params, $author, $uid, $ts);
				continue;
			}
			try {
				$event = $this->activityManager->generateEvent();
				$event->setApp('user_group_admin')
					->setType('group_membership')
					->setAuthor($author)
					->setTimestamp($ts)
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

	private function sendOwnershipOfferNotification(string $gid, string $fromUid, string $toUid, ?string $committed = null): void {
		$n = $this->notificationManager->createNotification();
		$n->setApp('user_group_admin')
			->setUser($toUid)
			->setDateTime(new \DateTime())
			->setObject('ownership_transfer', $gid . '/' . $toUid)
			->setSubject('ownership_transfer', ['gid' => $gid, 'inviter' => $fromUid, 'committed' => $committed ?? '']);
		$this->notificationManager->notify($n);
	}

	private function dismissOwnershipNotification(string $gid, string $toUid): void {
		$n = $this->notificationManager->createNotification();
		$n->setApp('user_group_admin')
			->setUser($toUid)
			->setObject('ownership_transfer', $gid . '/' . $toUid);
		$this->notificationManager->markProcessed($n);
	}

	/** Dismiss every pending notification tied to a group (used before deleting it). */
	private function dismissAllGroupNotifications(Group $group, array $members): void {
		$gid   = $group->getGid();
		$owner = $group->getOwner();
		foreach ($members as $m) {
			$this->dismissInvitationNotification($gid, $m->getUid());
			if ($owner !== '') {
				$this->dismissJoinRequestNotification($gid, $m->getUid(), $owner);
			}
		}
		if ($group->getPendingOwner() !== '') {
			$this->dismissOwnershipNotification($gid, $group->getPendingOwner());
		}
	}
}
