<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\TableMaker;
use Anorm\Test\TestEnvironment;
use Anorm\Test\DynamicUserModel;

/**
 * A foreign key constraint that cannot be created used to be logged and forgotten,
 * so a declared belongsTo could end up with no constraint and nothing to notice it
 * by. These tests pin the two halves of the fix: the failure is raised, and a
 * constraint that already exists is still tolerated.
 *
 * @see https://github.com/saygoweb/anorm/issues/60
 */

/**
 * Carries a driver code and message the way PDO does, so the classifier can be
 * tested against the real codes MariaDB and MySQL return without provoking them.
 */
class ForeignKeyFailureTestException extends \PDOException
{
    public function __construct(string $sqlState, int $driverCode, string $driverMessage)
    {
        $this->code = $sqlState;
        $this->message = "SQLSTATE[$sqlState]: General error: $driverCode $driverMessage";
        $this->errorInfo = [$sqlState, $driverCode, $driverMessage];
    }
}

/** A PDOException with no errorInfo at all, which PDO does produce in some paths. */
class ForeignKeyFailureTestBareException extends \PDOException
{
    public function __construct(string $message)
    {
        $this->code = 'HY000';
        $this->message = $message;
    }
}

class ForeignKeyFailure_Test extends TestCase
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
        $this->dropTables();
    }

    public function tearDown(): void
    {
        $this->dropTables();
    }

    private function dropTables()
    {
        // dynamic_users_cascade belongs to DynamicForeignKey_Test, but it holds an INT
        // foreign key to dynamic_companies: left in place it makes the CREATE TABLE
        // below fail with errno 150 for a reason that has nothing to do with the test.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_posts');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_users');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_users_cascade');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_companies');
        // TableMaker's own table-name derivation produces this misspelling; see the PR notes.
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_companys');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ---------------------------------------------------------------- classifier

    public function testIsDuplicateConstraintError_MariaDbDuplicateName_True()
    {
        // MariaDB 10.11 reports a duplicate constraint name as 1005, errno 121.
        $e = new ForeignKeyFailureTestException(
            'HY000',
            1005,
            'Can\'t create table `anorm_test`.`dynamic_users` (errno: 121 "Duplicate key on write or update")'
        );
        $this->assertTrue(TableMaker::isDuplicateConstraintError($e));
    }

    public function testIsDuplicateConstraintError_MariaDbTypeMismatch_False()
    {
        // The case behind #60: same 1005, same SQLSTATE, different errno.
        $e = new ForeignKeyFailureTestException(
            'HY000',
            1005,
            'Can\'t create table `anorm_test`.`dynamic_users` (errno: 150 "Foreign key constraint is incorrectly formed")'
        );
        $this->assertFalse(TableMaker::isDuplicateConstraintError($e));
    }

    public function testIsDuplicateConstraintError_MySql8DuplicateName_True()
    {
        $e = new ForeignKeyFailureTestException('HY000', 1826, 'Duplicate foreign key constraint name \'fk_dynamic_users_company_id\'');
        $this->assertTrue(TableMaker::isDuplicateConstraintError($e));
    }

    public function testIsDuplicateConstraintError_DuplicateKeyName_True()
    {
        $e = new ForeignKeyFailureTestException('42000', 1061, 'Duplicate key name \'fk_dynamic_users_company_id\'');
        $this->assertTrue(TableMaker::isDuplicateConstraintError($e));
    }

    public function testIsDuplicateConstraintError_MissingColumn_False()
    {
        $e = new ForeignKeyFailureTestException('42000', 1072, 'Key column \'no_such_column\' doesn\'t exist in table');
        $this->assertFalse(TableMaker::isDuplicateConstraintError($e));
    }

    public function testIsDuplicateConstraintError_NoErrorInfoFallsBackToMessage()
    {
        $duplicate = new ForeignKeyFailureTestBareException('Duplicate foreign key constraint name \'fk_a_b\'');
        $this->assertTrue(TableMaker::isDuplicateConstraintError($duplicate));

        $other = new ForeignKeyFailureTestBareException('Foreign key constraint is incorrectly formed');
        $this->assertFalse(TableMaker::isDuplicateConstraintError($other));
    }

    // ------------------------------------------------------- Model::createForeignKey

    public function testWrite_ForeignKeyColumnTypeMismatch_Throws()
    {
        // The shape from #59: company_id was typed VARCHAR(128) by something other than
        // a write, so the constraint the model declares cannot be created.
        $this->pdo->exec('CREATE TABLE dynamic_companies (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128), address VARCHAR(128)) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE dynamic_users (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128), email VARCHAR(128), company_id VARCHAR(128)) ENGINE=InnoDB');

        $user = new DynamicUserModel($this->pdo);
        $user->name = 'John Doe';
        $user->email = 'john@example.com';
        $user->company_id = '1';

        try {
            $user->write();
            $this->fail('Expected a PDOException for the unusable foreign key constraint');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('errno: 150', $e->getMessage());
        }

        // And the constraint really is absent — the exception is not incidental.
        $this->assertFalse($this->foreignKeyExists('dynamic_users', 'fk_dynamic_users_company_id'));
    }

    public function testWrite_ConstraintAlreadyExists_DoesNotThrow()
    {
        // A second write must stay harmless now that the catch is narrow.
        $company = new \Anorm\Test\DynamicCompanyModel($this->pdo);
        $company->name = 'Tech Corp';
        $company->write();

        $user = new DynamicUserModel($this->pdo);
        $user->name = 'John Doe';
        $user->email = 'john@example.com';
        $user->company_id = $company->id;
        $user->write();

        $this->assertTrue($this->foreignKeyExists('dynamic_users', 'fk_dynamic_users_company_id'));

        $second = new DynamicUserModel($this->pdo);
        $second->name = 'Jane Doe';
        $second->email = 'jane@example.com';
        $second->company_id = $company->id;
        $second->write();

        $this->assertNotEmpty($second->id);
        $this->assertCount(1, $this->getForeignKeyConstraints('dynamic_users'));
    }

    // -------------------------------------------------- TableMaker::createForeignKey

    public function testTableMakerFix_ForeignKeyColumnTypeMismatch_Throws()
    {
        // TableMaker has its own copy of createForeignKey, reached from TableMaker::fix
        // rather than through Model::write, so it needs its own coverage. The HY000
        // foreign-key path is used because it works on an existing table, which lets the
        // mismatch be set up directly on the column the constraint is declared for.
        $this->pdo->exec('CREATE TABLE dynamic_users (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128), email VARCHAR(128), company_id VARCHAR(128)) ENGINE=InnoDB');

        $user = new DynamicUserModel($this->pdo);
        $fkTrouble = new ForeignKeyFailureTestException(
            'HY000',
            1005,
            'Can\'t create table `anorm_test`.`dynamic_users` (errno: 150 "foreign key constraint is incorrectly formed")'
        );

        try {
            TableMaker::fix($fkTrouble, $user->_mapper, $user);
            $this->fail('Expected a PDOException for the unusable foreign key constraint');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('errno: 150', $e->getMessage());
        }

        $this->assertFalse($this->foreignKeyExists('dynamic_users', 'fk_dynamic_users_company_id'));
    }

    // ------------------------------------------------------------------- helpers

    private function foreignKeyExists($tableName, $constraintName)
    {
        $sql = "SELECT COUNT(*) as count
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ?
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName, $constraintName]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result['count'] > 0;
    }

    private function getForeignKeyConstraints($tableName)
    {
        $sql = "SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME
                FROM information_schema.KEY_COLUMN_USAGE kcu
                WHERE kcu.TABLE_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = ?
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
