<?php

namespace Easi\DB2\Database\Gateway;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Descriptor (what GatewayGrammar emits) <-> wire frame (what the COBOL gateway parses).
 *
 * A frame is an 8-digit ASCII byte length followed by records separated by RS (0x1E),
 * fields separated by US (0x1F). See docs/vms-rms-gateway.md for the record layout.
 */
final class GatewayCodec
{
    public const RECORD = "\x1E";

    public const FIELD = "\x1F";

    /** @param array<string, mixed> $descriptor */
    public static function encodeRequest(array $descriptor): string
    {
        $records = [[
            'H',
            $descriptor['op'],
            $descriptor['file'],
            $descriptor['limit'] ?? '',
            $descriptor['offset'] ?? 0,
            ($descriptor['aggregate'] ?? null) === 'count' ? 1 : 0,
        ]];

        if (isset($descriptor['columns'])) {
            $records[] = ['C', ...$descriptor['columns']];
        }

        if (! empty($descriptor['order'])) {
            $records[] = ['O', ...array_merge(...array_map(fn (array $o) => [$o['col'], $o['dir']], $descriptor['order']))];
        }

        foreach ($descriptor['rows'] ?? [] as $row) {
            $records[] = ['V', ...$row];
        }

        foreach ($descriptor['set'] ?? [] as $column => $value) {
            $records[] = ['S', $column, $value];
        }

        $group = 0;
        foreach ($descriptor['where'] ?? [] as $condition) {
            if (isset($condition['any'])) {
                $group++;
                foreach ($condition['any'] as $alternative) {
                    $records[] = self::condition($group, $alternative);
                }
            } else {
                $records[] = self::condition(0, $condition);
            }
        }

        return self::frame($records);
    }

    /** @return array{op: string, file: string, limit: ?int, offset: int, count: bool, columns: list<string>, order: list<array{0: string, 1: string}>, where: list<array{0: int, 1: string, 2: string, 3: list<string>}>, rows: list<list<string>>, set: array<string, string>} */
    public static function decodeRequest(string $frame): array
    {
        $records = self::unframe($frame);
        [, $op, $file, $limit, $offset, $count] = array_shift($records);

        $request = [
            'op' => $op, 'file' => $file,
            'limit' => $limit === '' ? null : (int) $limit, 'offset' => (int) $offset, 'count' => $count === '1',
            'columns' => [], 'order' => [], 'where' => [], 'rows' => [], 'set' => [],
        ];

        foreach ($records as $fields) {
            match (array_shift($fields)) {
                'C' => $request['columns'] = $fields,
                'O' => $request['order'] = array_chunk($fields, 2),
                'V' => $request['rows'][] = $fields,
                'S' => $request['set'][$fields[0]] = $fields[1],
                'W' => $request['where'][] = [(int) $fields[0], $fields[1], $fields[2], array_slice($fields, 3)],
            };
        }

        return $request;
    }

    /** @param array{columns?: list<string>, rows?: list<list<string>>, count?: int, truncated?: bool, affected?: int} $result */
    public static function encodeResponse(array $result): string
    {
        $records = [['OK', $result['count'] ?? count($result['rows'] ?? []), ($result['truncated'] ?? false) ? 1 : 0, $result['affected'] ?? 0]];
        $records[] = ['C', ...($result['columns'] ?? [])];

        foreach ($result['rows'] ?? [] as $row) {
            $records[] = ['R', ...$row];
        }

        return self::frame($records);
    }

    public static function encodeError(string $sqlState, string $message): string
    {
        return self::frame([['ER', $sqlState, $message]]);
    }

    /**
     * @return array{columns: list<string>, rows: list<list<string>>, count: int, truncated: bool, affected: int}
     *
     * @throws GatewayException on an error frame
     */
    public static function decodeResponse(string $frame): array
    {
        $records = self::unframe($frame);
        $status = array_shift($records);

        if ($status[0] === 'ER') {
            throw new GatewayException($status[1], $status[2] ?? '');
        }

        $result = ['columns' => [], 'rows' => [], 'count' => (int) $status[1], 'truncated' => $status[2] === '1', 'affected' => (int) $status[3]];

        foreach ($records as $fields) {
            if (array_shift($fields) === 'C') {
                $result['columns'] = $fields;
            } else {
                // RMS fields are fixed width and space padded.
                $result['rows'][] = array_map(rtrim(...), $fields);
            }
        }

        return $result;
    }

    /** @param array{col: string, op: string, val: string|list<string>} $condition */
    private static function condition(int $group, array $condition): array
    {
        return ['W', $group, $condition['col'], $condition['op'], ...(array) $condition['val']];
    }

    /** @param list<list<scalar>> $records */
    private static function frame(array $records): string
    {
        $payload = implode(self::RECORD, array_map(function (array $fields) {
            foreach ($fields as $field) {
                if (str_contains((string) $field, self::RECORD) || str_contains((string) $field, self::FIELD)) {
                    throw new InvalidArgumentException('A gateway field may not contain the RS (0x1E) or US (0x1F) separator.');
                }
            }

            return implode(self::FIELD, $fields);
        }, $records));

        return sprintf('%08d', strlen($payload)).$payload;
    }

    /** @return list<list<string>> */
    private static function unframe(string $frame): array
    {
        $payload = substr($frame, 8);

        if (strlen($frame) < 8 || (int) substr($frame, 0, 8) !== strlen($payload)) {
            throw new UnexpectedValueException('Malformed gateway frame: length prefix does not match the payload.');
        }

        return array_map(fn (string $record) => explode(self::FIELD, $record), explode(self::RECORD, $payload));
    }
}
