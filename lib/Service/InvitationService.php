<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

use OCA\UserGroupAdmin\Service\IShardingAdapter;
use OCA\UserGroupAdmin\Db\Group;
use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

class InvitationService {
	public function __construct(
		private GroupMapper       $groupMapper,
		private GroupMemberMapper $memberMapper,
		private IUserManager      $userManager,
		private IGroupManager     $groupManager,
		private IShardingAdapter  $shardingService,
		private GroupSyncService  $syncService,
		private IMailer           $mailer,
		private IURLGenerator     $urlGenerator,
		private IConfig           $config,
		private IL10N             $l,
		private LoggerInterface   $logger,
	) {}

	/**
	 * Invite an external email address to a group.
	 * Creates a pending GroupMember with EXTERNAL_UID and sends an email with accept/decline links.
	 *
	 * @throws \RuntimeException on validation failure
	 */
	public function inviteExternal(string $ownerUid, string $gid, string $email): GroupMember {
		$email = strtolower(trim($email));
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new \RuntimeException('Invalid email address');
		}

		$group = $this->groupMapper->findByGid($gid);
		if ($group->getOwner() !== $ownerUid) {
			throw new \RuntimeException('Only the group owner can invite external collaborators');
		}

		// Reject if already invited.
		$existing = $this->memberMapper->findByGid($gid);
		foreach ($existing as $m) {
			if ($m->getInvitationEmail() === $email) {
				throw new \RuntimeException('This email address has already been invited');
			}
		}

		$acceptToken  = bin2hex(random_bytes(32));
		$declineToken = bin2hex(random_bytes(32));

		$member = new GroupMember();
		$member->setGid($gid);
		$member->setUid($email); // the email IS the pending uid (distinct per invite; swapped to a real uid on login)
		$member->setStatus(GroupMember::STATUS_PENDING);
		$member->setAcceptToken($acceptToken);
		$member->setDeclineToken($declineToken);
		$member->setInvitationEmail($email);
		$this->memberMapper->insert($member);

		$this->sendInvitationEmail($email, $gid, $ownerUid, $acceptToken, $declineToken);
		$this->syncService->pushMemberToAllSilos($member);

