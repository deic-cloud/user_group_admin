<?php

declare(strict_types=1);

/**
 * Legacy group-admin API — backwards compatible with the OwnCloud user_group_admin app.
 *
 * Endpoint: GET /remote.php/groupadmin?action=<action>&group=<gid>&member=<uid>
 *
 * Actions: createGroup, addToGroup, isMember, listMembers,
 *          removeFromGroup, disable, deleteGroup
 *
 * NC's main remote.php bootstraps the full stack before including this file.
 */

use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCA\UserGroupAdmin\Service\GroupService;
use OCA\UserGroupAdmin\Service\InvitationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

// ── Authentication ────────────────────────────────────────────────────────────

$userSession = \OC::$server->get(IUserSession::class);

if ($userSession->getUser() === null) {
	$request = \OC::$server->get(\OCP\IRequest::class);
	/** @var \OC\User\Session $concreteSession */
	$concreteSession = $userSession;
	if (method_exists($concreteSession, 'tryBasicAuthLogin')) {
		$concreteSession->tryBasicAuthLogin(
			$request,
			\OC::$server->get(\OCP\Security\Bruteforce\IThrottler::class)
		);
	}
}

if ($userSession->getUser() === null) {
	header('WWW-Authenticate: Basic realm="Nextcloud"');
	http_response_code(401);
	echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
	exit;
}

$currentUid = $userSession->getUser()->getUID();

// ── Parameters ────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';
$gid    = trim($_GET['group']  ?? '');
$member = trim($_GET['member'] ?? '');
$format = $_GET['format'] ?? 'json';

$groupService      = \OC::$server->get(GroupService::class);
$invitationService = \OC::$server->get(InvitationService::class);
$groupMapper       = \OC::$server->get(GroupMapper::class);
$memberMapper      = \OC::$server->get(GroupMemberMapper::class);
$syncService       = \OC::$server->get(\OCA\UserGroupAdmin\Service\GroupSyncService::class);
$userManager       = \OC::$server->get(IUserManager::class);
$groupManager      = \OC::$server->get(IGroupManager::class);
$config            = \OC::$server->get(\OCP\IConfig::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function legacyOk(mixed $data = ['status' => 'success']): void {
	header('Content-Type: application/json');
	echo json_encode($data);
	exit;
}

function legacyErr(string $message, int $code = 400): void {
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode(['status' => 'error', 'message' => $message]);
	exit;
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

try {
	switch ($action) {

		// ── createGroup ───────────────────────────────────────────────────────
		case 'createGroup':
			if ($gid === '') legacyErr('Missing group parameter');
			$groupService->createGroup($currentUid, $gid);
			legacyOk();

		// ── deleteGroup ───────────────────────────────────────────────────────
		case 'deleteGroup':
			if ($gid === '') legacyErr('Missing group parameter');
			$groupService->deleteGroup($currentUid, $gid);
			legacyOk();

		// ── addToGroup ────────────────────────────────────────────────────────
		// Legacy behaviour: owner adds member directly (STATUS_ACCEPTED, no invitation flow).
		case 'addToGroup':
			if ($gid === '' || $member === '') legacyErr('Missing group or member parameter');

			$group = $groupMapper->findByGid($gid);
			if ($group->getOwner() !== $currentUid) {
				legacyErr('Only the group owner can add members via this endpoint', 401);
			}

			$targetUser = $userManager->get($member);
			if ($targetUser === null) {
				legacyErr("User '{$member}' not found");
			}

			try {
				$existing = $memberMapper->findByGidUid($gid, $member);
				if ($existing->getStatus() === GroupMember::STATUS_ACCEPTED) {
					legacyOk(); // already a member — idempotent
				}
				$existing->setStatus(GroupMember::STATUS_ACCEPTED);
				$existing->setAcceptToken('');
				$existing->setDeclineToken('');
				$memberMapper->update($existing);
				$m = $existing;
			} catch (DoesNotExistException) {
				$m = new GroupMember();
				$m->setGid($gid);
				$m->setUid($member);
				$m->setStatus(GroupMember::STATUS_ACCEPTED);
				$m->setAcceptToken('');
				$m->setDeclineToken('');
				$m->setInvitationEmail('');
				$memberMapper->insert($m);
			}

			$groupManager->get($gid)?->addUser($targetUser);
			$syncService->pushMemberToAllSilos($m);
			legacyOk();

		// ── removeFromGroup ───────────────────────────────────────────────────
		case 'removeFromGroup':
			if ($gid === '' || $member === '') legacyErr('Missing group or member parameter');
			$groupService->removeMember($currentUid, $gid, $member);
			legacyOk();

		// ── isMember ──────────────────────────────────────────────────────────
		case 'isMember':
			if ($gid === '' || $member === '') legacyErr('Missing group or member parameter');
			try {
				$m = $memberMapper->findByGidUid($gid, $member);
				if ($m->getStatus() === GroupMember::STATUS_ACCEPTED) {
					legacyOk(['status' => 'success']);
				}
			} catch (DoesNotExistException) {}
			http_response_code(403);
			header('Content-Type: application/json');
			echo json_encode(['status' => 'error']);
			exit;

		// ── listMembers ───────────────────────────────────────────────────────
		case 'listMembers':
			if ($gid === '') legacyErr('Missing group parameter');

			$group   = $groupMapper->findByGid($gid);
			$members = $memberMapper->findByGid($gid);

			// Any authenticated user may list members.
			$rows = array_map(function (GroupMember $m) use ($group): array {
				$isExternal = $m->getInvitationEmail() !== '' || $m->getUid() === GroupMember::EXTERNAL_UID;
				return [
					'gid'              => $m->getGid(),
					'uid'              => $m->getUid(),
					'verified'         => $m->getStatus() === GroupMember::STATUS_ACCEPTED ? 1 : 0,
					'accept'           => $m->getStatus() === GroupMember::STATUS_ACCEPTED ? 1 : 0,
					'decline'          => $m->getStatus() === GroupMember::STATUS_DECLINED ? 1 : 0,
					'files_usage'      => $m->getStorageUsed(),
					'invitation_email' => $m->getInvitationEmail(),
					'owner'            => $group->getOwner(),
					'type'             => $isExternal ? 'external' : 'internal',
				];
			}, $members);

			if ($format === 'text' || $format === 'x509') {
				header('Content-Type: text/plain');
				foreach ($rows as $row) {
					echo $row['uid'] . "\n";
				}
				exit;
			}

			legacyOk($rows);

		// ── disable ───────────────────────────────────────────────────────────
		// Disable a user account. Caller must be curator or admin.
		case 'disable':
			if ($member === '') legacyErr('Missing member parameter');

			$isAdmin   = \OC::$server->get(\OCP\IGroupManager::class)->isAdmin($currentUid);
			$isCurator = $invitationService->isCurator($currentUid, $member);

			if (!$isAdmin && !$isCurator) {
				legacyErr('Not authorised to disable this user', 401);
			}

			$targetUser = $userManager->get($member);
			if ($targetUser === null) {
				legacyErr("User '{$member}' not found");
			}
			$targetUser->setEnabled(false);
			legacyOk();

		default:
			legacyErr('Unknown action', 400);
	}

} catch (\RuntimeException $e) {
	legacyErr($e->getMessage());
} catch (\Throwable $e) {
	\OC::$server->get(\Psr\Log\LoggerInterface::class)
		->error('user_group_admin legacy API error: ' . $e->getMessage(), ['exception' => $e]);
	legacyErr('Internal error', 500);
}
