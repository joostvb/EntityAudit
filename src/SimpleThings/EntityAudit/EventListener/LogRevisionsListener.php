<?php
/*
 * (c) 2011 SimpleThings GmbH
 *
 * @package SimpleThings\EntityAudit
 * @author Benjamin Eberlei <eberlei@simplethings.de>
 * @link http://www.simplethings.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */

namespace SimpleThings\EntityAudit\EventListener;

use SimpleThings\EntityAudit\AuditManager;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\DBAL\Types\Type;

class LogRevisionsListener implements EventSubscriber
{
    /**
     * @var \SimpleThings\EntityAudit\AuditConfiguration
     */
    private $config;

    /**
     * @var \SimpleThings\EntityAudit\Metadata\MetadataFactory
     */
    private $metadataFactory;

    /**
     * @var Doctrine\DBAL\Connection
     */
    private $conn;
    
    /**
     * @var Doctrine\DBAL\Platforms\AbstractPlatform
     */
    private $platform;
    
    /**
     * @var Doctrine\ORM\EntityManager
     */
    private $em;
    
    /**
     * @var array
     */
    private $insertRevisionSQL = array();
    
    /**
     * @var Doctrine\ORM\UnitOfWork
     */
    private $uow;

    /**
     * @var int
     */
    private $revisionId;

    public function __construct(AuditManager $auditManager)
    {
        $this->config = $auditManager->getConfiguration();
        $this->metadataFactory = $auditManager->getMetadataFactory();
    }

    public function getSubscribedEvents()
    {
        return array(Events::preUpdate, Events::onFlush, Events::postPersist, Events::postUpdate);
    }

    // Keep track of changesets so we can use it in the post events
    protected $changeSetIndex = array();

    public function preUpdate(LifecycleEventArgs $eventArgs)
    {
        $entity = $eventArgs->getEntity();

        $class = $this->em->getClassMetadata(get_class($entity));
        if (!$this->metadataFactory->isAudited($class->name)) {
            return;
        }

        $changes = $eventArgs->getEntityChangeSet();

        $id = $class->getIdentifierValues($entity);
        $identifierColumnName = $class->getSingleIdentifierColumnName();
        $classname = $class->getName();

        if (!isset($changeSetIndex[$classname]))
        {
            $changeSetIndex[$classname] = array();
        }

        $this->changeSetIndex[$classname][$id[$identifierColumnName]] = $changes;
    }

    public function postPersist(LifecycleEventArgs $eventArgs)
    {
        // onFlush was executed before, everything already initialized
        $entity = $eventArgs->getEntity();

        $class = $this->em->getClassMetadata(get_class($entity));
        if (!$this->metadataFactory->isAudited($class->name)) {
            return;
        }

        $this->saveRevisionEntityData($class, $this->getOriginalEntityData($entity), 'INS');
    }

    public function postUpdate(LifecycleEventArgs $eventArgs)
    {
        // onFlush was executed before, everything already initialized
        $entity = $eventArgs->getEntity();

        $class = $this->em->getClassMetadata(get_class($entity));
        if (!$this->metadataFactory->isAudited($class->name)) {
            return;
        }

        $entityData = array_merge($this->getOriginalEntityData($entity), $this->uow->getEntityIdentifier($entity));
        $this->saveRevisionEntityData($class, $entityData, 'UPD');
    }

    public function onFlush(OnFlushEventArgs $eventArgs)
    {
        $this->em = $eventArgs->getEntityManager();
        $this->conn = $this->em->getConnection();
        $this->uow = $this->em->getUnitOfWork();
        $this->platform = $this->conn->getDatabasePlatform();
        $this->revisionId = null; // reset revision

        foreach ($this->uow->getScheduledEntityDeletions() AS $entity) {
            $class = $this->em->getClassMetadata(get_class($entity));
            if (!$this->metadataFactory->isAudited($class->name)) {
                continue;
            }
            $entityData = array_merge($this->getOriginalEntityData($entity), $this->uow->getEntityIdentifier($entity));
            $this->saveRevisionEntityData($class, $entityData, 'DEL');
        }
    }
    
    /**
     * get original entity data, including versioned field, if "version" constraint is used
     * 
     * @param mixed $entity
     * @return array
     */
    private function getOriginalEntityData($entity)
    {
        $class = $this->em->getClassMetadata(get_class($entity));
        $data = $this->uow->getOriginalEntityData($entity);
        if( $class->isVersioned ){
            $versionField = $class->versionField;
            $data[$versionField] = $class->reflFields[$versionField]->getValue($entity);
        }
        return $data;
    }

