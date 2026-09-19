<?php

namespace Anorm\Transform;

use Anorm\Schema\ColumnTypeHintInterface;
use Anorm\TransformInterface;
use DateTime;

class SqlDateTimeTransform implements TransformInterface, ColumnTypeHintInterface
{
    /** @var string */
    private $format;

    public function __construct($format = 'Y-m-d H:i:s')
    {
        $this->format = $format;
    }

    public function txDatabaseToModel($value)
    {
        return $value !== null ? new \DateTime($value) : null;
    }

    /**
     * A formatted date needs a date column, whatever value happened to be sampled first.
     * @return string|null
     */
    public function sqlColumnType()
    {
        return 'DATETIME NULL';
    }

    public function txModelToDatabase(/** @var \DateTime */$value)
    {
        return $value !== null ? $value->format($this->format) : null;
    }
}
