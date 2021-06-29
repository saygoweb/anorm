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
        return new \DateTime($value);
    }

    public function txModelToDatabase(/** @var \DateTime */$value)
    {
        return $value->format($this->format);
    }

}