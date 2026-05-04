<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Relationship\ManyHasMany;
use Anorm\Relationship\ManyHasOne;
use Anorm\Relationship\BatchLoader\ManyHasManyBatchLoader;
use PHPUnit\Framework\TestCase;

class ManyHasRelationship_Test extends TestCase
{
    private $pdo;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
        $pdo = TestEnvironment::pdo();

        $sql = file_get_contents(__DIR__ . '/../RelationshipTestSchema.sql');

        $lines = explode("\n", $sql);
        $cleanSql = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && strpos($line, '#') !== 0) {
                $cleanSql .= $line . "\n";
            }
        }

        $statements = explode(';', $cleanSql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
    }

    protected function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function testManyHasManyGetType()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $this->assertEquals('manyHasMany', $rel->getType());
    }

    public function testManyHasManyGetCardinality()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $this->assertEquals('many-to-many', $rel->getCardinality());
    }

    public function testManyHasOneGetType()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);
        $rel = $postArray[0]->getRelationshipManager()->getRelationship('user');
        $this->assertInstanceOf(ManyHasOne::class, $rel);
        $this->assertEquals('manyHasOne', $rel->getType());
    }

    public function testManyHasManyEstimateDataSizeNoFieldSelection()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $size = $rel->estimateDataSize(4);
        $this->assertEquals(4 * 3 * 1024, $size);
    }

    public function testManyHasManyEstimateDataSizeWithFieldSelection()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $fields = ['id', 'name'];
        $size = $rel->estimateDataSize(4, $fields);
        $this->assertEquals(4 * 3 * count($fields) * 50, $size);
    }

    public function testManyHasManyEstimateDataSizeWithEmptyFieldSelection()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $size = $rel->estimateDataSize(4, []);
        $this->assertEquals(4 * 3 * 1024, $size);
    }

    public function testManyHasManyEstimateDataSizeZeroSourceCount()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $this->assertEquals(0, $rel->estimateDataSize(0));
    }

    public function testManyHasManyGenerateForeignKeyConstraints()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $constraints = $rel->generateForeignKeyConstraints('posts');

        $this->assertIsArray($constraints);
        $this->assertCount(2, $constraints);

        $this->assertStringContainsString('ALTER TABLE `post_tags`', $constraints[0]);
        $this->assertStringContainsString('`post_id`', $constraints[0]);
        $this->assertStringContainsString('`posts`', $constraints[0]);
        $this->assertStringContainsString('fk_post_tags_post_id', $constraints[0]);

        $this->assertStringContainsString('ALTER TABLE `post_tags`', $constraints[1]);
        $this->assertStringContainsString('`tag_id`', $constraints[1]);
        $this->assertStringContainsString('`tags`', $constraints[1]);
        $this->assertStringContainsString('fk_post_tags_tag_id', $constraints[1]);
    }

    public function testManyHasManyGenerateForeignKeyConstraintsDefaultOnDeleteOnUpdate()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $constraints = $rel->generateForeignKeyConstraints('posts');

        $this->assertStringContainsString('ON DELETE RESTRICT', $constraints[0]);
        $this->assertStringContainsString('ON UPDATE CASCADE', $constraints[0]);
    }

    public function testManyHasManyBatchLoad()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $this->assertNotEmpty($postArray);

        $rel = $postArray[0]->getRelationshipManager()->getRelationship('tags');
        $this->assertInstanceOf(ManyHasMany::class, $rel);

        $batchResults = $rel->batchLoad($postArray, $this->pdo);
        $this->assertIsArray($batchResults);

        // post_tags data: (1,1),(1,2),(2,2),(3,3) — post 1 has 2 tags, post 2 has 1 tag, post 3 has 1 tag
        $this->assertArrayHasKey(1, $batchResults);
        $this->assertCount(2, $batchResults[1]);
        $this->assertArrayHasKey(2, $batchResults);
        $this->assertCount(1, $batchResults[2]);
        $this->assertArrayHasKey(3, $batchResults);
        $this->assertCount(1, $batchResults[3]);
    }

    public function testManyHasManyBatchLoadEmptySourceModels()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $result = $rel->batchLoad([], $this->pdo);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testManyHasManyBatchLoadWithFieldSelection()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $rel = $postArray[0]->getRelationshipManager()->getRelationship('tags');
        $this->assertInstanceOf(ManyHasMany::class, $rel);

        $batchResults = $rel->batchLoad($postArray, $this->pdo, ['id', 'name']);
        $this->assertIsArray($batchResults);
        $this->assertNotEmpty($batchResults);
    }

    public function testManyHasManyDistributeBatchResults()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $rel = $postArray[0]->getRelationshipManager()->getRelationship('tags');
        $this->assertInstanceOf(ManyHasMany::class, $rel);

        $batchResults = $rel->batchLoad($postArray, $this->pdo);
        $rel->distributeBatchResults($postArray, $batchResults);

        // post 1 should have 2 tags loaded
        $this->assertIsArray($postArray[0]->tags);
        $this->assertCount(2, $postArray[0]->tags);

        // post 2 should have 1 tag
        $this->assertIsArray($postArray[1]->tags);
        $this->assertCount(1, $postArray[1]->tags);
    }

    public function testManyHasManyDistributeBatchResultsEmptyResults()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $rel = $postArray[0]->getRelationshipManager()->getRelationship('tags');
        $this->assertInstanceOf(ManyHasMany::class, $rel);

        $rel->distributeBatchResults($postArray, []);

        foreach ($postArray as $post) {
            $this->assertIsArray($post->tags);
            $this->assertEmpty($post->tags);
        }
    }

    public function testManyHasManyBatchLoaderBatchLoad()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $loader = new ManyHasManyBatchLoader();
        $batchResults = $loader->batchLoad($postArray, 'tags');

        $this->assertIsArray($batchResults);
        $this->assertArrayHasKey(1, $batchResults);
        $this->assertCount(2, $batchResults[1]);
    }

    public function testManyHasManyBatchLoaderBatchLoadEmptyModels()
    {
        $loader = new ManyHasManyBatchLoader();
        $result = $loader->batchLoad([], 'tags');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testManyHasManyBatchLoaderThrowsOnUnknownRelationship()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $loader = new ManyHasManyBatchLoader();
        $this->expectException(\Exception::class);
        $loader->batchLoad($postArray, 'nonexistent_relationship');
    }

    public function testManyHasManyBatchLoaderDistributeBatchResults()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $loader = new ManyHasManyBatchLoader();
        $batchResults = $loader->batchLoad($postArray, 'tags');
        $loader->distributeBatchResults($postArray, $batchResults, 'tags');

        $this->assertIsArray($postArray[0]->tags);
        $this->assertCount(2, $postArray[0]->tags);
    }

    public function testManyHasManyBatchLoaderDistributeBatchResultsEmpty()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $loader = new ManyHasManyBatchLoader();
        $loader->distributeBatchResults($postArray, [], 'tags');

        foreach ($postArray as $post) {
            $this->assertIsArray($post->tags);
            $this->assertEmpty($post->tags);
        }
    }

    public function testManyHasManyBatchLoaderDistributeBatchResultsEmptyModels()
    {
        $loader = new ManyHasManyBatchLoader();
        $loader->distributeBatchResults([], [], 'tags');
        $this->assertTrue(true);
    }

    public function testManyHasOneGenerateForeignKeyConstraints()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $this->assertNotEmpty($postArray);

        $rel = $postArray[0]->getRelationshipManager()->getRelationship('user');
        $this->assertInstanceOf(ManyHasOne::class, $rel);

        $constraints = $rel->generateForeignKeyConstraints('posts');

        $this->assertIsArray($constraints);
        $this->assertCount(1, $constraints);
        $this->assertStringContainsString('ALTER TABLE `posts`', $constraints[0]);
        $this->assertStringContainsString('`user_id`', $constraints[0]);
        $this->assertStringContainsString('`users`', $constraints[0]);
    }

    public function testManyHasOneGenerateForeignKeyConstraintsDefaultActions()
    {
        $posts = DataMapper::find(PostModel::class, $this->pdo)->some();
        $postArray = iterator_to_array($posts);

        $rel = $postArray[0]->getRelationshipManager()->getRelationship('user');
        $this->assertInstanceOf(ManyHasOne::class, $rel);

        $constraints = $rel->generateForeignKeyConstraints('posts');

        $this->assertStringContainsString('ON DELETE RESTRICT', $constraints[0]);
        $this->assertStringContainsString('ON UPDATE CASCADE', $constraints[0]);
    }

    public function testManyHasManyGenerateJoinClause()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $clause = $rel->generateJoinClause('posts', 'tags');

        $this->assertStringContainsString('LEFT JOIN `post_tags`', $clause);
        $this->assertStringContainsString('`posts`', $clause);
        $this->assertStringContainsString('`post_id`', $clause);
        $this->assertStringContainsString('LEFT JOIN `tags`', $clause);
        $this->assertStringContainsString('`tag_id`', $clause);
    }

    public function testManyHasManyGenerateJoinTableSQL()
    {
        $rel = new ManyHasMany('Anorm\Test\TagModel', 'tags', 'post_id', 'tag_id', 'post_tags');
        $sql = $rel->generateJoinTableSQL('posts');

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `post_tags`', $sql);
        $this->assertStringContainsString('`post_id`', $sql);
        $this->assertStringContainsString('`tag_id`', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
    }
}
