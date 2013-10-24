<?php
/**
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

use midgard\portable\storage\connection;
use midgard\portable\mgdschema\translator;

class midgard_reflection_property
{
    /**
     *
     * @var Doctrine\Common\Persistence\Mapping\ClassMetadata
     */
    private $cm;

    public function __construct($mgdschema_class)
    {
        $cmf = connection::get_em()->getMetadataFactory();
        if (!$cmf->hasMetadataFor($mgdschema_class))
        {
            $mgdschema_class = 'midgard:' . $mgdschema_class;
        }
        $this->cm = $cmf->getMetadataFor($mgdschema_class);
    }

    public function description($property)
    {
        throw new \exception('not implemented yet');
    }

    public function get_mapping($property)
    {
        if (!$this->cm->hasField($property))
        {
            return null;
        }
        return $this->cm->getFieldMapping($property);
    }

    public function is_link($property)
    {
        if ($this->cm->hasAssociation($property))
        {
            return true;
        }
        return $this->is_special_link($property);
    }

    public function is_special_link($property)
    {
        $mapping = $this->get_mapping($property);
        if ($this->cm->hasAssociation($property) || is_null($mapping))
        {
            return false;
        }
        return isset($mapping["noidlink"]);
    }

    public function get_link_name($property)
    {
        if ($this->cm->hasAssociation($property))
        {
            $mapping = $this->cm->getAssociationMapping($property);
            return $mapping['midgard:link_name'];
        }
        $mapping = $this->get_mapping($property);
        if (is_null($mapping))
        {
            return null;
        }
        if (isset($mapping["noidlink"]["target"]))
        {
            return $mapping["noidlink"]["target"];
        }
        return null;
    }

    public function get_link_target($property)
    {
        if ($this->cm->hasAssociation($property))
        {
            $mapping = $this->cm->getAssociationMapping($property);
            return $mapping['midgard:link_target'];
        }
        $mapping = $this->get_mapping($property);
        if (is_null($mapping))
        {
            return null;
        }
        if (isset($mapping["noidlink"]["field"]))
        {
            return $mapping["noidlink"]["field"];
        }
        return null;
    }

    public function get_midgard_type($property)
    {
        if ($this->cm->hasField($property))
        {
            $mapping = $this->cm->getFieldMapping($property);
            return $mapping['midgard:midgard_type'];
        }
        else if ($this->cm->hasAssociation($property))
        {
            // for now, only PK fields are supported, which are always IDs, so..
            return translator::TYPE_UINT;
        }
        return translator::TYPE_NONE;
    }
}