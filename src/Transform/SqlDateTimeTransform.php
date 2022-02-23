<?php
namespace Anorm\Transform;

use Anorm\TransformInterface;
use DateTime;

class SqlDateTimeTransform implements TransformInterface
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

    public function txModelToDatabase(/** @var \DateTime */$value)
    {
        return $value !== null ? $value->format($this->format) : null;
    }

}