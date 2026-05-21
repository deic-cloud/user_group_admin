<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001Date20260425000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('uga_groups')) {
			$t = $schema->createTable('uga_groups');
			$t->addColumn('gid',             Types::STRING,  ['notnull' => true,  'length' => 64]);
			$t->addColumn('owner',           Types::STRING,  ['notnull' => true,  'length' => 64]);
			$t->addColumn('description',     Types::STRING,  ['notnull' => false, 'length' => 512, 'default' => '']);
			$t->addColumn('private',         Types::BOOLEAN, ['notnull' => true,  'default' => false]);
			$t->addColumn('open',            Types::BOOLEAN, ['notnull' => true,  'default' => false]);
			$t->addColumn('hidden',          Types::BOOLEAN, ['notnull' => true,  'default' => false]);
			$t->addColumn('storage_grant',   Types::STRING,  ['notnull' => false, 'length' => 64, 'default' => '']);
			$t->setPrimaryKey(['gid']);
			$t->addIndex(['owner'],  'uga_groups_owner');
			$t->addIndex(['hidden'], 'uga_groups_hidden');
		}

		if (!$schema->hasTable('uga_group_members')) {
			$t = $schema->createTable('uga_group_members');
			$t->addColumn('id',               Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('gid',              Types::STRING,  ['notnull' => true,  'length' => 64]);
			$t->addColumn('uid',              Types::STRING,  ['notnull' => true,  'length' => 64]);
			$t->addColumn('status',           Types::SMALLINT,['notnull' => true,  'default' => 1]);
			$t->addColumn('accept_token',     Types::STRING,  ['notnull' => false, 'length' => 64, 'default' => '']);
			$t->addColumn('decline_token',    Types::STRING,  ['notnull' => false, 'length' => 64, 'default' => '']);
			$t->addColumn('invitation_email', Types::STRING,  ['notnull' => false, 'length' => 255, 'default' => '']);
			$t->addColumn('storage_used',     Types::BIGINT,  ['notnull' => true,  'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['gid', 'uid'],  'uga_members_gid_uid');
			$t->addIndex(['uid'],               'uga_members_uid');
			$t->addIndex(['gid'],               'uga_members_gid');
			$t->addIndex(['accept_token'],      'uga_members_accept');
			$t->addIndex(['decline_token'],     'uga_members_decline');
		}

		return $schema;
	}
}
