<?php

namespace Anorm\Test\Fixtures\Models;

use Anorm\DataMapper;
use Anorm\Model;

/** A model whose constructor wants more than a PDO, as plenty of real ones do. */
class CliAwkwardModel extends Model
{
    public function __construct(\PDO $pdo, $tenant)
    {
        if ($tenant === null) {
            throw new \InvalidArgumentException('a tenant is required');
        }
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'cli_' . $tenant;
        parent::__construct($pdo, $mapper);
    }

    public $id;
}
