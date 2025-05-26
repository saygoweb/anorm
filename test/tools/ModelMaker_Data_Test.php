<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Tools\ModelMaker;
use Anorm\Test\TestEnvironment;

class ModelMakerDataTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    public function __construct()
    {
        parent::__construct();
        $this->pdo = TestEnvironment::pdo();
        $this->pdo->query('DROP TABLE IF EXISTS `model_test`');
        $sql = file_get_contents(__DIR__ . '/ModelMakerSchema.sql');
        $this->pdo->query($sql);
    }

    /**
     * @expectedException \PDOException
     * @expectedExceptionMessage SQLSTATE[42S02]: Base table or view not found: 1146 Table 'anorm_test.bogus_table' doesn't exist
     */
    public function testWriteModelAsString_WithBogusTable_Throws()
    {
        $o = new ModelMaker($this->pdo, 'bogus_table');
        $this->assertEquals('bogus_table', $o->table);
        $actual = $o->writeModelAsString();
    }
    
    public function testWriteModelAsString_OK()
    {
        $o = new ModelMaker($this->pdo, 'model_test');
        $this->assertEquals('model_test', $o->table);
        $actual = $o->writeModelAsString();
        $expected = <<<"EOD"
<?php
namespace App;

use Anorm\DataMapper;
use Anorm\Model;

class ModelTestModel extends Model
{
    public function __construct(\PDO \$pdo)
    {
        parent::__construct(\$pdo, DataMapper::createByClass(\$pdo, \$this));
        \$this->_mapper->modelPrimaryKey = 'someId';
    }

    // Properties
    /** @var string */
    public \$someId;

    /** @var string */
    public \$name;

    /** @var string */
    public \$dtc;


}
EOD;
        $this->assertEquals($expected, $actual);
    }

}
