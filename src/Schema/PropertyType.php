<?php

namespace Anorm\Schema;

/**
 * What a model *declares* about a property's type, as opposed to what one sampled
 * value happens to suggest.
 *
 * A sampled value is an accident of execution order — the first write, or the first
 * read, decides the column type and then it sticks. A declared type is intent, and
 * intent is what a schema wants. This reads that declaration from the two places PHP
 * puts it: a typed property, then an `@var` docblock.
 *
 * Declaring nothing is not an error. A property with no type returns null and the
 * caller falls back to sampling, exactly as before.
 *
 * @see https://github.com/saygoweb/anorm/issues/61
 */
class PropertyType
{
    /** @var array<string, array<string, string>> class name => property name => normalised type */
    private static $cache = [];

    /** Spellings that mean the same scalar. */
    private const ALIASES = [
        'int' => 'int',
        'integer' => 'int',
        'bool' => 'bool',
        'boolean' => 'bool',
        'true' => 'bool',
        'false' => 'bool',
        'float' => 'float',
        'double' => 'float',
        'string' => 'string',
        'array' => 'array',
        'non-empty-string' => 'string',
        'numeric-string' => 'string',
        'class-string' => 'string',
        'literal-string' => 'string',
        'positive-int' => 'int',
        'negative-int' => 'int',
        'non-negative-int' => 'int',
        'non-positive-int' => 'int',
    ];

    /** Declarations that say nothing a column type can be built from. */
    private const UNINFORMATIVE = [
        'mixed',
        'object',
        'iterable',
        'callable',
        'resource',
        'void',
        'null',
        'scalar',
        'self',
        'static',
        'parent',
        '$this',
    ];

    /**
     * The type $model declares for $property, or null where it declares nothing.
     *
     * @param mixed $model The model instance to read the declaration from
     * @param string $property Property name
     * @return string|null 'int', 'float', 'bool', 'string', 'array', a class name, or null
     */
    public static function forProperty($model, $property)
    {
        if (!\is_object($model)) {
            return null;
        }
        $types = self::forClass(\get_class($model));
        return isset($types[$property]) ? $types[$property] : null;
    }

    /**
     * Every public, non-static property of $class that declares a type.
     *
     * Cached per class: a schema diff walks every property of every model, and the
     * reflection is the expensive part.
     *
     * @param string $class Fully qualified class name
     * @return array<string, string> property name => normalised type
     */
    public static function forClass($class)
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }
        try {
            $reflection = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            self::$cache[$class] = [];
            return [];
        }
        $types = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $name = $property->getName();
            // Underscore-prefixed properties are Anorm's own infrastructure, never columns.
            if ($name[0] === '_') {
                continue;
            }
            $type = self::fromReflection($property);
            if ($type === null) {
                $doc = $property->getDocComment();
                $type = self::fromDocComment($doc === false ? '' : $doc);
            }
            if ($type !== null) {
                $types[$name] = $type;
            }
        }
        self::$cache[$class] = $types;
        return $types;
    }

    /**
     * Discard the reflection cache. Tests that define classes on the fly need this;
     * nothing in normal use does, since a class's declarations cannot change.
     * @return void
     */
    public static function clearCache()
    {
        self::$cache = [];
    }

    /**
     * @param \ReflectionProperty $property
     * @return string|null
     */
    private static function fromReflection(\ReflectionProperty $property)
    {
        if (!$property->hasType()) {
            return null;
        }
        $type = $property->getType();
        // A union or intersection type declares alternatives rather than a column
        // type, and is PHP 8 syntax, so it is handled here rather than branched on.
        return $type instanceof \ReflectionNamedType ? self::normalise($type->getName()) : null;
    }

    /**
     * @param string $doc The raw docblock
     * @return string|null
     */
    private static function fromDocComment($doc)
    {
        if ($doc === '' || \preg_match('/@var\s+([^\s*]+)/', $doc, $matches) !== 1) {
            return null;
        }
        $found = null;
        foreach (\explode('|', $matches[1]) as $candidate) {
            $candidate = \trim($candidate);
            if ($candidate === '' || \strtolower(\ltrim($candidate, '?')) === 'null') {
                continue;
            }
            $normalised = self::normalise($candidate);
            // One uninformative or conflicting member makes the whole declaration
            // uninformative — better to sample than to act on half a union.
            if ($normalised === null || ($found !== null && $found !== $normalised)) {
                return null;
            }
            $found = $normalised;
        }
        return $found;
    }

    /**
     * @param string $type A type as written in the source
     * @return string|null The normalised type, or null where it carries no information
     */
    private static function normalise($type)
    {
        $type = \ltrim(\trim($type), '?');
        if ($type === '') {
            return null;
        }
        // array<string, int>, list<int>, int[] — the shape does not matter, the storage does.
        if (\substr($type, -2) === '[]' || \preg_match('/^(array|list)</i', $type) === 1) {
            return 'array';
        }
        $lower = \strtolower($type);
        if (isset(self::ALIASES[$lower])) {
            return self::ALIASES[$lower];
        }
        if (\preg_match('/^int</i', $type) === 1) {
            return 'int';
        }
        if (\in_array($lower, self::UNINFORMATIVE, true)) {
            return null;
        }
        // Anything else is a class name; a leading backslash is spelling, not meaning.
        // A residual pseudo-type such as `key-of<T>` is not a class, and says nothing.
        if (\strpos($type, '<') !== false || \strpos($type, '-') !== false) {
            return null;
        }
        $class = \ltrim($type, '\\');
        return $class === '' ? null : $class;
    }
}
