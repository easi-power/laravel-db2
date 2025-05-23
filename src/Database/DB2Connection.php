<?php

namespace Easi\DB2\Database;

use Easi\DB2\Exceptions\TranslatedQueryException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDO;

use Illuminate\Database\Connection;

use Easi\DB2\Database\Schema\Builder;
use Easi\DB2\Database\Query\Processors\DB2Processor;
use Easi\DB2\Database\Query\Processors\DB2ZOSProcessor;
use Easi\DB2\Database\Query\Grammars\DB2Grammar as QueryGrammar;
use Easi\DB2\Database\Schema\Grammars\DB2Grammar as SchemaGrammar;
use Easi\DB2\Database\Schema\Grammars\DB2ExpressCGrammar;

/**
 * Class DB2Connection
 *
 * @package Easi\DB2\Database
 */
class DB2Connection extends Connection
{
    /**
     * The name of the default schema.
     *
     * @var string
     */
    protected $defaultSchema;
    /**
     * The name of the current schema in use.
     *
     * @var string
     */
    protected $currentSchema;

    public function __construct(PDO $pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
        $this->currentSchema = $this->defaultSchema = strtoupper($config['schema'] ?? null);
    }

    /**
     * Get the name of the default schema.
     *
     * @return string
     */
    public function getDefaultSchema()
    {
        return $this->defaultSchema;
    }

    /**
     * Reset to default the current schema.
     *
     * @return string
     */
    public function resetCurrentSchema()
    {
        $this->setCurrentSchema($this->getDefaultSchema());
    }

    /**
     * Set the name of the current schema.
     *
     * @param $schema
     *
     * @return string
     */
    public function setCurrentSchema($schema)
    {
        $this->statement('SET SCHEMA ?', [strtoupper($schema)]);
    }

    /**
     * Execute a system command on IBMi.
     *
     * @param $command
     *
     * @return string
     */
    public function executeCommand($command)
    {
        $this->statement('CALL QSYS2.QCMDEXC(?)', [$command]);
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Easi\DB2\Database\Schema\Builder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new Builder($this);
    }

    /**
     * @return \Illuminate\Database\Grammar
     */
    protected function getDefaultQueryGrammar()
    {
        $defaultGrammar = new QueryGrammar($this);

        if (array_key_exists('date_format', $this->config)) {
            $defaultGrammar->setDateFormat($this->config['date_format']);
        }

        if (array_key_exists('offset_compatibility_mode', $this->config)) {
            $defaultGrammar->setOffsetCompatibilityMode($this->config['offset_compatibility_mode']);
        }

        // Apply table prefix if it exists
        if ($this->tablePrefix !== '') {
            $defaultGrammar->setTablePrefix($this->tablePrefix);
        }

        return $defaultGrammar;
    }

    /**
     * Default grammar for specified Schema
     *
     * @return \Illuminate\Database\Grammar
     */
    protected function getDefaultSchemaGrammar()
    {
        switch ($this->config['driver']) {
            case 'db2_expressc_odbc':
                $defaultGrammar = new DB2ExpressCGrammar($this);
                break;
            default:
                $defaultGrammar = new SchemaGrammar($this);
                break;
        }

        // Apply table prefix if it exists
        if ($this->tablePrefix !== '') {
            $defaultGrammar->setTablePrefix($this->tablePrefix);
        }

        return $defaultGrammar;
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Easi\DB2\Database\Query\Processors\DB2Processor|\Easi\DB2\Database\Query\Processors\DB2ZOSProcessor
     */
    protected function getDefaultPostProcessor()
    {
        if ($this->config['driver'] === 'db2_zos_odbc') {
            $defaultProcessor = new DB2ZOSProcessor;
        } else {
            $defaultProcessor = new DB2Processor($this->config);
        }

        return $defaultProcessor;
    }

    /**
     * Bind values to their parameters in the given statement.
     *
     * @param  \PDOStatement  $statement
     * @param  array  $bindings
     * @return void
     */
    public function bindValues($statement, $bindings)
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                isset($this->config['from_encoding']) && $this->config['from_encoding'] && !is_null($value) ?
                    iconv('utf-8', $this->config['from_encoding'], $value)
                    : $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
    }

    protected function handleQueryException(QueryException $e, $query, $bindings, \Closure $callback)
    {
        $e = new TranslatedQueryException($e->getConnectionName(), $e->getSql(), $e->getBindings(), $e, $this->config);
        return parent::handleQueryException($e, $query, $bindings, $callback);
    }
}
