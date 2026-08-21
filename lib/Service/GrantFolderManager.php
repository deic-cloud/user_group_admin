<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

use OCA\UserGroupAdmin\Db\GroupMapper;
use OCP\Files\IRootFolder;
use OCP\IConfig;
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

	public function __construct(
		private GroupMapper       $groupMapper,
		private IConfig           $config,
		private IShardingAdapter  $shardingAdapter,
		private IRootFolder       $rootFolder,
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
		foreach ($uids as $uid) {
			$path = $dataDir . '/' . $uid . '/files/' . self::GRANT_DIR . '/' . $gid;
			if (!is_dir($path)) {
				continue; // not this member's home node
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
