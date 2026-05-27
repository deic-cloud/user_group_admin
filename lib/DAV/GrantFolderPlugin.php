<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\DAV;

use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Service\GrantFolderManager;
use OCP\IRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Sabre plugin registered on the main DAV server that:
 *
 * 1. Blocks manual MKCOL/PUT/COPY of the .uga_grants parent directory itself
 *    (subfolders and files within it are allowed).
 *
 * 2. When files_sharding is NOT installed: enforces sync-hide server-side by
 *    returning 404 to sync clients for any path inside .uga_grants when the
 *    relevant group has grantSyncHide=true.
 */
class GrantFolderPlugin extends ServerPlugin {
	private const SYNC_CLIENT_RE = '/(?:mirall|ownCloudSync)\//i';

	public function __construct(
		private GroupMapper $groupMapper,
		private IRequest    $ncRequest,
		private bool        $filesShardingActive,
	) {}

	public function initialize(Server $server): void {
		$server->on('beforeBind',    [$this, 'beforeBind']);
		$server->on('beforeMethod:*', [$this, 'beforeMethod']);
	}

	/** Block manual creation of .uga_grants itself (sub-paths are allowed). */
	public function beforeBind(string $path): void {
		// path format after base-URI stripping: files/{uid}/.uga_grants
		if (preg_match('#^files/[^/]+/' . preg_quote(GrantFolderManager::GRANT_DIR, '#') . '$#u', $path)) {
			throw new Forbidden('The .uga_grants directory is reserved and cannot be created manually.');
		}
	}

	/**
	 * When files_sharding is absent: block sync clients from accessing
	 * .uga_grants if any of the user's grant groups has grantSyncHide=true.
	 */
	public function beforeMethod(RequestInterface $request, ResponseInterface $response): void {
		if ($this->filesShardingActive) {
			return; // files_sharding handles this
		}

		$path = ltrim($request->getPath(), '/');
		if (!preg_match('#^files/([^/]+)/' . preg_quote(GrantFolderManager::GRANT_DIR, '#') . '(?:/|$)#u', $path, $m)) {
			return;
		}

		$userAgent = $this->ncRequest->getHeader('User-Agent');
		if (!preg_match(self::SYNC_CLIENT_RE, $userAgent)) {
			return;
		}

		$uid = $m[1];
		try {
			$groups = $this->groupMapper->findGrantGroupsForMember($uid);
		} catch (\Throwable) {
			return;
		}

		foreach ($groups as $group) {
			if ($group->getGrantSyncHide()) {
				throw new NotFound('Folder not accessible from sync clients');
			}
		}
	}
}
