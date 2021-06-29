<?php
namespace Anorm;

interface TransformInterface
{

    public function txDatabaseToModel($value);

    public function txModelToDatabase($value);

}