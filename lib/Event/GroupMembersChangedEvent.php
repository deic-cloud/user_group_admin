<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched whenever the ACCEPTED membership of a group changes (member accepted,
 * removed, or a change applied from another silo). Because user_group_admin ships
 * its own group backend, core's UserAddedEvent/UserRemovedEvent do NOT fire for
 * these groups (the backend already reports the user in-group, so core's addUser
 * short-circuits) — so this is the reliable membership-change signal. files_sharding
 * listens to it to reconcile cross-silo group-share fan-out; other apps may too.
 */
class GroupMembersChangedEvent extends Event {
	public function __construct(
		private string $gid,
	) {
		parent::__construct();
	}

	public function getGid(): string {
		return $this->gid;
	}
}
