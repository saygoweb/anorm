<?php

namespace Anorm\Test\Fixtures\Models;

use Anorm\DataMapper;
use Anorm\Model;

/** Right column type, no constraint: a warning, not a failure. */
class CliOrderModel extends Model
{
    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'cli_orders';
        parent::__construct($pdo, $mapper);
        $this->belongsTo(CliClientModel::class, 'clientId', 'id', 'client');
    }

    public $id;
    public ?int $clientId = null;
}
