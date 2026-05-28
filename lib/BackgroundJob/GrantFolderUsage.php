<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\BackgroundJob;

use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCA\UserGroupAdmin\Service\GrantFolderManager;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily job that recalculates grant folder sizes and reports them to files_accounting.
 *
 * For each group with a storage_grant, iterates over accepted members, measures
 * their {datadirectory}/{uid}/user_group_admin/{gid}/ directory size, and calls
 * FilesAccounting\Service\StorageService::updateMemberUsage() +
 * FilesAccounting\Service\StorageService::logGrantUsage() so billing is accurate.
 */
class GrantFolderUsage extends TimedJob {
	public function __construct(
		ITimeFactory                $time,
		private GroupMapper         $groupMapper,
		private GroupMemberMapper   $memberMapper,
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

		$groups = $this->groupMapper->findAll();
		foreach ($groups as $group) {
			if (empty($group->getStorageGrant())) {
				continue;
			}
			$gid     = $group->getGid();
			$members = $this->memberMapper->findByGid($gid, GroupMember::STATUS_ACCEPTED);
			$groupTotal = 0;

			foreach ($members as $member) {
				$uid  = $member->getUid();
				$path = $dataDir . '/' . $uid . '/files/' . GrantFolderManager::GRANT_DIR . '/' . $gid;
				$size = $this->dirSize($path);
				$groupTotal += $size;

				if ($accounting !== null) {
					try {
						$accounting->updateMemberUsage($gid, $uid, $size);
					} catch (\Throwable $e) {
						$this->logger->warning('user_group_admin: updateMemberUsage failed for ' . $uid . '/' . $gid . ': ' . $e->getMessage());
					}
				}
			}

			if ($accounting !== null && !empty($members)) {
				try {
					$accounting->logGrantUsage($gid, $groupTotal);
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
