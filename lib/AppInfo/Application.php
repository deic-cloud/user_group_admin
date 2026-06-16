<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\UserGroupAdmin\Activity\Provider as ActivityProvider;
use OCA\UserGroupAdmin\BackgroundJob\GrantFolderUsage;
use OCA\UserGroupAdmin\Group\GroupBackend;
use OCA\UserGroupAdmin\Listener\EnsureGrantFoldersListener;
use OCA\UserGroupAdmin\Listener\GrantFolderSabreListener;
use OCA\UserGroupAdmin\Listener\GrantQuotaWrapperListener;
use OCA\UserGroupAdmin\Listener\LoadFilesNavigationListener;
use OCA\UserGroupAdmin\Notification\Notifier;
use OCP\Files\Events\BeforeFileSystemSetupEvent;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;
use OCA\UserGroupAdmin\Service\FilesShardingAdapter;
use OCA\UserGroupAdmin\Service\IShardingAdapter;
use OCA\UserGroupAdmin\Service\StandaloneShardingAdapter;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Activity\IManager as IActivityManager;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'user_group_admin';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesNavigationListener::class);
		$context->registerEventListener(UserLoggedInEvent::class, EnsureGrantFoldersListener::class);
		$context->registerEventListener(UserLoggedInWithCookieEvent::class, EnsureGrantFoldersListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, GrantFolderSabreListener::class);
		$context->registerEventListener(BeforeFileSystemSetupEvent::class, GrantQuotaWrapperListener::class);

		try {
			$context->registerBackgroundJob(GrantFolderUsage::class);
		} catch (\Throwable) {}
		try {
			$context->registerNotifierService(Notifier::class);
		} catch (\Throwable) {}

		$context->registerService(IShardingAdapter::class, function (ContainerInterface $c): IShardingAdapter {
			if ($c->get(IAppManager::class)->isInstalled('files_sharding')) {
				return new FilesShardingAdapter(
					$c->get(\OCA\FilesSharding\Service\ShardingService::class),
					$c->get(\OCA\FilesSharding\Db\DataFolderMapper::class),
				);
			}
			return new StandaloneShardingAdapter();
		});
	}

	public function boot(IBootContext $context): void {
		$container = $context->getServerContainer();

		$container->get(IGroupManager::class)
			->addBackend($container->get(GroupBackend::class));

		try {
			$container->get(IActivityManager::class)
				->registerProvider(ActivityProvider::class);
		} catch (\Throwable) {}

	}
}
