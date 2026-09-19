<?php
namespace Anorm\Tools;

class ModelInfo
{
    /** @var array An array of (possible) property names inferred from field names. */
    public $properties = array();

    /**
     * @var array Property name => the PHP type its column holds, as a docblock writes
     * it ('int', '?string', ...). A property missing from here is documented as a
     * string, which is what every generated model used to say about every column.
     */
    public $propertyTypes = array();

    /** @var string The key property name likely indicated by a primary key field in the database. */
    public $keyProperty = '';
}   

