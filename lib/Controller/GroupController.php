<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Controller;

use OCA\UserGroupAdmin\Service\GroupService;
use OCA\UserGroupAdmin\Service\InvitationService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class GroupController extends OCSController {
	public function __construct(
		string              $appName,
		IRequest            $request,
		private GroupService      $groupService,
		private InvitationService $invitationService,
		private IUserSession      $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): string {
		return $this->userSession->getUser()->getUID();
	}

	private function ok(mixed $data = []): DataResponse {
		return new DataResponse($data);
	}

	private function err(string $message, int $code = 400): DataResponse {
		return new DataResponse(['message' => $message], $code);
	}

	// ── Groups ────────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listInvitations(): DataResponse {
		$groups = $this->groupService->listPendingInvitations($this->uid());
		return $this->ok(array_map(fn ($g) => $g->toArray(), $groups));
	}

	#[NoAdminRequired]
	public function listGroups(): DataResponse {
		$groups = $this->groupService->listGroupsForUser($this->uid());
		return $this->ok(array_map(fn ($g) => $g->toArray(), $groups));
	}

	#[NoAdminRequired]
	public function searchJoinable(string $q = ''): DataResponse {
		$groups = $this->groupService->searchJoinable($this->uid(), $q);
		return $this->ok(array_map(fn ($g) => $g->toArray(), $groups));
	}

	#[NoAdminRequired]
	public function searchUsers(string $q = ''): DataResponse {
		if (strlen($q) < 2) return $this->ok([]);
		return $this->ok($this->groupService->searchUsers($q));
	}

	#[NoAdminRequired]
	public function getGroup(string $gid): DataResponse {
		try {
			return $this->ok($this->groupService->getGroup($gid)->toArray());
		} catch (\RuntimeException $e) {
			return $this->err($e->getMessage(), 404);
		}
	}

	#[NoAdminRequired]
	public function createGroup(
		string $gid,
		string $description = '',
		bool   $private     = false,
		bool   $open        = false,
	): DataResponse {
		try {
			$group = $this->groupService->createGroup($this->uid(), $gid, $description, $private, $open);
			return $this->ok($group->toArray());
		} catch (\RuntimeException $e) {
			return $this->err($e->getMessage());
		}
	}

	#[NoAdminRequired]
	public function updateGroup(
		string  $gid,
		?string $description  = null,
		?bool   $private      = null,
		?bool   $open         = null,
		?string $storageGrant = null,
	): DataResponse {
		try {
			$group = $this->groupService->updateGroup($this->uid(), $gid, $description, $private, $open, $storageGrant);
			return $this->ok($group->toArray());
		} catch (\RuntimeException $e) {
			return $this->err($e->getMessage());
		}
	}

	#[NoAdminRequired]
	public function deleteGroup(string $gid): DataResponse {
		try {
			$this->groupService->deleteGroup($this->uid(), $gid);
			return $this->ok();
		} catch (\RuntimeException $e) {
			return $this->err($e->getMessage());
		}
	}

	// ── Members ───────────────────────────────────────────────────────────────

	#[NoAdminRequired]
	public function listMembers(string $gid): DataResponse {
		$members = $this->groupService->listMembers($gid);
		return $this->ok(array_map(fn ($m) => $m->toArray(), $members));
	}

	/** Invite an existing NC user or request to join an open group. */
	#[NoAdminRequired]
	public function inviteOrRequest(string $gid, string $uid): DataResponse {
		try {
			$member = $this->groupService->inviteOrRequest($this->uid(), $gid, $uid);
			return $this->ok($member->toArray());
		} catch (\RuntimeException $e) {
			return $this->err($e->getMessage());
		}
	}

	/** Invite an external email address (owner only). */
	#[NoAdminRequired]
	public function inviteExternal(string $gid, string $email): DataResponse {
		try {
			$member = $this->invitationService->inviteExternal($this->uid(), $gid, $email);
			return $this->ok($member->toArray());
		} catch (\RuntimeException $e) {
			return $this->err($e->getMessage());
		}
	}

	/** Accept an invitation (member) or approve a join request (owner). */
	#[NoAdminRequired]
	public function acceptMembership(string $gid, string $uid): DataResponse {
		try {
			$this->groupService->acceptMembership($this->uid(), $gid, $uid);
			return $this->ok();
		} catch (\RuntimeException $e) {
			return $this->err($e->getMessage());
		}
	}

	#[NoAdminRequired]
	public function removeMember(string $gid, string $uid): DataResponse {
		try {
			$this->groupService->removeMember($this->uid(), $gid, $uid);
			return $this->ok();
		} catch (\RuntimeException $e) {
			return $this->err($e->getMessage());
		}
	}
}
