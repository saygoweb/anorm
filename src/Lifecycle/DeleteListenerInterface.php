<?php

namespace Anorm\Lifecycle;

use Anorm\Model;

/**
 * Opt-in companion to ChangeListenerInterface for deletions.
 *
 * Registered through the same slot, DataMapper::setChangeListener(). A listener
 * that wants both events implements both interfaces; existing write-only
 * listeners are unaffected.
 */
interface DeleteListenerInterface
{
    /**
     * Invoked once per DataMapper::delete() that removed at least one row.
     *
     * @param string     $table The table the row was deleted from.
     * @param int|string $id    The primary key value that was deleted.
     * @param Model|null $model The model, when the delete came through
     *                          Model::delete(); null for $mapper->delete($id).
     */
    public function onDelete(string $table, $id, ?Model $model): void;
}
