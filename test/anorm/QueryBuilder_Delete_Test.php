<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\DataMapper;
use Anorm\MangoQuery;
use Anorm\QueryBuilder;
use Anorm\SqlCondition;
use Anorm\Test\Lifecycle\RecordingDeleteListener;
use Anorm\Test\SomeTableModel;
use Anorm\Test\TestEnvironment;
use Anorm\Test\UserModel;

class QueryBuilder_Delete_Test extends TestCase
{
    private $pdo;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
        TestEnvironment::loadRelationshipSchema();
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `some_table`');
        $pdo->query(file_get_contents(__DIR__ . '/TestSchema.sql'));
    }

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
        $this->pdo->query('TRUNCATE TABLE `some_table`');

        $this->makeRow('alice', 'a', '2023-01-01');
        $this->makeRow('bob', 'a', '2023-02-01');
        $this->makeRow('charlie', 'b', '2023-03-01');
        $this->makeRow('dave', 'b', '2023-04-01');
    }

    protected function tearDown(): void
    {
        DataMapper::setChangeListener(null);
        DataMapper::setDeleteListener(null);
    }

    private function makeRow($name, $category, $dtc)
    {
        $model = new SomeTableModel();
        $model->name = $name;
        $model->category = $category;
        $model->dtc = $dtc;
        $model->write();
        return $model;
    }

    private function find(): QueryBuilder
    {
        return DataMapper::find(SomeTableModel::class, $this->pdo);
    }

    /** @return string[] The names left in the table, in primary key order. */
    private function remainingNames(): array
    {
        $names = [];
        foreach ($this->find()->orderBy('some_id')->some() as $model) {
            $names[] = $model->name;
        }
        return $names;
    }

    public function testDelete_MatchingRows_DeletesThemAndReturnsCount()
    {
        $deleted = $this->find()
            ->where('category = :category', [':category' => 'a'])
            ->delete();

        $this->assertSame(2, $deleted);
        $this->assertSame(['charlie', 'dave'], $this->remainingNames());
    }

    public function testDelete_NothingMatches_ReturnsZeroAndDeletesNothing()
    {
        $deleted = $this->find()
            ->where('category = :category', [':category' => 'nope'])
            ->delete();

        $this->assertSame(0, $deleted);
        $this->assertCount(4, $this->remainingNames());
    }

    public function testDelete_PositionalParameters_AreBound()
    {
        $deleted = $this->find()
            ->where('category = ? AND dtc < ?', ['b', '2023-03-15'])
            ->delete();

        $this->assertSame(1, $deleted);
        $this->assertSame(['alice', 'bob', 'dave'], $this->remainingNames());
    }

    public function testDelete_NoWhere_ThrowsAndDeletesNothing()
    {
        try {
            $this->find()->delete();
            $this->fail('delete() without a condition should throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('where()', $e->getMessage());
        }
        $this->assertCount(4, $this->remainingNames());
    }

    public function testDelete_OrderByAndLimitWithoutWhere_ThrowsAndDeletesNothing()
    {
        try {
            $this->find()->orderBy('some_id')->limit(2)->delete();
            $this->fail('delete() without a condition should throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('where()', $e->getMessage());
        }
        $this->assertCount(4, $this->remainingNames());
    }

    public function testDelete_BlankWhere_ThrowsAndDeletesNothing()
    {
        try {
            $this->find()->where('  ')->delete();
            $this->fail('delete() with a blank condition should throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('where()', $e->getMessage());
        }
        $this->assertCount(4, $this->remainingNames());
    }

    public function testDelete_EmptyMangoSelector_ThrowsAndDeletesNothing()
    {
        try {
            $this->find()
                ->byMango(MangoQuery::fromArray([MangoQuery::MANGO_SELECTOR => []]))
                ->delete();
            $this->fail('delete() with an empty Mango selector should throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('where()', $e->getMessage());
        }
        $this->assertCount(4, $this->remainingNames());
    }

    public function testDelete_SqlInjectionInBoundValue_DeletesNothing()
    {
        // Interpolated, this becomes  WHERE name = 'x' OR '1'='1'  and empties the
        // table. Bound, the whole payload is one value that matches no name.
        $deleted = $this->find()
            ->where('name = :name', [':name' => "x' OR '1'='1"])
            ->delete();

        $this->assertSame(0, $deleted);
        $this->assertCount(4, $this->remainingNames());
    }

    public function testDelete_OrderByAndLimit_DeletesTheFirstN()
    {
        $deleted = $this->find()
            ->where('some_id > :id', [':id' => 0])
            ->orderBy('dtc DESC')
            ->limit(3)
            ->delete();

        $this->assertSame(3, $deleted);
        $this->assertSame(['alice'], $this->remainingNames());
    }

    public function testDelete_LimitAboveMatchCount_DeletesOnlyMatches()
    {
        $deleted = $this->find()
            ->where('category = :category', [':category' => 'a'])
            ->orderBy('some_id')
            ->limit(10)
            ->delete();

        $this->assertSame(2, $deleted);
        $this->assertSame(['charlie', 'dave'], $this->remainingNames());
    }

    public function testDelete_LimitInBatches_EmptiesTheMatchingSet()
    {
        $total = 0;
        do {
            $deleted = $this->find()
                ->where('some_id > :id', [':id' => 0])
                ->orderBy('some_id')
                ->limit(3)
                ->delete();
            $total += $deleted;
        } while ($deleted > 0);

        $this->assertSame(4, $total);
        $this->assertSame([], $this->remainingNames());
    }

    public function testDelete_LimitWithWhereMentioningLimit_StillLimits()
    {
        // A read skips limit() when the SQL so far already contains the word
        // LIMIT. A delete must not: dropping the limit would delete more rows
        // than the caller asked for.
        $deleted = $this->find()
            ->where("name <> :name", [':name' => 'limit'])
            ->orderBy('some_id')
            ->limit(1)
            ->delete();

        $this->assertSame(1, $deleted);
        $this->assertSame(['bob', 'charlie', 'dave'], $this->remainingNames());
    }

    public function testDelete_LimitWithOffset_ThrowsAndDeletesNothing()
    {
        try {
            $this->find()
                ->where('some_id > :id', [':id' => 0])
                ->orderBy('some_id')
                ->limit(2, 1)
                ->delete();
            $this->fail('delete() with an offset should throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('offset', $e->getMessage());
        }
        $this->assertCount(4, $this->remainingNames());
    }

    public function testDelete_NonIntegerLimit_ThrowsAndDeletesNothing()
    {
        try {
            $this->find()
                ->where('some_id > :id', [':id' => 0])
                ->limit('1; DROP TABLE some_table')
                ->delete();
            $this->fail('delete() with a non-integer limit should throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('limit()', $e->getMessage());
        }
        $this->assertCount(4, $this->remainingNames());
    }

    public function testDelete_MangoSkip_ThrowsAndDeletesNothing()
    {
        try {
            $this->find()
                ->byMango(MangoQuery::fromArray([
                    MangoQuery::MANGO_SELECTOR => ['category' => 'a'],
                    MangoQuery::MANGO_LIMIT => 1,
                    MangoQuery::MANGO_SKIP => 1,
                ]))
                ->delete();
            $this->fail('delete() with a Mango skip should throw');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('offset', $e->getMessage());
        }
        $this->assertCount(4, $this->remainingNames());
    }

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public function unsupportedClauses(): array
    {
        return [
            'select' => ['select()', function (QueryBuilder $qb) {
                return $qb->select('name')->where('category = :c', [':c' => 'a']);
            }],
            'from' => ['from()', function (QueryBuilder $qb) {
                return $qb->from('`some_table`')->where('category = :c', [':c' => 'a']);
            }],
            'join' => ['join()', function (QueryBuilder $qb) {
                return $qb->join('JOIN `users` ON `users`.`id` = `some_table`.`some_id`')->where('category = :c', [':c' => 'a']);
            }],
            'groupBy' => ['groupBy()', function (QueryBuilder $qb) {
                return $qb->where('category = :c', [':c' => 'a'])->groupBy('category');
            }],
            'having' => ['having()', function (QueryBuilder $qb) {
                return $qb->where('category = :c', [':c' => 'a'])->having('COUNT(*) > 0');
            }],
            'mango fields' => ['select()', function (QueryBuilder $qb) {
                return $qb->byMango(MangoQuery::fromArray([
                    MangoQuery::MANGO_SELECTOR => ['category' => 'a'],
                    MangoQuery::MANGO_FIELDS => ['name'],
                ]));
            }],
        ];
    }

    /**
     * @dataProvider unsupportedClauses
     */
    public function testDelete_UnsupportedClause_ThrowsNamingItAndDeletesNothing(string $clause, callable $build)
    {
        $qb = $build($this->find());
        try {
            $qb->delete();
            $this->fail("delete() after $clause should throw");
        } catch (\LogicException $e) {
            $this->assertStringContainsString($clause, $e->getMessage());
        }
        $this->assertCount(4, $this->remainingNames());
    }

    public function testDelete_With_ThrowsNamingIt()
    {
        $qb = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts'])
            ->where('id = :id', [':id' => -1]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('with()');
        $qb->delete();
    }

    public function testDelete_JoinRelationship_ThrowsNamingIt()
    {
        $qb = DataMapper::find(UserModel::class, $this->pdo)
            ->joinRelationship('posts')
            ->where('`users`.`id` = :id', [':id' => -1]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('joinRelationship()');
        $qb->delete();
    }

    public function testDelete_MangoCondition_DeletesMatchingRows()
    {
        $deleted = $this->find()
            ->byMango(MangoQuery::fromArray([
                MangoQuery::MANGO_SELECTOR => ['category' => 'b', 'dtc' => ['$gte' => '2023-04-01']],
            ]))
            ->delete();

        $this->assertSame(1, $deleted);
        $this->assertSame(['alice', 'bob', 'charlie'], $this->remainingNames());
    }

    public function testDelete_MangoSortAndLimit_DeletesTheFirstN()
    {
        $deleted = $this->find()
            ->byMango(MangoQuery::fromArray([
                MangoQuery::MANGO_SELECTOR => ['someId' => ['$gt' => 0]],
                MangoQuery::MANGO_SORT => [['dtc' => 'desc']],
                MangoQuery::MANGO_LIMIT => 2,
            ]))
            ->delete();

        $this->assertSame(2, $deleted);
        $this->assertSame(['alice', 'bob'], $this->remainingNames());
    }

    public function testDelete_SqlConditionInWhere_DeletesMatchingRows()
    {
        $deleted = $this->find()
            ->where(new SqlCondition('`name` IN (:n1, :n2)', [':n1' => 'alice', ':n2' => 'dave']))
            ->delete();

        $this->assertSame(2, $deleted);
        $this->assertSame(['bob', 'charlie'], $this->remainingNames());
    }

    public function testDelete_ListenerRegistered_IsNotCalled()
    {
        $listener = new RecordingDeleteListener();
        DataMapper::setDeleteListener($listener);

        $deleted = $this->find()
            ->where('category = :category', [':category' => 'a'])
            ->delete();

        $this->assertSame(2, $deleted);
        $this->assertSame([], $listener->deletes);
    }

    public function testDelete_LoadedModel_KeepsItsSnapshot()
    {
        // Snapshots are only captured while a change listener is registered.
        DataMapper::setChangeListener(new RecordingDeleteListener());

        $model = new SomeTableModel();
        $this->assertNotFalse($model->_mapper->read($model, 1));
        $snapshot = $model->_lastSnapshot;
        $this->assertNotNull($snapshot);

        $this->find()->where('some_id = :id', [':id' => 1])->delete();

        $this->assertSame($snapshot, $model->_lastSnapshot);
    }
}