		return $member;
	}

	/**
	 * Complete an external collaborator signup.
	 * Validates the accept token, creates the NC account, assigns it to the owner's silo,
	 * and marks the membership as accepted.
	 *
	 * @throws \RuntimeException on any validation failure
	 */
	public function completeSignup(
		string $acceptToken,
		string $password,
		string $displayName,
	): string {
		try {
			$member = $this->memberMapper->findByAcceptToken($acceptToken);
		} catch (DoesNotExistException) {
			throw new \RuntimeException('Invalid or expired invitation link');
		}

		if ($member->getStatus() !== GroupMember::STATUS_PENDING
			|| $member->getInvitationEmail() === '') {
			throw new \RuntimeException('This invitation has already been used');
		}

		$email = $member->getInvitationEmail();
		if ($email === '') {
			throw new \RuntimeException('Invitation data is incomplete');
		}

		if (strlen($password) < 10) {
			throw new \RuntimeException('Password must be at least 10 characters');
		}

		// Use email as username (canonical identity: email@master-host).
		$uid = $email;
		if ($this->userManager->userExists($uid)) {
			throw new \RuntimeException('An account with this email already exists');
		}

		// Create user.
		$user = $this->userManager->createUser($uid, $password);
		if ($user === false) {
			throw new \RuntimeException('Failed to create user account');
		}
		$user->setEMailAddress($email);
		if ($displayName !== '') {
			$user->setDisplayName($displayName);
		}

		// Assign new user to owner's silo.
		$gid   = $member->getGid();
		$group = $this->groupMapper->findByGid($gid);
		$owner = $group->getOwner();
		$ownerServer = $this->shardingService->getUserServer($owner);
		if ($ownerServer !== null) {
			$this->shardingService->setUserServer($uid, $ownerServer->getId());
		}

		// Mark membership accepted and replace EXTERNAL_UID with real uid.
		$member->setUid($uid);
		$member->setStatus(GroupMember::STATUS_ACCEPTED);
		$member->setAcceptToken('');
		$member->setDeclineToken('');
		$this->memberMapper->update($member);

		// Add to NC group.
		$ncGroup = $this->groupManager->get($gid);
		$ncGroup?->addUser($user);

		$this->syncService->pushMemberToAllSilos($member);

		// Track curator relationship (owner is responsible for this external user).
		$this->config->setUserValue($owner, 'user_group_admin', 'curator_' . $uid, '1');
		// Record the creating group so removal from it disables this external account.
		$this->config->setUserValue($uid, 'user_group_admin', 'external_group', $gid);

		return $uid;
	}

	/**
	 * Process a decline token — mark invitation as declined.
	 */
	/**
	 * Describe a pending email invite for the accept page: email, group, and whether
	 * the address already belongs to an enabled account (→ log-in-to-accept path).
	 * @return array{email:string,gid:string,existingUser:bool}|null
	 */
	public function describeInvite(string $acceptToken): ?array {
		try {
			$member = $this->memberMapper->findByAcceptToken($acceptToken);
		} catch (DoesNotExistException) {
			return null;
		}
		if ($member->getStatus() !== GroupMember::STATUS_PENDING || $member->getInvitationEmail() === '') {
			return null;
		}
		$email = $member->getInvitationEmail();
		$existing = false;
		foreach ($this->userManager->getByEmail($email) as $u) {
			if ($u->isEnabled()) { $existing = true; break; }
		}
		return ['email' => $email, 'gid' => $member->getGid(), 'existingUser' => $existing];
	}

	/**
	 * Accept an email invite as an already-logged-in existing user: swap the pending
	 * uid=email membership to the real uid (no new account). The logged-in user's
	 * email MUST match the invitation — only the invited person may accept.
	 * @throws \RuntimeException
	 */
	public function acceptAsExistingUser(string $acceptToken, string $loggedInUid): string {
		try {
			$member = $this->memberMapper->findByAcceptToken($acceptToken);
		} catch (DoesNotExistException) {
			throw new \RuntimeException('Invalid or expired invitation link');
		}
		if ($member->getStatus() !== GroupMember::STATUS_PENDING || $member->getInvitationEmail() === '') {
			throw new \RuntimeException('This invitation has already been used');
		}
		$user = $this->userManager->get($loggedInUid);
		if ($user === null) {
			throw new \RuntimeException('Not logged in');
		}
		$mine = strtolower((string)$user->getEMailAddress());
		if ($mine === '' || $mine !== strtolower($member->getInvitationEmail())) {
			throw new \RuntimeException('This invitation was sent to a different email address than the account you are logged in with.');
		}
		$gid   = $member->getGid();
		$email = $member->getInvitationEmail();
		// If they are already a member under their real uid, just drop the pending row.
		try { $already = $this->memberMapper->findByGidUid($gid, $loggedInUid); } catch (DoesNotExistException) { $already = null; }
		if ($already !== null) {
			$this->memberMapper->deleteByGidEmail($gid, $email);
		} else {
			$member->setUid($loggedInUid);
			$member->setInvitationEmail(''); // now a normal member, not an external account
			$member->setStatus(GroupMember::STATUS_ACCEPTED);
			$member->setAcceptToken('');
			$member->setDeclineToken('');
			$this->memberMapper->update($member);
			$this->syncService->pushMemberToAllSilos($member);
		}
		// Purge the pending uid=email invite row on every peer. The swap only renamed
		// OUR local row; peers still hold the original invite row (uid=email) and would
		// otherwise keep showing a stale pending invitation for a member who has joined.
		$this->syncService->removeMemberOnAllSilos($gid, $email, $email);
		$this->groupManager->get($gid)?->addUser($user);
		return $gid;
	}

	public function declineInvitation(string $declineToken): string {
		try {
			$member = $this->memberMapper->findByDeclineToken($declineToken);
		} catch (DoesNotExistException) {
			throw new \RuntimeException('Invalid or expired decline link');
		}

		$member->setStatus(GroupMember::STATUS_DECLINED);
		$member->setAcceptToken('');
		$member->setDeclineToken('');
		$this->memberMapper->update($member);
		$this->syncService->pushMemberToAllSilos($member);

		return $member->getGid();
	}

	public function isCurator(string $ownerUid, string $targetUid): bool {
		return $this->config->getUserValue($ownerUid, 'user_group_admin', 'curator_' . $targetUid, '0') === '1';
	}

	// ── Email ─────────────────────────────────────────────────────────────────

	private function sendInvitationEmail(
		string $email,
		string $gid,
		string $ownerUid,
		string $acceptToken,
		string $declineToken,
	): void {
		$masterUrl  = $this->shardingService->masterUrl() ?: $this->urlGenerator->getAbsoluteURL('/');
		$base       = rtrim($masterUrl, '/');
		$acceptUrl  = $base . '/index.php/apps/user_group_admin/signup?token=' . urlencode($acceptToken);
		$declineUrl = $base . '/index.php/apps/user_group_admin/signup/decline?token=' . urlencode($declineToken);

		$subject = $this->config->getAppValue('user_group_admin', 'invitation_subject', 'Group invitation');
		$sender  = $this->config->getAppValue('user_group_admin', 'invitation_sender', '');

		$owner = $this->userManager->get($ownerUid);
		$ownerName = $owner?->getDisplayName() ?? $ownerUid;

		$body = implode("\n\n", [
			"{$ownerName} has invited you to join the group '{$gid}'.",
			"Accept the invitation: {$acceptUrl}",
			"Decline the invitation: {$declineUrl}",
		]);

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			if ($sender !== '') {
				$message->setFrom([$sender]);
			}
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->error("user_group_admin: failed to send invitation email to {$email}: " . $e->getMessage());
		}
	}
}
