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
     * @return bool Returns false if not found.
     */
    public function read($id)
    {
        return $this->_mapper->read($this, $id);
    }
    
    /**
     * @param int $id The primary key id of the model to read.
     * @return bool Returns true if found, throws \Exception if not found.
     * @throws \Exception if not found.
     */
    public function readOrThrow($id)
    {
        $result = $this->_mapper->read($this, $id);
        if (!$result) {
            $className = get_class($this);
            $className = str_replace('Model', '', $className);
            $tokens = explode('\\', $className);
            if ($tokens && count($tokens) > 0) {
                $className = $tokens[count($tokens) - 1];
            }
            throw new \Exception("$className id '$id' not found");
        }
        return $result;
    }
    
}