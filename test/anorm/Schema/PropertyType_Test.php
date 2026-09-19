<?php

// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

require_once(__DIR__ . '/../../../vendor/autoload.php');

use PHPUnit\Framework\TestCase;
use Anorm\Schema\PropertyType;

/**
 * What a model declares about its properties, read from typed properties and `@var`
 * docblocks. No database: this is the input to the column guess, not the guess.
 *
 * @see https://github.com/saygoweb/anorm/issues/61
 */

/** Typed properties, the PHP 7.4 way of declaring intent. */
class PtTypedModel
{
    public int $hitCount = 0;
    public ?int $ownerId = null;
    public float $ratio = 0.0;
    public bool $isActive = false;
    public string $name = '';
    public array $tags = [];
    public ?\DateTime $createdAt = null;
    public $untyped;
    public static int $counter = 0;
    /** @var int */
    public $_infrastructure;
}

/** Docblocks, the way every model written before PHP 7.4 declares intent. */
class PtDocModel
{
    /** @var int */
    public $hitCount;

    /** @var integer */
    public $legacySpelling;

    /** @var ?int */
    public $nullableShorthand;

    /** @var int|null */
    public $nullableUnion;

    /** @var null|int */
    public $nullableUnionFirst;

    /** @var bool */
    public $isActive;

    /** @var double */
    public $ratio;

    /** @var string[] */
    public $names;

    /** @var array<string, int> */
    public $counts;

    /** @var \Moment\Moment */
    public $occurredAt;

    /** @var Moment\Moment */
    public $noLeadingSlash;

    /** @var mixed */
    public $anything;

    /** @var int|string */
    public $ambiguous;

    /** @var int|mixed */
    public $partlyUninformative;

    /** @var non-empty-string */
    public $pseudoScalar;

    /** @var key-of<Foo> */
    public $pseudoUnknown;

    public $noDocblock;
}

/** A typed property wins over a docblock that disagrees with it. */
class PtBothModel
{
    /** @var string */
    public int $hitCount = 0;
}

class PropertyType_Test extends TestCase
{
    protected function setUp(): void
    {
        PropertyType::clearCache();
    }

    public function testTypedProperties_AreRead()
    {
        $model = new PtTypedModel();
        $this->assertSame('int', PropertyType::forProperty($model, 'hitCount'));
        $this->assertSame('int', PropertyType::forProperty($model, 'ownerId'), 'nullable is still an int');
        $this->assertSame('float', PropertyType::forProperty($model, 'ratio'));
        $this->assertSame('bool', PropertyType::forProperty($model, 'isActive'));
        $this->assertSame('string', PropertyType::forProperty($model, 'name'));
        $this->assertSame('array', PropertyType::forProperty($model, 'tags'));
        $this->assertSame('DateTime', PropertyType::forProperty($model, 'createdAt'));
    }

    public function testUndeclaredProperty_IsNull()
    {
        $model = new PtTypedModel();
        $this->assertNull(PropertyType::forProperty($model, 'untyped'), 'nothing declared, so nothing to say');
        $this->assertNull(PropertyType::forProperty($model, 'notAProperty'));
    }

    public function testStaticAndInfrastructureProperties_AreSkipped()
    {
        $types = PropertyType::forClass(PtTypedModel::class);
        $this->assertArrayNotHasKey('counter', $types, 'a static property is not a column');
        $this->assertArrayNotHasKey('_infrastructure', $types, 'underscore is Anorm infrastructure');
    }

    public function testDocblockScalars_AreRead()
    {
        $model = new PtDocModel();
        $this->assertSame('int', PropertyType::forProperty($model, 'hitCount'));
        $this->assertSame('int', PropertyType::forProperty($model, 'legacySpelling'));
        $this->assertSame('bool', PropertyType::forProperty($model, 'isActive'));
        $this->assertSame('float', PropertyType::forProperty($model, 'ratio'));
        $this->assertSame('string', PropertyType::forProperty($model, 'pseudoScalar'));
    }

    public function testDocblockNullable_IsTheTypeWithoutTheNull()
    {
        $model = new PtDocModel();
        $this->assertSame('int', PropertyType::forProperty($model, 'nullableShorthand'));
        $this->assertSame('int', PropertyType::forProperty($model, 'nullableUnion'));
        $this->assertSame('int', PropertyType::forProperty($model, 'nullableUnionFirst'));
    }

    public function testDocblockArrays_AreArrays()
    {
        $model = new PtDocModel();
        $this->assertSame('array', PropertyType::forProperty($model, 'names'));
        $this->assertSame('array', PropertyType::forProperty($model, 'counts'));
    }

    public function testDocblockClasses_AreNormalisedWithoutTheLeadingSlash()
    {
        $model = new PtDocModel();
        $this->assertSame('Moment\Moment', PropertyType::forProperty($model, 'occurredAt'));
        $this->assertSame('Moment\Moment', PropertyType::forProperty($model, 'noLeadingSlash'));
    }

    public function testUninformativeDocblocks_AreNull()
    {
        $model = new PtDocModel();
        $this->assertNull(PropertyType::forProperty($model, 'anything'), 'mixed says nothing');
        $this->assertNull(PropertyType::forProperty($model, 'ambiguous'), 'two real types is not a decision');
        $this->assertNull(PropertyType::forProperty($model, 'partlyUninformative'));
        $this->assertNull(PropertyType::forProperty($model, 'pseudoUnknown'));
        $this->assertNull(PropertyType::forProperty($model, 'noDocblock'));
    }

    public function testTypedProperty_BeatsADisagreeingDocblock()
    {
        $this->assertSame('int', PropertyType::forProperty(new PtBothModel(), 'hitCount'));
    }

    public function testForClass_ReturnsOnlyPropertiesThatDeclareSomething()
    {
        $types = PropertyType::forClass(PtDocModel::class);
        $this->assertSame('int', $types['hitCount']);
        $this->assertArrayNotHasKey('noDocblock', $types);
        $this->assertArrayNotHasKey('anything', $types);
    }

    public function testUnknownClass_IsEmptyRatherThanFatal()
    {
        $this->assertSame([], PropertyType::forClass('No\Such\Class'));
        $this->assertNull(PropertyType::forProperty(null, 'anything'));
        $this->assertNull(PropertyType::forProperty('not an object', 'anything'));
    }
}
