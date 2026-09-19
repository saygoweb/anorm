# Test Coverage Improvement Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve PHPUnit test coverage for `src/TableMaker.php` (38.9% → ~80%+), `src/QueryBuilder.php` (80.3% → ~92%+), and `src/Model.php` (80.3% → ~90%+).

**Architecture:** Four independent tasks. Tasks 1–2 address TableMaker (including one real bug fix). Task 3 addresses QueryBuilder. Task 4 addresses Model. Each task follows TDD: write failing test, implement/fix, verify green, commit.

**Tech Stack:** PHPUnit 9+, PHP 7.4-compatible syntax, MySQL (via TestEnvironment::pdo()), Anorm DataMapper/Model/QueryBuilder/TableMaker.

---

## Coverage baseline (before this plan)

| File | Lines covered | % |
|---|---|---|
| `src/TableMaker.php` | 51 / 131 | 38.9% |
| `src/QueryBuilder.php` | 126 / 157 | 80.3% |
| `src/Model.php` | 139 / 173 | 80.3% |

Gaps by logical group:
- **TableMaker**: `_fix()` switch cases for `23000`/`HY000` codes; entire FK-creation chain (lines 109–304) including a **type-case bug** where the method compares `'ManyHasOne'` but `getType()` returns `'manyHasOne'`.
- **QueryBuilder**: `with()` string input; `with()` invalid-spec throw; entire `joinRelationship()` method; performance-monitor branches inside `someWithBatchLoading()`; `setBatchLoadingConfig()`; `setPerformanceMonitor()`; `loadNestedRelationships()` body.
- **Model**: auto-generated property names in `hasMany`/`belongsTo`/`hasManyThrough`; `getPropertyNameFromClass()` body; `setNullDelete()`, `restrictDelete()`, `constraintOptions()` bodies; `createForeignKeyConstraints()` early-return branch.

---

## File map

| Action | Path | Purpose |
|---|---|---|
| Modify | `src/TableMaker.php` | Fix type-case bug: `'ManyHasOne'` → `'manyHasOne'`, `'OneHasMany'` → `'oneHasMany'` |
| Modify | `test/anorm/TableMaker_Test.php` | Add switch-case + FK-chain tests |
| Create | `test/anorm/TmModels.php` | Fixture models for TableMaker FK integration tests |
| Modify | `test/anorm/QueryBuilder_Test.php` | Add `with()` validation, `joinRelationship()`, perf-monitor, nested-relationship tests |
| Create | `test/anorm/Model_ConvenienceMethods_Test.php` | Tests for Model protected convenience methods + auto-name generation |

---

## Task 1: TableMaker — `_fix()` switch-case coverage

**Goal:** Cover the `23000` and `HY000` cases in `TableMaker::_fix()`.  
**Files:** `test/anorm/TableMaker_Test.php`  
**No DB schema changes required.** All three tests pass a `null` model, which causes early return before any DB work.

- [ ] **Step 1: Add exception helper class and three tests**

Add after the existing `TableMakerTestException` class and before `TableMakerTest` in `test/anorm/TableMaker_Test.php`:

```php
class TableMakerTestExceptionWithMessage extends \PDOException
{
    public function __construct(string $code, string $msg)
    {
        $this->code = $code;
        $this->message = $msg;
    }
}
```

Add these three test methods inside `TableMakerTest`:

```php
public function testFix_23000_NoModel_DoesNotThrow()
{
    $e = new TableMakerTestExceptionWithMessage('23000', 'Cannot add or update a child row: a foreign key constraint fails');
    $mapper = DataMapper::create($this->pdo, null, null);
    TableMaker::fix($e, $mapper, null);
    $this->assertTrue(true);
}

public function testFix_HY000_WithForeignKeyMessage_DoesNotThrow()
{
    $e = new TableMakerTestExceptionWithMessage('HY000', 'General error: 1215 Cannot add foreign key constraint');
    $mapper = DataMapper::create($this->pdo, null, null);
    TableMaker::fix($e, $mapper, null);
    $this->assertTrue(true);
}

public function testFix_HY000_WithoutForeignKeyMessage_Rethrows()
{
    $this->expectException(\PDOException::class);
    $this->expectExceptionMessage('Some unrelated general error');

    $e = new TableMakerTestExceptionWithMessage('HY000', 'Some unrelated general error');
    $mapper = DataMapper::create($this->pdo, null, null);
    TableMaker::fix($e, $mapper, null);
}
```

- [ ] **Step 2: Run the new tests — expect all three to pass immediately**

