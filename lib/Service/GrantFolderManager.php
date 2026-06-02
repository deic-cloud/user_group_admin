<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

use OCA\UserGroupAdmin\Db\GroupMapper;
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

		// One-time migration: rename legacy Grants → .uga_grants
		$oldParent = $dataDir . '/' . $uid . '/files/Grants';
		if (!is_dir($grantParent) && is_dir($oldParent)) {
			if (rename($oldParent, $grantParent)) {
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
		}

		$anySyncHide = false;

		foreach ($groups as $group) {
			$gid  = $group->getGid();
			$path = $grantParent . '/' . $gid;

			// Migrate from old path outside files/ tree if present
			$oldPath = $dataDir . '/' . $uid . '/user_group_admin/' . $gid;
			if (!is_dir($path) && is_dir($oldPath)) {
				if (rename($oldPath, $path)) {
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
				$this->logger->info('user_group_admin: created grant folder ' . $path);
			}

			if ($group->getGrantSyncHide()) {
				$anySyncHide = true;
			}
		}

		$this->shardingAdapter->setGrantSyncHide($uid, $anySyncHide);
	}
}
