<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds uga_groups.pending_owner — the uid of a proposed new owner awaiting
 * their consent during an ownership transfer. Empty when there is no pending
 * offer. The owner field only changes once the pending owner accepts.
 */
class Version005Date20260804000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('uga_groups')) {
			return null;
		}

		$t = $schema->getTable('uga_groups');
		if (!$t->hasColumn('pending_owner')) {
			$t->addColumn('pending_owner', Types::STRING, [
				'notnull' => false,
				'length'  => 64,
				'default' => '',
			]);
		}

		return $schema;
	}
}
