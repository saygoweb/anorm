<?php

namespace Anorm\Test;

require_once(__DIR__ . '/../../vendor/autoload.php');

use Anorm\DataMapper;
use Anorm\Model;
use PHPUnit\Framework\TestCase;

/**
 * Exposes protected convenience methods for direct testing.
 */
class ExposedModel extends UserModel
{
    public function publicSetNullDelete(): array
    {
        return $this->setNullDelete();
    }

    public function publicRestrictDelete(): array
    {
        return $this->restrictDelete();
    }

    public function publicConstraintOptions(
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'CASCADE',
        ?string $constraintName = null
    ): array {
        return $this->constraintOptions($onDelete, $onUpdate, $constraintName);
    }

    public function publicCascadeDelete(): array
    {
        return $this->cascadeDelete();
    }
}

/**
 * Model that uses hasMany/belongsTo/hasManyThrough WITHOUT explicit property names.
 * Auto-generated names: 'posts' (hasMany PostModel), 'company' (belongsTo CompanyModel),
 * 'tags' (hasManyThrough TagModel).
 */
class AutoNameModel extends Model
{
    public $id;
    public $company_id;

    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'users';
        parent::__construct($pdo, $mapper);

        // No $propertyName argument -> triggers getPropertyNameFromClass()
        $this->hasMany(PostModel::class, 'user_id');
        $this->belongsTo(CompanyModel::class, 'company_id');
        $this->hasManyThrough(TagModel::class, 'post_id', 'tag_id', 'post_tags');
    }
}

class Model_ConvenienceMethods_Test extends TestCase
{
    /** @var \PDO */
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    // -----------------------------------------------------------------
    // setNullDelete / restrictDelete / constraintOptions
    // -----------------------------------------------------------------

    public function testSetNullDelete_ReturnsCorrectOptions()
    {
        $model = new ExposedModel($this->pdo);
        $result = $model->publicSetNullDelete();
        $this->assertEquals([
            'constraints' => [
                'on_delete' => 'SET NULL',
                'on_update' => 'CASCADE',
            ]
        ], $result);
    }

    public function testRestrictDelete_ReturnsCorrectOptions()
    {
        $model = new ExposedModel($this->pdo);
        $result = $model->publicRestrictDelete();
        $this->assertEquals([
            'constraints' => [
                'on_delete' => 'RESTRICT',
                'on_update' => 'CASCADE',
            ]
        ], $result);
    }

    public function testConstraintOptions_CustomValues()
    {
        $model = new ExposedModel($this->pdo);
        $result = $model->publicConstraintOptions('CASCADE', 'RESTRICT', 'my_constraint');
        $this->assertEquals([
            'constraints' => [
                'on_delete' => 'CASCADE',
                'on_update' => 'RESTRICT',
                'constraint_name' => 'my_constraint',
            ]
        ], $result);
    }

    public function testConstraintOptions_WithConstraintName_IncludesName()
    {
        $model = new ExposedModel($this->pdo);
        $result = $model->publicConstraintOptions('SET NULL', 'CASCADE', 'fk_custom');
        $this->assertArrayHasKey('constraint_name', $result['constraints']);
        $this->assertEquals('fk_custom', $result['constraints']['constraint_name']);
    }

    // -----------------------------------------------------------------
    // Auto-generated property names via hasMany / belongsTo / hasManyThrough
    // -----------------------------------------------------------------

    public function testHasMany_NoPropertyName_AutoGeneratesPluralName()
    {
        $model = new AutoNameModel($this->pdo);
        $manager = $model->getRelationshipManager();

        $rel = $manager->getRelationship('posts');
        $this->assertNotNull($rel, "Expected auto-generated relationship name 'posts'");
        $this->assertEquals('Anorm\Test\PostModel', $rel->getRelatedModelClass());
    }

    public function testBelongsTo_NoPropertyName_AutoGeneratesSingularName()
    {
        $model = new AutoNameModel($this->pdo);
        $manager = $model->getRelationshipManager();

        $rel = $manager->getRelationship('company');
        $this->assertNotNull($rel, "Expected auto-generated relationship name 'company'");
        $this->assertEquals('Anorm\Test\CompanyModel', $rel->getRelatedModelClass());
    }

    public function testHasManyThrough_NoPropertyName_AutoGeneratesPluralName()
    {
        $model = new AutoNameModel($this->pdo);
        $manager = $model->getRelationshipManager();

        $rel = $manager->getRelationship('tags');
        $this->assertNotNull($rel, "Expected auto-generated relationship name 'tags'");
        $this->assertEquals('Anorm\Test\TagModel', $rel->getRelatedModelClass());
    }

    // -----------------------------------------------------------------
    // createForeignKeyConstraints() early-return (non-dynamic mode)
    // -----------------------------------------------------------------

    /**
     * @doesNotPerformAssertions
     */
    public function testCreateForeignKeyConstraints_NonDynamicMode_DoesNothing()
    {
        $user = new UserModel($this->pdo);
        $user->createForeignKeyConstraints();
    }

    public function testCascadeDelete_ReturnsCorrectOptions()
    {
        $model = new ExposedModel($this->pdo);
        $result = $model->publicCascadeDelete();
        $this->assertEquals([
            'constraints' => [
                'on_delete' => 'CASCADE',
                'on_update' => 'CASCADE',
            ]
        ], $result);
    }
}
