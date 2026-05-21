<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

/** Used when files_sharding is not installed: single-instance, no sync. */
class StandaloneShardingAdapter implements IShardingAdapter {
	public function isMaster(): bool                      { return true; }
	public function getAllServers(): array                 { return []; }
	public function apiUrlForServer(mixed $server): string { return ''; }
	public function masterInternalUrl(): string            { return ''; }
	public function masterUrl(): string                    { return ''; }
	public function getUserServer(string $uid): mixed      { return null; }
	public function setUserServer(string $uid, int $serverId): void {}
}
