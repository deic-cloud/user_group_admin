<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Listener;

use OCA\UserGroupAdmin\Service\GroupService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * No-orphan rule: when a user account is deleted, hand off any groups they own
 * so a live billing relationship is never left ownerless. Groups are reassigned
 * to the institution's domain owner when one can be resolved, otherwise to the
 * HIDDEN_OWNER sentinel (ownerless → unbilled, never deleted).
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class ReassignOwnershipListener implements IEventListener {
	public function __construct(
		private GroupService    $groupService,
		private LoggerInterface $logger,
	) {}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}
		try {
			$this->groupService->reassignOwnedGroupsOnUserDeletion($event->getUser()->getUID());
		} catch (\Throwable $e) {
			$this->logger->warning('user_group_admin: ownership reassignment on user deletion failed: ' . $e->getMessage());
		}
	}
}
