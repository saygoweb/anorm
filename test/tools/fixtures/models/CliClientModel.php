<?php

namespace Anorm\Test\Fixtures\Models;

use Anorm\DataMapper;
use Anorm\Model;

/** A model whose table matches it. */
class CliClientModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'cli_clients';
        parent::__construct($pdo, $mapper);
    }

    public $id;
    public ?string $name = null;
}
