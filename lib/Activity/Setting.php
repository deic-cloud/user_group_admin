<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * Registers the "group_membership" activity type with the Activity app so events
 * published by GroupService (setType('group_membership')) are stored in the stream
 * and shown to users — and so users/admins can toggle stream/mail/notification for it.
 * The identifier MUST equal the event type used in setType().
 */
class Setting extends ActivitySettings {
	public function __construct(private IL10N $l) {}

	public function getIdentifier(): string {
		return 'group_membership';
	}

	public function getName(): string {
		return $this->l->t('Group memberships, invitations and ownership changes');
	}

	public function getGroupIdentifier(): string {
		return 'other';
	}

	public function getGroupName(): string {
		return $this->l->t('Other activities');
	}

	public function getPriority(): int {
		return 50;
	}

	public function canChangeStream(): bool {
		return true;
	}

	public function isDefaultEnabledStream(): bool {
		return true;
	}

	public function canChangeMail(): bool {
		return true;
	}

	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
