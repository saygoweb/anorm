<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Test\TestEnvironment;
use Anorm\Transform\JsonArrayTransform;
use Anorm\Transform\SqlDateTimeTransform;

/**
 * Columns created in dynamic mode from what the model *declares*, rather than from
 * whichever value happened to reach the column first.
 *
 * @see https://github.com/saygoweb/anorm/issues/61
 */

/** Typed properties: the declaration is there even when the value is null. */
class DeclWidgetModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'decl_widgets';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public ?int $ownerId = null;
    public ?bool $isActive = null;
    public ?float $ratio = null;
    public ?string $reference = null;
}

/** Docblocks: how every model written before PHP 7.4 declares the same thing. */
class DeclLegacyModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'decl_legacies';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
    }

    public $id;

    /** @var int */
    public $ownerId;

    /** @var \DateTime */
    public $occurredAt;
}

/** A pin is still the last word, over a declaration as over a sample. */
class DeclPinnedModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'decl_pinneds';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        $mapper->columnDefinitions = ['owner_id' => 'BIGINT(20) NULL'];
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public ?int $ownerId = null;
}

/** Transformers know the format they write, whatever the property says. */
class DeclTransformedModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'decl_transformeds';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        $mapper->transformers['occurred_at'] = new SqlDateTimeTransform();
        $mapper->transformers['payload'] = new JsonArrayTransform();
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public $occurredAt;
    public $payload;
}

class DeclaredTypeInference_Test extends TestCase
{
    /** @var \PDO */
    private $pdo;

    private static $tables = ['decl_widgets', 'decl_legacies', 'decl_pinneds', 'decl_transformeds'];

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
    }

    public function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
        $this->dropTables();
    }

    public function tearDown(): void
    {
        $this->dropTables();
    }

    public function testReadBeforeWrite_TypesColumnsFromTheDeclaration()
    {
        // The case #59's fix could not reach: QueryBuilder builds a fresh instance, so
        // every property is null and there is nothing to sample. The declaration is.
        $this->pdo->exec('CREATE TABLE decl_widgets (id INT(11) AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        DataMapper::find('DeclWidgetModel', $this->pdo)
            ->where('`owner_id` = :o', [':o' => 1])
            ->one();

        $this->assertEquals('int(11)', $this->columnType('decl_widgets', 'owner_id'));
    }

    public function testWrite_TypesColumnsFromTheDeclarationRatherThanTheFirstValue()
    {
        // Every one of these is written null, which is exactly the case that used to
        // produce four VARCHAR(128) columns.
        $model = new DeclWidgetModel($this->pdo);
        $model->write();

        $this->assertEquals('int(11)', $this->columnType('decl_widgets', 'owner_id'));
        $this->assertEquals('tinyint(1)', $this->columnType('decl_widgets', 'is_active'));
        $this->assertEquals('double', $this->columnType('decl_widgets', 'ratio'));
        $this->assertEquals('varchar(128)', $this->columnType('decl_widgets', 'reference'));
    }

    public function testDocblockDeclaration_IsRead()
    {
        $this->pdo->exec('CREATE TABLE decl_legacies (id INT(11) AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        DataMapper::find('DeclLegacyModel', $this->pdo)
            ->where('`owner_id` = :o AND `occurred_at` IS NULL', [':o' => 1])
            ->one();

        $this->assertEquals('int(11)', $this->columnType('decl_legacies', 'owner_id'));
        $this->assertEquals('datetime', $this->columnType('decl_legacies', 'occurred_at'));
    }

    public function testDeclaredInt_TakesItsWidthFromTheValueBeingWritten()
    {
        // The declaration settles the type; the value still settles the width.
        $model = new DeclWidgetModel($this->pdo);
        $model->ownerId = 10737418240;
        $model->write();

        $this->assertEquals('bigint(20)', $this->columnType('decl_widgets', 'owner_id'));
    }

    public function testPinnedDefinition_BeatsTheDeclaration()
    {
        $model = new DeclPinnedModel($this->pdo);
        $model->write();

        $this->assertEquals('bigint(20)', $this->columnType('decl_pinneds', 'owner_id'));
    }

    public function testTransformer_TypesTheColumnItWritesInto()
    {
        // A date formatted for storage is a DATETIME, and encoded JSON is not a
        // VARCHAR(128) however short the first array written to it happened to be.
        $model = new DeclTransformedModel($this->pdo);
        $model->occurredAt = new \DateTime('2026-09-19 10:00:00');
        $model->payload = ['a' => 1];
        $model->write();

        $this->assertEquals('datetime', $this->columnType('decl_transformeds', 'occurred_at'));
        $this->assertEquals('text', $this->columnType('decl_transformeds', 'payload'));
    }

    public function testTransformer_TypesTheColumnOnAReadPathToo()
    {
        // No model is needed for this one: transformers are keyed by column name.
        $this->pdo->exec('CREATE TABLE decl_transformeds (id INT(11) AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        DataMapper::find('DeclTransformedModel', $this->pdo)
            ->where('`payload` IS NULL')
            ->one();

        $this->assertEquals('text', $this->columnType('decl_transformeds', 'payload'));
    }

    private function dropTables()
    {
        foreach (self::$tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS $table");
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
