<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Settings;

use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCA\UserGroupAdmin\Service\GroupSyncService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	public function __construct(
		private IConfig           $config,
		private GroupMemberMapper $memberMapper,
		private GroupMapper       $groupMapper,
		private GroupSyncService  $syncService,
	) {}

	public function getForm(): TemplateResponse {
		$params = [
			'invitation_subject' => $this->config->getAppValue('user_group_admin', 'invitation_subject', 'Group invitation'),
			'invitation_sender'  => $this->config->getAppValue('user_group_admin', 'invitation_sender', ''),
			'externals'          => $this->externalCollaborators(),
		];
		return new TemplateResponse('user_group_admin', 'admin_settings', $params, '');
	}

	/**
	 * External collaborators and who is responsible for them: each external account's
	 * creating-group membership, with that group's owner as the responsible party.
	 * @return list<array{uid:string,email:string,name:string,group:string,owner:string,owner_name:string}>
	 */
	private function externalCollaborators(): array {
		$out = [];
		foreach ($this->memberMapper->findExternalAccounts() as $m) {
			$gid   = $m->getGid();
			$owner = '';
			try {
				$owner = $this->groupMapper->findByGid($gid)->getOwner();
			} catch (\Throwable) {
			}
			$out[] = [
				'uid'        => $m->getUid(),
				'email'      => $m->getInvitationEmail(),
				'name'       => $this->syncService->resolveDisplayName($m->getUid()),
				'group'      => $gid,
				'owner'      => $owner,
				'owner_name' => $owner !== '' ? $this->syncService->resolveDisplayName($owner) : '',
			];
		}
		return $out;
	}

	public function getSection(): string {
		return 'groupadmin';
	}

	public function getPriority(): int {
		return 50;
	}
}
