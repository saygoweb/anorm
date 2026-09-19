<?php

// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Test\TestEnvironment;

/**
 * A PHP 7.4 typed property with no default holds no value until something assigns
 * one, so get_object_vars() does not report it and the column map used to miss it
 * entirely: the property was silently not a column, in either direction.
 *
 * @see https://github.com/saygoweb/anorm/issues/61
 */

/** Declares four columns; three of them have no value to report. */
class MapDeclaredModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'map_declareds';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public int $hitCount;
    public ?int $ownerId;
    public ?string $note;
    public $legacy = '';
    public static int $counter = 0;
    public $_scratch;
}

class DeclaredPropertyMap_Test extends TestCase
{
    /** @var \PDO */
    private $pdo;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
    }

    public function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
        $this->pdo->exec('DROP TABLE IF EXISTS map_declareds');
    }

    public function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS map_declareds');
    }

    public function testDeclaredButUninitialisedProperties_AreColumns()
    {
        $map = DataMapper::autoMap(new MapDeclaredModel($this->pdo));

        $this->assertSame('hit_count', $map['hitCount'], 'a typed property with no default is still a column');
        $this->assertSame('owner_id', $map['ownerId']);
        $this->assertSame('note', $map['note']);
        $this->assertSame('legacy', $map['legacy'], 'an ordinary property is unaffected');
    }

    public function testStaticAndInfrastructureProperties_AreStillNotColumns()
    {
        $map = DataMapper::autoMap(new MapDeclaredModel($this->pdo));

        $this->assertArrayNotHasKey('counter', $map);
        $this->assertArrayNotHasKey('_scratch', $map);
    }

    public function testWritingAModelThatNeverSetThem_StoresNullAndTypesTheColumns()
    {
        // Nothing has assigned to three of these properties. Reading one directly would
        // throw; writing the model treats "no value" as the null it is.
        $model = new MapDeclaredModel($this->pdo);
        $model->write();

        $this->assertEquals('int(11)', $this->columnType('map_declareds', 'hit_count'));
        $this->assertEquals('int(11)', $this->columnType('map_declareds', 'owner_id'));
        $this->assertEquals('varchar(128)', $this->columnType('map_declareds', 'note'));

        $row = $this->pdo->query('SELECT * FROM map_declareds')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['hit_count']);
        $this->assertNull($row['owner_id']);
    }

    public function testNullableDeclaredProperties_RoundTrip()
    {
        $model = new MapDeclaredModel($this->pdo);
        $model->ownerId = 7;
        $model->note = 'kept';
        $model->hitCount = 3;
        $id = $model->write();

        $read = new MapDeclaredModel($this->pdo);
        $read->read($id);

        $this->assertEquals(7, $read->ownerId);
        $this->assertEquals('kept', $read->note);
        $this->assertEquals(3, $read->hitCount);
    }

    public function testNonNullableProperty_AgainstANullColumn_SaysWhichColumn()
    {
        // The model declares `int` and the column holds NULL: a contradiction PHP
        // reports without naming the column or the table. Anorm knows both.
        $model = new MapDeclaredModel($this->pdo);
        $id = $model->write();

        $read = new MapDeclaredModel($this->pdo);
        try {
            $read->read($id);
            $this->fail('Expected a TypeError naming the column');
        } catch (\TypeError $e) {
            $this->assertStringContainsString('map_declareds', $e->getMessage());
            $this->assertStringContainsString('hit_count', $e->getMessage());
            $this->assertStringContainsString('MapDeclaredModel::$hitCount', $e->getMessage());
            $this->assertStringContainsString('Declare the property nullable', $e->getMessage());
            $this->assertInstanceOf(\TypeError::class, $e->getPrevious(), 'PHP\'s own error is kept');
        }
    }

    private function columnType($table, $column)
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? strtolower($row['COLUMN_TYPE']) : null;
    }
}
