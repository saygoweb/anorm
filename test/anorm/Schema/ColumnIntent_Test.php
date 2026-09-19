<?php

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;
use Anorm\Schema\ColumnIntent;
use Anorm\Test\TestEnvironment;
use Anorm\Transform\SqlDateTimeTransform;

/**
 * What a mapper and a model say a column should be, and on what grounds. The grounds
 * are the part a diff needs: a VARCHAR(128) reached by falling through asserts
 * nothing, and reporting it as drift would bury every real finding.
 */

class IntentModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'intent_things';
        $mapper->columnDefinitions = ['pinned' => 'BIGINT(20) NULL'];
        $mapper->transformers['occurred_at'] = new SqlDateTimeTransform();
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public $pinned;
    public $occurredAt;
    public ?int $declared = null;
    public $sampled = 3.5;
    public $unknowable;
}

class ColumnIntent_Test extends TestCase
{
    /** @var IntentModel */
    private $model;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::connect();
    }

    public function setUp(): void
    {
        $this->model = new IntentModel(TestEnvironment::pdo());
    }

    public function testPin_IsTheFirstWord()
    {
        $intent = $this->intentFor('pinned');
        $this->assertSame('BIGINT(20) NULL', $intent->definition);
        $this->assertSame(ColumnIntent::SOURCE_PIN, $intent->source);
        $this->assertSame('pinned', $intent->property);
    }

    public function testTransformer_SaysWhatItWrites()
    {
        $intent = $this->intentFor('occurred_at');
        $this->assertSame('DATETIME NULL', $intent->definition);
        $this->assertSame(ColumnIntent::SOURCE_TRANSFORMER, $intent->source);
    }

    public function testDeclaration_IsUsedWhereThereIsNoValue()
    {
        $intent = $this->intentFor('declared');
        $this->assertSame('INT(11) NULL', $intent->definition);
        $this->assertSame(ColumnIntent::SOURCE_DECLARATION, $intent->source);
    }

    public function testSample_IsUsedWhereNothingIsDeclared()
    {
        $intent = $this->intentFor('sampled');
        $this->assertSame('DOUBLE NULL', $intent->definition);
        $this->assertSame(ColumnIntent::SOURCE_SAMPLE, $intent->source);
    }

    public function testNothingAtAll_IsMarkedAsSuch()
    {
        $intent = $this->intentFor('unknowable');
        $this->assertSame('VARCHAR(128)', $intent->definition);
        $this->assertSame(ColumnIntent::SOURCE_NONE, $intent->source);
        $this->assertFalse($intent->isInformed(), 'the fallback asserts nothing');
    }

    public function testInformedSources_SayTheyAre()
    {
        $this->assertTrue($this->intentFor('pinned')->isInformed());
        $this->assertTrue($this->intentFor('occurred_at')->isInformed());
        $this->assertTrue($this->intentFor('declared')->isInformed());
        $this->assertTrue($this->intentFor('sampled')->isInformed());
    }

    public function testColumnTheMapDoesNotMention_HasNoProperty()
    {
        $intent = $this->intentFor('not_mapped');
        $this->assertNull($intent->property);
        $this->assertSame(ColumnIntent::SOURCE_NONE, $intent->source);
    }

    private function intentFor($column)
    {
        return ColumnIntent::forColumn($this->model->_mapper, $this->model, $column);
    }
}
