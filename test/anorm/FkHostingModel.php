<?php

namespace Anorm\Test;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

/**
 * The child side of the foreign key identifier fixtures.
 *
 * The foreign key is named as a camelCase property, which is the spelling Anorm's
 * own auto-mapping exists to support: `companyId` is the property, `company_id` is
 * the column. The relationship is declared with the property name, as a model author
 * reading `belongsTo()`'s signature would write it.
 */
class FkHostingModel extends Model
{
    public function __construct(\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        $mapper = DataMapper::createByClass($pdo, $this);
        $mapper->table = 'fk_hostings';
        $mapper->mode = DataMapper::MODE_DYNAMIC;
        parent::__construct($pdo, $mapper);

        $this->belongsTo(FkCompanyModel::class, 'companyId', 'id', 'company');
    }

    public $id;
    public $companyId;
    public $label;

    /** @var FkCompanyModel */
    public $company;
}
