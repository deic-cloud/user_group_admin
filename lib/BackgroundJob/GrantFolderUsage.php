<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\BackgroundJob;

use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCA\UserGroupAdmin\Service\GrantFolderManager;
use OCA\UserGroupAdmin\Service\GroupSyncService;
use OCA\UserGroupAdmin\Service\IShardingAdapter;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily job that recalculates grant folder sizes and reports them to files_accounting.
 *
 * For each group with a storage_grant, iterates over accepted members, measures
 * their {datadirectory}/{uid}/files/.uga_grants/{gid}/ directory size, and calls
 * FilesAccounting\Service\StorageService::updateMemberUsage() +
 * FilesAccounting\Service\StorageService::logGrantUsage() so billing is accurate.
 */
class GrantFolderUsage extends TimedJob {
	public function __construct(
		ITimeFactory                $time,
		private GroupMapper         $groupMapper,
		private GroupMemberMapper   $memberMapper,
		private GroupSyncService    $syncService,
		private IShardingAdapter    $shardingService,
		private IConfig             $config,
		private IAppManager         $appManager,
		private LoggerInterface     $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400); // once per day
	}

	protected function run(mixed $argument): void {
		$dataDir = rtrim((string) $this->config->getSystemValue('datadirectory', ''), '/');
		if ($dataDir === '') {
			return;
		}

		$accountingAvailable = $this->appManager->isInstalled('files_accounting');
		/** @var \OCA\FilesAccounting\Service\StorageService|null $accounting */
		$accounting = null;
		if ($accountingAvailable) {
			try {
				$accounting = \OC::$server->get(\OCA\FilesAccounting\Service\StorageService::class);
			} catch (\Throwable) {
				$accounting = null;
			}
		}

		$isMaster = $this->shardingService->isMaster();

		$groups = $this->groupMapper->findAll();
		foreach ($groups as $group) {
			if (empty($group->getStorageGrant())) {
				continue;
			}
			$gid     = $group->getGid();
			$members = $this->memberMapper->findByGid($gid, GroupMember::STATUS_ACCEPTED);

			foreach ($members as $member) {
				$uid  = $member->getUid();
				$path = $dataDir . '/' . $uid . '/files/' . GrantFolderManager::GRANT_DIR . '/' . $gid;
				// A member's grant folder physically lives only on their HOME node, so an
				// absent dir means this isn't their home — skip it (measuring 0 here would
				// clobber the real value recorded on their home node). This is the cross-
				// silo fix: each member is measured exactly once, on their home node.
				if (!is_dir($path)) {
					continue;
				}
				$size = $this->dirSize($path);
				// Record locally + propagate to every peer so the per-node pool cap
				// (GrantQuotaWrapper::sumStorageUsedExcept) counts off-silo members too.
				$this->memberMapper->updateStorageUsed($gid, $uid, $size);
				try {
					$this->syncService->pushMemberUsage($gid, $uid, $size);
				} catch (\Throwable $e) {
					$this->logger->warning('user_group_admin: pushMemberUsage failed for ' . $uid . '/' . $gid . ': ' . $e->getMessage());
				}
			}

			// Log the group's total (billing history) once, from the MASTER, using the
			// synced per-member usage — so it reflects all silos, not just this node's.
			if ($accounting !== null && $isMaster && !empty($members)) {
				try {
					$accounting->logGrantUsage($gid, $this->memberMapper->sumStorageUsed($gid));
				} catch (\Throwable $e) {
					$this->logger->warning('user_group_admin: logGrantUsage failed for ' . $gid . ': ' . $e->getMessage());
				}
			}
		}
	}

	private function dirSize(string $path): int {
		if (!is_dir($path)) {
			return 0;
		}
		$size = 0;
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iter as $file) {
			$size += $file->getSize();
		}
		return $size;
	}
}
