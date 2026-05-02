<?php

namespace Anorm\Test\Lifecycle;

use Anorm\Lifecycle\ChangeListenerInterface;
use Anorm\Model;

class RecordingListener implements ChangeListenerInterface
{
    /** @var array<int, array{model: Model, diff: array, isInsert: bool}> */
    public $calls = [];

    public function onWrite(Model $model, array $diff, bool $isInsert): void
    {
        $this->calls[] = ['model' => $model, 'diff' => $diff, 'isInsert' => $isInsert];
    }
}
