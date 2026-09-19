<?php

namespace Anorm\Transform;

use Anorm\Schema\ColumnTypeHintInterface;
use Anorm\TransformInterface;

class JsonArrayTransform implements TransformInterface, ColumnTypeHintInterface
{
    public function txDatabaseToModel($value)
    {
        return \json_decode($value, true);
    }

    public function txModelToDatabase($value)
    {
        return \json_encode($value);
    }

    /**
     * Encoded JSON is not a VARCHAR(128) waiting to happen, however short the first
     * array written to it was.
     * @return string|null
     */
    public function sqlColumnType()
    {
        return 'TEXT';
    }
}
