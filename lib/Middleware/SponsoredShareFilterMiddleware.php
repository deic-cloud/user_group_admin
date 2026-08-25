<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Middleware;

use OCA\UserGroupAdmin\Service\GrantFolderManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Http\Response;

/**
 * Hide the sponsored-folder SYSTEM shares (member grant folder → group owner,
 * app-managed, non-unshareable) from the web UI's share listings — both the
 * member's "Shared by me"/sidebar view and the owner's "Shared with you".
 * They are plumbing for the owner's "Sponsored folders" overview, not shares a
 * person made, and an unremovable share row is pure support noise. Registered
 * GLOBALLY so it wraps files_sharing's ShareAPIController.
 *
 * Recognition: the parked mount target (/.uga_sponsored~gid~member) on local
 * shares (both row sides carry it), or — for the member's federated flavour,
 * whose initiator row keeps a plain target — the shared node being a grant
 * folder ROOT (deliberate shares of grant SUBfolders have deeper paths and are
 * untouched).
 */
class SponsoredShareFilterMiddleware extends Middleware {
	public function afterController(Controller $controller, string $methodName, Response $response): Response {
		if (!($controller instanceof \OCA\Files_Sharing\Controller\ShareAPIController)
			|| !($response instanceof DataResponse)
			|| !in_array($methodName, ['getShares', 'getInheritedShares'], true)) {
			return $response;
		}
		$data = $response->getData();
		if (!is_array($data)) {
			return $response;
		}
		$filtered = array_values(array_filter($data, static function ($row): bool {
			if (!is_array($row)) {
				return true;
			}
			$target = (string)($row['file_target'] ?? '');
			if (str_starts_with($target, GrantFolderManager::SPONSORED_PREFIX)) {
				return false;
			}
			$path = (string)($row['path'] ?? '');
			if (preg_match('#^/\.uga_grants/[^/]+$#', $path)
				&& in_array((int)($row['share_type'] ?? -1), [0, 6], true)) {
				return false; // grant-folder ROOT → owner (user or federated flavour)
			}
			return true;
		}));
		if (count($filtered) !== count($data)) {
			$response->setData($filtered);
		}
		return $response;
	}
}
