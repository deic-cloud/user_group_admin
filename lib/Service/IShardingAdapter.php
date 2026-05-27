<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

interface IShardingAdapter {
	public function isMaster(): bool;
	/** @return mixed[] opaque server objects passed back to apiUrlForServer() */
	public function getAllServers(): array;
	public function apiUrlForServer(mixed $server): string;
	public function masterInternalUrl(): string;
	/** Public-facing master URL, or '' if unknown. */
	public function masterUrl(): string;
	/** Return the server object the user is assigned to, or null if none/standalone. */
	public function getUserServer(string $uid): mixed;
	/** Assign a user to a server by ID. No-op in standalone mode. */
	public function setUserServer(string $uid, int $serverId): void;

	/**
	 * Ensure the .uga_grants sync-hide rule exists (hide=true) or is removed
	 * (hide=false) for the given user in the folder-visibility system.
	 */
	public function setGrantSyncHide(string $uid, bool $hide): void;
}
