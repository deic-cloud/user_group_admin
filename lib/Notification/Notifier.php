<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private IFactory      $l10nFactory,
	) {}

	public function getID(): string   { return 'user_group_admin'; }
	public function getName(): string { return 'Groups'; }

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'user_group_admin') {
			throw new \InvalidArgumentException('Wrong app');
		}

		$l = $this->l10nFactory->get('user_group_admin', $languageCode);

		if ($notification->getSubject() === 'group_invitation') {
			$p   = $notification->getSubjectParameters();
			$gid = $p['gid'];
			$uid = $notification->getUser();

			$notification->setParsedSubject($l->t('You have been invited to group "%s"', [$gid]));
			$notification->setParsedMessage($l->t('Invited by %s', [$p['inviter']]));

			$base = '/ocs/v2.php/apps/user_group_admin/api/v1/groups/'
				. urlencode($gid) . '/members/' . urlencode($uid);

			$accept = $notification->createAction();
			$accept->setLabel('accept')
				->setParsedLabel($l->t('Accept'))
				->setLink($this->urlGenerator->getAbsoluteURL($base), 'PUT')
				->setPrimary(true);
			$notification->addParsedAction($accept);

			$decline = $notification->createAction();
			$decline->setLabel('decline')
				->setParsedLabel($l->t('Decline'))
				->setLink($this->urlGenerator->getAbsoluteURL($base), 'DELETE')
				->setPrimary(false);
			$notification->addParsedAction($decline);

			return $notification;
		}

		if ($notification->getSubject() === 'join_request') {
			$p         = $notification->getSubjectParameters();
			$gid       = $p['gid'];
			$requester = $p['requester'];

			$notification->setParsedSubject($l->t('"%s" wants to join group "%s"', [$requester, $gid]));

			$base = '/ocs/v2.php/apps/user_group_admin/api/v1/groups/'
				. urlencode($gid) . '/members/' . urlencode($requester);

			$approve = $notification->createAction();
			$approve->setLabel('approve')
				->setParsedLabel($l->t('Approve'))
				->setLink($this->urlGenerator->getAbsoluteURL($base), 'PUT')
				->setPrimary(true);
			$notification->addParsedAction($approve);

			$decline = $notification->createAction();
			$decline->setLabel('decline')
				->setParsedLabel($l->t('Decline'))
				->setLink($this->urlGenerator->getAbsoluteURL($base), 'DELETE')
				->setPrimary(false);
			$notification->addParsedAction($decline);

			return $notification;
		}

		throw new \InvalidArgumentException('Unknown subject');
	}
}
