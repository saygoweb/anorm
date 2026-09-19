<?php

namespace Anorm\Test;

require_once(__DIR__ . '/../../vendor/autoload.php');

use Anorm\DataMapper;
use Anorm\Model;
use PHPUnit\Framework\TestCase;

/**
 * A model that says what it means: the flags are PHP booleans, and the columns they
 * are written to are the `TINYINT(1)` that dynamic mode now creates for a bool.
 */
class BoolFlagModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'bool_flags';
        parent::__construct($pdo, $mapper);
    }

    public $id;

    /** @var bool */
    public $emailVerified = false;

    /** @var bool */
    public $isActive = true;

    public $label;
}

/**
 * What `write()` does with a PHP boolean.
 *
 * `PDO::quote()` takes a string, so `false` reaches SQL as `''` — which a strict
 * integer column rejects, with a message that names neither the column nor the
 * boolean. The type inference and the write path have to agree that a bool is 0 or 1.
 *
 * @see https://github.com/saygoweb/anorm/issues/66
 */
class DynamicBoolFlagModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'dynamic_bool_flags';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
    }

    public $id;

    /** @var bool */
    public $emailVerified = false;

    public $label;
}

class DataMapper_WriteBoolean_Test extends TestCase
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
        $this->pdo->exec('DROP TABLE IF EXISTS `bool_flags`');
        $this->pdo->exec(
            'CREATE TABLE `bool_flags` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `email_verified` TINYINT(1) NULL,
                `is_active` TINYINT(1) NULL,
                `label` VARCHAR(128) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB'
        );
    }

    public function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS `bool_flags`');
    }

    /** @return array<string, mixed> */
    private function row($id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM `bool_flags` WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    public function testFalseIsWrittenAsZero()
    {
        $model = new BoolFlagModel($this->pdo);
        $model->emailVerified = false;
        $model->label = 'first';
        $model->write();

        $this->assertEquals(0, $this->row($model->id)['email_verified']);
    }

    public function testTrueIsWrittenAsOne()
    {
        $model = new BoolFlagModel($this->pdo);
        $model->isActive = true;
        $model->label = 'first';
        $model->write();

        $this->assertEquals(1, $this->row($model->id)['is_active']);
    }

    public function testAFalseUpdateIsWrittenAsZero()
    {
        $model = new BoolFlagModel($this->pdo);
        $model->isActive = true;
        $model->label = 'first';
        $model->write();

        $model->isActive = false;
        $model->write();

        $this->assertEquals(0, $this->row($model->id)['is_active']);
    }

    public function testDynamicModeCreatesAColumnItsOwnWritePathCanWriteTo()
    {
        // The two halves of #66 meeting: the column a bool property is typed as, and
        // the literal a bool property is written as, have to agree.
        $this->pdo->exec('DROP TABLE IF EXISTS `dynamic_bool_flags`');

        $model = new DynamicBoolFlagModel($this->pdo);
        $model->emailVerified = false;
        $model->label = 'first';
        $model->write();

        $statement = $this->pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute(['dynamic_bool_flags', 'email_verified']);
        $this->assertEquals('tinyint(1)', $statement->fetchColumn());

        $statement = $this->pdo->prepare('SELECT email_verified FROM `dynamic_bool_flags` WHERE id = ?');
        $statement->execute([$model->id]);
        $this->assertEquals(0, $statement->fetchColumn());

        $this->pdo->exec('DROP TABLE IF EXISTS `dynamic_bool_flags`');
    }

    public function testABooleanSurvivesTheRoundTripAsTheValueItWas()
    {
        $model = new BoolFlagModel($this->pdo);
        $model->emailVerified = true;
        $model->isActive = false;
        $model->label = 'first';
        $model->write();

        $read = new BoolFlagModel($this->pdo);
        $read->read($model->id);

        $this->assertEquals(1, $read->emailVerified);
        $this->assertEquals(0, $read->isActive);
    }
}
