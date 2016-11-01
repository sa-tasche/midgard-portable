<?php
/**
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace midgard\portable\storage;

use midgard\portable\storage\metadata\entity;
use midgard\portable\api\dbobject;
use midgard\portable\api\repligard;
use midgard\portable\api\error\exception;
use midgard\portable\storage\type\datetime;
use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Events as dbal_events;
use Doctrine\DBAL\Event\SchemaCreateTableEventArgs;
use Doctrine\DBAL\Event\SchemaColumnDefinitionEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;

class subscriber implements EventSubscriber
{
    const ACTION_NONE = 0;
    const ACTION_DELETE = 1;
    const ACTION_PURGE = 2;
    const ACTION_CREATE = 3;
    const ACTION_UPDATE = 4;

    public function onFlush(OnFlushEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity)
        {
            $this->on_create($entity, $em);
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity)
        {
            $this->on_update($entity, $em);
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity)
        {
            $this->on_remove($entity, $em);
        }
    }

    private function on_create(dbobject $entity, EntityManagerInterface $em)
    {
        $cm = $em->getClassMetadata(get_class($entity));
        if (!($entity instanceof repligard))
        {
            if (empty($entity->guid))
            {
                $entity->set_guid(connection::generate_guid());
            }
            $om = new objectmanager($em);
            $repligard_cm = $em->getClassMetadata('midgard:midgard_repligard');
            $repligard_entry = $om->new_instance($repligard_cm->getName());
            $repligard_entry->typename = $cm->getReflectionClass()->getShortName();
            $repligard_entry->guid = $entity->guid;
            $repligard_entry->object_action = self::ACTION_CREATE;
            $em->persist($repligard_entry);
            $em->getUnitOfWork()->computeChangeSet($repligard_cm, $repligard_entry);
        }

        if ($entity instanceof entity)
        {
            $entity->metadata->created = new \midgard_datetime();
            // we copy here instead of creating a new, because otherwise we might have
            // a one second difference if the code runs at the right millisecond
            $entity->metadata->revised = $entity->metadata->created;
            if ($user = connection::get_user())
            {
                $entity->metadata_creator = $user->person;
                $entity->metadata_revisor = $user->person;
            }
            $entity->metadata->size = $this->calculate_size($cm, $entity);
            $em->getUnitOfWork()->recomputeSingleEntityChangeSet($cm, $entity);
        }
    }

    private function on_update(dbobject $entity, EntityManagerInterface $em)
    {
        if ($entity instanceof entity)
        {
            $cm = $em->getClassMetadata(get_class($entity));
            $entity->metadata_revised = new \midgard_datetime();
            $entity->metadata_revision++;
            if ($user = connection::get_user())
            {
                $entity->metadata_revisor = $user->person;
            }
            $entity->metadata->size = $this->calculate_size($cm, $entity);
            $em->getUnitOfWork()->recomputeSingleEntityChangeSet($cm, $entity);
        }

        if (!($entity instanceof repligard))
        {
            $repligard_entry = $em->getRepository('midgard:midgard_repligard')->findOneBy(array('guid' => $entity->guid));

            if (   $entity instanceof entity
                && $entity->metadata->deleted)
            {
                $repligard_entry->object_action = self::ACTION_DELETE;
            }
            else
            {
                $repligard_entry->object_action = self::ACTION_UPDATE;
            }
            $em->persist($repligard_entry);
            $em->getUnitOfWork()->computeChangeSet($em->getClassMetadata('midgard:midgard_repligard'), $repligard_entry);
        }
    }

    private function on_remove(dbobject $entity, EntityManagerInterface $em)
    {
        if (!($entity instanceof repligard))
        {
            $repligard_entry = $em->getRepository('midgard:midgard_repligard')->findOneBy(array('guid' => $entity->guid));
            $repligard_entry->object_action = self::ACTION_PURGE;
            $em->persist($repligard_entry);
            $em->getUnitOfWork()->computeChangeSet($em->getClassMetadata('midgard:midgard_repligard'), $repligard_entry);
        }
    }

    private function calculate_size(ClassMetadata $cm, entity $entity)
    {
        $size = 0;
        foreach ($cm->getAssociationNames() as $name)
        {
            $size += strlen($entity->$name);
        }
        foreach ($cm->getFieldNames() as $name)
        {
            $size += strlen($entity->$name);
        }
        return $size;
    }

    public function onSchemaCreateTable(SchemaCreateTableEventArgs $args)
    {
        $platform = $args->getPlatform();
        $columns = $args->getColumns();
        $modified = false;

        foreach ($columns as $name => &$config)
        {
            if ($platform->getName() === 'sqlite')
            {
                if (   !empty($config['primary'])
                    && !empty($config['autoincrement']))
                {
                    /*
                     * This is essentially a workaround for http://www.doctrine-project.org/jira/browse/DBAL-642
                     * It makes sure we get auto increment behavior similar to msyql (i.e. IDs unique during table's lifetime)
                     */
                    $modified = true;
                    $config['columnDefinition'] = 'INTEGER PRIMARY KEY AUTOINCREMENT';
                }
                if (   !empty($config['comment'])
                    && $config['comment'] == 'BINARY')
                {
                    $modified = true;
                    $config['columnDefinition'] = $config['type']->getSQLDeclaration($config, $platform) . ' COLLATE BINARY' . $platform->getDefaultValueDeclarationSQL($config);
                }
            }
            if ($platform->getName() === 'mysql')
            {
                if (!empty($config['comment']))
                {
                    if ($config['comment'] == 'BINARY')
                    {
                        $modified = true;
                        $config['columnDefinition'] = $config['type']->getSQLDeclaration($config, $platform) . ' CHARACTER SET utf8 COLLATE utf8_bin' . $platform->getDefaultValueDeclarationSQL($config);
                    }
                    if (substr(strtolower(trim($config['comment'])), 0, 3) == 'set')
                    {
                        $modified = true;
                        $config['columnDefinition'] = $config['comment'] . $platform->getDefaultValueDeclarationSQL($config);
                    }
                }
            }
        }

        if (!$modified)
        {
            return;
        }

        $args->preventDefault();

        //The following is basically copied from the respective Doctrine function, since there seems to be no way
        //to just modify columns and pass them back to the SchemaManager
        $table = $args->getTable();
        $options = $args->getOptions();

        $queryFields = $platform->getColumnDeclarationListSQL($columns);

        if (!empty($options['uniqueConstraints']))
        {
            foreach ($options['uniqueConstraints'] as $name => $definition)
            {
                $queryFields .= ', ' . $platform->getUniqueConstraintDeclarationSQL($name, $definition);
            }
        }

        if (!empty($options['foreignKeys']))
        {
            foreach ($options['foreignKeys'] as $foreignKey)
            {
                $queryFields .= ', ' . $platform->getForeignKeyDeclarationSQL($foreignKey);
            }
        }

        $name = str_replace('.', '__', $table->getName());
        $args->addSql('CREATE TABLE ' . $name . ' (' . $queryFields . ')');

        if (isset($options['alter']) && true === $options['alter'])
        {
            return;
        }

        if (!empty($options['indexes']))
        {
            foreach ($options['indexes'] as $indexDef)
            {
                $args->addSql($platform->getCreateIndexSQL($indexDef, $name));
            }
        }

        if (!empty($options['unique']))
        {
            foreach ($options['unique'] as $indexDef)
            {
                $args->addSql($platform->getCreateIndexSQL($indexDef, $name));
            }
        }
    }

    /**
     * This function contains workarounds for reading existing Midgard databases
     *
     * ENUM fields are converted to string for now (Like in the XML reader)
     * DATETIME files use our custom datetime type
     */
    public function onSchemaColumnDefinition(SchemaColumnDefinitionEventArgs $args)
    {
        $column = array_change_key_case($args->getTableColumn(), CASE_LOWER);
        $type = strtok($column['type'], '()');

        if ($type == 'enum')
        {
            $args->preventDefault();

            $options = array
            (
                'length' => 255,
                'default' => isset($column['default']) ? $column['default'] : null,
                'notnull' => (bool) ($column['null'] != 'YES'),
                'comment' => $column['type']
            );

            $args->setColumn(new Column($column['field'], Type::getType(Type::STRING), $options));
        }
        else if ($type == 'datetime')
        {
            $args->preventDefault();
            $options = array
            (
                'default' => isset($column['default']) ? $column['default'] : null,
                'notnull' => (bool) ($column['null'] != 'YES'),
            );

            $args->setColumn(new Column($column['field'], Type::getType(datetime::TYPE), $options));
        }
    }

    /**
     * This is mostly a workaround for the fact that SchemaTool wants to create FKs on
     * each run since it doesn't detect that MyISAM tables don't support them
     *
     * @see http://www.doctrine-project.org/jira/browse/DDC-3460
     * @param GenerateSchemaTableEventArgs $args
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args)
    {
        $table = $args->getClassTable();
        if (   !$table->hasOption('engine')
            || $table->getOption('engine') !== 'MyISAM')
        {
            return;
        }
        foreach ($table->getForeignKeys() as $key)
        {
            $table->removeForeignKey($key->getName());
        }
    }

    public function getSubscribedEvents()
    {
        return array
        (
            Events::onFlush,
            dbal_events::onSchemaCreateTable, dbal_events::onSchemaColumnDefinition,
            ToolEvents::postGenerateSchemaTable
        );
    }
}