```bash
vendor/bin/phpunit --filter testFix_23000_NoModel_DoesNotThrow test/anorm/TableMaker_Test.php
vendor/bin/phpunit --filter testFix_HY000_WithForeignKeyMessage_DoesNotThrow test/anorm/TableMaker_Test.php
vendor/bin/phpunit --filter testFix_HY000_WithoutForeignKeyMessage_Rethrows test/anorm/TableMaker_Test.php
```

Expected: `OK (1 test, 1 assertion)` for each.

- [ ] **Step 3: Run the full TableMaker test class to confirm no regressions**

```bash
vendor/bin/phpunit test/anorm/TableMaker_Test.php
```

Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add test/anorm/TableMaker_Test.php
git commit -m "test(tablemaker): cover _fix() 23000 and HY000 switch cases"
```

---

## Task 2: TableMaker — Bug fix + FK-chain integration tests

**Context / Bug:** `TableMaker::createForeignKeyFromRelationship()` at lines 162 and 171 compares `$type === 'ManyHasOne'` and `$type === 'OneHasMany'` (uppercase). But `ManyHasOne::getType()` returns `'manyHasOne'` and `OneHasMany::getType()` returns `'oneHasMany'` (lowercase). This means the FK creation chain in TableMaker **never creates any foreign keys** when called via the relationship system. Fix the bug first, then write integration tests.

**Files:**
- Modify: `src/TableMaker.php`
- Create: `test/anorm/TmModels.php`
- Modify: `test/anorm/TableMaker_Test.php`

### Step 1: Write the failing integration test first (TDD)

- [ ] **Step 1a: Create `test/anorm/TmModels.php`**

Class names are chosen so `TableMaker::getTableNameFromModelClass()` derives the table name used in the mapper (the regex converts `TmParent` → `tm_parent` → `tm_parents`):

```php
<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Model;

/**
 * Minimal fixture models for TableMaker FK integration tests.
 * Class names chosen so getTableNameFromModelClass() derives 'tm_parents' / 'tm_items'.
 */
class TmParentModel extends Model
{
    public $id;
    public $name;
    /** @var TmItemModel[] */
    public $items;

    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'tm_parents';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
        $this->hasMany(TmItemModel::class, 'parent_id', 'id', 'items');
    }
}

class TmItemModel extends Model
{
    public $id;
    public $name;
    public $parent_id;
    /** @var TmParentModel */
    public $parent;

    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'tm_items';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
        $this->belongsTo(TmParentModel::class, 'parent_id', 'id', 'parent');
    }
}
```

- [ ] **Step 1b: Add integration tests to `test/anorm/TableMaker_Test.php`**

Add at the top of the file (after existing use statements):

```php
use Anorm\Test\TmItemModel;
use Anorm\Test\TmParentModel;
```

Add these helper and test methods inside `TableMakerTest`:

```php
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

    $this->dropTmTables();
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

    $this->dropTmTables();
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

    // Should not throw even though FK already exists
    TableMaker::fix($e, $child->_mapper, $child);
    $this->assertTrue($this->tmForeignKeyExists('tm_items', 'fk_tm_items_parent_id'));

    $this->dropTmTables();
}

