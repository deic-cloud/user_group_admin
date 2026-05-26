<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\DAV;

use Sabre\DAV\IFile;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Sabre plugin that adds NC-specific DAV properties and enforces quota for grant folders.
 *
 * Sabre\DAV\FS\* nodes do not expose OwnCloud/Nextcloud namespace properties, so
 * resultToNode() on the frontend would see id=0 and no permissions. This plugin
 * fills those gaps and blocks writes that exceed the configured quota limits.
 */
class GrantPropertiesPlugin extends ServerPlugin {
	private const OC = 'http://owncloud.org/ns';
	private const NC = 'http://nextcloud.org/ns';

	/**
	 * @param int    $perMemberBytes  Per-folder quota in bytes; 0 = no limit.
	 * @param int    $totalBytes      Total-group quota in bytes; 0 = no limit.
	 * @param string $memberPath      Absolute path to the member's grant folder (empty = owner view).
	 * @param list<string> $allMemberPaths  All member grant folder paths (for total-cap check).
	 */
	public function __construct(
		private int    $perMemberBytes,
		private int    $totalBytes,
		private string $memberPath,
		private array  $allMemberPaths,
	) {}

	public function initialize(Server $server): void {
		$server->on('propFind',           [$this, 'handlePropFind'], 120);
		$server->on('beforeBind',         [$this, 'checkQuotaBind']);
		$server->on('beforeWriteContent', [$this, 'checkQuotaWrite']);
	}

	public function handlePropFind(PropFind $pf, INode $node): void {
		$path = $pf->getPath();

		$hashSeed = ($path !== '') ? $path : ($this->memberPath ?: 'uga-root');
		$pf->handle('{' . self::OC . '}fileid',
			fn() => (string) sprintf('%u', crc32($hashSeed)));

		$pf->handle('{' . self::OC . '}permissions',
			fn() => $this->memberPath !== '' ? 'RGDNVCK' : 'G');

		$pf->handle('{' . self::OC . '}size',
			fn() => ($node instanceof IFile) ? $node->getSize() : 0);

		$pf->handle('{' . self::NC . '}has-preview', fn() => '0');

		if ($node instanceof IFile) {
			$pf->handle('{DAV:}getcontenttype', fn() => $this->detectMime($path));
		}

		// Quota reporting at the root of a member's folder
		if ($path === '' && $this->memberPath !== '') {
			$pf->handle('{DAV:}quota-used-bytes', function (): int {
				return $this->dirSize($this->memberPath);
			});
			$pf->handle('{DAV:}quota-available-bytes', function (): int {
				if ($this->perMemberBytes <= 0) {
					return -1; // unlimited
				}
				return max(0, $this->perMemberBytes - $this->dirSize($this->memberPath));
			});
		}
	}

	public function checkQuotaBind(string $uri): void {
		$this->enforceQuota();
	}

	/** @param mixed $data passthrough, ignored */
	public function checkQuotaWrite(string $uri, INode $node, mixed &$data = null): void {
		$this->enforceQuota();
	}

	private function enforceQuota(): void {
		if ($this->memberPath === '') {
			return; // owner view — read-only, no quota to enforce
		}

		$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
		$used          = $this->dirSize($this->memberPath);

		if ($this->perMemberBytes > 0 && ($used + $contentLength) > $this->perMemberBytes) {
			throw new \Sabre\DAV\Exception\InsufficientStorage('Per-folder quota exceeded');
		}

		if ($this->totalBytes > 0 && !empty($this->allMemberPaths)) {
			$totalUsed = array_sum(array_map([$this, 'dirSize'], $this->allMemberPaths));
			if (($totalUsed + $contentLength) > $this->totalBytes) {
				throw new \Sabre\DAV\Exception\InsufficientStorage('Group total quota exceeded');
			}
		}
	}

	private function detectMime(string $relPath): string {
		// Try to resolve the full filesystem path so mime_content_type() can read the file.
		$fullPath = '';
		if ($this->memberPath !== '') {
			$fullPath = rtrim($this->memberPath, '/') . '/' . ltrim($relPath, '/');
		} else {
			// Owner view: relPath is "{memberUid}/{file}", allMemberPaths are "{dataDir}/{uid}/user_group_admin/{gid}"
			$parts = explode('/', ltrim($relPath, '/'), 2);
			$uid   = $parts[0];
			$rest  = $parts[1] ?? '';
			foreach ($this->allMemberPaths as $mp) {
				if (basename(dirname(dirname($mp))) === $uid) {
					$fullPath = $mp . '/' . $rest;
					break;
				}
			}
		}
		if ($fullPath !== '' && is_file($fullPath)) {
			$type = @mime_content_type($fullPath);
			if ($type !== false && $type !== '') {
				return $type;
			}
		}
		return 'application/octet-stream';
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
