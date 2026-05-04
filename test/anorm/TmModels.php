<?php

namespace Anorm\Test;

use Anorm\DataMapper;
use Anorm\Model;

/**
 * Minimal fixture models for TableMaker FK integration tests.
 * Class names chosen so getTableNameFromModelClass() derives 'tm_parents' / 'tm_items'.
 */
class TmParentModel extends Model
{
    public $id;
    public $name;
    /** @var TmItemModel[] */
    public $items;

    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'tm_parents';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
        $this->hasMany(TmItemModel::class, 'parent_id', 'id', 'items');
    }
}

class TmItemModel extends Model
{
    public $id;
    public $name;
    public $parent_id;
    /** @var TmParentModel */
    public $parent;

    public function __construct(\PDO $pdo)
    {
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'tm_items';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);
        $this->belongsTo(TmParentModel::class, 'parent_id', 'id', 'parent');
    }
}
