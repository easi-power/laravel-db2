<?php

namespace Easi\DB2\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Builder;

/**
 * Class DB2Grammar
 *
 * @package Easi\DB2\Database\Query\Grammars
 */
class DB2Grammar extends Grammar
{
    /**
     * The format for database-stored dates.
     *
     * @var string
     */
    protected string $dateFormat;

    /**
     * Offset compatibility mode true triggers FETCH FIRST X ROWS and ROW_NUM behavior for older versions of DB2
     * @var bool
     */
    protected bool $offsetCompatibilityMode = true;

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param string $value
     *
     * @return string
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        return str_replace('"', '""', $value);
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param Builder $query
     * @param int                                $limit
     *
     * @return string
     */
    protected function compileLimit(Builder $query, $limit): string
    {
        if($this->offsetCompatibilityMode){
            return "FETCH FIRST $limit ROWS ONLY";
        }
        return parent::compileLimit($query, $limit);
    }

    /**
     * Compile the lock into SQL.
     *
     * @param Builder $query
     * @param bool|string $value
     *
     * @return string
     */
    protected function compileLock(Builder $query, $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return $value
            ? 'with rs use and keep exclusive locks'
            : 'with rs';
    }

    /**
     * Compile a select query into SQL.
     *
     * @param Builder $query
     *
     * @return string
     */
    public function compileSelect(Builder $query): string
    {
        if (!$this->offsetCompatibilityMode) {
            $sql = parent::compileSelect($query);
        } else {
            if (is_null($query->columns)) {
                $query->columns = ['*'];
            }

            $components = $this->compileComponents($query);

            // If an offset is present on the query, we will need to wrap the query in
            // a big "ANSI" offset syntax block. This is very nasty compared to the
            // other database systems but is necessary for implementing features.
            $sql = $query->offset > 0
                ? $this->compileAnsiOffset($query, $components)
                : $this->concatenate($components);
        }

        /** @var array<string, Builder> $expressions */
        $expressions = get_object_vars($query)['expressions'] ?? [];
        if (count($expressions) === 0) {
            return $sql;
        }

        $cteParts = collect($expressions)
            ->map(fn (Builder $expr, string $name) => "$name AS ({$this->compileSelect($expr)})")
            ->implode(', ');

        return "WITH $cteParts $sql";
    }

    /**
     * Create a full ANSI offset clause for the query.
     *
     * @param Builder $query
     * @param array $components
     *
     * @return string
     */
    protected function compileAnsiOffset(Builder $query, array $components): string
    {
        // An ORDER BY clause is required to make this offset query work, so if one does
        // not exist, we'll just create a dummy clause to trick the database, and so it
        // does not complain about the queries for not having an "order by" clause.
        if (!isset($components['orders'])) {
            $components['orders'] = 'order by 1';
        }

        unset($components['limit']);

        // We need to add the row number to the query so we can compare it to the offset
        // and limit values given for the statements. So we will add an expression to
        // the "select" that will give back the row numbers on each of the records.
        $orderings = $components['orders'];

        $columns = (!empty($components['columns']) ? $components['columns'] . ', ' : 'select');

        if ($columns == 'select *, ' && $query->from) {
            $columns = 'select ' . $this->connection->getTablePrefix() . $query->from . '.*, ';
        }

        $components['columns'] = $this->compileOver($orderings, $columns);

        // if there are bindings in the order, we need to move them to the select since we are moving the parameter
        // markers there with the OVER statement
        if(isset($query->getRawBindings()['order'])){
            $query->addBinding($query->getRawBindings()['order'], 'select');
            $query->setBindings([], 'order');
        }

        unset($components['orders']);

        // Next, we need to calculate the constraints that should be placed on the query
        // to get the right offset and limit from our query. However, if there is no limit
        // set, we will just handle the offset only since that is all that matters.
        $start = $query->offset + 1;

        $constraint = $this->compileRowConstraint($query);

        $sql = $this->concatenate($components);

        // We are now ready to build the final SQL query, so we'll create a common table
        // expression from the query and get the records with row numbers within our
        // given limit and offset value that we just put on as a query constraint.
        return $this->compileTableExpression($sql, $constraint);
    }

    /**
     * Compile the over statement for a table expression.
     *
     * @param string $orderings
     * @param        $columns
     *
     * @return string
     */
    protected function compileOver(string $orderings, $columns): string
    {
        return "{$columns} row_number() over ({$orderings}) as row_num";
    }

