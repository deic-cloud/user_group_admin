<?php

declare(strict_types=1);

/**
 * "Sponsored folders" DAV endpoint: /remote.php/sponsoredfolders/
 *
 * The GROUP OWNER's read-only overview of the grant folders they sponsor (the
 * old service's "binoculars" view) — deliberately separate from
 * /remote.php/grantfolders/, which shows the grant folders a user HAS as a
 * member.
 *
 *   PROPFIND /sponsoredfolders/                 → one dir per sponsored group
 *   PROPFIND /sponsoredfolders/{gid}/           → one dir per accepted member
 *   …/{gid}/{memberUid}/…                       → that member's grant folder,
 *                                                 read-only
 *
 * Member content is served through the parked member→owner SYSTEM-share mount
 * (/.uga_sponsored~{gid}~{member} in the owner's tree) — which is how the view
 * works CROSS-SILO (federated mirror). Fallback: the member's local FS path,
 * covering same-node members whose system share hasn't been provisioned yet.
 * On production the pretty URL /sponsoredfolders/ is an Apache rewrite.
 */

use OCA\UserGroupAdmin\DAV\NamedDirectory;
use OCA\UserGroupAdmin\DAV\SponsoredDirectory;
use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCA\UserGroupAdmin\Service\GrantFolderManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserSession;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;

// ── Auth (same pattern as grants.php) ─────────────────────────────────────────

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

// ── Parse URL ─────────────────────────────────────────────────────────────────

$prefix  = '/remote.php/sponsoredfolders/';
$uriPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
$subPath = str_starts_with($uriPath, $prefix) ? substr($uriPath, strlen($prefix)) : '';
$gid     = explode('/', $subPath)[0];

$groupMapper = \OC::$server->get(GroupMapper::class);

// ── Root: one directory per group this user sponsors ─────────────────────────

if ($gid === '') {
	$children = [];
	foreach ($groupMapper->findByOwner($uid) as $g) {
		if (!empty($g->getStorageGrant())) {
			$children[] = new SimpleCollection($g->getGid());
		}
	}
	$server = new Server(new SimpleCollection('sponsoredfolders', $children));
	$server->setBaseUri($prefix);
	$server->exec();
	exit;
}

// ── Per group: owner only ─────────────────────────────────────────────────────

try {
	$group = $groupMapper->findByGid($gid);
} catch (\Throwable) {
	http_response_code(404);
	exit;
}
if ($group->getOwner() !== $uid || empty($group->getStorageGrant())) {
	http_response_code(403);
	exit;
}

$rootFolder  = \OC::$server->get(IRootFolder::class);
$dataDir     = rtrim((string)\OC::$server->get(IConfig::class)->getSystemValue('datadirectory', ''), '/');
$ownerFolder = $rootFolder->getUserFolder($uid);

$memberMapper = \OC::$server->get(GroupMemberMapper::class);
$children = [];
foreach ($memberMapper->findByGid($gid, GroupMember::STATUS_ACCEPTED) as $m) {
	$memberUid = $m->getUid();
	if ($memberUid === $uid) {
		continue;
	}
	// Preferred: the parked system-share mount (works cross-silo).
	try {
		$node = $ownerFolder->get(ltrim(GrantFolderManager::SPONSORED_PREFIX, '/') . $gid . '~' . $memberUid);
		if ($node instanceof Folder) {
			$children[] = new SponsoredDirectory($node, $memberUid);
			continue;
		}
	} catch (\Throwable) {
	}
	// Fallback: same-node member without a provisioned system share yet.
	$fsPath = $dataDir . '/' . $memberUid . '/files/' . GrantFolderManager::GRANT_DIR . '/' . $gid;
	if (is_dir($fsPath)) {
		$children[] = new NamedDirectory($memberUid, $fsPath);
	}
}

$server = new Server(new SimpleCollection($gid, $children));
$server->setBaseUri($prefix . $gid . '/');
$server->addPlugin(new \Sabre\DAV\Locks\Plugin(
	new \Sabre\DAV\Locks\Backend\File(sys_get_temp_dir() . '/nc_uga_dav_locks')
));
$server->exec();
