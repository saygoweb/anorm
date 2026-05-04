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

class TableMakerTestExceptionWithMessage extends \PDOException
{
    public function __construct(string $code, string $msg)
    {
        $this->code = $code;
        $this->message = $msg;
    }
}

class TableMakerTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    public function setUp(): void
    {
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

    public function testColumnDefinition_Moment_OK()
    {
        if (!class_exists('Moment\\Moment')) {
            eval('namespace Moment; class Moment {}');
        }
        /** @noinspection PhpUndefinedClassInspection */
        $moment = new \Moment\Moment();

        $result = TableMaker::columnDefinition('date', $moment);
        $this->assertEquals('DATETIME NULL', $result);
    }

    public function testCreateTable_BadException_Throws()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Anorm: Could not parse PDOException");

        $e = new TableMakerTestException('42S02'); // Table does not exist
        $mapper = DataMapper::create($this->pdo, null, null);
        TableMaker::fix($e, $mapper);
    }

    public function testCreateColumn_BadException_Throws()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Anorm: Could not parse PDOException");

        $e = new TableMakerTestException('42S22'); // Column does not exist
        $mapper = DataMapper::create($this->pdo, null, null);
        TableMaker::fix($e, $mapper);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testFix_23000_NoModel_DoesNotThrow()
    {
        $e = new TableMakerTestExceptionWithMessage(
            '23000',
            'Cannot add or update a child row: a foreign key constraint fails'
        );
        $mapper = DataMapper::create($this->pdo, null, null);
        TableMaker::fix($e, $mapper, null);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testFix_HY000_WithForeignKeyMessage_DoesNotThrow()
    {
        $e = new TableMakerTestExceptionWithMessage(
            'HY000',
            'General error: 1215 Cannot add foreign key constraint'
        );
        $mapper = DataMapper::create($this->pdo, null, null);
        TableMaker::fix($e, $mapper, null);
    }

    public function testFix_HY000_WithoutForeignKeyMessage_Rethrows()
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('Some unrelated general error');

        $e = new TableMakerTestExceptionWithMessage('HY000', 'Some unrelated general error');
        $mapper = DataMapper::create($this->pdo, null, null);
        TableMaker::fix($e, $mapper, null);
    }
}
