<?php

namespace Anorm\Test;

use Anorm\Model;

/**
 * A model that cannot be constructed with a connection alone.
 *
 * Reaching a related model's mapper is the better answer, but it is not always
 * available, and a model that refuses to be built is not a failure to report — the
 * derived name is what the caller had before there was anything better.
 */
class FkUnbuildableModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        throw new \RuntimeException('this model needs more than a connection: ' . ($pdo === null ? 'none' : 'one'));
    }
}
