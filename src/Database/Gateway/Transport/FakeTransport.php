<?php

namespace Easi\DB2\Database\Gateway\Transport;

use Easi\DB2\Database\Gateway\GatewayCodec;
use Easi\DB2\Database\Gateway\GatewayException;

/**
 * In-memory gateway: the reference implementation of the wire protocol, for tests and for
 * whoever writes the COBOL side. It verifies semantics, never performance — every select is
 * a linear scan with no index selection.
 */
class FakeTransport implements GatewayTransport
{
    /** @var array<string, list<array<string, string>>> file => records */
    public array $files = [];

    /** @var list<array<string, mixed>> every decoded request, in order */
    public array $requests = [];

    /** @param array<string, list<string>> $keys file => primary key columns, for duplicate-key errors */
    public function __construct(public array $keys = [])
    {
    }

    /** @param list<array<string, scalar>> $records */
    public function seed(string $file, array $records): static
    {
        foreach ($records as $record) {
            $this->files[$file][] = array_map(strval(...), $record);
        }

        return $this;
    }

    public function send(string $frame): string
    {
        $request = GatewayCodec::decodeRequest($frame);
        $this->requests[] = $request;

        try {
            return GatewayCodec::encodeResponse(match ($request['op']) {
                'select' => $this->select($request),
                'insert' => $this->insert($request),
                'update' => $this->update($request),
                'delete' => $this->delete($request),
            });
        } catch (GatewayException $e) {
            return GatewayCodec::encodeError($e->getCode(), $e->getMessage());
        }
    }

    private function select(array $request): array
    {
        $records = $this->files[$request['file']] ?? [];
        $matched = array_values(array_filter($records, fn (array $record) => $this->matches($record, $request['where'])));

        usort($matched, function (array $a, array $b) use ($request) {
            foreach ($request['order'] as [$column, $direction]) {
                $cmp = $this->compare($a[$column] ?? '', $b[$column] ?? '');
                if ($cmp !== 0) {
                    return $direction === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        $page = array_slice($matched, $request['offset'], $request['limit']);
        $columns = $request['columns'] ?: array_keys($records[0] ?? []);

        return [
            'columns' => $columns,
            'rows' => array_map(fn (array $record) => array_map(fn (string $column) => $record[$column] ?? '', $columns), $page),
            'count' => count($matched),
        ];
    }

    private function insert(array $request): array
    {
        foreach ($request['rows'] as $values) {
            $record = array_combine($request['columns'], $values);

            if ($this->exists($request['file'], $record)) {
                throw new GatewayException('23505', "Duplicate key in {$request['file']}.");
            }

            $this->files[$request['file']][] = $record;
        }

        return ['affected' => count($request['rows'])];
    }

    private function update(array $request): array
    {
        $affected = 0;
        foreach ($this->files[$request['file']] ?? [] as $i => $record) {
            if ($this->matches($record, $request['where'])) {
                $this->files[$request['file']][$i] = array_merge($record, $request['set']);
                $affected++;
            }
        }

        return ['affected' => $affected];
    }

    private function delete(array $request): array
    {
        $kept = array_values(array_filter($this->files[$request['file']] ?? [], fn (array $record) => ! $this->matches($record, $request['where'])));
        $affected = count($this->files[$request['file']] ?? []) - count($kept);
        $this->files[$request['file']] = $kept;

        return ['affected' => $affected];
    }

    /** Group 0 conditions must all hold; every other group needs one condition that holds. */
    private function matches(array $record, array $where): bool
    {
        $groups = [];
        foreach ($where as [$group, $column, $operator, $values]) {
            $holds = $this->holds($record[$column] ?? '', $operator, $values);

            if ($group === 0 && ! $holds) {
                return false;
            }
            $groups[$group] = ($groups[$group] ?? false) || $holds;
        }

        return ! in_array(false, $groups, true);
    }

    private function holds(string $field, string $operator, array $values): bool
    {
        return match ($operator) {
            '=' => $this->compare($field, $values[0]) === 0,
            '!=' => $this->compare($field, $values[0]) !== 0,
            '<' => $this->compare($field, $values[0]) < 0,
            '<=' => $this->compare($field, $values[0]) <= 0,
            '>' => $this->compare($field, $values[0]) > 0,
            '>=' => $this->compare($field, $values[0]) >= 0,
            'like' => $this->like($field, $values[0]),
            'notlike' => ! $this->like($field, $values[0]),
            'in' => array_filter($values, fn (string $value) => $this->compare($field, $value) === 0) !== [],
            'notin' => array_filter($values, fn (string $value) => $this->compare($field, $value) === 0) === [],
        };
    }

    /** Numeric fields compare as numbers (the gateway knows the PIC; here both sides must look numeric). */
    private function compare(string $a, string $b): int
    {
        return is_numeric($a) && is_numeric($b) ? $a <=> $b : strcmp($a, $b);
    }

    /** Case-insensitive, % for any run of characters, _ for exactly one. */
    private function like(string $field, string $pattern): bool
    {
        $regex = strtr(preg_quote($pattern, '/'), ['%' => '.*', '_' => '.']);

        return preg_match("/^{$regex}$/isu", $field) === 1;
    }

    private function exists(string $file, array $record): bool
    {
        $key = $this->keys[$file] ?? [];

        foreach ($key === [] ? [] : $this->files[$file] ?? [] as $existing) {
            if (array_filter($key, fn (string $column) => $this->compare($existing[$column] ?? '', $record[$column] ?? '') !== 0) === []) {
                return true;
            }
        }

        return false;
    }
}
