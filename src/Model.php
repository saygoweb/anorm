<?php
namespace Anorm;

class Model
{
    /** @var DataMapper */
    public $_mapper;

    public function __construct(\PDO $pdo, DataMapper $mapper)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->_mapper = $mapper;
    }

    /**
     * @return int The primary key id of the model.
     */
    public function write()
    {
        return $this->_mapper->write($this);
    }

    /**
     * @param int $id The primary key id of the model to read.
     */
    public function read($id)
    {
        return $this->_mapper->read($this, $id);
    }
    
}