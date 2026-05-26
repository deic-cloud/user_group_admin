<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\DAV;

/**
 * Sabre FS Directory with an overridden display name.
 *
 * Sabre\DAV\FS\Directory::getName() returns basename($path), which for a path like
 * /var/www/data/alice/user_group_admin/mygroup returns "mygroup" rather than "alice".
 * This subclass lets the owner view present member UIDs as folder names.
 */
class NamedDirectory extends \Sabre\DAV\FS\Directory {
	public function __construct(
		private string $displayName,
		string         $path,
	) {
		parent::__construct($path);
	}

	public function getName(): string {
		return $this->displayName;
	}
}
