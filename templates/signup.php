<?php /** @var array $_ */ ?>
<div class="uga-signup-wrap">
	<h2><?php p($l->t('Create your account')) ?></h2>
	<p><?php p($l->t('You have been invited to join a group. Fill in your details to create an account and accept the invitation.')) ?></p>

	<?php if (!empty($_['error'])): ?>
		<p class="uga-signup-error"><?php p($_['error']) ?></p>
	<?php endif ?>

	<form method="post" action="" class="uga-signup-form">
		<input type="hidden" name="requesttoken" value="<?php p(\OCP\Util::callRegister()) ?>">
		<input type="hidden" name="token" value="<?php p($_['token']) ?>">

		<div class="uga-signup-field">
			<label for="displayName"><?php p($l->t('Full name')) ?></label>
			<input type="text" id="displayName" name="displayName" required autocomplete="name"
			       class="uga-signup-input">
		</div>

		<div class="uga-signup-field">
			<label for="password"><?php p($l->t('Password')) ?></label>
			<input type="password" id="password" name="password" required autocomplete="new-password"
			       minlength="10" placeholder="<?php p($l->t('At least 10 characters')) ?>"
			       class="uga-signup-input">
		</div>

		<button type="submit" class="button-vue button-vue--vue-primary uga-signup-submit">
			<?php p($l->t('Create account and join group')) ?>
		</button>
	</form>

	<p class="uga-signup-login-hint">
		<?php p($l->t('Already have an account?')) ?>
		<a href="<?php p(\OCP\Util::linkToRoute('core.login.showLoginForm')) ?>">
			<?php p($l->t('Log in')) ?>
		</a>
	</p>
</div>

<style>
.uga-signup-wrap {
	max-width: 400px;
	margin: 0 auto;
	padding: 2em 1em;
}
.uga-signup-wrap h2 {
	font-size: 1.5em;
	margin-bottom: 0.5em;
}
.uga-signup-error {
	color: var(--color-error);
	margin: 1em 0;
}
.uga-signup-field {
	margin-top: 1em;
}
.uga-signup-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 0.25em;
}
.uga-signup-input {
	display: block;
	width: 100%;
	padding: 0.5em;
	border: 2px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-sizing: border-box;
	font-size: 1em;
}
.uga-signup-input:focus {
	border-color: var(--color-primary-element);
	outline: none;
}
.uga-signup-submit {
	margin-top: 1.5em;
	width: 100%;
	justify-content: center;
}
.uga-signup-login-hint {
	margin-top: 1.5em;
	text-align: center;
	color: var(--color-text-lighter);
}
</style>
