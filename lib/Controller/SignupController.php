<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Controller;

use OCA\UserGroupAdmin\Service\InvitationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Handles the external collaborator signup flow (public, no session required).
 */
class SignupController extends Controller {
	public function __construct(
		string                    $appName,
		IRequest                  $request,
		private InvitationService $invitationService,
		private IURLGenerator     $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	/** Render the signup form for an accept token. */
	#[PublicPage]
	#[NoCSRFRequired]
	public function showForm(string $token = ''): TemplateResponse {
		if ($token === '') {
			return new TemplateResponse('user_group_admin', 'signup_invalid', [], 'guest');
		}
		return new TemplateResponse('user_group_admin', 'signup', ['token' => $token], 'guest');
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
