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

	/** Render the signup form for an accept token. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function showForm(string $token = ''): TemplateResponse {
		$info = $token !== '' ? $this->invitationService->describeInvite($token) : null;
		if ($info === null) {
			return new TemplateResponse('user_group_admin', 'signup_invalid', [], 'guest');
		}
		if ($info['existingUser']) {
			return $this->loginToAccept($token, $info);
		}
		return new TemplateResponse('user_group_admin', 'signup', ['token' => $token], 'guest');
	}

	/** Render the 'log in to accept' page for an invite whose email is an existing user. */
	private function loginToAccept(string $token, array $info, string $error = ''): TemplateResponse {
		// Link straight to the session-authed accept endpoint. If the visitor isn't
		// logged in, NC's auth middleware redirects them to login and back here
		// automatically (a hand-built redirect_url isn't honoured reliably).
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
			$this->invitationService->acceptAsExistingUser($token, $uid);
			return new TemplateResponse('user_group_admin', 'signup_success', [], 'guest');
		} catch (\RuntimeException $e) {
			$info = $this->invitationService->describeInvite($token);
			if ($info !== null) {
				return $this->loginToAccept($token, $info, $e->getMessage());
			}
			return new TemplateResponse('user_group_admin', 'signup_invalid', [], 'guest');
		}
	}

	/** Process the submitted signup form. */
	#[PublicPage]
	public function submitForm(
		string $token       = '',
		string $password    = '',
		string $displayName = '',
	): TemplateResponse|RedirectResponse {
		try {
			$this->invitationService->completeSignup($token, $password, $displayName);
			return new TemplateResponse('user_group_admin', 'signup_success', [], 'guest');
		} catch (\RuntimeException $e) {
			return new TemplateResponse(
				'user_group_admin',
				'signup',
				['token' => $token, 'error' => $e->getMessage()],
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
