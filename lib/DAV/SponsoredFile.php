<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\DAV;

use OCP\Files\File;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\IFile;

/**
 * Read-only Sabre FILE node for the owner's "Sponsored folders" overview,
 * wrapping an \OCP\Files\File (typically reached through the parked
 * member→owner system-share mount, so it works cross-silo). Files and
 * directories are separate classes: Sabre decides resourcetype by
 * `instanceof ICollection`.
 */
class SponsoredFile implements IFile {
	public function __construct(
		private File   $node,
		private string $name,
	) {
	}

	public function getName(): string {
		return $this->name;
	}

	public function getLastModified(): int {
		return $this->node->getMTime();
	}

	public function getETag(): string {
		return '"' . $this->node->getEtag() . '"';
	}

	public function get() {
		return $this->node->fopen('r');
	}

	public function getSize(): int {
		return $this->node->getSize();
	}

	public function getContentType(): string {
		return $this->node->getMimetype();
	}

	public function put($data): never {
		throw new Forbidden('The sponsored-folders overview is read-only');
	}

	public function delete(): never {
		throw new Forbidden('The sponsored-folders overview is read-only');
	}

	public function setName($name): never {
		throw new Forbidden('The sponsored-folders overview is read-only');
	}
}
