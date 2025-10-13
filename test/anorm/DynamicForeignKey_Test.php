<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\DataMapper;
use Anorm\Test\TestEnvironment;
use Anorm\Test\DynamicUserModel;
use Anorm\Test\DynamicCompanyModel;
use Anorm\Test\DynamicPostModel;
use Anorm\Test\DynamicTagModel;
use Anorm\Test\DynamicUserWithCascadeModel;

/**
 * Test dynamic foreign key creation based on relationship definitions
 */
class DynamicForeignKey_Test extends TestCase
{
    private $pdo;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
    }

    public function setUp(): void
    {
        $this->pdo = TestEnvironment::pdo();
        
        // Clean up any existing test tables
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_posts');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_users');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_companies');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_post_tags');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_tags');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function tearDown(): void
    {
        // Clean up test tables
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_posts');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_users');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_companies');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_post_tags');
        $this->pdo->exec('DROP TABLE IF EXISTS dynamic_tags');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testDynamicForeignKeyCreationForBelongsTo()
    {
        // Create company first to satisfy foreign key constraint
        $company = new DynamicCompanyModel($this->pdo);
        $company->name = 'Tech Corp';
        $company->write();

        // Create models that will trigger dynamic schema creation
        $user = new DynamicUserModel($this->pdo);
        $user->name = 'John Doe';
        $user->email = 'john@example.com';
        $user->company_id = $company->id; // Reference the actual company

        // This write operation should trigger:
        // 1. Creation of dynamic_users table
        // 2. Creation of foreign key constraint from dynamic_users.company_id to dynamic_companies.id
        $user->write();
        
        // Verify the foreign key constraint was created
        $this->assertTrue($this->foreignKeyExists('dynamic_users', 'fk_dynamic_users_company_id'));
        
        // Verify we can query the constraint information
        $constraints = $this->getForeignKeyConstraints('dynamic_users');
        $this->assertCount(1, $constraints);
        $this->assertEquals('dynamic_companies', $constraints[0]['REFERENCED_TABLE_NAME']);
        $this->assertEquals('company_id', $constraints[0]['COLUMN_NAME']);
        $this->assertEquals('id', $constraints[0]['REFERENCED_COLUMN_NAME']);
    }

    public function testDynamicForeignKeyCreationForHasMany()
    {
        // Create a company first
        $company = new DynamicCompanyModel($this->pdo);
        $company->name = 'Tech Corp';
        $company->write();
        
        // Create a user that belongs to the company
        $user = new DynamicUserModel($this->pdo);
        $user->name = 'Jane Smith';
        $user->email = 'jane@example.com';
        $user->company_id = $company->id;
        $user->write();
        
        // Create a post that belongs to the user
        $post = new DynamicPostModel($this->pdo);
        $post->title = 'Test Post';
        $post->content = 'This is a test post';
        $post->user_id = $user->id; // This should trigger foreign key creation
        
        // This write operation should trigger:
        // 1. Creation of dynamic_posts table
        // 2. Creation of foreign key constraint from dynamic_posts.user_id to dynamic_users.id
        $post->write();
        
        // Verify the foreign key constraint was created
        $this->assertTrue($this->foreignKeyExists('dynamic_posts', 'fk_dynamic_posts_user_id'));
        
        // Test the relationship works
        $post->loadRelated('user');
        $this->assertInstanceOf(DynamicUserModel::class, $post->user);
        $this->assertEquals('Jane Smith', $post->user->name);
    }

    public function testDynamicForeignKeyWithCascadeDelete()
    {
        // Create company first
        $company = new DynamicCompanyModel($this->pdo);
        $company->name = 'Test Company';
        $company->write();

        // Create models with CASCADE delete constraint
        $user = new DynamicUserWithCascadeModel($this->pdo);
        $user->name = 'Test User';
        $user->email = 'test@example.com';
        $user->company_id = $company->id;
        $user->write();
        
        // Verify the foreign key was created with CASCADE delete
        $constraints = $this->getForeignKeyConstraints('dynamic_users_cascade');
        $this->assertCount(1, $constraints);
        $this->assertEquals('CASCADE', $constraints[0]['DELETE_RULE']);
    }

    public function testDynamicManyToManyJoinTable()
    {
        // Create company and user first
        $company = new DynamicCompanyModel($this->pdo);
        $company->name = 'Blog Company';
        $company->write();

        $user = new DynamicUserModel($this->pdo);
        $user->name = 'Blogger';
        $user->email = 'blogger@example.com';
        $user->company_id = $company->id;
        $user->write();

        // Create a post
        $post = new DynamicPostModel($this->pdo);
        $post->title = 'Tagged Post';
        $post->content = 'This post has tags';
        $post->user_id = $user->id;
        $post->write();
        
        // Create a tag
        $tag = new DynamicTagModel($this->pdo);
        $tag->name = 'technology';
        $tag->write();
        
        // Load the many-to-many relationship, which should trigger join table creation
        $post->loadRelated('tags');
        
        // Verify the join table was created
        $this->assertTrue($this->tableExists('dynamic_post_tags'));
        
        // Verify foreign keys on join table were created
        $this->assertTrue($this->foreignKeyExists('dynamic_post_tags', 'fk_dynamic_post_tags_post_id'));
        $this->assertTrue($this->foreignKeyExists('dynamic_post_tags', 'fk_dynamic_post_tags_tag_id'));
    }

    /**
     * Helper method to check if a foreign key constraint exists
     */
    private function foreignKeyExists($tableName, $constraintName)
    {
        $sql = "SELECT COUNT(*) as count 
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND CONSTRAINT_NAME = ? 
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName, $constraintName]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    }

    /**
     * Helper method to get foreign key constraint information
     */
    private function getForeignKeyConstraints($tableName)
    {
        $sql = "SELECT 
                    kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE,
                    rc.UPDATE_RULE
                FROM information_schema.KEY_COLUMN_USAGE kcu
                JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
                    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                WHERE kcu.TABLE_SCHEMA = DATABASE() 
                AND kcu.TABLE_NAME = ?
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Helper method to check if a table exists
     */
    private function tableExists($tableName)
    {
        $sql = "SELECT COUNT(*) as count 
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result['count'] > 0;
    }
}
