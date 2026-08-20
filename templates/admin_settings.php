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

	<h2 class="uga-admin-externals-h"><?php p($l->t('External collaborators')) ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Accounts created through an email invitation. The responsible party is the owner of the group that vouched for the collaborator — removing the collaborator from that group deactivates the account.')) ?>
	</p>
	<?php if (empty($_['externals'])): ?>
		<p><em><?php p($l->t('No external collaborators.')) ?></em></p>
	<?php else: ?>
		<table class="grid uga-admin-externals">
			<thead>
				<tr>
					<th><?php p($l->t('Collaborator')) ?></th>
					<th><?php p($l->t('Email (username)')) ?></th>
					<th><?php p($l->t('Group')) ?></th>
					<th><?php p($l->t('Responsible owner')) ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($_['externals'] as $e): ?>
					<tr>
						<td><?php p($e['name'] !== '' && $e['name'] !== $e['uid'] ? $e['name'] : $e['email']) ?></td>
						<td><?php p($e['email']) ?></td>
						<td><?php p($e['group']) ?></td>
						<td><?php p($e['owner_name'] !== '' ? $e['owner_name'] . ' (' . $e['owner'] . ')' : ($e['owner'] !== '' ? $e['owner'] : $l->t('— none —'))) ?></td>
					</tr>
				<?php endforeach ?>
			</tbody>
		</table>
	<?php endif ?>
</div>

<style>
#user-group-admin-settings .uga-admin-externals-h { margin-top: 2em; }
#user-group-admin-settings .uga-admin-externals { margin-top: .5em; max-width: 900px; }
#user-group-admin-settings .uga-admin-externals th { font-weight: bold; }
#user-group-admin-settings .uga-admin-externals td,
#user-group-admin-settings .uga-admin-externals th { padding: 4px 12px 4px 0; text-align: left; }
</style>
