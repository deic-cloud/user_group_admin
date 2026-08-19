<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<div class="uga-signup-wrap">
	<h2><?php p($l->t('Accept group invitation')) ?></h2>
	<p><?php p($l->t('You have been invited to join the group "%s".', [$_['gid']])) ?></p>
	<p><?php p($l->t('Your email address (%s) already has an account here — log in to accept. No new account is created.', [$_['email']])) ?></p>

	<?php if (!empty($_['error'])): ?>
		<p class="uga-signup-error"><?php p($_['error']) ?></p>
	<?php endif ?>

	<a href="<?php p($_['acceptUrl']) ?>" class="button-vue button-vue--vue-primary uga-signup-submit">
		<?php p($l->t('Log in to accept')) ?>
	</a>
</div>

<style>
.uga-signup-wrap { max-width: 400px; margin: 0 auto; padding: 2em 1em; }
.uga-signup-wrap h2 { font-size: 1.5em; margin-bottom: 0.5em; }
.uga-signup-wrap p { margin: 0.5em 0; }
.uga-signup-error { color: var(--color-error); margin: 1em 0; }
.uga-signup-submit { display: inline-flex; margin-top: 1.5em; justify-content: center; }
</style>
