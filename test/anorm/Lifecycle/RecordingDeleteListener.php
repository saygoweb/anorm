<?php

namespace Anorm\Test\Lifecycle;

use Anorm\Lifecycle\ChangeListenerInterface;
use Anorm\Lifecycle\DeleteListenerInterface;
use Anorm\Model;

class RecordingDeleteListener implements ChangeListenerInterface, DeleteListenerInterface
{
    /** @var array<int, array{model: Model, diff: array, isInsert: bool}> */
    public $writes = [];

    /** @var array<int, array{table: string, id: mixed, model: Model|null}> */
    public $deletes = [];

    public function onWrite(Model $model, array $diff, bool $isInsert): void
    {
        $this->writes[] = ['model' => $model, 'diff' => $diff, 'isInsert' => $isInsert];
    }

    public function onDelete(string $table, $id, ?Model $model): void
    {
        $this->deletes[] = ['table' => $table, 'id' => $id, 'model' => $model];
    }
}
