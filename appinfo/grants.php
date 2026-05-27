<?php

declare(strict_types=1);

/**
 * Grant-folder DAV endpoint.
 *
 * Member  access: any WebDAV method on /remote.php/user_group_admin/{gid}/
 *   → serves {datadirectory}/{memberUid}/user_group_admin/{gid}/
 *     (read-write; quota enforced by GrantPropertiesPlugin)
 *
 * Owner access:   any WebDAV method on /remote.php/user_group_admin/{gid}/
 *   → serves a virtual directory listing all accepted members' grant folders:
 *     {datadirectory}/{memberUid}/user_group_admin/{gid}/  per member (read-only)
 *
 * Grant folders live OUTSIDE the member's files/ tree; NC's Files view never
 * sees them.  Space is accounted to the group owner via files_accounting.
 */

use OCA\UserGroupAdmin\DAV\GrantDirectory;
use OCA\UserGroupAdmin\DAV\GrantPropertiesPlugin;
use OCA\UserGroupAdmin\DAV\NamedDirectory;
use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCA\UserGroupAdmin\Service\GrantFolderManager;
use OCP\IConfig;
use OCP\IUserSession;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;

// ── Auth ──────────────────────────────────────────────────────────────────────

$userSession = \OC::$server->get(IUserSession::class);

if ($userSession->getUser() === null) {
	$request = \OC::$server->get(\OCP\IRequest::class);
	/** @var \OC\User\Session $concreteSession */
	$concreteSession = $userSession;
	if (method_exists($concreteSession, 'tryBasicAuthLogin')) {
		try {
			$concreteSession->tryBasicAuthLogin(
				$request,
				\OC::$server->get(\OCP\Security\Bruteforce\IThrottler::class)
			);
		} catch (\OC\User\LoginException) {}
	}
}

if ($userSession->getUser() === null) {
	header('WWW-Authenticate: Basic realm="Nextcloud"');
	http_response_code(401);
	exit;
}

$uid = $userSession->getUser()->getUID();

// ── Parse group ID from URL ───────────────────────────────────────────────────

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$prefix     = '/remote.php/user_group_admin/';
$uriPath    = strtok($requestUri, '?') ?: '';
$subPath    = str_starts_with($uriPath, $prefix) ? substr($uriPath, strlen($prefix)) : '';
$gid        = explode('/', $subPath)[0];

if ($gid === '') {
	http_response_code(400);
	exit;
}

// ── Validate group and membership ─────────────────────────────────────────────

$groupMapper = \OC::$server->get(GroupMapper::class);
try {
	$group = $groupMapper->findByGid($gid);
} catch (\Throwable) {
	http_response_code(404);
	exit;
}

if (empty($group->getStorageGrant())) {
	http_response_code(403);
	exit;
}

$ownerUid = $group->getOwner();
$isOwner  = ($uid === $ownerUid);

if (!$isOwner) {
	$memberGroups = $groupMapper->findGrantGroupsForMember($uid);
	$isMember = false;
	foreach ($memberGroups as $g) {
		if ($g->getGid() === $gid) {
			$isMember = true;
			break;
		}
	}
	if (!$isMember) {
		http_response_code(403);
		exit;
	}
}

// ── Resolve paths ─────────────────────────────────────────────────────────────

$dataDir = rtrim((string) \OC::$server->get(IConfig::class)->getSystemValue('datadirectory', ''), '/');

$memberMapper = \OC::$server->get(GroupMemberMapper::class);
$acceptedMembers = $memberMapper->findByGid($gid, GroupMember::STATUS_ACCEPTED);

// Collect all existing member grant folder paths (used for total-cap check)
$allMemberPaths = [];
foreach ($acceptedMembers as $m) {
	$p = $dataDir . '/' . $m->getUid() . '/files/' . GrantFolderManager::GRANT_DIR . '/' . $gid;
	if (is_dir($p)) {
		$allMemberPaths[] = $p;
	}
}

// ── Parse quota limits ────────────────────────────────────────────────────────

function uga_parse_bytes(string $quota): int {
	if ($quota === '' || strtolower($quota) === 'none') {
		return 0;
	}
	$value = (float) $quota;
	$unit  = strtoupper(trim(ltrim($quota, '0123456789. ')));
	return (int) match (true) {
		str_starts_with($unit, 'T') => $value * 1024 ** 4,
		str_starts_with($unit, 'G') => $value * 1024 ** 3,
		str_starts_with($unit, 'M') => $value * 1024 ** 2,
		str_starts_with($unit, 'K') => $value * 1024,
		default                     => $value,
	};
}

$perMemberBytes = uga_parse_bytes($group->getStorageGrant());
$totalBytes     = uga_parse_bytes($group->getStorageGrantTotal());

// ── Ensure the grant folder exists for member ─────────────────────────────────

if (!$isOwner) {
	$memberGrantPath = $dataDir . '/' . $uid . '/files/' . GrantFolderManager::GRANT_DIR . '/' . $gid;
	if (!is_dir($memberGrantPath)) {
		mkdir($memberGrantPath, 0750, true);
		$allMemberPaths[] = $memberGrantPath;
	}
}

// ── Build Sabre DAV tree ──────────────────────────────────────────────────────

$prefix  = '/remote.php/user_group_admin/';
$baseUri = $prefix . $gid . '/';

if ($isOwner) {
	$children = [];
	foreach ($acceptedMembers as $m) {
		$mPath = $dataDir . '/' . $m->getUid() . '/files/' . GrantFolderManager::GRANT_DIR . '/' . $gid;
		if (is_dir($mPath)) {
			$children[] = new NamedDirectory($m->getUid(), $mPath);
		}
	}
	$root       = new SimpleCollection($gid, $children);
	$memberPath = ''; // no per-member quota for owner view
} else {
	$root       = new GrantDirectory($memberGrantPath, $perMemberBytes);
	$memberPath = $memberGrantPath;
}

$server = new Server($root);
$server->setBaseUri($baseUri);

$server->addPlugin(new GrantPropertiesPlugin(
	$perMemberBytes,
	$totalBytes,
	$memberPath,
	$allMemberPaths,
));

$server->addPlugin(new \Sabre\DAV\Locks\Plugin(
	new \Sabre\DAV\Locks\Backend\File(sys_get_temp_dir() . '/nc_uga_dav_locks')
));

if (\OC::$server->get(\OCP\IConfig::class)->getSystemValue('debug', false)) {
	$server->addPlugin(new \Sabre\DAV\Browser\Plugin());
}

$server->exec();
