<?php

namespace Anorm\Test\Fixtures\Models;

use Anorm\Model;

/** An abstract base, as a project with several similar models tends to have. */
abstract class CliAbstractModel extends Model
{
    abstract public function label();
}
