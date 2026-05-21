<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	public function __construct(private IConfig $config) {}

	public function getForm(): TemplateResponse {
		$params = [
			'invitation_subject' => $this->config->getAppValue('user_group_admin', 'invitation_subject', 'Group invitation'),
			'invitation_sender'  => $this->config->getAppValue('user_group_admin', 'invitation_sender', ''),
		];
		return new TemplateResponse('user_group_admin', 'admin_settings', $params, '');
	}

	public function getSection(): string {
		return 'groupadmin';
	}

	public function getPriority(): int {
		return 50;
	}
}
