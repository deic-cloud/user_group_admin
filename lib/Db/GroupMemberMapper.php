<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<GroupMember> */
class GroupMemberMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'uga_group_members', GroupMember::class);
	}

	/** @throws DoesNotExistException */
	public function findByGidUid(string $gid, string $uid): GroupMember {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
		   ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
		   ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findByAcceptToken(string $token): GroupMember {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
		   ->where($qb->expr()->eq('accept_token', $qb->createNamedParameter($token)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findByDeclineToken(string $token): GroupMember {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
		   ->where($qb->expr()->eq('decline_token', $qb->createNamedParameter($token)));
		return $this->findEntity($qb);
	}

	/** @return GroupMember[] */
	public function findByGid(string $gid, ?int $status = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
		   ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	/** @return GroupMember[] accepted memberships for $uid */
	public function findByUid(string $uid, bool $acceptedOnly = true): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
		   ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		if ($acceptedOnly) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(GroupMember::STATUS_ACCEPTED, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	public function isMember(string $gid, string $uid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
		   ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
		   ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
		   ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(GroupMember::STATUS_ACCEPTED, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb) !== [];
	}

	public function deleteByGid(string $gid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
		   ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$qb->executeStatement();
	}

	public function deleteByGidUid(string $gid, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
		   ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
		   ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}

	/** @return GroupMember[] all members across all groups (for silo sync) */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		return $this->findEntities($qb);
	}
}
