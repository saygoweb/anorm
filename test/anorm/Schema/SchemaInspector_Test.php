<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Schema\SchemaInspector;
use Anorm\Test\TestEnvironment;

/**
 * The schema as the database actually has it, which is one half of a diff.
 */
class SchemaInspector_Test extends TestCase
{
    /** @var \PDO */
    private $pdo;

    /** @var SchemaInspector */
    private $inspector;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
    }

    public function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
        $this->dropTables();
        $this->pdo->exec('CREATE TABLE insp_owners (id INT(11) AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE insp_things (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            owner_id INT(11) NULL,
            label VARCHAR(64) NOT NULL,
            CONSTRAINT fk_insp_things_owner_id FOREIGN KEY (owner_id) REFERENCES insp_owners(id)
        ) ENGINE=InnoDB');
        $this->inspector = new SchemaInspector($this->pdo);
    }

    public function tearDown(): void
    {
        $this->dropTables();
    }

    public function testColumns_ReportTypeAndNullability()
    {
        $columns = $this->inspector->columns('insp_things');

        $this->assertSame(['id', 'owner_id', 'label'], array_keys($columns), 'in the order the table declares them');
        $this->assertSame('varchar(64)', strtolower($columns['label']['type']));
        $this->assertFalse($columns['label']['nullable']);
        $this->assertTrue($columns['owner_id']['nullable']);
        $this->assertSame('PRI', $columns['id']['key']);
    }

    public function testColumnType_IsNullForAColumnThatIsNotThere()
    {
        $this->assertSame('int(11)', strtolower($this->inspector->columnType('insp_things', 'owner_id')));
        $this->assertNull($this->inspector->columnType('insp_things', 'nonesuch'));
    }

    public function testATableThatDoesNotExist_HasNoColumns()
    {
        $this->assertFalse($this->inspector->tableExists('insp_nonesuch'));
        $this->assertSame([], $this->inspector->columns('insp_nonesuch'));
    }

    public function testForeignKeys_AreKeyedByTheConstrainedColumn()
    {
        $keys = $this->inspector->foreignKeys('insp_things');

        $this->assertSame(['owner_id'], array_keys($keys));
        $this->assertSame('fk_insp_things_owner_id', $keys['owner_id']['constraint']);
        $this->assertSame('insp_owners', $keys['owner_id']['referencedTable']);
        $this->assertSame('id', $keys['owner_id']['referencedColumn']);
        $this->assertSame([], $this->inspector->foreignKeys('insp_owners'), 'nothing points out of this one');
    }

    public function testTheSecondLook_IsCachedUntilCleared()
    {
        $this->assertArrayHasKey('label', $this->inspector->columns('insp_things'));
        $this->assertArrayHasKey('owner_id', $this->inspector->foreignKeys('insp_things'));
        $this->assertSame(
            $this->inspector->foreignKeys('insp_things'),
            $this->inspector->foreignKeys('insp_things'),
            'asked twice, read once'
        );

        $this->pdo->exec('ALTER TABLE insp_things ADD note VARCHAR(16) NULL');
        $this->assertArrayNotHasKey('note', $this->inspector->columns('insp_things'), 'still the cached answer');

        $this->inspector->clearCache();
        $this->assertArrayHasKey('note', $this->inspector->columns('insp_things'));
        $this->assertArrayHasKey('owner_id', $this->inspector->foreignKeys('insp_things'));
    }

    private function dropTables()
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS insp_things');
        $this->pdo->exec('DROP TABLE IF EXISTS insp_owners');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
