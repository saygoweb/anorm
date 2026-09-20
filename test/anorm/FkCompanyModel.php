<?php

namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

/**
 * The parent side of the foreign key identifier fixtures.
 *
 * The class name matters: `FkCompanyModel` pluralizes to `fk_companies` only under
 * the `y` -> `ies` rule, so a derivation that simply appends `s` reaches a table
 * that is not this one.
 */
class FkCompanyModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'fk_companies';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public $name;
}
