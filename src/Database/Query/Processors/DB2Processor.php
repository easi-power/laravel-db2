<?php

namespace Easi\DB2\Database\Query\Processors;

use Easi\DB2\Support\ConnectionGuard;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use Easi\DB2\Database\Query\Grammars\DB2Grammar;

/**
 * Class DB2Processor
 *
 * @package Easi\DB2\Database\Query\Processors
 */
class DB2Processor extends Processor
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Process an "insert get ID" query.
     *
     * @param Builder $query
     * @param string $sql
     * @param array $values
     * @param string|array|null $sequence
     *
     * @return int|string|array
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): int|string|array
    {
        $sequenceStr = $sequence ?: 'id';

        if (is_array($sequence)) {
            $grammar = new DB2Grammar(ConnectionGuard::assertConcrete($query->getConnection()));
            $sequenceStr = $grammar->columnize($sequence);
        }

        $sqlStr = 'select %s from new table (%s)';

        $finalSql = sprintf($sqlStr, $sequenceStr, $sql);
        $results = $query->getConnection()
                         ->select($finalSql, $values);

        $result = (array) $results[0];

        if (is_array($sequence)) {
            $ids = [];
            foreach ($sequence as $column) {
                $ids[$column] = $this->resolveColumn($result, $column);
            }

            return $ids;
        } else {
            $id = $this->resolveColumn($result, $sequenceStr);

            return is_numeric($id) ? (int) $id : $id;
        }
    }

    /**
     * Look up a column in a result row, falling back to its uppercased name.
     *
     * @param array $result
     * @param string $column
     * @return mixed
     */
    private function resolveColumn(array $result, string $column): mixed
    {
        return $result[$column] ?? $result[strtoupper($column)];
    }

    /**
     * Process the results of a "select" query.
     *
     * @param Builder $query
     * @param  array  $results
     * @return array
     */
    public function processSelect(Builder $query, $results): array
    {
        return array_map(function ($row) {
            $columns = array_map(function ($el) {
                if (! is_string($el)) {
                    return $el;
                }

                $el = trim($el);

                if (isset($this->config['from_encoding'])) {
                    return iconv($this->config['from_encoding'], 'utf-8', $el);
                }

                return $el;
            }, (array) $row);

            // Preserve the shape PDO handed us. The connection's fetch mode isn't
            // fixed (callers can pass a $fetchUsing mode to select()), so rows may
            // arrive as objects or arrays; the (array) cast above — needed to map
            // over the columns — is undone only when the original row was an object.
            return is_object($row) ? (object) $columns : $columns;
        }, $results);
    }
}
