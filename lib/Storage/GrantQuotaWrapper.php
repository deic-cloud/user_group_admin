<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Storage;

use OC\Files\Storage\Wrapper\Wrapper;
use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Service\GrantFolderManager;
use OCP\Files\FileInfo;

/**
 * Storage wrapper that gives each grant subfolder its own independent quota
 * and prevents grant storage from consuming the member's personal quota.
 *
 * free_space() is overridden for two scenarios:
 *
 *   1. Path is inside files/.uga_grants/{gid}/
 *      → return grantAllocation - grantUsed  (or SPACE_UNLIMITED if no limit)
 *
 *   2. Any other path inside files/
 *      → delegate to parent (personal Quota wrapper), then add back the total
 *        grant folder size so grant storage does not eat personal quota.
 */
class GrantQuotaWrapper extends Wrapper {
	/** @var array<string, list<\OCA\UserGroupAdmin\Db\Group>> */
	private static array $memberGrantCache = [];

	public function __construct(
		array $parameters,
		private readonly string $uid,
		private readonly GroupMapper $groupMapper,
	) {
		parent::__construct($parameters);
	}

	public function free_space($path): float|int|false {
		$rel    = ltrim((string)$path, '/');
		$prefix = 'files/' . GrantFolderManager::GRANT_DIR;

		// Grant subfolder path → return grant allocation minus used
		if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) {
			$gid = $this->extractGid($rel, $prefix);
			if ($gid !== '') {
				return $this->grantFreeSpace($gid);
			}
			// .uga_grants root itself — unlimited (no uploads land here directly)
			return FileInfo::SPACE_UNLIMITED;
		}

		// Non-grant path: delegate to parent, then compensate so grant storage
		// does not reduce the member's personal free space.
		$free = parent::free_space($path);
		if ($free === FileInfo::SPACE_UNLIMITED || $free === false) {
			return $free;
		}

		// Only compensate for paths inside the user's files tree
		if ($rel === '' || $rel === 'files' || str_starts_with($rel, 'files/')) {
			$grantUsed = $this->totalGrantUsed();
			if ($grantUsed > 0) {
				return max(0.0, (float)$free + (float)$grantUsed);
			}
		}

		return $free;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function extractGid(string $rel, string $prefix): string {
		$after = ltrim(substr($rel, strlen($prefix)), '/');
		return explode('/', $after)[0];
	}

	private function grantFreeSpace(string $gid): float|int {
		$allocation = $this->grantAllocationBytes($gid);
		if ($allocation === 0) {
			return FileInfo::SPACE_UNLIMITED;
		}
		$used = $this->grantUsedBytes($gid);
		return max(0.0, (float)($allocation - $used));
	}

	private function grantAllocationBytes(string $gid): int {
		foreach ($this->loadGrants() as $group) {
			if ($group->getGid() === $gid) {
				return $this->parseBytes($group->getStorageGrant());
			}
		}
		return 0;
	}

	private function grantUsedBytes(string $gid): int {
		$entry = $this->getCache()->get('files/' . GrantFolderManager::GRANT_DIR . '/' . $gid);
		return $entry ? max(0, (int)$entry['size']) : 0;
	}

	private function totalGrantUsed(): int {
		$entry = $this->getCache()->get('files/' . GrantFolderManager::GRANT_DIR);
		return $entry ? max(0, (int)$entry['size']) : 0;
	}

	/** @return list<\OCA\UserGroupAdmin\Db\Group> */
	private function loadGrants(): array {
		if (!array_key_exists($this->uid, self::$memberGrantCache)) {
			try {
				self::$memberGrantCache[$this->uid] =
					$this->groupMapper->findGrantGroupsForMember($this->uid);
			} catch (\Throwable) {
				self::$memberGrantCache[$this->uid] = [];
			}
		}
		return self::$memberGrantCache[$this->uid];
	}

	private function parseBytes(string $quota): int {
		if ($quota === '' || strtolower($quota) === 'none') {
			return 0;
		}
		$value = (float)$quota;
		$unit  = strtoupper(trim(ltrim($quota, '0123456789. ')));
		return (int)match (true) {
			str_starts_with($unit, 'T') => $value * 1024 ** 4,
			str_starts_with($unit, 'G') => $value * 1024 ** 3,
			str_starts_with($unit, 'M') => $value * 1024 ** 2,
			str_starts_with($unit, 'K') => $value * 1024,
			default                     => $value,
		};
	}
}
