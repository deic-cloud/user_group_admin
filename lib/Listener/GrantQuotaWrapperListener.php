<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Listener;

use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Storage\GrantQuotaWrapper;
use OC\Files\Filesystem;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeFileSystemSetupEvent;

/** @template-implements IEventListener<BeforeFileSystemSetupEvent> */
class GrantQuotaWrapperListener implements IEventListener {
	public function __construct(private GroupMapper $groupMapper) {}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeFileSystemSetupEvent) {
			return;
		}
		$groupMapper = $this->groupMapper;
		Filesystem::addStorageWrapper(
			'uga_grant_quota',
			static function (string $mountPoint, $storage) use ($groupMapper) {
				$parts = array_values(array_filter(explode('/', $mountPoint)));
				if (count($parts) !== 1) {
					return $storage;
				}
				return new GrantQuotaWrapper(
					['storage' => $storage],
					$parts[0],
					$groupMapper,
				);
			},
			50
		);
	}
}
