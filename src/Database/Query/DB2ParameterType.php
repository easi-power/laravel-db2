<?php

namespace Easi\DB2\Database\Query;

use Illuminate\Contracts\Database\Query\Expression;
use Stringable;

/**
 * The DB2 type a bound parameter is cast to inside a VALUES-derived table.
 *
 * A parameter marker in `MERGE ... USING (VALUES (?, ...))` is untyped: a literal carries
 * its own type, but a marker has no target column to infer one from, so the CAST is what
 * gives the derived table's column a type. IBM's own prepared-statement MERGE example
 * casts every marker for this reason. No other Laravel driver needs it — SQL Server
 * compiles the same statement with bare markers, because T-SQL infers them from the
 * merge target.
 *
 * Character values are cast to a VARCHAR sized from the data rather than to a LOB. A LOB
 * is legal here, but it is expensive twice over: the derived column in the ON predicate
 * stops DB2 probing the target's index, and every LOB marker is sized at its maximum by
 * the CLI layer, so a handful of short codes is handled as if it were megabytes. IBM's
 * example casts to CHAR/VARCHAR for the same statement shape.
 *
 * @package Easi\DB2\Database\Query
 */
final class DB2ParameterType
{
    /** Widest VARCHAR DB2 for i accepts; past it only a LOB will hold the value. */
    private const int VARCHAR_LIMIT = 32739;

    /**
     * Longest row DB2 for i allows, overhead included, while it holds no LOB.
     *
     * A row that does hold one is bounded by 3 758 096 383 instead, which no VALUES list
     * built from bound parameters will reach.
     */
    private const int ROW_LIMIT = 32766;

    /** What a LOB costs in the row itself; the value lives outside it. */
    private const int LOB_DESCRIPTOR = 64;

    /**
     * Declared widths are rounded up to a multiple of this.
     *
     * Sizing to the exact longest value would emit different SQL each time a batch's
     * contents changed, and DB2 would cache a separate statement for every variant.
     * Bucketing keeps similar batches sharing one.
     */
    private const int VARCHAR_BUCKET = 32;

    private function __construct(
        private readonly string $type,
        private readonly ?int $length = null,
    ) {
    }

    /**
     * PHP integers are 64-bit, so INT (31-bit) silently overflows on large keys.
     * DB2 assignment conversion narrows a BIGINT into an INT column without complaint.
     */
    public static function bigInt(): self
    {
        return new self('BIGINT');
    }

    /** Holds the full range of a PHP float, and of any integer alongside it. */
    public static function decFloat(): self
    {
        return new self('DECFLOAT');
    }

    /** A character column wide enough for the given number of bytes. */
    public static function varchar(int $length): self
    {
        $buckets = max(1, (int) ceil($length / self::VARCHAR_BUCKET));

        return new self('VARCHAR', $buckets * self::VARCHAR_BUCKET);
    }

    /** The fallback for character data too long for a VARCHAR. */
    public static function clob(): self
    {
        return new self('CLOB');
    }

    /**
     * Resolve the one type covering every value a derived-table column will hold.
     *
     * Each row of a VALUES list feeds the same column, so the cast has to be decided
     * across the whole column rather than per cell — otherwise a column that is an int in
     * one row and a string in the next gets two conflicting types.
     *
     * Callers should pass values as the connection will bind them, dates already
     * formatted, so that the width measured here is the width that arrives.
     *
     * @param array $values
     * @return DB2ParameterType
     */
    public static function forColumn(array $values): self
    {
        $hasCharacter = false;
        $hasFloat = false;
        $hasInteger = false;
        $width = 0;

        foreach ($values as $value) {
            // NULL says nothing about the column it sits in, and an Expression is raw SQL
            // that never becomes a bound parameter at all.
            if ($value === null || $value instanceof Expression) {
                continue;
            }

            // Bindings reach PDO after Connection::prepareBindings() has cast bools to int.
            if (is_int($value) || is_bool($value)) {
                $hasInteger = true;
            } elseif (is_float($value)) {
                $hasFloat = true;
            } else {
                $hasCharacter = true;
                $width = max($width, self::widthOf($value));
            }
        }

        return match (true) {
            // A string cannot be cast to a numeric type, so character wins any mix.
            $hasCharacter => self::character($width),
            $hasFloat => self::decFloat(),
            $hasInteger => self::bigInt(),
            // Nothing to go on: an all-NULL column still needs a type to be valid SQL.
            default => self::character(0),
        };
    }

    /**
     * Trade character columns for LOBs until the derived row fits.
     *
     * Sizing each column on its own can still build a row past the 32766-byte limit, even
     * though every column is individually legal. Moving the widest one to a LOB both drops
     * its width from the row and lifts the row into the far larger LOB budget, so in
     * practice one pass is enough — but the loop does not assume that.
     *
     * @param array<array-key, self> $types
     * @return array<array-key, self>
     */
    public static function fitRow(array $types): array
    {
        while (self::combinedRowWidth($types) > self::ROW_LIMIT) {
            $widest = self::widestSizedCharacter($types);

            // Only fixed-width columns left: there is nothing further to trade away.
            if ($widest === null) {
                break;
            }

            $types[$widest] = self::clob();
        }

        return $types;
    }

    /**
     * What this column contributes to the row.
     */
    public function rowWidth(): int
    {
        return match ($this->type) {
            // Two bytes of length prefix on top of the declared width.
            'VARCHAR' => $this->length + 2,
            'BIGINT' => 8,
            'DECFLOAT' => 16,
            default => self::LOB_DESCRIPTOR,
        };
    }

    /**
     * The placeholder to emit for this type.
     */
    public function placeholder(): string
    {
        return $this->length === null
            ? 'cast(? as '.$this->type.')'
            : 'cast(? as '.$this->type.'('.$this->length.'))';
    }

    /**
     * The combined row footprint of a set of columns.
     *
     * @param array<array-key, self> $types
     */
    private static function combinedRowWidth(array $types): int
    {
        return array_sum(array_map(static fn (self $type): int => $type->rowWidth(), $types));
    }

    /**
     * The key of the widest column still worth trading for a LOB, if any.
     *
     * @param array<array-key, self> $types
     * @return array-key|null
     */
    private static function widestSizedCharacter(array $types): string|int|null
    {
        $widest = null;

        foreach ($types as $key => $type) {
            if ($type->type !== 'VARCHAR') {
                continue;
            }

            if ($widest === null || $type->length > $types[$widest]->length) {
                $widest = $key;
            }
        }

        return $widest;
    }

    /**
     * The character type able to hold the given width.
     */
    private static function character(int $width): self
    {
        return $width > self::VARCHAR_LIMIT ? self::clob() : self::varchar($width);
    }

    /**
     * The number of bytes a value occupies once bound.
     *
     * Measured on the UTF-8 string. A `from_encoding` connection converts to a
     * single-byte encoding before binding, which only ever shrinks it.
     */
    private static function widthOf(mixed $value): int
    {
        return is_scalar($value) || $value instanceof Stringable
            ? strlen((string) $value)
            : self::VARCHAR_LIMIT + 1;
    }
}
