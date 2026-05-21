<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int    getId()
 * @method string getGid()
 * @method void   setGid(string $gid)
 * @method string getUid()
 * @method void   setUid(string $uid)
 * @method int    getStatus()
 * @method void   setStatus(int $status)
 * @method string getAcceptToken()
 * @method void   setAcceptToken(string $token)
 * @method string getDeclineToken()
 * @method void   setDeclineToken(string $token)
 * @method string getInvitationEmail()
 * @method void   setInvitationEmail(string $email)
 * @method int    getStorageUsed()
 * @method void   setStorageUsed(int $bytes)
 */
class GroupMember extends Entity {
	/** Owner has invited; waiting for member to accept. */
	public const STATUS_PENDING  = -1;
	/** Member has requested to join; waiting for owner to approve. */
	public const STATUS_OPEN     = 0;
	/** Active member. */
	public const STATUS_ACCEPTED = 1;
	/** Declined (invitation or request). */
	public const STATUS_DECLINED = 2;

	/** Placeholder uid for external invitations before account is created. */
	public const EXTERNAL_UID = 'uga_external';

	protected string $gid             = '';
	protected string $uid             = '';
	protected int    $status          = self::STATUS_ACCEPTED;
	protected string $acceptToken     = '';
	protected string $declineToken    = '';
	protected string $invitationEmail = '';
	protected int    $storageUsed     = 0;

	public function __construct() {
		$this->addType('status',      'integer');
		$this->addType('storageUsed', 'integer');
	}

	public function toArray(): array {
		return [
			'id'               => $this->getId(),
			'gid'              => $this->gid,
			'uid'              => $this->uid,
			'status'           => $this->status,
			'invitation_email' => $this->invitationEmail,
			'storage_used'     => $this->storageUsed,
		];
	}
}
