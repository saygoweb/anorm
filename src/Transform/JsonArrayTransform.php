<?php
namespace Anorm\Transform;

use Anorm\TransformInterface;

class JsonArrayTransform implements TransformInterface
{

    public function txDatabaseToModel($value)
    {
        return \json_decode($value, true);
    }

    public function txModelToDatabase($value)
    {
        return \json_encode($value);
    }

}