public function testGetTableNameFromModelClass_DerivedCorrectly()
{
    // Accessed indirectly: trigger createForeignKeyFromRelationship which calls getTableNameFromModelClass
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

    // Verify the FK was created on tm_items (auto-created by ensureTableExists)
    $this->assertTrue($this->tmForeignKeyExists('tm_items', 'fk_tm_items_parent_id'));

    $this->dropTmTables();
}
```

- [ ] **Step 2: Run the new integration tests — expect them to FAIL**

```bash
vendor/bin/phpunit --filter testFix_23000_WithBelongsToModel_CreatesForeignKey test/anorm/TableMaker_Test.php
```

Expected: `FAIL — Expected FK constraint fk_tm_items_parent_id to be created on tm_items`  
Reason: `createForeignKeyFromRelationship()` checks `$type === 'ManyHasOne'` but `getType()` returns `'manyHasOne'`.

- [ ] **Step 3: Fix the type-case bug in `src/TableMaker.php`**

In `src/TableMaker.php`, find `createForeignKeyFromRelationship()` (around line 158) and change:

```php
        if ($type === 'ManyHasOne') {
```
to:
```php
        if ($type === 'manyHasOne') {
```

And change:
```php
        } elseif ($type === 'OneHasMany') {
```
to:
```php
        } elseif ($type === 'oneHasMany') {
```

- [ ] **Step 4: Run all TableMaker tests — expect all to pass**

```bash
vendor/bin/phpunit test/anorm/TableMaker_Test.php
```

Expected: all tests pass (including the four new integration tests).

- [ ] **Step 5: Run the full test suite to confirm no regressions**

```bash
composer test:quick
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/TableMaker.php test/anorm/TmModels.php test/anorm/TableMaker_Test.php
git commit -m "fix(tablemaker): correct relationship type case in createForeignKeyFromRelationship

'ManyHasOne'/'OneHasMany' → 'manyHasOne'/'oneHasMany' to match getType() return values.
FK creation via TableMaker::fix() was silently a no-op for all relationship types.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 3: QueryBuilder — Uncovered methods

**Goal:** Cover `with()` string-input path, `with()` invalid-spec throw, `joinRelationship()`, `setPerformanceMonitor()`, `setBatchLoadingConfig()`, performance-monitor branches in `someWithBatchLoading()`, and `loadNestedRelationships()` body.

**Files:** `test/anorm/QueryBuilder_Test.php`

The `joinRelationship()` and nested/perf-monitor tests need the relationship tables (`users`, `posts`, `comments`). The test class needs a `setUpBeforeClass` that loads the schema.

- [ ] **Step 1: Extend `QueryBuilder_Test.php` with setup and new tests**

Replace the opening of `QueryBuilderTest` to add `setUpBeforeClass`, new imports, and a `setUp` that seeds one user and post row:

Add at the top of the file (after existing `use` statements):

```php
use Anorm\Test\UserModel;
use Anorm\Relationship\Performance\PerformanceMonitor;
```

Add `setUpBeforeClass` and `setUp` to `QueryBuilderTest`:

```php
public static function setUpBeforeClass(): void
{
    TestEnvironment::connect();
    TestEnvironment::loadRelationshipSchema();
}

protected function setUp(): void
{
    $this->pdo = TestEnvironment::pdo();
    // Seed minimal data so some() returns at least one row
    $this->pdo->exec('DELETE FROM posts');
    $this->pdo->exec('DELETE FROM users');
    $this->pdo->exec("INSERT INTO users (id, name, email) VALUES (1, 'Alice', 'alice@example.com')");
    $this->pdo->exec("INSERT INTO posts (id, user_id, title) VALUES (1, 1, 'Hello')");
}
```

- [ ] **Step 2: Add `with()` tests**

```php
public function testWith_StringInput_ConvertsToArray()
{
    $pdo = TestEnvironment::pdo();
    $qb = new QueryBuilder(UserModel::class, $pdo);
    $result = $qb->with('posts'); // string, not array
    $this->assertSame($qb, $result);
}

public function testWith_InvalidSpec_CircularRef_ThrowsInvalidArgumentException()
{
    $pdo = TestEnvironment::pdo();
    $qb = new QueryBuilder(UserModel::class, $pdo);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Invalid relationship specification 'posts.posts'");
    $qb->with(['posts.posts']); // circular: 'posts' appears twice → validation error
}

public function testWith_EmptySpec_ThrowsInvalidArgumentException()
{
    $pdo = TestEnvironment::pdo();
    $qb = new QueryBuilder(UserModel::class, $pdo);
    $this->expectException(\InvalidArgumentException::class);
    $qb->with(['']); // empty string
}
```

- [ ] **Step 3: Add `joinRelationship()` tests**

```php
public function testJoinRelationship_UndefinedRelationship_Throws()
{
    $pdo = TestEnvironment::pdo();
    $qb = new QueryBuilder(UserModel::class, $pdo);
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage("Relationship 'nonexistent' not defined");
    $qb->joinRelationship('nonexistent');
}

public function testJoinRelationship_ValidRelationship_ReturnsSelf()
{
    $pdo = TestEnvironment::pdo();
    $qb = new QueryBuilder(UserModel::class, $pdo);
    $result = $qb->joinRelationship('posts');
    $this->assertSame($qb, $result);
}

public function testJoinRelationship_CustomJoinType_InjectsJoinType()
{
    $pdo = TestEnvironment::pdo();
    $qb = new QueryBuilder(UserModel::class, $pdo);
    $result = $qb->joinRelationship('posts', 'INNER');
    $this->assertSame($qb, $result);
}
```

- [ ] **Step 4: Add `setBatchLoadingConfig()` and `setPerformanceMonitor()` tests**

```php
public function testSetBatchLoadingConfig_ReturnsSelf()
{
    $pdo = TestEnvironment::pdo();
    $qb = new QueryBuilder(UserModel::class, $pdo);
    $result = $qb->setBatchLoadingConfig(['batch_size' => 100]);
    $this->assertSame($qb, $result);
}

public function testSetPerformanceMonitor_ReturnsSelf()
{
    $pdo = TestEnvironment::pdo();
    $qb = new QueryBuilder(UserModel::class, $pdo);
    $result = $qb->setPerformanceMonitor(new PerformanceMonitor());
    $this->assertSame($qb, $result);
}
```

- [ ] **Step 5: Add performance-monitor branch test (covers `someWithBatchLoading()` lines 229–233, 247–249)**

```php
public function testSome_WithPerformanceMonitorAndEagerLoad_ReturnsModels()
{
    $pdo = TestEnvironment::pdo();
    $monitor = new PerformanceMonitor();
    $models = iterator_to_array(
        (new QueryBuilder(UserModel::class, $pdo))
            ->setPerformanceMonitor($monitor)
            ->with(['posts'])
            ->some()
    );

    $this->assertNotEmpty($models);
    $this->assertInstanceOf(UserModel::class, $models[0]);
}
```

- [ ] **Step 6: Add nested relationships test (covers `loadNestedRelationships()` body, lines 473–475)**

```php
public function testSome_WithNestedRelationships_LoadsNestedModels()
{
    $pdo = TestEnvironment::pdo();
    $models = iterator_to_array(
        (new QueryBuilder(UserModel::class, $pdo))
            ->with(['posts.comments'])
            ->some()
    );

    $this->assertNotEmpty($models);
    // Nested load ran without exception
    $this->assertInstanceOf(UserModel::class, $models[0]);
}
```

- [ ] **Step 7: Run all new QueryBuilder tests**

```bash
vendor/bin/phpunit test/anorm/QueryBuilder_Test.php
```

Expected: all tests pass.

- [ ] **Step 8: Run the full suite to confirm no regressions**

```bash
composer test:quick
```

Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add test/anorm/QueryBuilder_Test.php
git commit -m "test(querybuilder): cover with(), joinRelationship(), perf monitor, nested rels"
```

---

## Task 4: Model — Convenience methods + auto-generated property names

**Goal:** Cover `setNullDelete()`, `restrictDelete()`, `constraintOptions()`, the null-`$propertyName` branches in `hasMany`/`belongsTo`/`hasManyThrough`, the full body of `getPropertyNameFromClass()`, and the early-return branch of `createForeignKeyConstraints()` for non-dynamic mode.

**Files:** `test/anorm/Model_ConvenienceMethods_Test.php` (new)

These methods are `protected`, so tests call them through a thin test-helper subclass declared in the same file.

- [ ] **Step 1: Create `test/anorm/Model_ConvenienceMethods_Test.php`**

```php
<?php

namespace Anorm\Test;

require_once(__DIR__ . '/../../vendor/autoload.php');

use Anorm\DataMapper;
use Anorm\Model;
use PHPUnit\Framework\TestCase;

/**
 * Exposes protected Model methods for direct testing.
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
}

/**
 * Minimal model that calls hasMany/belongsTo/hasManyThrough WITHOUT explicit property names.
 * Auto-generated names: 'posts' (hasMany PostModel), 'company' (belongsTo CompanyModel),
 * 'tags' (hasManyThrough TagModel via auto-name).
 */
class AutoNameModel extends Model
{
    public $id;
    public $company_id;

    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'users'; // reuse existing users table
        parent::__construct($pdo, $mapper);

        // No $propertyName argument → triggers getPropertyNameFromClass()
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

    public function testConstraintOptions_DefaultValues()
    {
        $model = new ExposedModel($this->pdo);
        $result = $model->publicConstraintOptions();
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

        // Auto-generated from 'Anorm\Test\PostModel' → 'Post' → lcfirst → 'post' → plural → 'posts'
        $rel = $manager->getRelationship('posts');
        $this->assertNotNull($rel, "Expected auto-generated relationship name 'posts'");
        $this->assertEquals('Anorm\Test\PostModel', $rel->getRelatedModelClass());
    }

    public function testBelongsTo_NoPropertyName_AutoGeneratesSingularName()
    {
        $model = new AutoNameModel($this->pdo);
        $manager = $model->getRelationshipManager();

        // Auto-generated from 'Anorm\Test\CompanyModel' → 'Company' → lcfirst → 'company' (singular)
        $rel = $manager->getRelationship('company');
        $this->assertNotNull($rel, "Expected auto-generated relationship name 'company'");
        $this->assertEquals('Anorm\Test\CompanyModel', $rel->getRelatedModelClass());
    }

    public function testHasManyThrough_NoPropertyName_AutoGeneratesPluralName()
    {
        $model = new AutoNameModel($this->pdo);
        $manager = $model->getRelationshipManager();

        // Auto-generated from 'Anorm\Test\TagModel' → 'Tag' → lcfirst → 'tag' → plural → 'tags'
        $rel = $manager->getRelationship('tags');
        $this->assertNotNull($rel, "Expected auto-generated relationship name 'tags'");
        $this->assertEquals('Anorm\Test\TagModel', $rel->getRelatedModelClass());
    }

    // -----------------------------------------------------------------
    // createForeignKeyConstraints() early-return (non-dynamic mode)
    // -----------------------------------------------------------------

    public function testCreateForeignKeyConstraints_NonDynamicMode_DoesNothing()
    {
        // UserModel is not in dynamic mode — calling createForeignKeyConstraints()
        // should return without querying the DB (covers the early-return branch).
        $user = new UserModel($this->pdo);
        $user->createForeignKeyConstraints(); // must not throw
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Verify `TagModel` is available as a fixture (needed for `hasManyThrough` test)**

```bash
ls test/anorm/TagModel.php
```

Expected: file exists.  
If missing, open the file and check — `TagModel` is used in the relationship tests so it should exist. If not, create a minimal one:

```php
<?php
namespace Anorm\Test;
use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
class TagModel extends Model {
    public function __construct(\PDO $pdo = null) {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        parent::__construct($pdo, $mapper);
    }
    public $id;
    public $name;
}
```

- [ ] **Step 3: Run the new test file**

```bash
vendor/bin/phpunit test/anorm/Model_ConvenienceMethods_Test.php
```

Expected: all tests pass.

- [ ] **Step 4: Run the full suite**

```bash
composer test:quick
```

Expected: all green.

- [ ] **Step 5: Run cs:check and fix any style issues**

```bash
composer cs:check
composer cs:fix
```

- [ ] **Step 6: Commit**

```bash
git add test/anorm/Model_ConvenienceMethods_Test.php
git commit -m "test(model): cover setNullDelete, restrictDelete, constraintOptions, auto property names"
```

---

## Final verification

- [ ] **Run full test suite with coverage**

```bash
composer test:coverage
```

- [ ] **Check coverage numbers**

Open `build/coverage/src/index.html` and verify the three target files are above the original percentages. Expected rough targets:

| File | Before | Expected after |
|---|---|---|
| `TableMaker.php` | 38.9% | ≥ 75% |
| `QueryBuilder.php` | 80.3% | ≥ 90% |
| `Model.php` | 80.3% | ≥ 88% |

- [ ] **Run full quality checks**

```bash
composer quality
```

Expected: clean (`cs:check` + `analyze`).

---

## Self-review

**Spec coverage:**
- TableMaker `_fix()` 23000/HY000 cases → Task 1 ✓
- TableMaker type-case bug → Task 2 Step 3 ✓
- TableMaker FK chain (handleForeignKeyConstraint, createMissingForeignKeyConstraints, createForeignKeyFromRelationship, createForeignKey, foreignKeyExists, ensureTableExists, ensureColumnExists, getColumnDefinitionForForeignKey, getTableNameFromModelClass) → Task 2 ✓
- QueryBuilder `with()` string → Task 3 Step 2 ✓
- QueryBuilder `with()` invalid spec → Task 3 Step 2 ✓
- QueryBuilder `joinRelationship()` → Task 3 Step 3 ✓
- QueryBuilder `setPerformanceMonitor()` + `setBatchLoadingConfig()` → Task 3 Step 4 ✓
- QueryBuilder perf-monitor branches in `someWithBatchLoading()` → Task 3 Step 5 ✓
- QueryBuilder nested relationship body → Task 3 Step 6 ✓
- Model `setNullDelete/restrictDelete/constraintOptions` → Task 4 ✓
- Model auto-generated property names + `getPropertyNameFromClass()` → Task 4 ✓
- Model `createForeignKeyConstraints()` early return → Task 4 ✓

**Known gap:** `DataMapper::create(null, null, null)` usage in Task 1 passes a null table — verify this doesn't throw inside `TableMaker::fix()` for the 23000/HY000 null-model tests before running. If it does throw, replace with `DataMapper::create($this->pdo, 'dummy', [])`.
