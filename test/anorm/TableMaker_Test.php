<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;
use Anorm\TableMaker;
use Anorm\Test\TestEnvironment;

class TableMakerTestException extends \PDOException
{
    public function __construct($code)
    {
        $this->code = $code;
        $this->message = 'bogus message';
    }
}

class TableMakerTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    public function __construct()
    {
        parent::__construct();
        $this->pdo = TestEnvironment::pdo();
    }
    

    public function testColumnDefinition_Integer_OK()
    {
        $result = TableMaker::columnDefinition('integer', 1);
        $this->assertEquals('INT(11) NULL', $result);
    }

    public function testColumnDefinition_Double_OK()
    {
        $result = TableMaker::columnDefinition('double', 1.00);
        $this->assertEquals('DOUBLE NULL', $result);
    }

    public function testColumnDefinition_Text_OK()
    {
        $sampleData = '';
        for ($i = 0; $i < 50; ++$i) {
            $sampleData .= '0123456789';
        }
        $result = TableMaker::columnDefinition('text', $sampleData);
        $this->assertEquals('TEXT', $result);
    }

    public function testColumnDefinition_Varchar256_OK()
    {
        $sampleData = '';
        for ($i = 0; $i < 15; ++$i) {
            $sampleData .= '0123456789';
        }
        $result = TableMaker::columnDefinition('text', $sampleData);
        $this->assertEquals('VARCHAR(256)', $result);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Anorm: Could not parse PDOException
     */
    public function testCreateTable_BadException_Throws()
    {
        $e = new TableMakerTestException('42S02'); // Table does not exist
        $mapper = DataMapper::create($this->pdo, null, null);
        TableMaker::fix($e, $mapper);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Anorm: Could not parse PDOException
     */
    public function testCreateColumn_BadException_Throws()
    {
        $e = new TableMakerTestException('42S22'); // Column does not exist
        $mapper = DataMapper::create($this->pdo, null, null);
        TableMaker::fix($e, $mapper);
    }


}