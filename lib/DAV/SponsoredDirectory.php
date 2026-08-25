<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\DAV;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;

/**
 * Read-only Sabre DIRECTORY node for the owner's "Sponsored folders" overview,
 * wrapping an \OCP\Files\Folder (see SponsoredFile).
 */
class SponsoredDirectory implements ICollection {
	public function __construct(
		private Folder $node,
		private string $name,
	) {
	}

	/** Wrap an OCP node in the matching read-only Sabre class. */
	public static function wrap(Node $node, string $name): SponsoredDirectory|SponsoredFile {
		if ($node instanceof File) {
			return new SponsoredFile($node, $name);
		}
		/** @var Folder $node */
		return new self($node, $name);
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

	public function getChildren(): array {
		return array_values(array_map(
			fn (Node $c) => self::wrap($c, $c->getName()),
			$this->node->getDirectoryListing()
		));
	}

	public function getChild($name) {
		try {
			$c = $this->node->get($name);
			return self::wrap($c, $c->getName());
		} catch (\Throwable) {
			throw new NotFound($name);
		}
	}

	public function childExists($name): bool {
		return $this->node->nodeExists($name);
	}

	public function createFile($name, $data = null): never {
		throw new Forbidden('The sponsored-folders overview is read-only');
	}

	public function createDirectory($name): never {
		throw new Forbidden('The sponsored-folders overview is read-only');
	}

	public function delete(): never {
		throw new Forbidden('The sponsored-folders overview is read-only');
	}

	public function setName($name): never {
		throw new Forbidden('The sponsored-folders overview is read-only');
	}
}
