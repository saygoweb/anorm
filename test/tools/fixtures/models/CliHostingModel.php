<?php

namespace Anorm\Test\Fixtures\Models;

use Anorm\DataMapper;
use Anorm\Model;

/** A model whose foreign key column was typed by a lookup before the first write. */
class CliHostingModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'cli_hostings';
        parent::__construct($pdo, $mapper);
        $this->belongsTo(CliClientModel::class, 'clientId', 'id', 'client');
    }

    public $id;
    public ?int $clientId = null;
    public $loose;
}
