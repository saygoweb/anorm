<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Test\TestEnvironment;

/**
 * Columns created by a read used to be typed from a null sample, because the read path
 * never handed the model to TableMaker. These cover the model reaching the guess, and
 * the per-mapper override for the cases a guess cannot reach.
 *
 * @see https://github.com/saygoweb/anorm/issues/59
 * @see https://github.com/saygoweb/anorm/issues/61
 */

/** Properties carry defaults, which is the only type information a read has to offer. */
class InfWidgetModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'inf_widgets';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public $quotaBytes = 10737418240;
    public $isActive = false;
    public $hitCount = 0;
}

/** Properties are null, so a read has nothing to sample and needs the override. */
class InfGadgetModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'inf_gadgets';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        $mapper->columnDefinitions = ['owner_id' => 'INT(11) NULL'];
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public $ownerId;
}

/** Null property and no override: nothing to infer from, which is the point. */
class InfPlainModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'inf_plains';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public $ownerId;
}

class DynamicInference_Test extends TestCase
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
        $this->pdo->exec('DROP TABLE IF EXISTS inf_widgets');
        $this->pdo->exec('DROP TABLE IF EXISTS inf_gadgets');
        $this->pdo->exec('DROP TABLE IF EXISTS inf_plains');
    }

    public function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS inf_widgets');
        $this->pdo->exec('DROP TABLE IF EXISTS inf_gadgets');
        $this->pdo->exec('DROP TABLE IF EXISTS inf_plains');
    }

    public function testReadBeforeWrite_TypesColumnsFromTheModel()
    {
        // A lookup before the first write: the WHERE names columns the table has not got,
        // so they are created from this read and keep whatever type it decides.
        $this->pdo->exec('CREATE TABLE inf_widgets (id INT(11) AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        DataMapper::find('InfWidgetModel', $this->pdo)
            ->where('`quota_bytes` = :q', [':q' => 10737418240])
            ->one();

        // 10 GiB as bytes needs the wider type; a null sample used to make this VARCHAR(128).
        $this->assertEquals('bigint(20)', $this->columnType('inf_widgets', 'quota_bytes'));
    }

    public function testReadBeforeWrite_FalsyDefaultsStillCarryTheirType()
    {
        $this->pdo->exec('CREATE TABLE inf_widgets (id INT(11) AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        DataMapper::find('InfWidgetModel', $this->pdo)
            ->where('`is_active` = :a AND `hit_count` = :h', [':a' => 0, ':h' => 0])
            ->one();

        $this->assertEquals('tinyint(1)', $this->columnType('inf_widgets', 'is_active'));
        $this->assertEquals('int(11)', $this->columnType('inf_widgets', 'hit_count'));
    }

    public function testColumnDefinitions_OverrideTheGuess()
    {
        // owner_id is null on a fresh instance, so no sample can reach the right answer.
        // The per-mapper definition is how a foreign key gets INT(11) on a read path.
        $this->pdo->exec('CREATE TABLE inf_gadgets (id INT(11) AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        DataMapper::find('InfGadgetModel', $this->pdo)
            ->where('`owner_id` = :o', [':o' => 1])
            ->one();

        $this->assertEquals('int(11)', $this->columnType('inf_gadgets', 'owner_id'));
    }

    public function testColumnDefinitions_AppliesToTheWritePath()
    {
        $model = new InfGadgetModel($this->pdo);
        $model->ownerId = 7;
        $model->write();

        $this->assertEquals('int(11)', $this->columnType('inf_gadgets', 'owner_id'));
    }

    public function testNullSampleWithoutOverride_IsStillVarchar()
    {
        // A documented limit, not an aspiration. Threading the model through the read
        // path lets the guess see the property; it cannot conjure a type out of null.
        // That is what columnDefinitions is for, and why MODE_STATIC is the production
        // answer. Asserted so a future change to the fallback has to be deliberate.
        $this->pdo->exec('CREATE TABLE inf_plains (id INT(11) AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

        DataMapper::find('InfPlainModel', $this->pdo)
            ->where('`owner_id` = :o', [':o' => 1])
            ->one();

        $this->assertEquals('varchar(128)', $this->columnType('inf_plains', 'owner_id'));
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
