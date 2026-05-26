<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\UserGroupAdmin\Listener\LoadFilesNavigationListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'user_group_admin';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesNavigationListener::class);
	}

	public function boot(IBootContext $context): void {}
}
