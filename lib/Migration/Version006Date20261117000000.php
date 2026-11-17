<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops uga_groups.grant_sync_hide: grant folders are always hidden from sync
 * clients, so the per-group setting it stored is gone (1.0.51).
 */
class Version006Date20261117000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('uga_groups')) {
			return null;
		}

		$t = $schema->getTable('uga_groups');
		if (!$t->hasColumn('grant_sync_hide')) {
			return null;
		}
		$t->dropColumn('grant_sync_hide');

		return $schema;
	}
}
