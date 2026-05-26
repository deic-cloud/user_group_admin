<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\DAV;

/**
 * Sabre FS\Directory subclass that overrides getQuotaInfo() with the configured
 * per-member quota so CorePlugin reports the grant quota instead of raw disk stats.
 */
class GrantDirectory extends \Sabre\DAV\FS\Directory {
	public function __construct(
		string $path,
		private int $perMemberBytes,
	) {
		parent::__construct($path);
	}

	public function getQuotaInfo(): array {
		$used  = $this->calcSize();
		$avail = $this->perMemberBytes > 0
			? max(0, $this->perMemberBytes - $used)
			: -1;
		return [$used, $avail];
	}

	private function calcSize(): int {
		if (!is_dir($this->path)) {
			return 0;
		}
		$size = 0;
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iter as $file) {
			$size += $file->getSize();
		}
		return $size;
	}
}
