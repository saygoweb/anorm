<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

require_once(__DIR__ . '/SomeTableModel.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;
use Anorm\Model;

class BogusModel extends Model {
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->modelPrimaryKey = 'someId';
    }

    public $someId;
    public $name;
    public $dtc;
}

class DataMapperCrudTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    public function __construct()
    {
        parent::__construct();
        $this->pdo = new \PDO('mysql:host=localhost;dbname=anorm_test', 'travis', '');
    }
    
    public static function setUpBeforeClass()
    {
        $pdo = new \PDO('mysql:host=localhost;dbname=anorm_test', 'travis', '');
        $pdo->query('DROP TABLE IF EXISTS `some_table`');
        $sql = file_get_contents(__DIR__ . '/TestSchema.sql');
        $pdo->query($sql);
    }

    public function testCrud_OK()
    {
        $model0 = new SomeTableModel($this->pdo);
        $this->assertEquals($this->pdo, $model0->_mapper->pdo);
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
        $model1 = new SomeTableModel($this->pdo);
        $model1->read($model0->someId);
        $this->assertEquals($model0->name, $model1->name);
        $this->assertEquals($model0->dtc, $model1->dtc);

        // Update
        $model1->name = 'fred';
        $model1->write();

        // Read (data changed)
        $model2 = new SomeTableModel($this->pdo);
        $model2->read($model1->someId);
        $this->assertEquals($model1->name, $model2->name);

        // Delete
        $model0->_mapper->delete($model0->someId);

        // Count current rows (n)
        $n2 = $model0->countRows();
        $this->assertEquals($n0, $n2);

    }

    function testDateWrite_Null_Ok()
    {
        $model1 = new SomeTableModel($this->pdo);
        $model1->dtc = null;
        $model1->name = 'bob';
        $id = $model1->write();
        $model2 = new SomeTableModel($this->pdo);
        $model2->read($id);
        $this->assertNull($model2->dtc);
    }

    function testBogusRead_Fails()
    {
        $model = new SomeTableModel($this->pdo);
        $result = $model->read('1');
        $this->assertFalse($result);
    }

    /**
     * @expectedException \PDOException
     * @expectedExceptionMessage SQLSTATE[42S02]: Base table or view not found: 1146 Table 'anorm_test.bogus' doesn't exist
     */
    function testBogusWrite_Fails()
    {
        $model = new BogusModel($this->pdo);
        $result = $model->write();
        $this->assertFalse($result);
    }

    /**
     * @expectedException \PDOException
     * @expectedExceptionMessage SQLSTATE[42S02]: Base table or view not found: 1146 Table 'anorm_test.bogus' doesn't exist
     */
    function testBogusDelete_Fails()
    {
        $model = new BogusModel($this->pdo);
        $result = $model->_mapper->delete('bogus');
        $this->assertFalse($result);
    }
}