<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Controller;

use OCA\UserGroupAdmin\Service\InvitationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Handles the external collaborator signup flow (public, no session required).
 */
class SignupController extends Controller {
	public function __construct(
		string                    $appName,
		IRequest                  $request,
		private InvitationService $invitationService,
		private IURLGenerator     $urlGenerator,
		private IUserSession      $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Landing page for an accept link: greet the invitee and offer two paths —
	 * "Log in" (existing account, incl. WAYF/eduGAIN auto-created) or
	 * "Sign up as external collaborator". Never funnels anyone straight into
	 * account creation, so people who can log in federated don't end up with
	 * a second account.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function showForm(string $token = ''): TemplateResponse {
		$info = $token !== '' ? $this->invitationService->describeInvite($token) : null;
		if ($info === null) {
			return new TemplateResponse('user_group_admin', 'signup_invalid', [], 'guest');
		}
		return new TemplateResponse('user_group_admin', 'signup_choice', [
			'gid'       => $info['gid'],
			'owner'     => $info['owner'] ?? '',
			'email'     => $info['email'],
			'acceptUrl' => $this->urlGenerator->linkToRouteAbsolute('user_group_admin.signup.acceptAsUser', ['token' => $token]),
			'createUrl' => $this->urlGenerator->linkToRouteAbsolute('user_group_admin.signup.showSignupForm', ['token' => $token]),
		], 'guest');
	}

	/** Render the external-collaborator signup form (the "Sign up" path). */
	#[PublicPage]
	#[NoCSRFRequired]
	public function showSignupForm(string $token = ''): TemplateResponse {
		$info = $token !== '' ? $this->invitationService->describeInvite($token) : null;
		if ($info === null) {
			return new TemplateResponse('user_group_admin', 'signup_invalid', [], 'guest');
		}
		return new TemplateResponse('user_group_admin', 'signup', [
			'token' => $token,
			'email' => $info['email'],
			'gid'   => $info['gid'],
			'owner' => $info['owner'] ?? '',
		], 'guest');
	}

	/** Render the 'log in to accept' page for an invite whose email is an existing user. */
	private function loginToAccept(string $token, array $info, string $error = ''): TemplateResponse {
		// Link straight to the session-authed accept endpoint. If the visitor isn't
		// logged in, acceptAsUser redirects them to the login form with a redirect_url
		// pointing back at itself; that redirect_url is honoured and survives the
		// files_sharding SSO hop to the user's home silo, landing them back on accept.
		$acceptUrl = $this->urlGenerator->linkToRouteAbsolute('user_group_admin.signup.acceptAsUser', ['token' => $token]);
		return new TemplateResponse('user_group_admin', 'signup_login',
			['email' => $info['email'], 'gid' => $info['gid'], 'acceptUrl' => $acceptUrl, 'error' => $error], 'guest');
	}

	/** Accept an email invite as a logged-in existing user (uid swap; no new account). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function acceptAsUser(string $token = ''): TemplateResponse|RedirectResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			$acceptUrl = $this->urlGenerator->linkToRouteAbsolute('user_group_admin.signup.acceptAsUser', ['token' => $token]);
			return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm', ['redirect_url' => $acceptUrl]));
		}
		try {
			$gid = $this->invitationService->acceptAsExistingUser($token, $uid);
			// Send the invitee straight to the group's view in user_group_admin,
			// like the old ownCloud app did.
			$target = $this->urlGenerator->linkToRoute('user_group_admin.page.index')
				. '?group=' . urlencode($gid);
			return new RedirectResponse($target);
		} catch (\RuntimeException $e) {
			$info = $this->invitationService->describeInvite($token);
			if ($info !== null) {
				return $this->loginToAccept($token, $info, $e->getMessage());
			}
			return new TemplateResponse('user_group_admin', 'signup_invalid', [], 'guest');
		}
	}

	/** Process the submitted external-collaborator signup form. */
	#[PublicPage]
	public function submitForm(
		string $token       = '',
		string $password    = '',
		string $displayName = '',
		string $address     = '',
		string $affiliation = '',
	): TemplateResponse|RedirectResponse {
		try {
			$this->invitationService->completeSignup($token, $password, $displayName, $address, $affiliation);
			return new TemplateResponse('user_group_admin', 'signup_success', [], 'guest');
		} catch (\RuntimeException $e) {
			$info = $this->invitationService->describeInvite($token);
			return new TemplateResponse(
				'user_group_admin',
				'signup',
				[
					'token' => $token,
					'email' => $info['email'] ?? '',
					'gid'   => $info['gid'] ?? '',
					'owner' => $info['owner'] ?? '',
					'error' => $e->getMessage(),
				],
				'guest',
			);
		}
	}

	/** Process a decline token. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function decline(string $token = ''): TemplateResponse {
		try {
			$gid = $this->invitationService->declineInvitation($token);
			return new TemplateResponse('user_group_admin', 'signup_declined', ['gid' => $gid], 'guest');
		} catch (\RuntimeException $e) {
			return new TemplateResponse('user_group_admin', 'signup_invalid', [], 'guest');
		}
	}
}
