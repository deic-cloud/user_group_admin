<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<div class="uga-signup-wrap">
	<h2><?php p($l->t('Accept group invitation')) ?></h2>
	<p><?php p($l->t('You have been invited to join the group "%s".', [$_['gid']])) ?></p>
	<?php if (!empty($_['owner'])): ?>
		<p><?php p($l->t('Invited by %s.', [$_['owner']])) ?></p>
	<?php endif ?>
	<p><?php p($l->t('The invitation was sent to %1$s. You are logged in as %2$s.', [$_['email'], $_['account']])) ?></p>

	<form method="post" action="<?php p($_['joinUrl']) ?>">
		<input type="hidden" name="token" value="<?php p($_['token']) ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']) ?>">
		<button type="submit" class="button-vue button-vue--vue-primary uga-signup-submit">
			<?php p($l->t('Join "%s" as %s', [$_['gid'], $_['account']])) ?>
		</button>
	</form>
	<p class="uga-signup-hint"><?php p($l->t('If this is not you, log out and log in with the right account, or sign up with the link in the invitation.')) ?></p>
</div>

<style>
.uga-signup-wrap { max-width: 440px; margin: 0 auto; padding: 2em 1em; }
.uga-signup-wrap h2 { font-size: 1.5em; margin-bottom: 0.5em; }
.uga-signup-wrap p { margin: 0.5em 0; }
.uga-signup-submit { display: inline-flex; margin-top: 1.5em; justify-content: center; }
.uga-signup-hint { margin-top: 1.5em !important; color: var(--color-text-maxcontrast); font-size: 0.9em; }
</style>