    private function getRevisionId()
    {
        if ($this->revisionId === null) {

            $date = date_create("now");
            $lockedTimestamp = $this->config->getLockedTimestamp();

            if ($lockedTimestamp !== null && get_class($lockedTimestamp) == 'DateTime')
            {
                $date = $lockedTimestamp;
            }

            $date = $date->format($this->platform->getDateTimeFormatString());

            $this->conn->insert($this->config->getRevisionTableName(), array(
                'timestamp'     => $date,
                'username'      => $this->config->getCurrentUsername(),
                $this->config->getRevisionDescriptionFieldName()
                                => $this->config->getCurrentDescription(),
            ));
            if ($this->platform->getName() == 'postgresql'){
                // this assumes that the sequences name is 'revisions_id_seq'
                $this->revisionId = $this->conn->lastInsertId("revisions_id_seq");
            } else {
                $this->revisionId = $this->conn->lastInsertId();
            }
        }
        return $this->revisionId;
    }

    private function getInsertRevisionSQL($class)
    {
        if (!isset($this->insertRevisionSQL[$class->name])) {
            $placeholders = array('?', '?', '?');
            $tableName    = $this->config->getTablePrefix() . $class->table['name'] . $this->config->getTableSuffix();

            $sql = "INSERT INTO " . $tableName . " (" .
                    $this->config->getRevisionFieldName() . ", " . $this->config->getRevisionTypeFieldName() . ", " . $this->config->getRevisionDiffFieldName();

            foreach ($class->fieldNames AS $field) {
                $type = Type::getType($class->fieldMappings[$field]['type']);
                $placeholders[] = (!empty($class->fieldMappings[$field]['requireSQLConversion']))
                    ? $type->convertToDatabaseValueSQL('?', $this->platform)
                    : '?';
                $sql .= ', ' . $class->getQuotedColumnName($field, $this->platform);
            }

            foreach ($class->associationMappings AS $assoc) {
                if ( ($assoc['type'] & ClassMetadata::TO_ONE) > 0 && $assoc['isOwningSide']) {
                    foreach ($assoc['targetToSourceKeyColumns'] as $sourceCol) {
                        $sql .= ', ' . $sourceCol;
                        $placeholders[] = '?';
                    }
                }
            }

            $sql .= ") VALUES (" . implode(", ", $placeholders) . ")";
            $this->insertRevisionSQL[$class->name] = $sql;
        }

        return $this->insertRevisionSQL[$class->name];
    }

    private function changesetToSimpleArray($changeset)
    {
        if (!is_array($changeset))
        {
            return array();
        }

        $res = array();

        foreach (array_keys($changeset) as $key)
        {
            $valueArray = $changeset[$key];

            $old = $valueArray[0];
            $new = $valueArray[1];
            $res[$key] = array($this->changesetItemToString($old), $this->changesetItemToString($new));
        }

        return $res;
    }

    private function changesetItemToString($item)
    {
        $type = gettype($item);
        if ($type != 'object')
        {
            return $item;
        }

        $class = get_class($item);
        if ($class == 'DateTime')
        {
            return $item->format($this->config->getDatetimeToStringFormat());
        }
        else
        {
            return (string)$item;
        }
    }

    /**
     * @param ClassMetadata $class
     * @param array $entityData
     * @param string $revType
     */
    private function saveRevisionEntityData($class, $entityData, $revType)
    {
        $classname = $class->getName();
        $identifierColumnName = $class->getSingleIdentifierColumnName();
        $id = $entityData[$identifierColumnName];

        $changeset = (isset($this->changeSetIndex[$classname]) && isset($this->changeSetIndex[$classname][$id])) ? $this->changeSetIndex[$classname][$id] : null;

        $changeset = $this->changesetToSimpleArray($changeset);

        $diff = $changeset !== null ? serialize($changeset) : null;

        $params = array($this->getRevisionId(), $revType, $diff);
        $types = array(\PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_STR);

        foreach ($class->fieldNames AS $field) {
            $params[] = $entityData[$field];
            $types[] = $class->fieldMappings[$field]['type'];
        }

        foreach ($class->associationMappings AS $field => $assoc) {
            if (($assoc['type'] & ClassMetadata::TO_ONE) > 0 && $assoc['isOwningSide']) {
                $targetClass = $this->em->getClassMetadata($assoc['targetEntity']);

                if ($entityData[$field] !== null) {
                    $relatedId = $this->uow->getEntityIdentifier($entityData[$field]);
                }

                $targetClass = $this->em->getClassMetadata($assoc['targetEntity']);

                foreach ($assoc['sourceToTargetKeyColumns'] as $sourceColumn => $targetColumn) {
                    if ($entityData[$field] === null) {
                        $params[] = null;
                        $types[] = \PDO::PARAM_STR;
                    } else {
                        $params[] = $relatedId[$targetClass->fieldNames[$targetColumn]];
                        $types[] = $targetClass->getTypeOfColumn($targetColumn);
                    }
                }
            }
        }

        $this->conn->executeUpdate($this->getInsertRevisionSQL($class), $params, $types);
    }
}
