<?php

namespace Easi\DB2\Database\Gateway;

use Closure;
use Easi\DB2\Database\Gateway\Transport\GatewayTransport;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use LogicException;

/**
 * A Laravel connection whose "SQL" is a GatewayGrammar descriptor, sent over a transport to the
 * COBOL gateway on VMS instead of through PDO.
 *
 * There are no transactions: RMS has none to offer, and inventing one would mean a write-ahead
 * log in COBOL. transaction() still runs its callback and fires its events, but rolls nothing
 * back — callers get safety from write ordering (children before parents), not atomicity.
 */
class GatewayConnection extends Connection
{
    /** Whether the gateway capped the last select's scan, so its rows are a floor, not the truth. */
    public bool $lastResultTruncated = false;

    public function __construct(private readonly GatewayTransport $transport, array $config = [])
    {
        parent::__construct(new class
        {
            public function beginTransaction(): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }

            public function rollBack(): bool
            {
                return true;
            }

            public function inTransaction(): bool
            {
                return false;
            }
        }, $config['database'] ?? '', $config['prefix'] ?? '', $config);
    }

    public function transport(): GatewayTransport
    {
        return $this->transport;
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        return $this->run($query, $bindings, function (string $query) {
            if ($this->pretending()) {
                return [];
            }

            $descriptor = json_decode($query, true, flags: JSON_THROW_ON_ERROR);
            $result = $this->send($query, $descriptor);

            return match ($descriptor['aggregate'] ?? null) {
                'count' => [(object) ['aggregate' => $result['count']]],
                'max', 'min' => [(object) ['aggregate' => $result['rows'][0][0] ?? null]],
                'exists' => [(object) ['exists' => $result['rows'] !== []]],
                default => $this->toObjects($result, $descriptor['distinct'] ?? false),
            };
        });
    }

    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function (string $query) {
            if ($this->pretending()) {
                return true;
            }

            $this->send($query);
            $this->recordsHaveBeenModified();

            return true;
        });
    }

    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function (string $query) {
            if ($this->pretending()) {
                return 0;
            }

            $affected = $this->send($query)['affected'];
            $this->recordsHaveBeenModified($affected > 0);

            return $affected;
        });
    }

    public function getSchemaBuilder()
    {
        throw new LogicException('The RMS gateway has no schema to build: the legacy application owns its files.');
    }

    protected function getDefaultQueryGrammar()
    {
        return new GatewayGrammar($this);
    }

    /**
     * The transport reconnects by itself on its next send, so there is nothing to rebuild. A
     * select is always retried once; a write only if it never reached the gateway, since one lost
     * while reading may already be done.
     */
    protected function tryAgainIfCausedByLostConnection(QueryException $e, $query, $bindings, Closure $callback)
    {
        $message = $e->getPrevious()?->getMessage() ?? '';
        $lost = str_starts_with($message, 'Lost the RMS gateway') || str_starts_with($message, 'Cannot reach the RMS gateway');

        if ($lost && (! str_contains($message, 'while reading') || str_contains($query, '"op":"select"'))) {
            return $this->runQueryCallback($query, $bindings, $callback);
        }

        throw $e;
    }

    private function send(string $query, ?array $descriptor = null): array
    {
        $descriptor ??= json_decode($query, true, flags: JSON_THROW_ON_ERROR);
        $encoding = $this->getConfig('encoding');
        if ($encoding) {
            array_walk_recursive($descriptor, function (&$value) use ($encoding) {
                $value = is_string($value) ? $this->toFiles($value, $encoding) : $value;
            });
        }

        $result = GatewayCodec::decodeResponse($this->transport->send(GatewayCodec::encodeRequest($descriptor)));
        $this->lastResultTruncated = $result['truncated'];

        if ($encoding) {
            $result['rows'] = array_map(fn (array $row) => array_map(fn ($value) => mb_convert_encoding($value, 'UTF-8', $encoding), $row), $result['rows']);
        }

        return $result;
    }

    /** A character the files' encoding cannot hold is refused, never replaced. */
    private function toFiles(string $text, string $encoding): string
    {
        $converted = mb_convert_encoding($text, $encoding, 'UTF-8');

        if (! mb_check_encoding($text, 'UTF-8') || mb_convert_encoding($converted, 'UTF-8', $encoding) !== $text) {
            throw new GatewayException('22021', "Text that {$encoding} cannot hold: {$text}");
        }

        return $converted;
    }

    /** @return list<object> */
    private function toObjects(array $result, bool $distinct): array
    {
        $rows = array_map(fn (array $row) => array_combine($result['columns'], $row), $result['rows']);

        if ($distinct) {
            $rows = array_values(array_unique($rows, SORT_REGULAR));
        }

        return array_map(fn (array $row) => (object) $row, $rows);
    }
}
