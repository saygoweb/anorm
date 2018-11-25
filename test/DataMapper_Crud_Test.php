<?php

require_once(__DIR__ . '/../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;
use Anorm\Model;

class SomeTableModel extends Model {
    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
    }

    public function countRows()
    {
        $result = $this->_mapper->query('SELECT * FROM `some_table`');
        return $result->rowCount();
    }

    public $id;
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
        $this->pdo->query('DROP TABLE IF EXISTS `some_table`');
        $sql = file_get_contents(__DIR__ . '/TestSchema.sql');
        $this->pdo->query($sql);
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
        $this->assertNull($model0->id);
        $model0->write();
        $this->assertNotNull($model0->id);
        
        // Count current rows (n+1)
        $n1 = $model0->countRows();
        $this->assertEquals($n0 + 1, $n1);

        // Read (data present)
        $model1 = new SomeTableModel($this->pdo);
        $model1->read($model0->id);
        $this->assertEquals($model0->name, $model1->name);
        $this->assertEquals($model0->dtc, $model1->dtc);

        // Update
        $model1->name = 'fred';
        $model1->write();

        // Read (data changed)
        $model2 = new SomeTableModel($this->pdo);
        $model2->read($model1->id);
        $this->assertEquals($model1->name, $model2->name);

        // Delete
        $model0->_mapper->delete($model0->id);

        // Count current rows (n)
        $n2 = $model0->countRows();
        $this->assertEquals($n0, $n2);

    }

    function testBogusRead_Fails()
    {
        $model = new SomeTableModel($this->pdo);
        $result = $model->read('1');
        $this->assertFalse($result);
    }
}