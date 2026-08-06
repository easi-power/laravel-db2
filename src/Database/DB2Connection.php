<?php

namespace Easi\DB2\Database;

use Closure;
use Easi\DB2\Exceptions\TranslatedQueryException;
use Illuminate\Database\Grammar;
use Easi\DB2\Exceptions\TranslatedUniqueConstraintViolationException;
use Exception;
use PDO;
use Throwable;
use Illuminate\Support\Str;

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

    public function __construct(PDO|Closure $pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
        $this->currentSchema = $this->defaultSchema = strtoupper($config['schema'] ?? null);

        foreach ((array) ($config['startup_command'] ?? []) as $command) {
            $this->executeCommand($command);
        }

        foreach ((array) ($config['startup_statement'] ?? []) as $statement) {
            $this->statement($statement);
        }
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

    public function escape($value, $binary = false): string
    {
        if (!$binary && is_string($value) && !empty($this->config['from_encoding'])) {
            $value = iconv('utf-8', $this->config['from_encoding'], $value);
        }

        return parent::escape($value, $binary);
    }

    protected function escapeBinary($value): string
    {
        return "X'" . strtoupper(bin2hex($value)) . "'";
    }

    protected function escapeString($value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
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

    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return stripos($exception->getMessage(), "SQL0803") !== false;
    }

    /**
     * Determine if the given exception was caused by a lock-conflict error.
     *
     * @param Throwable $e
     * @return bool
     */
    protected function causedByConcurrencyError(Throwable $e): bool
    {
        if (parent::causedByConcurrencyError($e)) {
            return true;
        }

        return Str::contains($e->getMessage(), [
            'SQL0913',
            'Row or object',
        ]);
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

    protected function runQueryCallback($query, $bindings, Closure $callback): mixed
    {
        // To execute the statement, we'll simply call the callback, which will actually
        // run the SQL against the PDO connection. Then we can calculate the time it
        // took to execute and log the query SQL, bindings and time in our memory.
        try {
            return $callback($query, $bindings);
        }

        // If an exception occurs when attempting to run a query, we'll format the error
        // message to include the bindings with SQL, which will make this exception a
        // lot more helpful to the developer instead of just the database's errors.
        catch (Exception $e) {
            if ($this->isUniqueConstraintError($e)) {
                throw new TranslatedUniqueConstraintViolationException(
                    $this->getName(), $query, $this->prepareBindings($bindings), $e, $this->getQueryGrammar(), $this->config
                );
            }

            throw new TranslatedQueryException(
                $this->getName(), $query, $this->prepareBindings($bindings), $e, $this->getQueryGrammar(), $this->config
            );
        }
    }
}
