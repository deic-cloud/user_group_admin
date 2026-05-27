<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\UserGroupAdmin\DAV\GrantFolderPlugin;
use OCA\UserGroupAdmin\Db\GroupMapper;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;

/** @implements IEventListener<SabrePluginAddEvent> */
class GrantFolderSabreListener implements IEventListener {
	public function __construct(
		private GroupMapper $groupMapper,
		private IRequest    $request,
		private IAppManager $appManager,
	) {}

	public function handle(Event $event): void {
		if (!($event instanceof SabrePluginAddEvent)) {
			return;
		}

		$filesShardingActive = $this->appManager->isInstalled('files_sharding');

		$event->getServer()->addPlugin(
			new GrantFolderPlugin($this->groupMapper, $this->request, $filesShardingActive)
		);
	}
}
