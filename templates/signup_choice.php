<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<div class="uga-signup-wrap">
	<h2><?php p($l->t('You have been invited to join the group "%s"', [$_['gid']])) ?></h2>
	<?php if (!empty($_['owner'])): ?>
		<p><?php p($l->t('Invited by %s.', [$_['owner']])) ?></p>
	<?php endif ?>
	<p><?php p($l->t('The invitation was sent to %s.', [$_['email']])) ?></p>

	<div class="uga-choice-actions">
		<a href="<?php p($_['acceptUrl']) ?>" class="button-vue button-vue--vue-primary uga-choice-btn">
			<?php p($l->t('Log in')) ?>
		</a>
		<a href="<?php p($_['createUrl']) ?>" class="button-vue uga-choice-btn">
			<?php p($l->t('Sign up as external collaborator')) ?>
		</a>
	</div>

	<p class="uga-choice-hint">
		<?php p($l->t('If your institution takes part in WAYF/eduGAIN, choose “Log in” and sign in through your home institution — an account is created for you automatically. This is the preferred way and avoids ending up with a second account. Sign up as an external collaborator only if you cannot log in through an institution.')) ?>
	</p>
</div>

<style>
.uga-signup-wrap { max-width: 460px; margin: 0 auto; padding: 2em 1em; }
.uga-signup-wrap h2 { font-size: 1.5em; margin-bottom: 0.5em; }
.uga-signup-wrap p { margin: 0.5em 0; }
.uga-choice-actions { display: flex; flex-direction: column; gap: 12px; margin: 1.5em 0; }
.uga-choice-btn { display: inline-flex; justify-content: center; width: 100%; box-sizing: border-box; }
.uga-choice-hint { font-size: .9em; color: var(--color-text-maxcontrast); margin-top: 1em; }
</style>
