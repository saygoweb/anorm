<?php
namespace Anorm;

class SqlException extends \Exception
{
    public function __construct($sql)
    {
        parent::__construct("SQL failed while '$sql`");
    }
}