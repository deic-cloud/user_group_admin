<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

use OCA\UserGroupAdmin\Db\GroupMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Creates per-member grant folders on the member's own silo.
 *
 * Physical path: {datadirectory}/{memberUid}/user_group_admin/{gid}/
 *
 * The folder lives OUTSIDE the member's files/ tree so it never appears in
 * NC's Files view. Space is accounted to the group owner via files_accounting.
 * Members access the folder exclusively through the custom DAV endpoint at
 * /remote.php/user_group_admin/{gid}/.
 */
class GrantFolderManager {
	public function __construct(
		private GroupMapper     $groupMapper,
		private IConfig         $config,
		private LoggerInterface $logger,
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

		foreach ($groups as $group) {
			$path = $dataDir . '/' . $uid . '/user_group_admin/' . $group->getGid();
			if (!is_dir($path)) {
				if (!mkdir($path, 0750, true) && !is_dir($path)) {
					$this->logger->warning('user_group_admin: could not create grant folder ' . $path);
					continue;
				}
				$this->logger->info('user_group_admin: created grant folder ' . $path);
			}
		}
	}
}
