<?php

namespace Anorm\Test\Schema;

require_once(__DIR__ . '/../../../vendor/autoload.php');

use Anorm\QueryBuilder;
use Anorm\Test\FkCompanyModel;
use Anorm\Test\FkHostingModel;
use Anorm\Test\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The identifiers dynamic mode writes into a foreign key constraint.
 *
 * Everything here goes through the read-before-write path. `Model::write()` creates
 * its own constraints first and would mask what `TableMaker` does on its own, which
 * is how both of these bugs stayed invisible: the suite only ever wrote first.
 */
class ForeignKeyIdentifiers_Test extends TestCase
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

    private function dropTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['fk_hostings', 'fk_companies', 'fk_companys', 'fkcompanys'] as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * A lookup against a table that does not exist yet, which is what hands schema
     * creation to TableMaker with no prior write to have fixed things up.
     */
    private function readBeforeAnyWrite(): void
    {
        $hosting = new FkHostingModel($this->pdo);
        $hosting->read(1);
    }

    /** @return array<int, array<string, mixed>> */
    private function foreignKeys(string $table): array
    {
        $sql = "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$table]);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, string> */
    private function columns(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        return $statement->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function columnType(string $table, string $column): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        $type = $statement->fetchColumn();
        return $type === false ? null : (string) $type;
    }

    // Issue #70: the foreign key names a property, and the constraint needs a column.

    public function testConstraintIsPutOnTheColumnThePropertyMapsTo()
    {
        $this->readBeforeAnyWrite();

        $keys = $this->foreignKeys('fk_hostings');
        $this->assertCount(1, $keys);
        $this->assertEquals('company_id', $keys[0]['COLUMN_NAME']);
    }

    public function testNoColumnIsInventedFromThePropertyName()
    {
        $this->readBeforeAnyWrite();

        $this->assertNotContains(
            'companyId',
            $this->columns('fk_hostings'),
            'a column named after the property is one nothing ever writes to'
        );
    }

    public function testTheForeignKeyColumnIsTypedToMatchTheKeyItReferences()
    {
        $this->readBeforeAnyWrite();

        // Created by the foreign key machinery as INT, not later from a sampled
        // lastInsertId(), which is a string and would type the column VARCHAR.
        $this->assertEquals('int', $this->columnType('fk_hostings', 'company_id'));
    }

    public function testConstraintIsNamedAfterTheColumn()
    {
        $this->readBeforeAnyWrite();

        $keys = $this->foreignKeys('fk_hostings');
        $this->assertEquals('fk_fk_hostings_company_id', $keys[0]['CONSTRAINT_NAME']);
    }

    // Issue #64: the referenced table is derived by string mangling, and acted on.

    public function testConstraintReferencesTheTableTheRelatedModelMapsTo()
    {
        $this->readBeforeAnyWrite();

        $keys = $this->foreignKeys('fk_hostings');
        $this->assertEquals('fk_companies', $keys[0]['REFERENCED_TABLE_NAME']);
        $this->assertEquals('id', $keys[0]['REFERENCED_COLUMN_NAME']);
    }

    public function testNoTableIsInventedByPluralizingTheClassName()
    {
        $this->readBeforeAnyWrite();

        $statement = $this->pdo->query("SHOW TABLES LIKE 'fk%compan%'");
        $tables = $statement->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertEquals(['fk_companies'], array_values($tables));
    }

    public function testTheConstraintActuallyConstrains()
    {
        $this->readBeforeAnyWrite();

        $company = new FkCompanyModel($this->pdo);
        $company->name = 'Acme';
        $company->write();

        $hosting = new FkHostingModel($this->pdo);
        $hosting->companyId = $company->id;
        $hosting->label = 'web1';
        $hosting->write();

        $row = $this->pdo->query('SELECT * FROM `fk_hostings`')->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals($company->id, $row['company_id']);

        $orphan = new FkHostingModel($this->pdo);
        $orphan->companyId = 9999;
        $orphan->label = 'orphan';
        $this->expectException(\PDOException::class);
        $orphan->write();
    }

    // The same split, where the name becomes a JOIN rather than a constraint.

    public function testAJoinOnTheRelationshipNamesAColumnTheTableHas()
    {
        $this->readBeforeAnyWrite();

        $company = new FkCompanyModel($this->pdo);
        $company->name = 'Acme';
        $company->write();

        $hosting = new FkHostingModel($this->pdo);
        $hosting->companyId = $company->id;
        $hosting->label = 'web1';
        $hosting->write();

        $found = [];
        $rows = (new QueryBuilder(FkHostingModel::class, $this->pdo))
            ->select('`fk_hostings`.*')
            ->joinRelationship('company')
            ->where('`fk_companies`.`name` = ?', ['Acme'])
            ->some();
        foreach ($rows as $row) {
            $found[] = $row;
        }

        $this->assertCount(1, $found);
        $this->assertEquals('web1', $found[0]->label);
    }
}
