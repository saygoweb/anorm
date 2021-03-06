<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Test\SomeTableModel;
use Anorm\Test\TestEnvironment;

class BogusModel extends Model {
    public function __construct()
    {
        $pdo = Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->modelPrimaryKey = 'someId';
    }

    public $someId;
    public $name;
    public $dtc;
}

class DataMapperCrudTest extends TestCase
{

    public function __construct()
    {
        parent::__construct();
        TestEnvironment::connect();
    }
    
    public static function setUpBeforeClass()
    {
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `some_table`');
        $sql = file_get_contents(__DIR__ . '/TestSchema.sql');
        $pdo->query($sql);
    }

    public function testCrud_OK()
    {
        $model0 = new SomeTableModel();
        // Count current rows
        $n0 = $model0->countRows();
        $this->assertEquals(0, $n0);

        // Create
        $model0->name = 'bob';
        $model0->dtc = '2018-11-25';
        $this->assertNull($model0->someId);
        $model0->write();
        $this->assertNotNull($model0->someId);
        
        // Count current rows (n+1)
        $n1 = $model0->countRows();
        $this->assertEquals($n0 + 1, $n1);

        // Read (data present)
        $model1 = new SomeTableModel();
        $model1->read($model0->someId);
        $this->assertEquals($model0->name, $model1->name);
        $this->assertEquals($model0->dtc, $model1->dtc);

        // Update
        $model1->name = 'fred';
        $model1->write();

        // Read (data changed)
        $model2 = new SomeTableModel();
        $model2->read($model1->someId);
        $this->assertEquals($model1->name, $model2->name);

        // ReadOrThrow (data changed)
        $model2 = new SomeTableModel();
        $model2->readOrThrow($model1->someId);
        $this->assertEquals($model1->name, $model2->name);

        // Delete
        $model0->_mapper->delete($model0->someId);

        // Count current rows (n)
        $n2 = $model0->countRows();
        $this->assertEquals($n0, $n2);

    }

    function testDateWrite_Null_Ok()
    {
        $model1 = new SomeTableModel();
        $model1->dtc = null;
        $model1->name = 'bob';
        $id = $model1->write();
        $model2 = new SomeTableModel();
        $model2->read($id);
        $this->assertNull($model2->dtc);
    }

    function testBogusRead_Fails()
    {
        $model = new SomeTableModel();
        $result = $model->read('1');
        $this->assertFalse($result);
    }

    function testBogusReadOrThrow_Throws()
    {
        $model = new SomeTableModel();
        $this->expectException(\Exception::class);
        $result = $model->readOrThrow('1');
    }

    function testBogusReadOrThrow_MessageOk()
    {
        $model = new SomeTableModel();
        try {
            $result = $model->readOrThrow('1');
        }
        catch (\Exception $e) {
            $this->assertEquals("SomeTable id '1' not found", $e->getMessage());
        }
    }

    /**
     * @expectedException \PDOException
     * @expectedExceptionMessage SQLSTATE[42S02]: Base table or view not found: 1146 Table 'anorm_test.bogus' doesn't exist
     */
    function testBogusWrite_Fails()
    {
        $model = new BogusModel();
        $result = $model->write();
        $this->assertFalse($result);
    }

    /**
     * @expectedException \PDOException
     * @expectedExceptionMessage SQLSTATE[42S02]: Base table or view not found: 1146 Table 'anorm_test.bogus' doesn't exist
     */
    function testBogusDelete_Fails()
    {
        $model = new BogusModel();
        $result = $model->_mapper->delete('bogus');
        $this->assertFalse($result);
    }
}