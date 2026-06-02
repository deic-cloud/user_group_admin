<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

use OCA\FilesSharding\Db\DataFolderMapper;
use OCA\FilesSharding\Service\ShardingService;

/** Thin wrapper around ShardingService when files_sharding is installed. */
class FilesShardingAdapter implements IShardingAdapter {
	private const GRANT_FOLDER = '/.uga_grants';
	private const LOCKED_BY    = 'user_group_admin';

	public function __construct(
		private ShardingService  $service,
		private DataFolderMapper $folderMapper,
	) {}

	public function isMaster(): bool                      { return $this->service->isMaster(); }
	public function getAllServers(): array                 { return $this->service->getAllServers(); }
	public function apiUrlForServer(mixed $server): string { return $this->service->apiUrlForServer($server); }
	public function masterInternalUrl(): string            { return $this->service->masterInternalUrl(); }
	public function masterUrl(): string                    { return $this->service->masterUrl(); }
	public function getUserServer(string $uid): mixed      { return $this->service->getUserServer($uid); }
	public function setUserServer(string $uid, int $serverId): void { $this->service->setUserServer($uid, $serverId); }

	public function setGrantSyncHide(string $uid, bool $hide): void {
		if ($hide) {
			$this->folderMapper->upsertLockedRule($uid, self::GRANT_FOLDER, true, self::LOCKED_BY);
		} else {
			$this->folderMapper->deleteLockedRule($uid, self::GRANT_FOLDER, self::LOCKED_BY);
		}
	}
}
