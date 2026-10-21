<?php

namespace Easi\DB2\Database\Gateway;

use BackedEnum;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Stringable;

/**
 * Compiles a query to a JSON operation descriptor instead of SQL. The "SQL" is opaque to
 * Laravel between grammar and connection, so ->toSql(), the query log and QueryException
 * all show the descriptor. Everything the gateway cannot execute throws here, at compile time.
 */
class GatewayGrammar extends Grammar
{
    private const OPERATORS = ['=' => '=', '!=' => '!=', '<>' => '!=', '<' => '<', '<=' => '<=', '>' => '>', '>=' => '>=', 'like' => 'like', 'not like' => 'notlike'];

    public function compileSelect(Builder $query)
    {
        $this->assertSingleFile($query, 'select');

        if ($query->lock) {
            throw new UnsupportedGatewayQuery('RMS records cannot be locked across gateway calls; the gateway unlocks after every verb.');
        }

        $descriptor = [
            'op' => 'select',
            'file' => $this->file($query),
            'columns' => $this->columns($query, $query->columns),
            'where' => $this->conditions($query, $query->wheres),
            'order' => $this->orders($query),
            'limit' => $query->limit,
            'offset' => $query->offset ?? 0,
        ];

        if ($query->distinct) {
            if ($query->limit !== null || $query->offset) {
                throw new UnsupportedGatewayQuery('distinct() is applied after the gateway returns rows, so it cannot be combined with limit/offset.');
            }
            $descriptor['distinct'] = true;
        }

        if ($query->aggregate) {
            $descriptor = $this->aggregate($query, $descriptor);
        }

        return $this->encode($descriptor);
    }

    public function compileExists(Builder $query)
    {
        $descriptor = json_decode($this->compileSelect($query), true);

        return $this->encode(['aggregate' => 'exists', 'columns' => [], 'limit' => 1, 'offset' => 0] + $descriptor);
    }

    public function compileInsert(Builder $query, array $values)
    {
        $this->assertSingleFile($query, 'insert');

        $rows = array_is_list($values) && is_array(reset($values)) ? $values : [$values];
        $columns = array_keys(reset($rows));

        return $this->encode([
            'op' => 'insert',
            'file' => $this->file($query),
            'columns' => $columns,
            'rows' => array_map(fn (array $row) => array_map(fn (string $column) => $this->value($row[$column] ?? null), $columns), $rows),
        ]);
    }

    public function compileUpdate(Builder $query, array $values)
    {
        $this->assertSingleFile($query, 'update');

        $set = [];
        foreach ($values as $column => $value) {
            $set[$this->column($query, $column)] = $this->value($value);
        }

        return $this->encode([
            'op' => 'update',
            'file' => $this->file($query),
            'set' => $set,
            'where' => $this->conditions($query, $query->wheres),
        ]);
    }

