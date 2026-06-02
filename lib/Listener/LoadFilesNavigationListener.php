<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\UserGroupAdmin\Service\GrantFolderManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadFilesNavigationListener implements IEventListener {
	public function __construct(
		private IUserSession      $userSession,
		private GrantFolderManager $grantFolderManager,
	) {}

	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$this->grantFolderManager->ensureGrantFolders($user->getUID());
		}
		Util::addInitScript('user_group_admin', 'files-navigation-init');
		Util::addScript('user_group_admin', 'files-navigation', 'files');
	}
}
