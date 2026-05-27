<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version004Date20260527000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('uga_groups')) {
			return null;
		}

		$t = $schema->getTable('uga_groups');
		if (!$t->hasColumn('grant_sync_hide')) {
			$t->addColumn('grant_sync_hide', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
		}

		return $schema;
	}
}
