<?php

namespace Easi\DB2\Database\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;

/**
 * Class Builder
 *
 * @package Easi\DB2\Database\Schema
 */
class Builder extends \Illuminate\Database\Schema\Builder
{
    /**
     * Determine if the given table exists.
     *
     * @param string $table
     *
     * @return bool
     */
    public function hasTable($table)
    {
        $sql = $this->grammar->compileTableExists();
        $schemaTable = explode(".", $table);

        if (count($schemaTable) > 1) {
            $schema = $schemaTable[0];
            $table = $this->connection->getTablePrefix() . $schemaTable[1];
        } else {
            $schema = $this->connection->getDefaultSchema();
            $table = $this->connection->getTablePrefix() . $table;
        }

        return count($this->connection->select($sql, [
                $schema,
                $table,
            ])) > 0;
    }

    /**
     * Get the column listing for a given table.
     *
     * @param string $table
     *
     * @return array
     */
    public function getColumnListing($table)
    {
        $sql = $this->grammar->compileColumnExists();
        $database = $this->connection->getDatabaseName();
        $table = $this->connection->getTablePrefix() . $table;

        $tableExploded = explode('.', $table);

        if (count($tableExploded) > 1) {
            $database = $tableExploded[0];
            $table = $tableExploded[1];
        }

        $results = $this->connection->select($sql, [
            $database,
            $table,
        ]);

        $res = $this->connection->getPostProcessor()
                                ->processColumnListing($results);

        return array_values(array_map(function($r) {
            return $r->column_name;
        }, $res));
    }

    /**
     * Execute the blueprint to build / modify the table.
     *
     * @param Blueprint $blueprint
     */
    protected function build(Blueprint $blueprint)
    {
        $schemaTable = explode(".", $blueprint->getTable());

        if (count($schemaTable) > 1) {
            $this->connection->setCurrentSchema($schemaTable[0]);
        }

        $blueprint->build($this->connection, $this->grammar);
        $this->connection->resetCurrentSchema();
    }

    /**
     * Create a new command set with a Closure.
     *
     * @param string $table
     * @param \Closure $callback
     *
     * @return \Easi\DB2\Database\Schema\Blueprint
     */
    protected function createBlueprint($table, Closure $callback = null)
    {
        if (isset($this->resolver)) {
            return call_user_func($this->resolver, $table, $callback);
        }

        return new \Easi\DB2\Database\Schema\Blueprint($this->connection, $table, $callback);
    }

    /**
     * Get all of the table names for the database.
     *
     * @return array
     */
    public function getAllTables()
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
     * @throws \LogicException
     */
    public function dropAllTables()
    {
        $tables = $this->getAllTables();
        foreach ($tables as $table)
        {
            $this->drop($table);
        }
    }
}
