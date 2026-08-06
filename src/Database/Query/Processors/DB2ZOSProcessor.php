<?php

namespace Easi\DB2\Database\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use Easi\DB2\Database\Query\Grammars\DB2Grammar;

/**
 * Class DB2ZOSProcessor
 *
 * @package Easi\DB2\Database\Query\Processors
 */
class DB2ZOSProcessor extends Processor
{
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
            $grammar = new DB2Grammar($query->getConnection());
            $sequenceStr = $grammar->columnize($sequence);
        }

        $sqlStr = 'select %s from final table (%s)';

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
     * Look up a column in a result row, falling back to its lowercased name.
     *
     * @param array $result
     * @param string $column
     * @return mixed
     */
    private function resolveColumn(array $result, string $column): mixed
    {
        return $result[$column] ?? $result[strtolower($column)];
    }
}
