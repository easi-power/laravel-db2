<?php

namespace Tests\Unit;

use DateTimeImmutable;
use Easi\DB2\Database\DB2Connection;
use Illuminate\Database\Query\Expression;
use Tests\Unit\Helpers\FakePDO;

function upsertTestConnection(): DB2Connection
{
    return new DB2Connection(new FakePDO(), 'TESTDB', '', [
        'driver' => 'db2_ibmi_odbc',
        'schema' => 'test',
    ]);
}

function compileUpsertSql(array $values, array $uniqueBy, array $update): string
{
    $connection = upsertTestConnection();

    return $connection->getQueryGrammar()->compileUpsert(
        $connection->table('products'),
        $values,
        $uniqueBy,
        $update,
    );
}

describe('compileUpsert', function () {
    it('compiles a multi-row merge with LF separators only', function () {
        $sql = compileUpsertSql(
            [
                ['sku' => 'A', 'sort_order' => 2, 'updated_at' => '2026-08-20 12:39:06'],
                ['sku' => 'B', 'sort_order' => 11, 'updated_at' => '2026-08-20 12:39:06'],
            ],
            ['sku'],
            ['sort_order', 'updated_at'],
        );

        expect($sql)->toBe(
            'MERGE INTO products as t USING (VALUES'
            .'(cast(? as VARCHAR(32)), cast(? as BIGINT), cast(? as VARCHAR(32))),'."\n"
            .'(cast(? as VARCHAR(32)), cast(? as BIGINT), cast(? as VARCHAR(32)))'
            .') as x (sku, sort_order, updated_at)'."\n"
            .'ON t.sku = x.sku'."\n"
            .'WHEN NOT MATCHED THEN INSERT (sku, sort_order, updated_at) VALUES (x.sku, x.sort_order, x.updated_at)'."\n"
            .'WHEN MATCHED THEN UPDATE SET'."\n"
            .'t.sort_order = x.sort_order,'."\n"
            .'t.updated_at = x.updated_at'
        );
    });

    it('never emits a carriage return, so the SQL does not depend on the host OS', function () {
        $sql = compileUpsertSql(
            [['sku' => 'A', 'updated_at' => '2026-08-20 12:39:06']],
            ['sku'],
            ['updated_at'],
        );

        expect($sql)->not->toContain("\r");
    });

    it('keeps the last update assignment intact', function () {
        $sql = compileUpsertSql(
            [['sku' => 'A', 'sort_order' => 2, 'updated_at' => '2026-08-20 12:39:06']],
            ['sku'],
            ['sort_order', 'updated_at'],
        );

        expect($sql)->toEndWith('t.updated_at = x.updated_at');
        expect($sql)->not->toContain('x.updated_a,');
    });

    // The aliases are declared once and referenced everywhere else; a grammar that quotes
    // identifiers has to quote both, or the references name an object the merge never declared.
    it('declares the merge aliases the same way it references them', function () {
        $sql = compileUpsertSql([['sku' => 'A', 'sort_order' => 2]], ['sku'], ['sort_order']);

        preg_match('/MERGE INTO \S+ as (\S+) USING /', $sql, $target);
        preg_match('/\) as (\S+) \(/', $sql, $source);

        expect($target[1])->not->toBeEmpty()
            ->and($source[1])->not->toBeEmpty()
            ->and($sql)->toContain('ON '.$target[1].'.')
            ->and($sql)->toContain(' = '.$source[1].'.')
            ->and($sql)->toContain('VALUES ('.$source[1].'.');
    });

    it('ands together a composite unique key', function () {
        $sql = compileUpsertSql(
            [['sku' => 'A', 'brand' => 'X', 'sort_order' => 2]],
            ['sku', 'brand'],
            ['sort_order'],
        );

        expect($sql)->toContain('ON t.sku = x.sku AND t.brand = x.brand'."\n");
    });
});

