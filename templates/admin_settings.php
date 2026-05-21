<?php /** @var array $_ */ ?>
<div id="user-group-admin-settings">
	<h2><?php p($l->t('Group invitation settings')) ?></h2>
	<form id="uga-admin-form">
		<p>
			<label for="uga-subject"><?php p($l->t('Invitation email subject')) ?></label>
			<input type="text" id="uga-subject" name="invitation_subject"
			       value="<?php p($_['invitation_subject']) ?>">
		</p>
		<p>
			<label for="uga-sender"><?php p($l->t('Invitation sender address (leave blank for default)')) ?></label>
			<input type="text" id="uga-sender" name="invitation_sender"
			       value="<?php p($_['invitation_sender']) ?>">
		</p>
		<input type="submit" value="<?php p($l->t('Save')) ?>">
	</form>
</div>
