<?php
/**
 * @author CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @copyright CONTENT CONTROL http://www.contentcontrol-berlin.de/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace midgard\portable\test;

use midgard\portable\driver;
use midgard\portable\mapping\classmetadata;

class driverTest extends testcase
{
    public function test_getAllClassNames()
    {
        $ns = uniqid(__CLASS__ . '\\' . __FUNCTION__);
        $d = sys_get_temp_dir();
        $driver = new driver(array(TESTDIR . '__files/'), $d, $ns);
        $classnames = $driver->getAllClassNames();
        $this->assertInternalType('array', $classnames);
        $this->assertEquals(10, count($classnames));

        foreach ($classnames as $classname)
        {
            $this->assertTrue(class_exists($classname), $classname . ' not found');
        }
    }

    public function test_loadMetadataForClass()
    {
        $ns = uniqid(__CLASS__ . '\\' . __FUNCTION__);
        $d = sys_get_temp_dir();
        $driver = new driver(array(TESTDIR . '__files/'), $d, $ns);
        $metadata = new classmetadata($ns . '\\midgard_topic');
        $driver->loadMetadataForClass($ns . '\\midgard_topic', $metadata);

        $this->assertArrayHasKey('metadata_deleted', $metadata->fieldMappings);
        $this->assertArrayHasKey('score', $metadata->fieldMappings);

        $mapping = $metadata->fieldMappings['metadata_approved'];
        $this->assertEquals("midgard_datetime", $mapping["type"]);
        $this->assertEquals("0001-01-01 00:00:00", $mapping["default"]);
    }

    public function test_duplicate_tablenames()
    {
        $ns = uniqid(__CLASS__ . '\\' . __FUNCTION__);
        $d = sys_get_temp_dir();
        $driver = new driver(array(TESTDIR . '__files/duplicate_tablenames/'), $d, $ns);
        $driver->getAllClassNames();

        $classname = $ns . '\\midgard_member';
        $classname = get_class(new $classname);
        $metadata_member = new classmetadata($classname);
        $driver->loadMetadataForClass($classname, $metadata_member);
        $this->assertEquals('gid', $metadata_member->midgard['parentfield']);

        $classname = $ns . '\\midgard_group';
        $classname = get_class(new $classname);
        $metadata_group = new classmetadata($classname);
        $driver->loadMetadataForClass($classname, $metadata_group);

        $this->assertEquals(2, count($metadata_group->midgard['childtypes']));
    }
}