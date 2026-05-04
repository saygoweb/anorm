<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\QueryBuilder;
use Anorm\Test\SomeTableModel;
use Anorm\Test\TestEnvironment;
use Anorm\Test\UserModel;
use Anorm\Relationship\Performance\PerformanceMonitor;

class QueryBuilder_Test extends TestCase
{
    private $pdo;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
        TestEnvironment::loadRelationshipSchema();
    }

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function testConstruct_BogusClass_Throws()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("'\$creatable' is not a class");

        new QueryBuilder('bogus', null);
    }

    public function testFunctions_ReturnThis()
    {
        $pdo = TestEnvironment::pdo();
        $o = new QueryBuilder(SomeTableModel::class, $pdo);
        $result = $o->select('');
        $this->assertSame($o, $result);
        $result = $o->from('');
        $this->assertSame($o, $result);
        $result = $o->join('');
        $this->assertSame($o, $result);
        $result = $o->where('', []);
        $this->assertSame($o, $result);
        $result = $o->groupBy('');
        $this->assertSame($o, $result);
        $result = $o->having('');
        $this->assertSame($o, $result);
        $result = $o->orderBy('');
        $this->assertSame($o, $result);
        $result = $o->limit('');
        $this->assertSame($o, $result);
    }

    public function testWith_StringInput_ConvertsToArray()
    {
        $qb = new QueryBuilder(UserModel::class, $this->pdo);
        $result = $qb->with('posts'); // string, not array
        $this->assertSame($qb, $result);
    }

    public function testWith_InvalidSpec_CircularRef_ThrowsInvalidArgumentException()
    {
        $qb = new QueryBuilder(UserModel::class, $this->pdo);
        $this->expectException(\InvalidArgumentException::class);
        $qb->with(['posts.posts']); // 'posts' appears twice → circular reference error
    }

    public function testWith_EmptyString_ThrowsInvalidArgumentException()
    {
        $qb = new QueryBuilder(UserModel::class, $this->pdo);
        $this->expectException(\InvalidArgumentException::class);
        $qb->with(['']);
    }

    public function testJoinRelationship_UndefinedRelationship_Throws()
    {
        $qb = new QueryBuilder(UserModel::class, $this->pdo);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Relationship 'nonexistent' not defined");
        $qb->joinRelationship('nonexistent');
    }

    public function testJoinRelationship_ValidRelationship_ReturnsSelf()
    {
        $qb = new QueryBuilder(UserModel::class, $this->pdo);
        $result = $qb->joinRelationship('posts');
        $this->assertSame($qb, $result);
    }

    public function testJoinRelationship_CustomJoinType_ReturnsSelf()
    {
        $qb = new QueryBuilder(UserModel::class, $this->pdo);
        $result = $qb->joinRelationship('posts', 'INNER');
        $this->assertSame($qb, $result);
    }

    public function testSetBatchLoadingConfig_ReturnsSelf()
    {
        $qb = new QueryBuilder(UserModel::class, $this->pdo);
        $result = $qb->setBatchLoadingConfig(['batch_size' => 100]);
        $this->assertSame($qb, $result);
    }

    public function testSetPerformanceMonitor_ReturnsSelf()
    {
        $qb = new QueryBuilder(UserModel::class, $this->pdo);
        $result = $qb->setPerformanceMonitor(new PerformanceMonitor());
        $this->assertSame($qb, $result);
    }

    public function testSome_WithPerformanceMonitorAndEagerLoad_ReturnsModels()
    {
        $models = iterator_to_array(
            (new QueryBuilder(UserModel::class, $this->pdo))
                ->setPerformanceMonitor(new PerformanceMonitor())
                ->with(['posts'])
                ->some()
        );

        $this->assertNotEmpty($models);
        $this->assertInstanceOf(UserModel::class, $models[0]);
    }

    public function testSome_WithNestedRelationships_LoadsWithoutException()
    {
        $models = iterator_to_array(
            (new QueryBuilder(UserModel::class, $this->pdo))
                ->with(['posts.comments'])
                ->some()
        );

        $this->assertNotEmpty($models);
        $this->assertInstanceOf(UserModel::class, $models[0]);
    }
}
