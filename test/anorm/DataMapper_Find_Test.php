<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

require_once(__DIR__ . '/SomeTableModel.php');
require_once(__DIR__ . '/TestEnvironment.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;
use Anorm\Model;

class DataMapperFindTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    public function __construct()
    {
        parent::__construct();
        $this->pdo = TestEnvironment::pdo();
    }
    
    public static function setUpBeforeClass()
    {
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `some_table`');
        $sql = file_get_contents(__DIR__ . '/TestSchema.sql');
        $pdo->query($sql);

        // Write some data for this test suite
        $model = new SomeTableModel($pdo);
        for ($i = 0; $i < 10; ++$i) {
            $model->name = "Name $i";
            $model->someId = null;
            $model->write();
        }
    }

    // public function testFindOne_OK()
    // {
    //     /** @var SomeTableModel */
    //     $model = DataMapper::find('SomeTableModel', $this->pdo)
    //         ->where("`name`='Name 1'")
    //         ->one();
    //     $this->assertEquals('Name 1', $model->name);
    // }

    public function testFindOne_OK()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find('SomeTableModel', $this->pdo)
            ->where("`name`=:name", [':name' => 'Name 1'])
            ->one();
        $this->assertEquals('Name 1', $model->name);
    }

    public function testFindOneOrThrow_OK()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find('SomeTableModel', $this->pdo)
        ->where("`name`=:name", [':name' => 'Name 1'])
        ->oneOrThrow();
        $this->assertEquals('Name 1', $model->name);
    }

    public function testFindSome_OK()
    {
        $generator = DataMapper::find('SomeTableModel', $this->pdo)
            ->orderBy("name")
            ->limit(3)
            ->some();
        $i = 0;
        foreach ($generator as $model) {
            $this->assertEquals("Name $i", $model->name);
            ++$i;
        }
        $this->assertEquals(3, $i);
    }

    public function testFindOne_NotPresent_False()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find('SomeTableModel', $this->pdo)
            ->where("`name`=:name", [':name' => 'Bogus Name'])
            ->one();
        $this->assertEquals(false, $model);
    }

    /** 
     * @expectedException \Exception
     * @expectedExceptionMessage QueryBuilder: Expected one not found from 'SELECT * FROM `some_table` WHERE `name`=:name LIMIT 1'
     */
    public function testFindOneOrThrow_NotPresent_Throws()
    {
        /** @var SomeTableModel */
        $model = DataMapper::find('SomeTableModel', $this->pdo)
            ->where("`name`=:name", [':name' => 'Bogus Name'])
            ->oneOrThrow();
    }

}
