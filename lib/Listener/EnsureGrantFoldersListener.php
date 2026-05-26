<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Listener;

use OCA\UserGroupAdmin\Service\GrantFolderManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;

/**
 * @template-implements IEventListener<UserLoggedInEvent|UserLoggedInWithCookieEvent>
 */
class EnsureGrantFoldersListener implements IEventListener {
	public function __construct(
		private GrantFolderManager $grantFolderManager,
	) {}

	public function handle(Event $event): void {
		if ($event instanceof UserLoggedInEvent) {
			$this->grantFolderManager->ensureGrantFolders($event->getUser()->getUID());
		} elseif ($event instanceof UserLoggedInWithCookieEvent) {
			$this->grantFolderManager->ensureGrantFolders($event->getUser()->getUID());
		}
	}
}
