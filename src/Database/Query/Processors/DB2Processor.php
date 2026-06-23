<?php

namespace Easi\DB2\Database\Query\Processors;

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
     * @return int|array
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): int|array
    {
        $sequenceStr = $sequence ?: 'id';

        if (is_array($sequence)) {
            $grammar = new DB2Grammar($query->getConnection());
            $sequenceStr = $grammar->columnize($sequence);
        }

        $sqlStr = 'select %s from new table (%s)';

        $finalSql = sprintf($sqlStr, $sequenceStr, $sql);
        $results = $query->getConnection()
                         ->select($finalSql, $values);

        if (is_array($sequence)) {
            return array_values((array) $results[0]);
        } else {
            $result = (array) $results[0];
            if (isset($result[$sequenceStr])) {
                $id = $result[$sequenceStr];
            } else {
                $id = $result[strtoupper($sequenceStr)];
            }

            return is_numeric($id) ? (int) $id : $id;
        }
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
        foreach ($results as $index=>$result)
        {
            $results[$index] = array_map(function ($el) {
                $el = is_string($el) ? trim($el) : $el;
                if(isset($this->config['from_encoding']) && !is_null($el)) {
                    return iconv($this->config['from_encoding'], 'utf-8', $el);
                } else {
                    return $el;
                }
            }, (array)$result);
        }
        return $results;
    }
}
