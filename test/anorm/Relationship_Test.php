<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Test\UserModel;
use Anorm\Test\PostModel;
use Anorm\Test\CommentModel;
use Anorm\Test\CompanyModel;
use Anorm\Test\TagModel;
use Anorm\Test\TestEnvironment;

class Relationship_Test extends TestCase
{
    private $pdo;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect(); // Connect to database
        $pdo = TestEnvironment::pdo();

        // First, clean up any existing data
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('TRUNCATE TABLE post_tags');
        $pdo->exec('TRUNCATE TABLE comments');
        $pdo->exec('TRUNCATE TABLE posts');
        $pdo->exec('TRUNCATE TABLE users');
        $pdo->exec('TRUNCATE TABLE companies');
        $pdo->exec('TRUNCATE TABLE tags');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Create relationship test tables
        $sql = file_get_contents(__DIR__ . '/RelationshipTestSchema.sql');

        // Remove comments and split by semicolon
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
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    echo "SQL Error: " . $e->getMessage() . "\n";
                    echo "Statement: " . $statement . "\n";
                    throw $e;
                }
            }
        }
    }

    public function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
    }

    public function tearDown(): void
    {
        // Clean up any data created during tests to ensure test isolation
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DELETE FROM users WHERE id > 3'); // Keep only the original 3 test users
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testOneHasManyRelationship()
    {
        $user = new UserModel($this->pdo);
        $user->read(1); // John Doe
        
        // Load posts relationship
        $user->loadRelated('posts');
        
        $this->assertIsArray($user->posts);
        $this->assertCount(2, $user->posts); // John has 2 posts
        $this->assertInstanceOf(PostModel::class, $user->posts[0]);
        $this->assertEquals('First Post', $user->posts[0]->title);
    }

    public function testManyHasOneRelationship()
    {
        $post = new PostModel($this->pdo);
        $post->read(1); // First Post

        // Load user relationship
        $post->loadRelated('user');

        $this->assertInstanceOf(UserModel::class, $post->user);
        $this->assertEquals('John Doe', $post->user->name);
        $this->assertEquals(1, $post->user->id);
    }

    public function testManyHasManyRelationship()
    {
        $post = new PostModel($this->pdo);
        $post->read(1); // First Post
        
        // Load tags relationship
        $post->loadRelated('tags');
        
        $this->assertIsArray($post->tags);
        $this->assertCount(2, $post->tags); // First post has 2 tags
        $this->assertInstanceOf(TagModel::class, $post->tags[0]);
        
        // Check tag names
        $tagNames = array_map(function($tag) { return $tag->name; }, $post->tags);
        $this->assertContains('technology', $tagNames);
        $this->assertContains('programming', $tagNames);
    }

    public function testLoadAllRelated()
    {
        $user = new UserModel($this->pdo);
        $user->read(1); // John Doe
        
        // Load all relationships at once
        $user->loadAllRelated();
        
        $this->assertIsArray($user->posts);
        $this->assertInstanceOf(CompanyModel::class, $user->company);
        $this->assertIsArray($user->comments);
        
        $this->assertEquals('Tech Corp', $user->company->name);
        $this->assertCount(2, $user->posts);
    }

    public function testEagerLoadingWithQueryBuilder()
    {
        $users = DataMapper::find(UserModel::class, $this->pdo)
            ->with(['posts', 'company'])
            ->some();

        $userArray = iterator_to_array($users);
        $this->assertCount(3, $userArray);

        $john = $userArray[0];
        $this->assertEquals('John Doe', $john->name);
        $this->assertIsArray($john->posts);
        $this->assertInstanceOf(CompanyModel::class, $john->company);
        $this->assertEquals('Tech Corp', $john->company->name);
    }

    public function testRelationshipWithConditions()
    {
        $user = new UserModel($this->pdo);
        $user->read(1); // John Doe
        
        // Load posts
        $user->loadRelated('posts');
        
        // Check that we get both published and draft posts
        $this->assertCount(2, $user->posts);
        
        $statuses = array_map(function($post) { return $post->status; }, $user->posts);
        $this->assertContains('published', $statuses);
        $this->assertContains('draft', $statuses);
    }

    public function testNullRelationship()
    {
        // Create a user without a company
        $user = new UserModel($this->pdo);
        $user->name = 'Test User';
        $user->email = 'test@example.com';
        $user->company_id = null;
        $user->write();
        
        // Load company relationship
        $user->loadRelated('company');
        
        $this->assertNull($user->company);
    }

    public function testEmptyRelationship()
    {
        // Create a user with no posts
        $user = new UserModel($this->pdo);
        $user->name = 'New User';
        $user->email = 'new@example.com';
        $user->company_id = 1;
        $user->write();
        
        // Load posts relationship
        $user->loadRelated('posts');
        
        $this->assertIsArray($user->posts);
        $this->assertEmpty($user->posts);
    }

    public function testRelationshipException()
    {
        $user = new UserModel($this->pdo);
        $user->read(1);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Relationship 'nonexistent' not defined");
        
        $user->loadRelated('nonexistent');
    }
}
