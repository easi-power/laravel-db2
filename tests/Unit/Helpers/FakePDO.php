<?php

namespace Tests\Unit\Helpers;

use Exception;
use PDO;
use PDOStatement;

class FakePDO extends PDO
{
    public ?Exception $exceptionToThrow = null;

    /** When set, the exception is only thrown by statements whose SQL contains this. */
    public ?string $throwOnlyFor = null;

    public array $selectResults = [];

    /** @var list<string> Every statement prepared, in order. */
    public array $preparedQueries = [];

    private bool $inTransaction = false;

    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct() {}

    public function prepare($query, $options = []): PDOStatement|false
    {
        $this->preparedQueries[] = $query;

        $statement = new FakePdoStatement;
        $statement->exceptionToThrow = $this->throwOnlyFor === null || str_contains($query, $this->throwOnlyFor)
            ? $this->exceptionToThrow
            : null;
        $statement->results = $this->selectResults;

        return $statement;
    }

    public function beginTransaction(): bool
    {
        return $this->inTransaction = true;
    }

    public function commit(): bool
    {
        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->inTransaction = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }
}
