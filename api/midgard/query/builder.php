<?php
/**
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

use midgard\portable\query;
use midgard\portable\api\error\exception;

class midgard_query_builder extends query
{
    public function add_constraint_with_property($name, $operator, $value) : bool
    {
        try {
            return parent::add_constraint_with_property($name, $operator, $value);
        } catch (exception $e) {
            return false;
        }
    }

    public function add_constraint($name, $operator, $value) : bool
    {
        try {
            return parent::add_constraint($name, $operator, $value);
        } catch (exception $e) {
            return false;
        }
    }

    /**
     * @return midgard\portable\api\mgdobject[]
     */
    public function execute()
    {
        $query = $this->prepare_query();
        $result = $query->getResult();
        $this->post_execution();
        return $result;
    }

    /**
     * @return midgard\portable\api\mgdobject[]
     */
    public function iterate()
    {
        $query = $this->prepare_query();
        $resultset = $query->iterate();
        $this->post_execution();
        foreach ($resultset as $result) {
            $this->qb->getEntityManager()->detach($result[0]);
            yield $result[0];
        }
    }

    private function prepare_query() : \Doctrine\ORM\Query
    {
        $this->check_groups();
        $this->qb->addSelect('c');
        $this->pre_execution();
        return $this->qb->getQuery();
    }
}
