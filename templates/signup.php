<?php /** @var array $_ */ /** @var \OCP\IL10N $l */ ?>
<div class="uga-signup-wrap">
	<h2><?php p($l->t('Sign up as external collaborator')) ?></h2>
	<?php if (!empty($_['owner'])): ?>
		<p><?php p($l->t('You have been invited by %1$s to join the group "%2$s".', [$_['owner'], $_['gid']])) ?></p>
	<?php else: ?>
		<p><?php p($l->t('You have been invited to join the group "%s".', [$_['gid']])) ?></p>
	<?php endif ?>
	<p class="uga-signup-note"><?php p($l->t('The preferred way to obtain an account is to sign in via your home institution (WAYF/eduGAIN). Only create an external account here if that is not possible.')) ?></p>
	<p><?php p($l->t('Your username will be the email address the invitation was sent to: %s', [$_['email']])) ?></p>

	<?php $backUrl = \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('user_group_admin.signup.showForm', ['token' => $_['token']]); ?>
	<?php if (!empty($_['error'])): ?>
		<p class="uga-signup-error"><?php p($_['error']) ?></p>
		<p><a href="<?php p($backUrl) ?>">&larr; <?php p($l->t('Go back and choose how to join (Log in / Sign up)')) ?></a></p>
	<?php endif ?>

	<form method="post" action="" class="uga-signup-form">
		<input type="hidden" name="requesttoken" value="<?php p(\OCP\Util::callRegister()) ?>">
		<input type="hidden" name="token" value="<?php p($_['token']) ?>">

		<div class="uga-signup-field">
			<label for="password"><?php p($l->t('Password')) ?></label>
			<input type="password" id="password" name="password" required autocomplete="new-password"
			       minlength="10" placeholder="<?php p($l->t('At least 10 characters')) ?>"
			       class="uga-signup-input">
		</div>

		<div class="uga-signup-field">
			<label for="displayName"><?php p($l->t('Full name')) ?></label>
			<input type="text" id="displayName" name="displayName" required autocomplete="name"
			       class="uga-signup-input">
		</div>

		<div class="uga-signup-field">
			<label for="address"><?php p($l->t('Postal address')) ?></label>
			<textarea id="address" name="address" rows="3" autocomplete="street-address"
			          class="uga-signup-input"></textarea>
		</div>

		<div class="uga-signup-field">
			<label for="affiliation"><?php p($l->t('Affiliation')) ?></label>
			<textarea id="affiliation" name="affiliation" rows="2" autocomplete="organization"
			          class="uga-signup-input"></textarea>
		</div>

		<button type="submit" class="button-vue button-vue--vue-primary uga-signup-submit">
			<?php p($l->t('Proceed')) ?>
		</button>
	</form>
	<p class="uga-signup-back"><a href="<?php p($backUrl) ?>">&larr; <?php p($l->t('Back')) ?></a></p>
</div>

<style>
.uga-signup-wrap { max-width: 400px; margin: 0 auto; padding: 2em 1em; }
.uga-signup-wrap h2 { font-size: 1.5em; margin-bottom: 0.5em; }
.uga-signup-wrap p { margin: 0.5em 0; }
.uga-signup-note { font-size: .9em; color: var(--color-text-maxcontrast); }
.uga-signup-error { color: var(--color-error-text, var(--color-error)); margin: 1em 0; font-weight: bold; }
.uga-signup-back { margin-top: 1.5em; font-size: .9em; }
.uga-signup-field { margin-top: 1em; }
.uga-signup-field label { display: block; font-weight: bold; margin-bottom: 0.25em; }
.uga-signup-input {
	display: block; width: 100%; padding: 0.5em;
	border: 2px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background); color: var(--color-main-text);
	box-sizing: border-box; font-size: 1em; font-family: inherit;
}
.uga-signup-input:focus { border-color: var(--color-primary-element); outline: none; }
.uga-signup-submit { margin-top: 1.5em; width: 100%; justify-content: center; }
</style>
