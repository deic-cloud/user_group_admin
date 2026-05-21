<?php

declare(strict_types=1);

namespace OCA\UserGroupAdmin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Group> */
class GroupMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'uga_groups', Group::class);
	}

	/** @throws DoesNotExistException */
	public function findByGid(string $gid): Group {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
		   ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		return $this->findEntity($qb);
	}

	/** @return Group[] */
	public function findByOwner(string $owner): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
		   ->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)));
		return $this->findEntities($qb);
	}

	/** @return Group[] groups where the user has a pending invitation */
	public function findPendingInvitationsForUser(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('g.*')
		   ->from($this->getTableName(), 'g')
		   ->innerJoin('g', 'uga_group_members', 'm', $qb->expr()->eq('g.gid', 'm.gid'))
		   ->where($qb->expr()->eq('m.uid', $qb->createNamedParameter($uid)))
		   ->andWhere($qb->expr()->eq('m.status', $qb->createNamedParameter(GroupMember::STATUS_PENDING, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return Group[] groups the user is a member of (via uga_group_members) */
	public function findByMember(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('g.*')
		   ->from($this->getTableName(), 'g')
		   ->innerJoin('g', 'uga_group_members', 'm', $qb->expr()->eq('g.gid', 'm.gid'))
		   ->where($qb->expr()->eq('m.uid', $qb->createNamedParameter($uid)))
		   ->andWhere($qb->expr()->eq('m.status', $qb->createNamedParameter(GroupMember::STATUS_ACCEPTED, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return Group[] visible (non-hidden) groups matching search, excluding $uid's existing memberships */
	public function searchJoinable(string $uid, string $search = '', int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('g.*')
		   ->from($this->getTableName(), 'g')
		   ->leftJoin('g', 'uga_group_members', 'm',
		       $qb->expr()->andX(
		           $qb->expr()->eq('g.gid', 'm.gid'),
		           $qb->expr()->eq('m.uid', $qb->createNamedParameter($uid)),
		       ))
		   ->where($qb->expr()->eq('g.hidden', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
		   ->andWhere($qb->expr()->eq('g.private', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
		   ->andWhere($qb->expr()->isNull('m.uid'))
		   ->andWhere($qb->expr()->neq('g.owner', $qb->createNamedParameter($uid)));
		if ($search !== '') {
			$qb->andWhere($qb->expr()->iLike('g.gid', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%')));
		}
		$qb->setMaxResults($limit)->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/** @return Group[] all groups (for backend getGroups) */
	public function search(string $search = '', int $limit = -1, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
		   ->where($qb->expr()->eq('hidden', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
		if ($search !== '') {
			$qb->andWhere($qb->expr()->iLike('gid', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%')));
		}
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		if ($offset > 0) {
			$qb->setFirstResult($offset);
		}
		return $this->findEntities($qb);
	}

	/** @return Group[] all groups including hidden (for silo sync) */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		return $this->findEntities($qb);
	}

	public function update(Entity $entity): Entity {
		/** @var Group $entity */
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
		   ->set('owner',         $qb->createNamedParameter($entity->getOwner()))
		   ->set('description',   $qb->createNamedParameter($entity->getDescription()))
		   ->set('private',       $qb->createNamedParameter($entity->getPrivate(), IQueryBuilder::PARAM_BOOL))
		   ->set('open',          $qb->createNamedParameter($entity->getOpen(), IQueryBuilder::PARAM_BOOL))
		   ->set('hidden',        $qb->createNamedParameter($entity->getHidden(), IQueryBuilder::PARAM_BOOL))
		   ->set('storage_grant', $qb->createNamedParameter($entity->getStorageGrant()))
		   ->where($qb->expr()->eq('gid', $qb->createNamedParameter($entity->getGid())))
		   ->executeStatement();
		return $entity;
	}

	public function existsByGid(string $gid): bool {
		try {
			$this->findByGid($gid);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	public function deleteByGid(string $gid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
		   ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		$qb->executeStatement();
	}
}
