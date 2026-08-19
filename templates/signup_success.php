<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<div class="uga-signup-wrap">
	<h2><?php p($l->t('Account created')) ?></h2>
	<p><?php p($l->t('Your account has been created and you have been added to the group.')) ?></p>
	<a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute('core.login.showLoginForm')) ?>" class="button-vue button-vue--vue-primary uga-signup-submit">
		<?php p($l->t('Log in')) ?>
	</a>
</div>

<style>
.uga-signup-wrap { max-width: 400px; margin: 0 auto; padding: 2em 1em; }
.uga-signup-wrap h2 { font-size: 1.5em; margin-bottom: 0.5em; }
.uga-signup-wrap p { margin: 0.5em 0; }
.uga-signup-submit { display: inline-flex; margin-top: 1.5em; justify-content: center; }
</style>
