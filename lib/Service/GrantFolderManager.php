<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

use OCA\UserGroupAdmin\Db\GroupMapper;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Creates per-member grant folders inside the member's NC files tree.
 *
 * Physical path: {datadirectory}/{memberUid}/files/.uga_grants/{gid}/
 *
 * The .uga_grants parent is a hidden dotfolder, invisible in the normal Files view.
 * When files_sharding is installed a locked folder-visibility rule is also
 * written to files_sharding_folders to enforce server-side sync exclusion.
 *
 * The folder lives inside files/ so NC assigns it real fileids, enabling
 * full metadata support (tags, comments, activity, versions).
 */
class GrantFolderManager {
	public const GRANT_DIR = '.uga_grants';

	/**
	 * Mount-target prefix of the SYSTEM share of a member's grant folder to the
	 * group owner (the "Sponsored folders" / binoculars surface). Full target:
	 * /.uga_sponsored~{gid}~{memberUid}. Dot-prefixed so the owner's Files view
	 * hides it by default; recognized by this prefix wherever the system shares
	 * must be filtered out of normal share listings.
	 */
	public const SPONSORED_PREFIX = '/.uga_sponsored~';

	public function __construct(
		private GroupMapper       $groupMapper,
		private IConfig           $config,
		private IShardingAdapter  $shardingAdapter,
		private IRootFolder       $rootFolder,
		private IShareManager     $shareManager,
		private IUserManager      $userManager,
		private LoggerInterface   $logger,
	) {}

