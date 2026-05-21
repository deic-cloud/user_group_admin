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

		// Reject if a user with this email already exists.
		if ($this->userManager->getByEmail($email) !== []) {
			throw new \RuntimeException('A user with this email already exists; invite them by username instead');
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
		$member->setUid(GroupMember::EXTERNAL_UID);
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
			|| $member->getUid() !== GroupMember::EXTERNAL_UID) {
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

		return $uid;
	}

	/**
	 * Process a decline token — mark invitation as declined.
	 */
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
			"Accept the invitation and create your account: {$acceptUrl}",
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
