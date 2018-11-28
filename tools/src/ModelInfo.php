<?php
namespace Anorm\Tools;

class ModelInfo
{
    /** @var array An array of (possible) property names inferred from field names. */
    public $properties = array();

    /** @var string The key property name likely indicated by a primary key field in the database. */
    public $keyProperty = '';
}   

