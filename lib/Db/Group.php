<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getGid()
 * @method void   setGid(string $gid)
 * @method string getOwner()
 * @method void   setOwner(string $owner)
 * @method string getDescription()
 * @method void   setDescription(string $description)
 * @method bool   getPrivate()
 * @method void   setPrivate(bool $private)
 * @method bool   getOpen()
 * @method void   setOpen(bool $open)
 * @method bool   getHidden()
 * @method void   setHidden(bool $hidden)
 * @method string getStorageGrant()
 * @method void   setStorageGrant(string $storageGrant)
 * @method string getStorageGrantTotal()
 * @method void   setStorageGrantTotal(string $storageGrantTotal)
 * @method bool   getGrantSyncHide()
 * @method void   setGrantSyncHide(bool $grantSyncHide)
 */
class Group extends Entity {
	public const HIDDEN_OWNER = 'uga_hidden_owner';

	protected string $gid               = '';
	protected string $owner             = '';
	protected string $description       = '';
	protected bool   $private           = false;
	protected bool   $open              = false;
	protected bool   $hidden            = false;
	protected string $storageGrant      = '';
	protected string $storageGrantTotal = '';
	protected bool   $grantSyncHide     = true;

	public function __construct() {
		$this->addType('private',       'boolean');
		$this->addType('open',          'boolean');
		$this->addType('hidden',        'boolean');
		$this->addType('grantSyncHide', 'boolean');
	}

	public function toArray(): array {
		return [
			'gid'                 => $this->gid,
			'owner'               => $this->owner,
			'description'         => $this->description,
			'private'             => $this->private,
			'open'                => $this->open,
			'hidden'              => $this->hidden,
			'storage_grant'       => $this->storageGrant,
			'storage_grant_total' => $this->storageGrantTotal,
			'grant_sync_hide'     => $this->grantSyncHide,
		];
	}
}
