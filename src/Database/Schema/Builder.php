<?php

namespace Easi\DB2\Database\Schema;

use Closure;
use Easi\DB2\Database\DB2Connection;
use Easi\DB2\Database\Schema\Grammars\DB2Grammar;
use Illuminate\Database\Schema\Blueprint;
use LogicException;

/**
 * Class Builder
 *
 * @package Easi\DB2\Database\Schema
 *
 * @property DB2Connection $connection
 * @property DB2Grammar $grammar
 */
class Builder extends \Illuminate\Database\Schema\Builder
{
    /**
     * Get the column listing for a given table.
     *
     * @param string $table
     *
     * @return array
     */
    public function getColumnListing($table): array
    {
        $sql = $this->grammar->compileColumnExists();
        $schemaTable = explode('.', $table);

        if (count($schemaTable) > 1) {
            $schema = $schemaTable[0];
            $table = $schemaTable[1];
        } else {
            $schema = $this->connection->getDefaultSchema();
            $table = $this->connection->getTablePrefix() . $table;
        }

        $results = $this->connection->select($sql, [
            $schema,
            $table,
        ]);

        return array_values(array_map(function($r) {
            return $r->column_name;
        }, $results));
    }

    /**
     * Execute the blueprint to build / modify the table.
     *
     * @param Blueprint $blueprint
     */
    protected function build(Blueprint $blueprint): void
    {
        $schemaTable = explode(".", $blueprint->getTable());

        if (count($schemaTable) > 1) {
            $this->connection->setCurrentSchema($schemaTable[0]);
        }

        $blueprint->build();
        $this->connection->resetCurrentSchema();
    }

    /**
     * Create a new command set with a Closure.
     *
     * @param string $table
     * @param Closure|null $callback
     *
     * @return Blueprint
     */
    protected function createBlueprint($table, ?Closure $callback = null): Blueprint
    {
        $connection = $this->connection;

        if (isset($this->resolver)) {
            return call_user_func($this->resolver, $table, $callback);
        }

        return new \Easi\DB2\Database\Schema\Blueprint($connection, $table, $callback);
    }

    /**
     * Get all the table names for the database.
     *
     * @return array
     */
    public function getAllTables(): array
    {
        $list = $this->connection->select(
            $this->grammar->compileGetAllTables(),
            [$this->connection->getDefaultSchema()]
        );
        return array_map(function($tableObject) {
            $tableObject = (array)$tableObject;
            return $tableObject['00001'];
        }, $list);
    }

    /**
     * Drop all tables from the database.
     *
     * @return void
     *
     * @throws LogicException
     */
    public function dropAllTables(): void
    {
        $tables = $this->getAllTables();
        foreach ($tables as $table)
        {
            $this->drop($table);
        }
    }
}
