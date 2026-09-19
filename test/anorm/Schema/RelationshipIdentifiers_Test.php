<?php

namespace Anorm\Test\Schema;

require_once(__DIR__ . '/../../../vendor/autoload.php');

use Anorm\Schema\RelationshipSchema;
use Anorm\Test\FkCompanyModel;
use Anorm\Test\FkHostingModel;
use Anorm\Test\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The identifiers a relationship resolves to, away from the database.
 *
 * `FkHostingModel` names its foreign key as the camelCase property `companyId`, which
 * maps to the column `company_id`, and points at a class whose name pluralizes to
 * `fk_companies` only under a rule one of the three copies of the derivation did not
 * have.
 */
class RelationshipIdentifiers_Test extends TestCase
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
    }

    public function testBelongsToResolvesTheForeignKeyPropertyToItsColumn()
    {
        $hosting = new FkHostingModel($this->pdo);
        $relationship = $hosting->_relationshipManager->getRelationship('company');

        $schema = RelationshipSchema::forRelationship($hosting->_mapper, $relationship, $this->pdo);

        $this->assertEquals('fk_hostings', $schema->table);
        $this->assertEquals('company_id', $schema->column);
    }

    public function testBelongsToTakesTheReferencedTableFromTheRelatedModel()
    {
        $hosting = new FkHostingModel($this->pdo);
        $relationship = $hosting->_relationshipManager->getRelationship('company');

        $schema = RelationshipSchema::forRelationship($hosting->_mapper, $relationship, $this->pdo);

        $this->assertEquals('fk_companies', $schema->referencedTable);
        $this->assertEquals('id', $schema->referencedColumn);
    }

    public function testConstraintNameCarriesTheColumnNotTheProperty()
    {
        $hosting = new FkHostingModel($this->pdo);
        $relationship = $hosting->_relationshipManager->getRelationship('company');

        $schema = RelationshipSchema::forRelationship($hosting->_mapper, $relationship, $this->pdo);

        $this->assertEquals('fk_fk_hostings_company_id', $schema->constraintName);
    }

    public function testDerivedTableNamePluralizesAWordEndingInY()
    {
        $this->assertEquals('fk_companies', RelationshipSchema::deriveTableName(FkCompanyModel::class));
    }

    public function testDerivedTableNameSplitsAWordFollowingADigit()
    {
        $this->assertEquals('t64_companies', RelationshipSchema::deriveTableName('T64CompanyModel'));
    }

    public function testTableForClassFallsBackToDerivationWhenTheModelCannotBeBuilt()
    {
        $this->assertEquals(
            'no_such_companies',
            RelationshipSchema::tableForClass('Anorm\Test\NoSuchCompanyModel', $this->pdo),
            'a class that cannot be constructed still has to resolve to something'
        );
    }

    public function testGeneratedConstraintSqlSpellsTheForeignKeyAsAColumn()
    {
        $hosting = new FkHostingModel($this->pdo);
        $relationship = $hosting->_relationshipManager->getRelationship('company');

        $sql = $relationship->generateForeignKeyConstraints('fk_hostings', 'fk_companies', 'company_id')[0];

        $this->assertStringContainsString('FOREIGN KEY (`company_id`)', $sql);
        $this->assertStringContainsString('`fk_fk_hostings_company_id`', $sql);
        $this->assertStringContainsString('REFERENCES `fk_companies`', $sql);
        $this->assertStringNotContainsString('companyId', $sql);
    }

    public function testGeneratedConstraintSqlKeepsTheDeclaredSpellingWhenNothingResolvesIt()
    {
        $hosting = new FkHostingModel($this->pdo);
        $relationship = $hosting->_relationshipManager->getRelationship('company');

        $sql = $relationship->generateForeignKeyConstraints('fk_hostings')[0];

        $this->assertStringContainsString('FOREIGN KEY (`companyId`)', $sql);
    }

    public function testTableForClassPrefersWhatTheModelSaysOverTheDerivation()
    {
        // FkHostingModel derives to `fk_hostings` either way, so use the model that
        // proves the preference: one whose mapper table is set to something the
        // derivation would not reach.
        $this->assertEquals(
            'fk_companies',
            RelationshipSchema::tableForClass(FkCompanyModel::class, $this->pdo)
        );
    }
}
