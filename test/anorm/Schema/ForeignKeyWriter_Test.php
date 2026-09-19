<?php

namespace Anorm\Test\Schema;

require_once(__DIR__ . '/../../../vendor/autoload.php');

use Anorm\Relationship\Relationship;
use Anorm\Schema\ForeignKeyWriter;
use Anorm\Schema\RelationshipSchema;
use Anorm\Test\FkCompanyModel;
use Anorm\Test\FkHostingModel;
use Anorm\Test\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * A relationship kind the writer does not know how to put a key on a table for.
 * Anorm has three, but the base class is public and a consumer may add a fourth.
 */
class FkCustomRelationship extends Relationship
{
    public function getType()
    {
        return 'custom';
    }

    public function load($sourceModel, \PDO $pdo)
    {
        return null;
    }

    public function batchLoad(array $sourceModels, \PDO $pdo, ?array $fieldSelection = null): array
    {
        return [];
    }

    public function distributeBatchResults(array $sourceModels, array $batchResults): void
    {
    }

    public function estimateDataSize(int $sourceCount, ?array $fieldSelection = null): int
    {
        return 0;
    }

    public function getCardinality(): string
    {
        return 'one-to-one';
    }

    public function generateJoinClause($sourceTable, $relatedTable, $foreignKeyColumn = null, $primaryKeyColumn = null)
    {
        return '';
    }

    public function generateForeignKeyConstraints(
        $sourceTable,
        $targetTable = null,
        $foreignKeyColumn = null,
        $primaryKeyColumn = null
    ) {
        return [];
    }
}

/**
 * The paths a constraint takes when the database does not simply agree.
 */
class ForeignKeyWriter_Test extends TestCase
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
        foreach (['fk_hostings', 'fk_companies'] as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function constraintCount(string $table): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn();
    }

    public function testARelationshipKindThatPutsNoKeyOnATableIsSkipped()
    {
        $hosting = new FkHostingModel($this->pdo);
        $writer = new ForeignKeyWriter($this->pdo);

        $writer->createFromRelationship(
            $hosting->_mapper,
            new FkCustomRelationship(FkCompanyModel::class, 'whatever', 'companyId', 'id')
        );

        $this->assertSame(0, $this->constraintCount('fk_hostings'));
    }

    public function testAnIndexAlreadyOnTheColumnIsReusedRatherThanCollidedWith()
    {
        $this->pdo->exec('CREATE TABLE `fk_hostings` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            company_id INT(11) NULL,
            INDEX `fk_fk_hostings_company_id` (`company_id`)
        ) ENGINE=InnoDB');

        (new ForeignKeyWriter($this->pdo))->create($this->companySchema());

        $this->assertSame(1, $this->constraintCount('fk_hostings'));
    }

    public function testAConstraintNameTakenElsewhereInTheSchemaIsToleratedRatherThanFatal()
    {
        // InnoDB requires a foreign key name to be unique across the database, not
        // just the table, so a name taken by another table is a failure this one
        // cannot see coming. Re-running dynamic schema creation has to stay harmless.
        $this->pdo->exec('CREATE TABLE `fk_companies` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            parent_id INT(11) NULL,
            CONSTRAINT `fk_fk_hostings_company_id` FOREIGN KEY (`parent_id`) REFERENCES `fk_companies`(`id`)
        ) ENGINE=InnoDB');

        (new ForeignKeyWriter($this->pdo))->create($this->companySchema());

        $this->assertSame(0, $this->constraintCount('fk_hostings'), 'the name was taken, and that is survivable');
    }

    private function companySchema(): RelationshipSchema
    {
        $hosting = new FkHostingModel($this->pdo);
        $relationship = $hosting->_relationshipManager->getRelationship('company');
        return RelationshipSchema::forRelationship($hosting->_mapper, $relationship, $this->pdo);
    }

    public function testAConstraintAlreadyThereIsNotCreatedTwice()
    {
        $schema = $this->companySchema();
        $writer = new ForeignKeyWriter($this->pdo);

        $writer->create($schema);
        $writer->create($schema);

        $this->assertSame(1, $this->constraintCount('fk_hostings'));
    }
}