    public function compileDelete(Builder $query)
    {
        $this->assertSingleFile($query, 'delete');

        return $this->encode([
            'op' => 'delete',
            'file' => $this->file($query),
            'where' => $this->conditions($query, $query->wheres),
        ]);
    }

    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        throw new UnsupportedGatewayQuery('insertOrIgnore() is not supported by the RMS gateway; insert and catch the duplicate key (SQLSTATE 23505).');
    }

    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        throw new UnsupportedGatewayQuery('RMS files have no auto-increment keys; insertGetId() is not supported.');
    }

    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        throw new UnsupportedGatewayQuery('upsert() is not supported by the RMS gateway.');
    }

    public function compileTruncate(Builder $query)
    {
        throw new UnsupportedGatewayQuery('truncate() is not supported by the RMS gateway.');
    }

    public function supportsSavepoints()
    {
        return false;
    }

    /** The descriptor already carries its values; splicing bindings in would rewrite any "?" inside them. */
    public function substituteBindingsIntoRawSql($sql, $bindings)
    {
        return $sql;
    }

    /**
     * The gateway implements no aggregates. COUNT is the matched count every response carries,
     * MAX/MIN become a one-row read in key order, and exists() is "did a row come back".
     */
    private function aggregate(Builder $query, array $descriptor): array
    {
        $function = strtolower($query->aggregate['function']);

        if ($function === 'count') {
            return ['aggregate' => 'count', 'columns' => [], 'order' => [], 'limit' => 0, 'offset' => 0] + $descriptor;
        }

        if (in_array($function, ['max', 'min'], true)) {
            $column = $this->column($query, $query->aggregate['columns'][0]);

            return [
                'aggregate' => $function,
                'columns' => [$column],
                'order' => [['col' => $column, 'dir' => $function === 'max' ? 'desc' : 'asc']],
                'limit' => 1,
                'offset' => 0,
            ] + $descriptor;
        }

        throw new UnsupportedGatewayQuery("The RMS gateway has no {$function}() aggregate.");
    }

    /**
     * Where clauses become a list of conditions that are all true, where an {"any": [...]}
     * entry is true when one of its conditions is. That is every shape the app uses, and
     * all the COBOL evaluator has to understand.
     */
    private function conditions(Builder $query, array $wheres, bool $insideAny = false): array
    {
        $or = $this->isOrGroup($wheres);

        if ($or && ! $insideAny) {
            return [['any' => $this->conditions($query, $wheres, true)]];
        }

        $conditions = [];
        foreach ($wheres as $where) {
            $type = $where['type'];

            if ($type === 'Nested') {
                $nested = $where['query']->wheres;
                if (! $insideAny && $this->isOrGroup($nested)) {
                    $conditions[] = ['any' => $this->conditions($query, $nested, true)];
                } elseif ($insideAny === $this->isOrGroup($nested) || count($nested) === 1) {
                    array_push($conditions, ...$this->conditions($query, $nested, $insideAny));
                } else {
                    throw new UnsupportedGatewayQuery('The RMS gateway cannot evaluate an AND group inside an OR group.');
                }
                continue;
            }

            // RMS has no NULL, and Eloquent adds whereNotNull() to every hasMany query.
            if ($type === 'NotNull') {
                continue;
            }

            $column = $this->column($query, $where['column'] ?? '');

            if ($type === 'between' && ! $where['not'] && ! $insideAny) {
                [$low, $high] = array_values($where['values']);
                array_push($conditions, ['col' => $column, 'op' => '>=', 'val' => $this->value($low)], ['col' => $column, 'op' => '<=', 'val' => $this->value($high)]);
                continue;
            }

            $conditions[] = match ($type) {
                'Basic' => ['col' => $column, 'op' => $this->operator($where['operator']), 'val' => $this->value($where['value'])],
                'Like' => $this->like($column, $where),
                'In', 'InRaw' => ['col' => $column, 'op' => 'in', 'val' => array_map($this->value(...), array_values($where['values']))],
                'NotIn', 'NotInRaw' => ['col' => $column, 'op' => 'notin', 'val' => array_map($this->value(...), array_values($where['values']))],
                default => throw new UnsupportedGatewayQuery("The RMS gateway cannot evaluate a {$type} where clause."),
            };
        }

        return $conditions;
    }

    private function like(string $column, array $where): array
    {
        if ($where['caseSensitive']) {
            throw new UnsupportedGatewayQuery('The RMS gateway only implements case-insensitive LIKE.');
        }

        return ['col' => $column, 'op' => $where['not'] ? 'notlike' : 'like', 'val' => $this->value($where['value'])];
    }

    private function isOrGroup(array $wheres): bool
    {
        $booleans = array_unique(array_column(array_slice($wheres, 1), 'boolean'));

        if (count($booleans) > 1) {
            throw new UnsupportedGatewayQuery('The RMS gateway cannot mix AND and OR in one where group; wrap the OR part in a closure.');
        }

        return $booleans === ['or'];
    }

    private function orders(Builder $query): array
    {
        return array_map(function (array $order) use ($query) {
            if (! isset($order['column'])) {
                throw new UnsupportedGatewayQuery('The RMS gateway only orders by plain columns.');
            }

            return ['col' => $this->column($query, $order['column']), 'dir' => strtolower($order['direction'])];
        }, $query->orders ?? []);
    }

    private function columns(Builder $query, ?array $columns): array
    {
        $columns = array_values(array_filter($columns ?? [], fn ($column) => ! in_array($column, ['*', $this->file($query).'.*'], true)));

        return array_map(fn ($column) => $this->column($query, $column), $columns);
    }

    /** Strip the file qualifier Eloquent adds; a column of any other file means a join. */
    private function column(Builder $query, mixed $column): string
    {
        if ($column instanceof Expression || ! is_string($column) || str_contains(strtolower($column), ' as ')) {
            throw new UnsupportedGatewayQuery('The RMS gateway only accepts plain column names.');
        }

        $parts = explode('.', $column);
        if (count($parts) === 2 && $parts[0] === $this->file($query)) {
            return $parts[1];
        }
        if (count($parts) !== 1) {
            throw new UnsupportedGatewayQuery("Column {$column} does not belong to {$this->file($query)}.");
        }

        return $column;
    }

    private function operator(string $operator): string
    {
        return self::OPERATORS[strtolower($operator)]
            ?? throw new UnsupportedGatewayQuery("The RMS gateway has no {$operator} operator.");
    }

    /** Everything on the wire is a string: RMS has no NULL and converts per field from the FD. */
    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            $value instanceof BackedEnum => (string) $value->value,
            default => throw new UnsupportedGatewayQuery('The RMS gateway only accepts scalar values, not '.get_debug_type($value).'.'),
        };
    }

    private function file(Builder $query): string
    {
        if (! is_string($query->from)) {
            throw new UnsupportedGatewayQuery('The RMS gateway reads from a file name, not a subquery or expression.');
        }

        return $query->from;
    }

    private function assertSingleFile(Builder $query, string $verb): void
    {
        foreach (['joins', 'groups', 'havings', 'unions'] as $clause) {
            if (! empty($query->{$clause})) {
                throw new UnsupportedGatewayQuery("The RMS gateway cannot {$verb} with {$clause}: it reads one file at a time.");
            }
        }
    }

    private function encode(array $descriptor): string
    {
        return json_encode($descriptor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
