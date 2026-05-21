<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\AppInfo;

use OCA\UserGroupAdmin\Activity\Provider as ActivityProvider;
use OCA\UserGroupAdmin\Group\GroupBackend;
use OCA\UserGroupAdmin\Notification\Notifier;
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
		$context->registerNotifierService(Notifier::class);

		$context->registerService(IShardingAdapter::class, function (ContainerInterface $c): IShardingAdapter {
			if ($c->get(IAppManager::class)->isInstalled('files_sharding')) {
				return new FilesShardingAdapter(
					$c->get(\OCA\FilesSharding\Service\ShardingService::class)
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
