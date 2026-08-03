<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Listener;

use OCA\UserGroupAdmin\Service\GroupService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;
use Psr\Log\LoggerInterface;

/**
 * On login, ensure the user's home-organisation (domain) group exists as a hidden
 * user_group_admin group and the user is a member — so the domain billing rollup has
 * something to group on. Replaces the old ScienceData user_saml behaviour (which, with
 * user_group_admin enabled, created a hidden domain group per schacHomeOrganization).
 *
 * Domain = the part after '@' in the UID. Our SAML uid_mapping is eppn (user@homeorg,
 * e.g. fror@dtu.dk → dtu.dk), which equals schacHomeOrganization for our setup and was
 * the old code's own fallback — so we avoid plumbing SAML attributes into a listener.
 * Bare UIDs (local accounts like the admin) have no '@' and are skipped.
 *
 * @template-implements IEventListener<UserLoggedInEvent|UserLoggedInWithCookieEvent>
 */
class EnsureDomainGroupListener implements IEventListener {
	public function __construct(
		private GroupService    $groupService,
		private LoggerInterface $logger,
	) {}

	public function handle(Event $event): void {
		if (!($event instanceof UserLoggedInEvent) && !($event instanceof UserLoggedInWithCookieEvent)) {
			return;
		}
		$uid = $event->getUser()->getUID();
		$at  = strpos($uid, '@');
		if ($at === false || $at < 1) {
			return; // bare UID (local account) — no domain group
		}
		$domain = substr($uid, $at + 1);
		// Match the old allowed-group charset; skip anything odd.
		if ($domain === '' || preg_match('/[^a-zA-Z0-9_.\-]/', $domain)) {
			return;
		}
		try {
			$this->groupService->ensureDomainGroupMembership($domain, $uid);
		} catch (\Throwable $e) {
			$this->logger->warning('user_group_admin: domain-group ensure failed for ' . $uid . ': ' . $e->getMessage());
		}
	}
}
