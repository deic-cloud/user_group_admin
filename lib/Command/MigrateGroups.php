<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Command;

use OCA\UserGroupAdmin\Db\Group;
use OCA\UserGroupAdmin\Db\GroupMapper;
use OCA\UserGroupAdmin\Db\GroupMember;
use OCA\UserGroupAdmin\Db\GroupMemberMapper;
use OCA\UserGroupAdmin\Service\GroupSyncService;
use OCA\UserGroupAdmin\Service\IShardingAdapter;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recreate the groups of a migration list (groups.json from the export of the old
 * service): owner, flags, description, grant size (the old per-member group-folder
 * size) and the members with their status, invitation address and tokens, so
 * pending invitations keep working. The old service used the same status codes.
 * Each group is then pushed to all nodes, as every group change is.
 *
 * Run on the master after the accounts exist. Members and owners without an
 * account are left out (reported); an owner without one leaves the group out.
 * Safe to re-run: an existing group gets the missing members, nothing else.
 */
class MigrateGroups extends Command {
	private const OLD_HIDDEN_OWNER = 'hidden_group_owner';
	private const OLD_EXTERNAL_UID = 'unknown_group_member';

	public function __construct(
		private GroupMapper       $groupMapper,
		private GroupMemberMapper $memberMapper,
		private GroupSyncService  $syncService,
		private IShardingAdapter  $sharding,
		private IUserManager      $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('user_group_admin:migrate:groups')
			->setDescription('Recreate the groups of a migration list (old service) and push them to all nodes')
			->addArgument('file', InputArgument::REQUIRED, 'groups.json from the export')
			->addOption('only', null, InputOption::VALUE_REQUIRED, 'Comma-separated gids: import only these')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be done');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->sharding->isMaster()) {
			$output->writeln('<error>Run this on the master.</error>');
			return 1;
		}
		$list = json_decode((string)file_get_contents((string)$input->getArgument('file')), true);
		if (!is_array($list)) {
			$output->writeln('<error>Cannot read the list.</error>');
			return 1;
		}
		$only = $input->getOption('only') ? array_flip(explode(',', (string)$input->getOption('only'))) : null;
		$dry = (bool)$input->getOption('dry-run');

		$count = ['groups' => 0, 'existing' => 0, 'members' => 0, 'left out' => 0];
		foreach ($list as $entry) {
			$gid = (string)$entry['gid'];
			if ($gid === '' || ($only !== null && !isset($only[$gid]))) {
				continue;
			}
			$hidden = !empty($entry['hidden']);
			$owner = $hidden ? Group::HIDDEN_OWNER : (string)$entry['owner'];
			if (!$hidden && !$this->exists($owner)) {
				$output->writeln("<comment>{$gid}: owner {$owner} has no account - group left out</comment>");
				$count['left out']++;
				continue;
			}

			$isNew = !$this->groupMapper->existsByGid($gid);
			if ($isNew) {
				$group = new Group();
				$group->setGid($gid);
				$group->setOwner($owner);
				$group->setDescription(mb_substr((string)($entry['description'] ?? ''), 0, 512));
				$group->setPrivate(!empty($entry['private']));
				$group->setOpen(!empty($entry['open']));
				$group->setHidden($hidden);
				$group->setStorageGrant((string)($entry['storage_grant'] ?? ''));
				if (!$dry) {
					$this->groupMapper->insert($group);
				}
				$count['groups']++;
			} else {
				$group = $this->groupMapper->findByGid($gid);
				$count['existing']++;
			}

			$members = $entry['members'] ?? [];
			if (!$hidden && !array_filter($members, fn ($m) => $m['uid'] === $owner)) {
				$members[] = ['uid' => $owner, 'status' => GroupMember::STATUS_ACCEPTED];
			}
			$added = 0;
			foreach ($members as $m) {
				$uid = (string)$m['uid'];
				$email = (string)($m['invitation_email'] ?? '');
				if ($uid === self::OLD_EXTERNAL_UID) {
					$uid = GroupMember::EXTERNAL_UID;
				} elseif (!$this->exists($uid)) {
					$output->writeln("{$gid}: member {$uid} has no account - left out", OutputInterface::VERBOSITY_VERBOSE);
					continue;
				}
				if ($this->hasMember($gid, $uid, $email)) {
					continue;
				}
				if (!$dry) {
					$member = new GroupMember();
					$member->setGid($gid);
					$member->setUid($uid);
					$member->setStatus((int)($m['status'] ?? GroupMember::STATUS_ACCEPTED));
					$member->setAcceptToken((string)($m['accept_token'] ?? ''));
					$member->setDeclineToken((string)($m['decline_token'] ?? ''));
					$member->setInvitationEmail($email);
					$this->memberMapper->insert($member);
				}
				$added++;
			}
			$count['members'] += $added;
			if (!$dry && ($isNew || $added > 0)) {
				$this->syncService->pushGroupToAllSilos($group, $this->memberMapper->findByGid($gid));
			}
		}
		$output->writeln(sprintf('%s %d groups (%d already there), %d members; %d groups left out',
			$dry ? 'would create' : 'created', $count['groups'], $count['existing'], $count['members'], $count['left out']));
		return 0;
	}

	/** Strict uid match: get() also finds users by e-mail address. */
	private function exists(string $uid): bool {
		$user = $this->userManager->get($uid);
		return $user !== null && $user->getUID() === $uid;
	}

	/** External invitations share one placeholder uid, so they are told apart by address. */
	private function hasMember(string $gid, string $uid, string $email): bool {
		if ($uid !== GroupMember::EXTERNAL_UID) {
			try {
				$this->memberMapper->findByGidUid($gid, $uid); // any status, not only accepted
				return true;
			} catch (DoesNotExistException) {
				return false;
			} catch (MultipleObjectsReturnedException) {
				return true;
			}
		}
		foreach ($this->memberMapper->findByGid($gid) as $m) {
			if ($m->getUid() === $uid && $m->getInvitationEmail() === $email) {
				return true;
			}
		}
		return false;
	}
}
