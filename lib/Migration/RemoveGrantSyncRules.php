<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Migration;

use OCA\UserGroupAdmin\Service\IShardingAdapter;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Once, on upgrade: remove the locked ".uga_grants" rules the former group
 * setting "Hide grant folder from sync clients" wrote into members' folder
 * visibility settings. Grant folders are always kept from sync clients now
 * (files_sharding conceals them from WebDAV on the default endpoint;
 * GrantFolderPlugin without it), so the rules only confused the settings page.
 */
class RemoveGrantSyncRules implements IRepairStep {
	public function __construct(
		private IShardingAdapter $sharding,
	) {
	}

	public function getName(): string {
		return 'Remove the obsolete grant-folder rules from folder visibility settings';
	}

	public function run(IOutput $output): void {
		$n = $this->sharding->removeGrantSyncRules();
		$output->info("Removed $n grant-folder visibility rule(s)");
	}
}
