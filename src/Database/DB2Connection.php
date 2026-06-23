<?php

namespace Easi\DB2\Database;

use Closure;
use Easi\DB2\Exceptions\TranslatedQueryException;
use Illuminate\Database\Grammar;
use Illuminate\Database\QueryException;
use PDO;

use Illuminate\Database\Connection;

use Easi\DB2\Database\Schema\Builder;
use Easi\DB2\Database\Query\Builder as QueryBuilder;
use Easi\DB2\Database\Query\Processors\DB2Processor;
use Easi\DB2\Database\Query\Processors\DB2ZOSProcessor;
use Easi\DB2\Database\Query\Grammars\DB2Grammar as QueryGrammar;
use Easi\DB2\Database\Schema\Grammars\DB2Grammar as SchemaGrammar;
use Easi\DB2\Database\Schema\Grammars\DB2ExpressCGrammar;
use PDOStatement;

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
    protected string $defaultSchema;
    /**
     * The name of the current schema in use.
     *
     * @var string
     */
    protected string $currentSchema;

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
    public function getDefaultSchema(): string
    {
        return $this->defaultSchema;
    }

    /**
     * Get the name of the current schema.
     *
     * @return string
     */
    public function getCurrentSchema(): string
    {
        return $this->currentSchema;
    }

    /**
     * Reset to default the current schema.
     */
    public function resetCurrentSchema(): void
    {
        $this->setCurrentSchema($this->getDefaultSchema());
    }

    /**
     * Set the name of the current schema.
     *
     * @param $schema
     */
    public function setCurrentSchema($schema): void
    {
        $this->statement('SET SCHEMA ?', [strtoupper($schema)]);
    }

    /**
     * Execute a system command on IBMi.
     *
     * @param $command
     */
    public function executeCommand($command): void
    {
        $this->statement('CALL QSYS2.QCMDEXC(?)', [$command]);
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return Builder
     */
    public function getSchemaBuilder(): Builder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new Builder($this);
    }

    /**
     * Get a new query builder instance.
     *
     * @return QueryBuilder
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * @return Grammar|QueryGrammar
     */
    protected function getDefaultQueryGrammar(): Grammar|QueryGrammar
    {
        $defaultGrammar = new QueryGrammar($this);

        if (array_key_exists('date_format', $this->config)) {
            $defaultGrammar->setDateFormat($this->config['date_format']);
        }

        if (array_key_exists('offset_compatibility_mode', $this->config)) {
            $defaultGrammar->setOffsetCompatibilityMode($this->config['offset_compatibility_mode']);
        }

        return $defaultGrammar;
    }

    /**
     * Default grammar for specified Schema
     *
     * @return SchemaGrammar|Grammar|DB2ExpressCGrammar
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar|Grammar|DB2ExpressCGrammar
    {
        return match ($this->config['driver']) {
            'db2_expressc_odbc' => new DB2ExpressCGrammar($this),
            default => new SchemaGrammar($this),
        };
    }

    /**
     * Get the default post-processor instance.
     *
     * @return DB2Processor|DB2ZOSProcessor
     */
    protected function getDefaultPostProcessor(): DB2ZOSProcessor|DB2Processor
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
     * @param  PDOStatement  $statement
     * @param  array  $bindings
     * @return void
     */
    public function bindValues($statement, $bindings): void
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

    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        $e = new TranslatedQueryException($e->getConnectionName(), $e->getSql(), $e->getBindings(), $e, $this->config);
        return parent::handleQueryException($e, $query, $bindings, $callback);
    }
}
