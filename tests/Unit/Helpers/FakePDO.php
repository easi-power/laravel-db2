<?php

namespace Tests\Unit\Helpers;

use Exception;
use PDO;
use PDOStatement;

class FakePDO extends PDO
{
    public ?Exception $exceptionToThrow = null;

    public array $selectResults = [];

    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct() {}

    public function prepare($query, $options = []): PDOStatement|false
    {
        $statement = new FakePdoStatement;
        $statement->exceptionToThrow = $this->exceptionToThrow;
        $statement->results = $this->selectResults;

        return $statement;
    }
}