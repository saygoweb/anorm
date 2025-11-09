<?php

require_once(__DIR__ . '/../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Test\TestEnvironment;
use Anorm\Transform\FunctionTransform;

/**
 * Test model to demonstrate the transformer null bug
 */
class TransformerNullTestModel extends Model {
    public function __construct()
    {
        $pdo = Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this));
        $this->_mapper->modelPrimaryKey = 'testId';
        
        // Create a transformer that returns null when the input is 'MAKE_NULL'
        // Note: transformers are keyed by field name (test_value), not property name (testValue)
        $this->_mapper->transformers['test_value'] = new FunctionTransform(
            function($value) { 
                // Database to model: return the value as-is
                return $value; 
            },
            function($value) { 
                // Model to database: return null if value is 'MAKE_NULL'
                return $value === 'MAKE_NULL' ? null : $value; 
            }
        );
    }

    public $testId;
    public $testValue;
    public $name;
}

class DataMapper_TransformerNull_Test extends TestCase
{
    /** @var \PDO */
    private $pdo;

    public function __construct()
    {
        parent::__construct();
        TestEnvironment::connect();
        $this->pdo = TestEnvironment::pdo();
    }
    
    public static function setUpBeforeClass(): void
    {
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `transformer_null_test`');
        $pdo->query('
            CREATE TABLE `transformer_null_test` (
                `test_id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `test_value` varchar(128) NULL,
                `name` varchar(128) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1
        ');
    }

    public static function tearDownAfterClass(): void
    {
        $pdo = TestEnvironment::pdo();
        $pdo->query('DROP TABLE IF EXISTS `transformer_null_test`');
    }

    /**
     * Test that demonstrates the bug: when a transformer returns null,
     * the null value should be stored as NULL in the database, not as a quoted string
     */
    public function testTransformerReturnsNull_ShouldStoreNullInDatabase()
    {
        // Create a model instance
        $model = new TransformerNullTestModel();
        $model->name = 'Test Record';
        $model->testValue = 'MAKE_NULL'; // This will trigger the transformer to return null
        
        // Write the model to the database
        $id = $model->write();
        $this->assertNotNull($id, 'Model should be written successfully');
        
        // Read the record directly from the database to check the actual stored value
        $stmt = $this->pdo->prepare('SELECT test_value FROM transformer_null_test WHERE test_id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($result, 'Record should exist in database');
        
        // The bug: currently this will fail because the transformer's null return value
        // gets quoted as a string instead of being treated as SQL NULL
        $this->assertNull($result['test_value'], 
            'When transformer returns null, the database should store NULL, not a quoted string');
    }

    /**
     * Test that normal transformer behavior still works (non-null values)
     */
    public function testTransformerReturnsValue_ShouldStoreValueInDatabase()
    {
        // Create a model instance
        $model = new TransformerNullTestModel();
        $model->name = 'Test Record 2';
        $model->testValue = 'normal_value'; // This will pass through the transformer unchanged
        
        // Write the model to the database
        $id = $model->write();
        $this->assertNotNull($id, 'Model should be written successfully');
        
        // Read the record directly from the database to check the actual stored value
        $stmt = $this->pdo->prepare('SELECT test_value FROM transformer_null_test WHERE test_id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($result, 'Record should exist in database');
        $this->assertEquals('normal_value', $result['test_value'], 
            'Normal transformer values should be stored correctly');
    }

    /**
     * Test that when the model property itself is null (before transformation),
     * it's handled correctly (this should already work)
     */
    public function testModelPropertyIsNull_ShouldStoreNullInDatabase()
    {
        // Create a model instance
        $model = new TransformerNullTestModel();
        $model->name = 'Test Record 3';
        $model->testValue = null; // Property is null before transformation

        // Write the model to the database
        $id = $model->write();
        $this->assertNotNull($id, 'Model should be written successfully');

        // Read the record directly from the database to check the actual stored value
        $stmt = $this->pdo->prepare('SELECT test_value FROM transformer_null_test WHERE test_id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($result, 'Record should exist in database');
        $this->assertNull($result['test_value'],
            'When model property is null, the database should store NULL');
    }

    /**
     * Test that demonstrates the bug in UPDATE scenario: when a transformer returns null
     * during an update operation, the null value should be stored as NULL in the database
     */
    public function testTransformerReturnsNull_OnUpdate_ShouldStoreNullInDatabase()
    {
        // First, create a record with a normal value
        $model = new TransformerNullTestModel();
        $model->name = 'Test Record 4';
        $model->testValue = 'initial_value';

        $id = $model->write();
        $this->assertNotNull($id, 'Model should be written successfully');

        // Verify the initial value was stored correctly
        $stmt = $this->pdo->prepare('SELECT test_value FROM transformer_null_test WHERE test_id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('initial_value', $result['test_value']);

        // Now update the record with a value that will make the transformer return null
        $model->testValue = 'MAKE_NULL'; // This will trigger the transformer to return null
        $model->write(); // This should update the existing record

        // Read the record again to check if the null was stored correctly
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($result, 'Record should still exist in database');

        // The bug: currently this will fail because the transformer's null return value
        // gets quoted as a string instead of being treated as SQL NULL during UPDATE
        $this->assertNull($result['test_value'],
            'When transformer returns null during UPDATE, the database should store NULL, not a quoted string');
    }
}
