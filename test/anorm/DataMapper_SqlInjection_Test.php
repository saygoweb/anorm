<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Test\SomeTableModel;
use Anorm\Test\TestEnvironment;

/**
 * A string primary key the application assigns itself, against `replace_table`.
 * Writes through the UPDATE branch (useReplace stays false), so a test can flip
 * useReplace on for the initial insert and back off to exercise the update.
 */
class QuotedKeyModel extends Model
{
    public function __construct()
    {
        $pdo = Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->table = 'replace_table';
        $this->_mapper->modelPrimaryKey = 'replaceId';
        $this->dtc = null;
    }

    public $replaceId;
    public $name;
    public $dtc;
}

/**
 * The primary key value reaches the WHERE clause of read() and of the UPDATE
 * branch of write() as data, never as SQL. See GHSA-xc47-9hw7-px38.
 */
class DataMapperSqlInjectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `some_table`');
        $pdo->query(file_get_contents(__DIR__ . '/TestSchema.sql'));
        $pdo->query('DROP TABLE IF EXISTS `replace_table`');
        foreach (explode(';', file_get_contents(__DIR__ . '/TestReplaceSchema.sql')) as $statement) {
            $statement = trim($statement);
            if ($statement !== '' && strpos($statement, '#') !== 0) {
                $pdo->query($statement);
            }
        }
    }

    public function testRead_TautologyId_FindsNothingAndLeavesModelUnpopulated()
    {
        $seed = new SomeTableModel();
        $seed->name = 'bob';
        $seed->write();
        $this->assertNotNull($seed->someId);

        $model = new SomeTableModel();
        $result = $model->read("0' OR '1'='1");

        $this->assertFalse($result);
        $this->assertNull($model->someId);
        $this->assertNull($model->name);
    }

    public function testRead_UnionId_FindsNothing()
    {
        $seed = new SomeTableModel();
        $seed->name = 'bob';
        $seed->write();

        $model = new SomeTableModel();
        $result = $model->read("0' UNION SELECT 1, 'leaked', NULL, NULL -- ");

        $this->assertFalse($result);
        $this->assertNull($model->name);
    }

    public function testReadOrThrow_TautologyId_Throws()
    {
        $seed = new SomeTableModel();
        $seed->name = 'bob';
        $seed->write();

        $model = new SomeTableModel();
        $this->expectException(\Exception::class);
        $model->readOrThrow("0' OR '1'='1");
    }

    public function testWriteThenRead_StringKeyContainingQuote_RoundTrips()
    {
        $key = "O'RING";

        $created = new QuotedKeyModel();
        $created->_mapper->useReplace = true;
        $created->replaceId = $key;
        $created->name = 'oring';
        $created->write();

        $read = new QuotedKeyModel();
        $this->assertTrue((bool)$read->read($key));
        $this->assertEquals($key, $read->replaceId);
        $this->assertEquals('oring', $read->name);

        // The UPDATE branch: a legitimate quote in the key must not break the WHERE.
        $read->name = 'gasket';
        $read->write();

        $reread = new QuotedKeyModel();
        $this->assertTrue((bool)$reread->read($key));
        $this->assertEquals('gasket', $reread->name);
    }

    public function testWrite_TautologyKey_TouchesNoRow()
    {
        $seed = new SomeTableModel();
        $seed->name = 'bob';
        $seed->write();
        $seedId = $seed->someId;

        $hostile = new SomeTableModel();
        $hostile->someId = "0' OR '1'='1";
        $hostile->name = 'hacked';
        $hostile->write();

        $reread = new SomeTableModel();
        $this->assertTrue((bool)$reread->read($seedId));
        $this->assertEquals('bob', $reread->name);
    }
}
