<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

use OCA\FilesSharding\Service\ShardingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Thin wrapper around ShardingService when files_sharding is installed. */
class FilesShardingAdapter implements IShardingAdapter {
	private const GRANT_FOLDER = '/.uga_grants';
	// Grant parent name during a short-lived rename (ce3b53c, reverted); rules
	// written under it are dead and are removed on every provisioning pass.
	private const LEGACY_GRANT_FOLDER = '/Grants';
	private const LOCKED_BY    = 'user_group_admin';

	public function __construct(
		private ShardingService  $service,
		private IDBConnection $db,
	) {}

	public function isMaster(): bool                      { return $this->service->isMaster(); }
	public function getAllServers(): array                 { return $this->service->getAllServers(); }
	public function apiUrlForServer(mixed $server): string { return $this->service->apiUrlForServer($server); }
	public function isSelf(mixed $server): bool            { return $this->service->isSelf($server); }
	public function masterInternalUrl(): string            { return $this->service->masterInternalUrl(); }
	public function masterUrl(): string                    { return $this->service->masterUrl(); }
	public function getUserServer(string $uid): mixed      { return $this->service->getUserServer($uid); }
	public function setUserServer(string $uid, int $serverId): void { $this->service->setUserServer($uid, $serverId); }

	public function removeGrantSyncRules(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('files_sharding_folders')
			->where($qb->expr()->eq('locked_by', $qb->createNamedParameter(self::LOCKED_BY)))
			->andWhere($qb->expr()->in('folder', $qb->createNamedParameter(
				[self::GRANT_FOLDER, self::LEGACY_GRANT_FOLDER], IQueryBuilder::PARAM_STR_ARRAY)));
		return $qb->executeStatement();
	}
}
