<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Service;

use OCA\UserGroupAdmin\Service\IShardingAdapter;
use OCA\UserGroupAdmin\Db\Group;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Propagates group and membership changes to all peer servers.
 * From master: pushes to all registered silos.
 * From a silo: pushes to all registered silos + master (bidirectional sync).
 *
 * HTTP calls go directly to /index.php/apps/user_group_admin/... so there
 * is no dependency on InterServerClient's $app parameter or its OPcache state.
 */
class GroupSyncService {
	private string $secret;
	private bool   $verifySsl;

	public function __construct(
		private IShardingAdapter $shardingService,
		private IClientService  $clientService,
		private IUserManager    $userManager,
		private IConfig         $config,
		private LoggerInterface $logger,
	) {
		$this->secret    = (string)$config->getSystemValue('files_sharding_shared_secret', '');
		$this->verifySsl = (bool)$config->getSystemValue('files_sharding_verify_ssl', true);
	}

	/** Push a full group record + its current member list to all peers. */
	public function pushGroupToAllSilos(Group $group, array $members): void {
		$memberData = array_map(fn (GroupMember $m) => json_encode($m->toArray()), $members);
		$payload    = ['group' => json_encode($group->toArray()), 'members' => json_encode($memberData)];
		foreach ($this->syncTargets() as $url) {
			if (!$this->post($url, 'internal/groups/sync', $payload)) {
				$this->logger->error("user_group_admin: failed to sync group {$group->getGid()} to {$url}");
			}
		}
	}

	/** Tell all peers to delete a group. */
	public function deleteGroupOnAllSilos(string $gid): void {
		foreach ($this->syncTargets() as $url) {
			$this->post($url, 'internal/groups/' . urlencode($gid) . '/delete');
		}
	}

	/** Push a single membership record to all peers. */
	public function pushMemberToAllSilos(GroupMember $member): void {
		$path    = 'internal/groups/' . urlencode($member->getGid()) . '/members/sync';
		$payload = ['member' => json_encode($member->toArray())];
		foreach ($this->syncTargets() as $url) {
			if (!$this->post($url, $path, $payload)) {
				$this->logger->error("user_group_admin: failed to sync member to {$url}");
			}
		}
	}

	/** Tell all peers to remove a member from a group. */
	public function removeMemberOnAllSilos(string $gid, string $uid): void {
		$path = 'internal/groups/' . urlencode($gid) . '/members/' . urlencode($uid) . '/delete';
		foreach ($this->syncTargets() as $url) {
			$this->post($url, $path);
		}
	}

	/**
	 * Search for users across this instance and all peers.
	 * Returns array of ['uid' => string, 'displayName' => string] deduplicated by uid.
	 */
	public function searchUsersAcrossInstances(string $query, int $limit = 20): array {
		$results = [];
		foreach ($this->userManager->search($query, $limit) as $user) {
			$results[$user->getUID()] = ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
		}

		if ($this->shardingService->isMaster()) {
			foreach ($this->shardingService->getAllServers() as $server) {
				$remote = $this->get(
					$this->shardingService->apiUrlForServer($server),
					'internal/users/search',
					['q' => $query],
				);
				foreach ((array)$remote as $u) {
					$uid = $u['uid'] ?? '';
					if ($uid !== '') $results[$uid] ??= $u;
				}
			}
		} else {
			$masterUrl = $this->shardingService->masterInternalUrl();
			if ($masterUrl !== '') {
				$remote = $this->get($masterUrl, 'internal/users/search', ['q' => $query]);
				foreach ((array)$remote as $u) {
					$uid = $u['uid'] ?? '';
					if ($uid !== '') $results[$uid] ??= $u;
				}
			}
		}

		return array_values($results);
	}

	// ── Internal HTTP helpers ─────────────────────────────────────────────────

	/** POST to /index.php/apps/user_group_admin/{path} on $baseUrl. */
	private function post(string $baseUrl, string $path, array $body = []): bool {
		if ($this->secret === '') return false;
		$url = $this->appUrl($baseUrl, $path);
		try {
			$this->clientService->newClient()->post($url, [
				'headers'     => ['Authorization' => 'Bearer ' . $this->secret, 'Accept' => 'application/json'],
				'form_params' => $body,
				'verify'      => $this->verifySsl,
				'timeout'     => 10,
			]);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning("user_group_admin: POST {$url} failed: " . $e->getMessage());
			return false;
		}
	}

	/** GET /index.php/apps/user_group_admin/{path} on $baseUrl, returns decoded JSON or null. */
	private function get(string $baseUrl, string $path, array $query = []): ?array {
		if ($this->secret === '') return null;
		$url = $this->appUrl($baseUrl, $path);
		if ($query) $url .= '?' . http_build_query($query);
		try {
			$response = $this->clientService->newClient()->get($url, [
				'headers' => ['Authorization' => 'Bearer ' . $this->secret, 'Accept' => 'application/json'],
				'verify'  => $this->verifySsl,
				'timeout' => 10,
			]);
			$data = json_decode((string)$response->getBody(), true);
			return is_array($data) ? $data : null;
		} catch (\Throwable $e) {
			$this->logger->warning("user_group_admin: GET {$url} failed: " . $e->getMessage());
			return null;
		}
	}

	private function appUrl(string $baseUrl, string $path): string {
		return rtrim($baseUrl, '/') . '/index.php/apps/user_group_admin/' . ltrim($path, '/');
	}

	// ── Sync target resolution ────────────────────────────────────────────────

	/**
	 * @return string[] base URLs to push changes to
	 */
	private function syncTargets(): array {
		$urls = [];
		foreach ($this->shardingService->getAllServers() as $server) {
			$urls[] = $this->shardingService->apiUrlForServer($server);
		}
		if (!$this->shardingService->isMaster()) {
			$masterUrl = $this->shardingService->masterInternalUrl();
			if ($masterUrl !== '') {
				$urls[] = $masterUrl;
			}
		}
		return array_unique($urls);
	}
}