	public function ensureGrantFolders(string $uid): void {
		$dataDir = rtrim((string) $this->config->getSystemValue('datadirectory', ''), '/');
		if ($dataDir === '') {
			$this->logger->warning('user_group_admin: datadirectory not configured');
			return;
		}

		try {
			$groups = $this->groupMapper->findGrantGroupsForMember($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('user_group_admin: failed to load grant groups for ' . $uid . ': ' . $e->getMessage());
			return;
		}

		if (empty($groups)) {
			return;
		}

		$grantParent = $dataDir . '/' . $uid . '/files/' . self::GRANT_DIR;
		$dirty = false; // any mkdir/rename this pass → scan into oc_filecache at the end

		// One-time migration: rename legacy Grants → .uga_grants
		$oldParent = $dataDir . '/' . $uid . '/files/Grants';
		if (!is_dir($grantParent) && is_dir($oldParent)) {
			if (rename($oldParent, $grantParent)) {
				$dirty = true;
				$this->logger->info('user_group_admin: migrated grant parent ' . $oldParent . ' → ' . $grantParent);
			} else {
				$this->logger->warning('user_group_admin: could not migrate grant parent ' . $oldParent . ' → ' . $grantParent);
			}
		}

		if (!is_dir($grantParent)) {
			if (!mkdir($grantParent, 0750, true) && !is_dir($grantParent)) {
				$this->logger->warning('user_group_admin: could not create grant parent ' . $grantParent);
				return;
			}
			$dirty = true;
		}

		$anySyncHide = false;

		foreach ($groups as $group) {
			$gid  = $group->getGid();
			$path = $grantParent . '/' . $gid;

			// Migrate from old path outside files/ tree if present
			$oldPath = $dataDir . '/' . $uid . '/user_group_admin/' . $gid;
			if (!is_dir($path) && is_dir($oldPath)) {
				if (rename($oldPath, $path)) {
					$dirty = true;
					$this->logger->info('user_group_admin: migrated grant folder ' . $oldPath . ' → ' . $path);
				} else {
					$this->logger->warning('user_group_admin: could not migrate ' . $oldPath . ' → ' . $path);
				}
			}

			if (!is_dir($path)) {
				if (!mkdir($path, 0750, true) && !is_dir($path)) {
					$this->logger->warning('user_group_admin: could not create grant folder ' . $path);
					continue;
				}
				$dirty = true;
				$this->logger->info('user_group_admin: created grant folder ' . $path);
			}

			if ($group->getGrantSyncHide()) {
				$anySyncHide = true;
			}
		}

		// The folders were created with raw mkdir()/rename() on disk, so they're absent
		// from oc_filecache until something scans them — which is why a just-provisioned
		// grant folder 404s over WebDAV/Files until a later pass catches up. Scan the
		// grant tree into the member's home storage now so it's browsable immediately.
		if ($dirty) {
			try {
				$storage = $this->rootFolder->getUserFolder($uid)->getStorage();
				$storage->getScanner()->scan('files/' . self::GRANT_DIR);
			} catch (\Throwable $e) {
				$this->logger->warning('user_group_admin: failed to scan grant folders for ' . $uid . ': ' . $e->getMessage());
			}
		}

		$this->shardingAdapter->setGrantSyncHide($uid, $anySyncHide);

		// Sponsored-folders system shares: each member's grant folder is shared
		// (read-only, app-managed) with the group OWNER — "in principle just a
		// bunch of folders shared with the group owner that cannot be unshared"
		// (Frederik). Riding the ordinary share machinery makes the owner's
		// overview work CROSS-SILO via the proven federated mirror path,
		// replacing the node-local is_dir view. Idempotent; re-created by the
		// next provisioning pass if anything deletes one.
		foreach ($groups as $group) {
			$this->ensureOwnerShare($uid, $group->getGid(), $group->getOwner());
		}
	}

	/** Create the member→owner system share for one grant folder, if absent. */
	private function ensureOwnerShare(string $memberUid, string $gid, string $owner): void {
		if ($owner === '' || $owner === $memberUid) {
			return; // no self-share; the owner's own folder needs no overview share
		}
		try {
			$node = $this->rootFolder->getUserFolder($memberUid)->get(self::GRANT_DIR . '/' . $gid);
		} catch (\Throwable) {
			return; // folder not scanned yet — next pass
		}

		$ownerLocal = $this->isUserLocal($owner);
		$masterHost = (string)preg_replace('#^https?://#', '', rtrim($this->shardingAdapter->masterUrl(), '/'));
		$remoteWith = $owner . '@' . $masterHost;

		// Already shared? (either flavour)
		foreach ([IShare::TYPE_USER, IShare::TYPE_REMOTE] as $type) {
			try {
				foreach ($this->shareManager->getSharesBy($memberUid, $type, $node, false, -1, 0) as $existing) {
					$with = (string)$existing->getSharedWith();
					if ($with === $owner || $with === $remoteWith) {
						return;
					}
				}
			} catch (\Throwable) {
			}
		}

		try {
			$share = $this->shareManager->newShare();
			$share->setNode($node)
				->setSharedBy($memberUid)
				->setPermissions(\OCP\Constants::PERMISSION_READ);
			if ($ownerLocal) {
				$share->setShareType(IShare::TYPE_USER)->setSharedWith($owner);
			} else {
				if ($masterHost === '') {
					return; // standalone install without sharding — same-node only
				}
				$share->setShareType(IShare::TYPE_REMOTE)->setSharedWith($remoteWith);
			}
			$created = $this->shareManager->createShare($share);

			if ($ownerLocal) {
				// Park the owner's mount at the hidden sponsored target so a large
				// group doesn't flood "All files". (Federated mirrors get their
				// mountpoint from the recipient silo's ShareSyncService.)
				try {
					$created->setTarget(self::SPONSORED_PREFIX . $gid . '~' . $memberUid);
					$this->shareManager->moveShare($created, $owner);
				} catch (\Throwable $e) {
					$this->logger->warning("user_group_admin: could not park sponsored mount for {$gid}/{$memberUid}: " . $e->getMessage());
				}
			}
			$this->logger->info("user_group_admin: created sponsored-folder system share {$gid}/{$memberUid} → {$owner}" . ($ownerLocal ? '' : ' (federated)'));
		} catch (\Throwable $e) {
			$this->logger->warning("user_group_admin: sponsored system share {$gid}/{$memberUid} failed: " . $e->getMessage());
		}
	}

	/** Remove the member→owner system share (member left / group deleted). */
	public function removeOwnerShare(string $memberUid, string $gid, string $owner): void {
		$masterHost = (string)preg_replace('#^https?://#', '', rtrim($this->shardingAdapter->masterUrl(), '/'));
		try {
			$node = $this->rootFolder->getUserFolder($memberUid)->get(self::GRANT_DIR . '/' . $gid);
		} catch (\Throwable) {
			return;
		}
		foreach ([IShare::TYPE_USER, IShare::TYPE_REMOTE] as $type) {
			try {
				foreach ($this->shareManager->getSharesBy($memberUid, $type, $node, false, -1, 0) as $existing) {
					$with = (string)$existing->getSharedWith();
					if ($with === $owner || $with === $owner . '@' . $masterHost) {
						$this->shareManager->deleteShare($existing);
					}
				}
			} catch (\Throwable) {
			}
		}
	}

	/**
	 * Is $uid homed on THIS node? Node-aware (the master's directory holds every
	 * user, so userManager->get() is true for everyone there — use residency;
	 * silos have no residency map — use the local account).
	 */
	private function isUserLocal(string $uid): bool {
		if ($this->shardingAdapter->isMaster()) {
			$server = $this->shardingAdapter->getUserServer($uid);
			return $server === null || $this->shardingAdapter->isSelf($server);
		}
		return $this->userManager->get($uid) !== null;
	}

	/**
	 * Delete the per-member grant folders for a group (used when the group is deleted).
	 * Runs on every node; the is_dir gate means each node only removes the folders of
	 * members whose home is here (a grant folder lives only on its member's home silo).
	 * Removes content from disk AND from oc_filecache. DESTRUCTIVE — only invoked on an
	 * owner-initiated group deletion (a member merely leaving keeps their content).
	 */
	public function deleteGroupGrantFolders(string $gid, array $uids): void {
		$dataDir = rtrim((string) $this->config->getSystemValue('datadirectory', ''), '/');
		if ($dataDir === '') {
			return;
		}
		try {
			$owner = $this->groupMapper->findByGid($gid)->getOwner();
		} catch (\Throwable) {
			$owner = '';
		}
		foreach ($uids as $uid) {
			$path = $dataDir . '/' . $uid . '/files/' . self::GRANT_DIR . '/' . $gid;
			if (!is_dir($path)) {
				continue; // not this member's home node
			}
			if ($owner !== '') {
				$this->removeOwnerShare($uid, $gid, $owner); // drop the sponsored system share first
			}
			$this->rrmdir($path);
			try {
				$this->rootFolder->getUserFolder($uid)->getStorage()->getCache()
					->remove('files/' . self::GRANT_DIR . '/' . $gid);
			} catch (\Throwable $e) {
				$this->logger->warning('user_group_admin: failed to clear grant-folder cache for ' . $uid . '/' . $gid . ': ' . $e->getMessage());
			}
			$this->logger->info('user_group_admin: deleted grant folder ' . $path . ' (group deleted)');
		}
	}

	/** Recursively delete a directory tree. */
	private function rrmdir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($it as $f) {
			$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		}
		@rmdir($dir);
	}
}
