<?php

namespace Anorm\Lifecycle;

use Anorm\Model;

interface ChangeListenerInterface
{
    /**
     * Invoked once per successful DataMapper::write().
     *
     * @param Model $model    The model that was just persisted (primary key populated).
     * @param array $diff     ['property' => ['from' => mixed, 'to' => mixed]]. Empty when
     *                        nothing changed; always empty when $isInsert is true.
     * @param bool  $isInsert True when the model had no prior snapshot.
     */
    public function onWrite(Model $model, array $diff, bool $isInsert): void;
}
