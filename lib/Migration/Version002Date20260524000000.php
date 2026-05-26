<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002Date20260524000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('uga_groups')) {
			$t = $schema->getTable('uga_groups');
			if (!$t->hasColumn('storage_grant')) {
				$t->addColumn('storage_grant', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => '']);
			}
		}

		return $schema;
	}
}
