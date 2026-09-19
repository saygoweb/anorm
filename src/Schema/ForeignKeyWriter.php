<?php

namespace Anorm\Schema;

use Anorm\DataMapper;
use Anorm\Relationship\ManyHasMany;
use Anorm\Relationship\Relationship;
use Anorm\TableMaker;

/**
 * Creates the foreign key constraints a model's relationships declare.
 *
 * `Model` and `TableMaker` both had to do this, and both held their own copy of every
 * step — deriving the referenced table, checking whether the constraint was there,
 * creating a missing table, creating a missing column. The copies drifted: one
 * pluralized `y` to `ies` and the other did not, so the same relationship reached a
 * different table depending on which of them got there first. One copy makes that
 * particular bug unavailable rather than merely fixed.
 *
 * @see \Anorm\Schema\RelationshipSchema for the identifiers this writes
 */
class ForeignKeyWriter
{
    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create the constraints every relationship in $relationships declares.
     *
     * @param DataMapper $sourceMapper The mapper of the model declaring them
     * @param array<int|string, Relationship> $relationships
     * @return void
     */
    public function createFromRelationships(DataMapper $sourceMapper, array $relationships)
    {
        foreach ($relationships as $relationship) {
            $this->createFromRelationship($sourceMapper, $relationship);
        }
    }

    /**
     * Create the constraint one relationship declares.
     *
     * @param DataMapper $sourceMapper The mapper of the model declaring it
     * @param Relationship $relationship
     * @return void
     */
    public function createFromRelationship(DataMapper $sourceMapper, Relationship $relationship)
    {
        if ($relationship instanceof ManyHasMany) {
            $this->createManyHasMany($sourceMapper, $relationship);
            return;
        }

        $schema = RelationshipSchema::forRelationship($sourceMapper, $relationship, $this->pdo);
        if ($schema === null) {
            return;
        }
        $this->create($schema);
    }

    /**
     * Create the join table and both constraints a manyHasMany declares.
     *
     * @param DataMapper $sourceMapper
     * @param ManyHasMany $relationship
     * @return void
     */
    private function createManyHasMany(DataMapper $sourceMapper, ManyHasMany $relationship)
    {
        $joinTable = $relationship->getJoinTable();
        $joinForeignKey = $relationship->getJoinForeignKey();
        $joinRelatedKey = $relationship->getJoinRelatedKey();
        $sourceTable = $sourceMapper->table;
        $targetTable = RelationshipSchema::tableForClass($relationship->getRelatedModelClass(), $this->pdo);
        $primaryKey = RelationshipSchema::column($sourceMapper, $relationship->getPrimaryKey());
        $options = $relationship->getConstraintOptions();

        $this->createJoinTable($joinTable, $joinForeignKey, $joinRelatedKey);

        $this->create(new RelationshipSchema(
            $joinTable,
            $joinForeignKey,
            $sourceTable,
            $primaryKey,
            $options['constraint_name'] ?? "fk_{$joinTable}_{$joinForeignKey}",
            $options
        ));

        $this->create(new RelationshipSchema(
            $joinTable,
            $joinRelatedKey,
            $targetTable,
            $primaryKey,
            $options['constraint_name'] ?? "fk_{$joinTable}_{$joinRelatedKey}",
            $options
        ));
    }

    /**
     * Create a join table for a many-to-many relationship.
     *
     * @param string $joinTable
     * @param string $joinForeignKey
     * @param string $joinRelatedKey
     * @return void
     */
    private function createJoinTable($joinTable, $joinForeignKey, $joinRelatedKey)
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$joinTable}` (
                    `{$joinForeignKey}` INT(11) NOT NULL,
                    `{$joinRelatedKey}` INT(11) NOT NULL,
                    PRIMARY KEY (`{$joinForeignKey}`, `{$joinRelatedKey}`),
                    INDEX `idx_{$joinTable}_{$joinForeignKey}` (`{$joinForeignKey}`),
                    INDEX `idx_{$joinTable}_{$joinRelatedKey}` (`{$joinRelatedKey}`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->query($sql);
    }

    /**
     * Create one constraint, with the tables and columns it needs.
     *
     * The columns are created before the constraint, and as `INT(11)`, which is what
     * a key they have to match is. That ordering matters beyond the constraint: a
     * foreign key column created here is never left to be typed from a sampled value
     * later, and a sampled `lastInsertId()` is a string.
     *
     * @param RelationshipSchema $schema
     * @return void
     */
    public function create(RelationshipSchema $schema)
    {
        if ($this->exists($schema->table, $schema->constraintName)) {
            return;
        }

        $this->ensureTableExists($schema->table);
        $this->ensureTableExists($schema->referencedTable);
        $this->ensureColumnExists($schema->table, $schema->column);
        $this->ensureColumnExists($schema->referencedTable, $schema->referencedColumn);

        $onDelete = $schema->constraintOptions['on_delete'] ?? 'RESTRICT';
        $onUpdate = $schema->constraintOptions['on_update'] ?? 'CASCADE';

        $sql = "ALTER TABLE `{$schema->table}`
                ADD CONSTRAINT `{$schema->constraintName}`
                FOREIGN KEY (`{$schema->column}`)
                REFERENCES `{$schema->referencedTable}`(`{$schema->referencedColumn}`)
                ON DELETE {$onDelete}
                ON UPDATE {$onUpdate}";

        try {
            $this->pdo->query($sql);
        } catch (\PDOException $e) {
            // A duplicate constraint name means the constraint is already there, which is
            // what a re-run of dynamic schema creation looks like. Anything else leaves the
            // relationship the model declared without a constraint, so the caller is told.
            if (!TableMaker::isDuplicateConstraintError($e)) {
                throw $e;
            }
            $constraint = $schema->constraintName;
            error_log("Anorm: Foreign key constraint `$constraint` already exists on `{$schema->table}`: " . $e->getMessage());
        }
    }

    /**
     * @param string $table
     * @param string $constraintName
     * @return bool
     */
    public function exists($table, $constraintName)
    {
        $sql = "SELECT COUNT(*) as count
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ?
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'";

        $statement = $this->pdo->prepare($sql);
        $statement->execute([$table, $constraintName]);
        $result = $statement->fetch(\PDO::FETCH_ASSOC);

        return $result['count'] > 0;
    }

    /**
     * @param string $tableName
     * @return void
     */
    public function ensureTableExists($tableName)
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
            id INT(11) AUTO_INCREMENT PRIMARY KEY
        )";
        $this->pdo->query($sql);
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @return void
     */
    public function ensureColumnExists($tableName, $columnName)
    {
        $this->ensureTableExists($tableName);

        $sql = "SELECT COUNT(*) as count
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?";

        $statement = $this->pdo->prepare($sql);
        $statement->execute([$tableName, $columnName]);
        $result = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($result['count'] == 0) {
            // A key column matches the key it references, which is what the id
            // `ensureTableExists()` creates is.
            $sql = "ALTER TABLE `{$tableName}` ADD `{$columnName}` INT(11) NULL";
            $this->pdo->query($sql);
        }
    }
}
