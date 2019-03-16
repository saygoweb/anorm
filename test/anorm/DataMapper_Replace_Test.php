<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

require_once(__DIR__ . '/ReplaceTableModel.php');

use PHPUnit\Framework\TestCase;

class DataMapperReplaceTest extends TestCase
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
        $pdo->query('DROP TABLE IF EXISTS `replace_table`');
        $sql = file_get_contents(__DIR__ . '/TestReplaceSchema.sql');
        $pdo->query($sql);
    }

    public function testReplace_OK()
    {
        $model0 = new ReplaceTableModel($this->pdo);
        $this->assertEquals($this->pdo, $model0->_mapper->pdo);
        // Count current rows
        $n0 = $model0->countRows();
        $this->assertEquals(0, $n0);

        // Create
        $model0->replaceId = 'bob_id';
        $model0->name = 'bob';
        $model0->write();
        
        // Count current rows (n+1)
        $n1 = $model0->countRows();
        $this->assertEquals($n0 + 1, $n1);

        // Read (data present)
        $model1 = new ReplaceTableModel($this->pdo);
        $model1->read($model0->replaceId);
        $this->assertEquals($model0->name, $model1->name);

        // Update
        $model1->name = 'fred';
        $model1->write();

        // Read (data changed)
        $model2 = new ReplaceTableModel($this->pdo);
        $model2->read($model1->replaceId);
        $this->assertEquals($model1->name, $model2->name);

        // Delete
        $model0->_mapper->delete($model0->replaceId);

        // Count current rows (n)
        $n2 = $model0->countRows();
        $this->assertEquals($n0, $n2);

    }

    /** 
     * @expectedException \Exception
     * @expectedExceptionMessage Key 'replaceId' must be set when using replace mode
     */
    public function testNoPrimaryKey_Throws()
    {
        $model = new ReplaceTableModel($this->pdo);
        $model->write();
    }
}