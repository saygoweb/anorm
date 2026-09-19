<?php

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/TmModels.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;
use Anorm\TableMaker;
use Anorm\Test\TestEnvironment;
use Anorm\Test\TmItemModel;
use Anorm\Test\TmParentModel;

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

    protected function tearDown(): void
    {
        $this->dropTmTables();
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

    // Inference is a best guess, but a guess can still be wrong in ways that cost data.
    // These pin the cases from #56, #57 and #58.

    public function testColumnDefinition_Zero_IsNotVarchar()
    {
        // A counter or balance whose first row is 0 used to type the column VARCHAR(128).
        $this->assertEquals('INT(11) NULL', TableMaker::columnDefinition('balance', 0));
    }

    public function testColumnDefinition_ZeroFloat_IsNotVarchar()
    {
        $this->assertEquals('DOUBLE NULL', TableMaker::columnDefinition('rate', 0.0));
    }

    public function testColumnDefinition_False_IsBoolean()
    {
        $this->assertEquals('TINYINT(1) NULL', TableMaker::columnDefinition('is_active', false));
    }

    public function testColumnDefinition_True_IsBoolean()
    {
        $this->assertEquals('TINYINT(1) NULL', TableMaker::columnDefinition('is_active', true));
    }

    public function testColumnDefinition_EmptyString_IsVarchar()
    {
        $this->assertEquals('VARCHAR(128)', TableMaker::columnDefinition('note', ''));
    }

    public function testColumnDefinition_Null_IsVarchar()
    {
        // null really is an absence of information, so the fallback is the right answer.
        $this->assertEquals('VARCHAR(128)', TableMaker::columnDefinition('note', null));
    }

    public function testColumnDefinition_NumericString_IsVarchar()
    {
        // '0' and '42' are strings the application chose to keep as strings.
        $this->assertEquals('VARCHAR(128)', TableMaker::columnDefinition('code', '0'));
        $this->assertEquals('VARCHAR(128)', TableMaker::columnDefinition('code', '42'));
    }

    public function testColumnDefinition_LargeInteger_IsBigInt()
    {
        // 10 GiB as bytes: INT(11) stops at 2147483647 and would clamp or be rejected.
        $this->assertEquals('BIGINT(20) NULL', TableMaker::columnDefinition('disk_quota', 10737418240));
        $this->assertEquals('BIGINT(20) NULL', TableMaker::columnDefinition('offset', -10737418240));
    }

    public function testColumnDefinition_IntegerBoundaries_StayInt()
    {
        $this->assertEquals('INT(11) NULL', TableMaker::columnDefinition('n', 2147483647));
        $this->assertEquals('INT(11) NULL', TableMaker::columnDefinition('n', -2147483648));
        $this->assertEquals('BIGINT(20) NULL', TableMaker::columnDefinition('n', 2147483648));
    }

    public function testColumnDefinition_StringContainingDate_IsNotDatetime()
    {
        // The #58 casualty: an error column whose first value happens to mention a date.
        $this->assertEquals(
            'VARCHAR(128)',
            TableMaker::columnDefinition('last_error', 'registrar refused on 2026-09-19 at 10:00')
        );
    }

    public function testColumnDefinition_WholeValueIsDate_IsDatetime()
    {
        $this->assertEquals('DATETIME NULL', TableMaker::columnDefinition('dtc', '2026-09-19'));
        $this->assertEquals('DATETIME NULL', TableMaker::columnDefinition('dtc', '2026-09-19 10:00'));
        $this->assertEquals('DATETIME NULL', TableMaker::columnDefinition('dtc', '2026-09-19 10:00:00'));
        $this->assertEquals('DATETIME NULL', TableMaker::columnDefinition('dtc', '2026-09-19T10:00:00'));
    }

    public function testColumnDefinition_DateLikeButNotADate_IsVarchar()
    {
        $this->assertEquals('VARCHAR(128)', TableMaker::columnDefinition('ref', '2026-09-19-extra'));
        $this->assertEquals('VARCHAR(128)', TableMaker::columnDefinition('ref', 'v2026-09-19'));
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

    private function dropTmTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS tm_items');
        $this->pdo->exec('DROP TABLE IF EXISTS tm_parents');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function tmForeignKeyExists(string $table, string $constraint): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->execute([$table, $constraint]);
        return (bool) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt'];
    }

    public function testFix_23000_WithBelongsToModel_CreatesForeignKey()
    {
        $this->dropTmTables();
        $this->pdo->exec(
            'CREATE TABLE tm_parents (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NULL) ENGINE=InnoDB'
        );
        $this->pdo->exec(
            'CREATE TABLE tm_items (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NULL) ENGINE=InnoDB'
        );

        $child = new TmItemModel($this->pdo);
        $e = new TableMakerTestExceptionWithMessage(
            '23000',
            'Cannot add or update a child row: a foreign key constraint fails'
        );

        TableMaker::fix($e, $child->_mapper, $child);

        $this->assertTrue(
            $this->tmForeignKeyExists('tm_items', 'fk_tm_items_parent_id'),
            'Expected FK constraint fk_tm_items_parent_id to be created on tm_items'
        );
    }

    public function testFix_HY000_WithFkMessageAndModel_CreatesForeignKey()
    {
        $this->dropTmTables();
        $this->pdo->exec(
            'CREATE TABLE tm_parents (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NULL) ENGINE=InnoDB'
        );
        $this->pdo->exec(
            'CREATE TABLE tm_items (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NULL) ENGINE=InnoDB'
        );

        $child = new TmItemModel($this->pdo);
        $e = new TableMakerTestExceptionWithMessage(
            'HY000',
            'General error: 1215 Cannot add foreign key constraint'
        );

        TableMaker::fix($e, $child->_mapper, $child);

        $this->assertTrue(
            $this->tmForeignKeyExists('tm_items', 'fk_tm_items_parent_id'),
            'Expected FK constraint to be created via HY000 + FK message path'
        );
    }

    public function testFix_ForeignKeyAlreadyExists_DoesNotDuplicate()
    {
        $this->dropTmTables();
        $this->pdo->exec(
            'CREATE TABLE tm_parents (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NULL) ENGINE=InnoDB'
        );
        $this->pdo->exec(
            'CREATE TABLE tm_items (
                id INT(11) AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(128) NULL,
                parent_id INT(11) NULL,
                CONSTRAINT fk_tm_items_parent_id FOREIGN KEY (parent_id) REFERENCES tm_parents(id)
             ) ENGINE=InnoDB'
        );

        $child = new TmItemModel($this->pdo);
        $e = new TableMakerTestExceptionWithMessage(
            '23000',
            'Cannot add or update a child row: a foreign key constraint fails'
        );

        // Should not throw even though FK already exists (idempotency guard in foreignKeyExists())
        TableMaker::fix($e, $child->_mapper, $child);

        // Confirm FK still exists and wasn't doubled up
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tm_items'
             AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->execute();
        $this->assertEquals(1, (int) $stmt->fetch(\PDO::FETCH_ASSOC)['cnt'], 'Expected exactly one FK on tm_items');
    }

    public function testFix_HasManyRelationship_CreatesForeignKeyOnRelatedTable()
    {
        $this->dropTmTables();
        $this->pdo->exec(
            'CREATE TABLE tm_parents (id INT(11) AUTO_INCREMENT PRIMARY KEY, name VARCHAR(128) NULL) ENGINE=InnoDB'
        );

        $parent = new TmParentModel($this->pdo);
        $e = new TableMakerTestExceptionWithMessage(
            '23000',
            'Cannot add or update a child row: a foreign key constraint fails'
        );

        // The hasMany relationship on TmParentModel creates FK on tm_items referencing tm_parents.
        // getTableNameFromModelClass('Anorm\Test\TmItemModel') must resolve to 'tm_items'.
        TableMaker::fix($e, $parent->_mapper, $parent);

        $this->assertTrue(
            $this->tmForeignKeyExists('tm_items', 'fk_tm_items_parent_id'),
            'Expected FK on tm_items (auto-created by ensureTableExists) referencing tm_parents'
        );
    }
}