describe('compileUpsert casts', function () {
    it('casts integers to BIGINT so large keys do not overflow', function () {
        $sql = compileUpsertSql([['sku' => 'A', 'weight' => 3_000_000_000]], ['sku'], ['weight']);

        expect($sql)->toContain('cast(? as BIGINT)')
            ->and($sql)->not->toContain('cast(? as INT)');
    });

    it('casts floats to DECFLOAT rather than binding them as character data', function () {
        $sql = compileUpsertSql([['sku' => 'A', 'price' => 12.5]], ['sku'], ['price']);

        expect($sql)->toContain('(cast(? as VARCHAR(32)), cast(? as DECFLOAT))');
    });

    it('casts booleans to BIGINT, matching the int the connection binds', function () {
        $sql = compileUpsertSql([['sku' => 'A', 'active' => true]], ['sku'], ['active']);

        expect($sql)->toContain('(cast(? as VARCHAR(32)), cast(? as BIGINT))');
    });

    // Each row feeds the same derived-table column, so one column cannot carry two types.
    it('gives a column one type across every row', function () {
        $sql = compileUpsertSql(
            [
                ['sku' => 'A', 'sort_order' => 2],
                ['sku' => 'B', 'sort_order' => null],
            ],
            ['sku'],
            ['sort_order'],
        );

        expect(substr_count($sql, 'cast(? as BIGINT)'))->toBe(2);
    });

    it('widens a column holding both integers and floats to DECFLOAT', function () {
        $sql = compileUpsertSql(
            [
                ['sku' => 'A', 'price' => 2],
                ['sku' => 'B', 'price' => 12.5],
            ],
            ['sku'],
            ['price'],
        );

        expect(substr_count($sql, 'cast(? as DECFLOAT)'))->toBe(2);
    });

    it('falls back to the narrowest character type for an all-null column', function () {
        $sql = compileUpsertSql([['sku' => 'A', 'note' => null]], ['sku'], ['note']);

        expect($sql)->toContain('(cast(? as VARCHAR(32)), cast(? as VARCHAR(32)))');
    });

    it('inlines an expression instead of binding it', function () {
        $sql = compileUpsertSql(
            [['sku' => 'A', 'updated_at' => new Expression('current timestamp')]],
            ['sku'],
            ['updated_at'],
        );

        expect($sql)->toContain('(cast(? as VARCHAR(32)), current timestamp)');
    });
});

describe('compileUpsert character widths', function () {
    // A LOB is legal in the ON predicate but stops DB2 probing the target's index, and the
    // CLI layer sizes every LOB marker at its maximum. Short codes must not pay for that.
    it('sizes a character column instead of reaching for a LOB', function () {
        $sql = compileUpsertSql([['sku' => 'A', 'sort_order' => 1]], ['sku'], ['sort_order']);

        expect($sql)->toContain('cast(? as VARCHAR(32))')
            ->and($sql)->not->toContain('CLOB');
    });

    it('widens the cast to fit the longest value in the column', function () {
        $sql = compileUpsertSql(
            [
                ['sku' => 'A'],
                ['sku' => str_repeat('x', 40)],
            ],
            ['sku'],
            ['sku'],
        );

        expect(substr_count($sql, 'cast(? as VARCHAR(64))'))->toBe(2);
    });

    // Sizing to the exact longest value would give DB2 a new statement to cache whenever a
    // batch's contents shifted by a character.
    it('buckets the width so similar batches share one statement', function () {
        $shorter = compileUpsertSql([['sku' => str_repeat('x', 33)]], ['sku'], ['sku']);
        $longer = compileUpsertSql([['sku' => str_repeat('x', 40)]], ['sku'], ['sku']);

        expect($shorter)->toBe($longer)
            ->and($shorter)->toContain('VARCHAR(64)');
    });

    it('falls back to CLOB past the VARCHAR limit', function () {
        $sql = compileUpsertSql([['sku' => 'A', 'body' => str_repeat('x', 40000)]], ['sku'], ['body']);

        expect($sql)->toContain('cast(? as CLOB)');
    });

    it('sizes a date on the string the connection will bind, not the object', function () {
        $sql = compileUpsertSql(
            [['sku' => 'A', 'updated_at' => new DateTimeImmutable('2026-08-20 12:39:06')]],
            ['sku'],
            ['updated_at'],
        );

        expect($sql)->toContain('(cast(? as VARCHAR(32)), cast(? as VARCHAR(32)))');
    });
});

describe('compileUpsert update forms', function () {
    // Laravel's associative form: set the column to a literal, bound after the values.
    it('binds a literal for a string-keyed update entry', function () {
        $sql = compileUpsertSql(
            [['sku' => 'A', 'sort_order' => 2]],
            ['sku'],
            ['sort_order' => 0],
        );

        expect($sql)->toContain('WHEN MATCHED THEN UPDATE SET'."\n".'t.sort_order = ?');
    });

    it('mixes copied columns and literals in one update clause', function () {
        $sql = compileUpsertSql(
            [['sku' => 'A', 'sort_order' => 2, 'updated_at' => '2026-08-20 12:39:06']],
            ['sku'],
            ['updated_at', 'sort_order' => 0],
        );

        expect($sql)->toContain('t.updated_at = x.updated_at,'."\n".'t.sort_order = ?');
    });

    it('omits the matched clause when there is nothing to update', function () {
        $sql = compileUpsertSql([['sku' => 'A']], ['sku'], []);

        expect($sql)->not->toContain('WHEN MATCHED')
            ->and($sql)->toEndWith('WHEN NOT MATCHED THEN INSERT (sku) VALUES (x.sku)');
    });
});
