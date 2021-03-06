<?php
namespace Anorm\Transform;

use Anorm\TransformInterface;

class FunctionTransform implements TransformInterface
{

    /** @var callable */
    private $databaseToModel;

    /** @var callable */
    private $modelToDatabase;

    public function __construct(callable $databaseToModel, callable $modelToDatabase)
    {
        $this->databaseToModel = $databaseToModel;
        $this->modelToDatabase = $modelToDatabase;
    }

    public function txDatabaseToModel($value)
    {
        return ($this->databaseToModel)($value);
    }

    public function txModelToDatabase($value)
    {
        return ($this->modelToDatabase)($value);
    }

}