    /**
     * @param $query
     *
     * @return string
     */
    protected function compileRowConstraint($query): string
    {
        $start = $query->offset + 1;

        if ($query->limit > 0) {
            $finish = $query->offset + $query->limit;

            return "between {$start} and {$finish}";
        }

        return ">= {$start}";
    }

    /**
     * Compile a common table expression for a query.
     *
     * @param string $sql
     * @param string $constraint
     *
     * @return string
     */
    protected function compileTableExpression(string $sql, string $constraint): string
    {
        return "select * from ({$sql}) as temp_table where row_num {$constraint}";
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param Builder $query
     * @param int                                $offset
     *
     * @return string
     */
    protected function compileOffset(Builder $query, $offset): string
    {
        if($this->offsetCompatibilityMode){
            return '';
        }
        return parent::compileOffset($query, $offset);
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @param Builder $query
     * @return string
     */
    public function compileExists(Builder $query): string
    {
        $existsQuery = clone $query;

        $existsQuery->columns = [];

        return $this->compileSelect($existsQuery->selectRaw('1 exists')->limit(1));
    }

    /**
     * Get the format for database-stored dates.
     *
     * @return string
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat ?? parent::getDateFormat();
    }

    /**
     * Set the format for database-stored dates.
     *
     * @param $dateFormat
     */
    public function setDateFormat($dateFormat): void
    {
        $this->dateFormat = $dateFormat;
    }

    /**
     * Set offset compatibility mode to trigger FETCH FIRST X ROWS and ROW_NUM behavior for older versions of DB2
     *
     * @param $bool
     */
    public function setOffsetCompatibilityMode($bool): void
    {
        $this->offsetCompatibilityMode = $bool;
    }

    /**
     * Compile the SQL statement to define a savepoint.
     *
     * @param  string  $name
     * @return string
     */
    public function compileSavepoint($name): string
    {
        return 'SAVEPOINT '.$name.' ON ROLLBACK RETAIN CURSORS';
    }

    /**
     * Compile an "upsert" statement into SQL.
     *
     * @param Builder $query
     * @param  array  $values
     * @param  array  $uniqueBy
     * @param  array  $update
     * @return string
     */
    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update): string
    {
        $table = $this->wrapTable($query->from);

        $keys = collect($values[0])->keys();
        $keysString = "(".$keys->implode(", ").")";
        $tuples = [];
        foreach ($values as $value)
        {
            $tuples[] = "(".$this->parameterizeWithTypes($value).")";
        }
        $valuesString = "VALUES".implode(",".PHP_EOL, $tuples);

        // Start statement
        $sql = "MERGE INTO $table as t USING ($valuesString) as x $keysString".PHP_EOL;

        // Unique key constraint
        foreach ($uniqueBy as $index => $uniqueCol)
        {
            if($index === 0) {
                $sql .= "ON t.$uniqueCol = x.$uniqueCol ";
            } else {
                $sql .= "AND t.$uniqueCol = x.$uniqueCol ";
            }
        }
        $sql .= PHP_EOL;

        // When no match => INSERT
        $values = "VALUES (".$keys->map(function($key) {
            return "x.$key";
        })->implode(', ').")";

        $sql .= "WHEN NOT MATCHED THEN INSERT $keysString $values".PHP_EOL;

        // When matched => update
        $sql .= "WHEN MATCHED THEN UPDATE SET".PHP_EOL;
        foreach ($update as $col)
        {
            $sql .= "t.$col = x.$col,".PHP_EOL;
        }
        return substr($sql, 0, -3);
    }

    /**
     * Create query parameter place-holders for an array.
     *
     * @param  array  $values
     * @return string
     */
    public function parameterizeWithTypes(array $values): string
    {
        return implode(', ', array_map([$this, 'parameterWithType'], $values));
    }

    /**
     * Get the appropriate query parameter place-holder for a value.
     *
     * @param  mixed  $value
     * @return string
     */
    public function parameterWithType(mixed $value): string
    {
        if($this->isExpression($value)) {
            return $this->getValue($value);
        } else {
            if(is_int($value)) {
                return 'cast(? as INT)';
            } else {
                return 'cast(? as CLOB)';
            }
        }
    }


}
