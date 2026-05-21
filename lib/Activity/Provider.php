<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Activity;

use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IL10N;
use OCP\L10N\IFactory;

class Provider implements IProvider {
	public function __construct(private IFactory $l10nFactory) {}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== 'user_group_admin') {
			throw new \InvalidArgumentException('Wrong app');
		}

		$l = $this->l10nFactory->get('user_group_admin', $language);
		$p = $event->getSubjectParameters();

		$event->setParsedSubject(match ($event->getSubject()) {
			'group_created'   => $l->t('You created group "%s"',                    [$p['gid']]),
			'group_deleted'   => $l->t('You deleted group "%s"',                    [$p['gid']]),
			'member_invited'  => $l->t('You invited %s to group "%s"',              [$p['uid'], $p['gid']]),
			'invitation_received' => $l->t('%s invited you to group "%s"',          [$p['inviter'], $p['gid']]),
			'member_joined'   => $l->t('You joined group "%s"',                     [$p['gid']]),
			'member_left'     => $l->t('You left group "%s"',                       [$p['gid']]),
			'member_removed'  => $l->t('You removed %s from group "%s"',            [$p['uid'], $p['gid']]),
			'member_removed_from' => $l->t('You were removed from group "%s"',      [$p['gid']]),
			'join_requested'  => $l->t('You requested to join group "%s"',          [$p['gid']]),
			'join_request_received' => $l->t('%s requested to join your group "%s"', [$p['uid'], $p['gid']]),
			'join_approved'   => $l->t('You approved %s joining group "%s"',        [$p['uid'], $p['gid']]),
			'join_approval_received' => $l->t('Your request to join "%s" was approved', [$p['gid']]),
			default           => throw new \InvalidArgumentException('Unknown subject'),
		});

		$event->setIcon(\OCP\Server::get(\OCP\IURLGenerator::class)->imagePath('user_group_admin', 'nav-icon.svg'));

		return $event;
	}
}
