<?php

namespace Tests\Unit\Gateway;

use Easi\DB2\Database\Gateway\GatewayConnection;
use Easi\DB2\Database\Gateway\Transport\FakeTransport;
use Easi\DB2\Database\Gateway\UnsupportedGatewayQuery;
use Illuminate\Database\Query\Builder;

function gateway(): GatewayConnection
{
    return new GatewayConnection(new FakeTransport(), ['driver' => 'vms_rms_gateway', 'name' => 'rms']);
}

function descriptor(Builder $query): array
{
    return json_decode($query->toSql(), true);
}

/** The descriptor a terminal call (count(), max(), exists()...) sends, captured without running it. */
function sent(callable $call): array
{
    $connection = gateway();

    return json_decode($connection->pretend(fn () => $call($connection))[0]['query'], true);
}

it('compiles a keyed read, stripping the file qualifier Eloquent adds', function () {
    $query = gateway()->table('TOFDETA')->where('TOFDETA.TOFHEAD_NUMMER', 9000001)->orderBy('NUMMER')->limit(10);

    expect(descriptor($query))->toBe([
        'op' => 'select', 'file' => 'TOFDETA', 'columns' => [],
        'where' => [['col' => 'TOFHEAD_NUMMER', 'op' => '=', 'val' => '9000001']],
        'order' => [['col' => 'NUMMER', 'dir' => 'asc']],
        'limit' => 10, 'offset' => 0,
    ]);
});

it('compiles an OR group in a closure to an "any" condition', function () {
    $query = gateway()->table('OFFADRES')
        ->where('OFFKLANT_NUMMER', '1')
        ->where(fn ($q) => $q->where('NAAM', 'like', '%x%')->orWhere('STRAAT', 'like', '%x%'));

    expect(descriptor($query)['where'])->toBe([
        ['col' => 'OFFKLANT_NUMMER', 'op' => '=', 'val' => '1'],
        ['any' => [['col' => 'NAAM', 'op' => 'like', 'val' => '%x%'], ['col' => 'STRAAT', 'op' => 'like', 'val' => '%x%']]],
    ]);
});

it('compiles in, integer-in (eager loading), between and like, and drops whereNotNull', function () {
    $query = gateway()->table('TOFHEAD')
        ->whereIn('STATUS', ['A', 'B'])
        ->whereIntegerInRaw('NUMMER', [1, 2])
        ->whereNotIn('VERT', ['X'])
        ->whereBetween('NUMMER', [9000000, 9099999])
        ->whereNotNull('OPVOLG_DAT')
        ->whereLike('REFERTE', 'abc%')
        ->where('OPVOLG_DAT', '<>', '');

    expect(descriptor($query)['where'])->toBe([
        ['col' => 'STATUS', 'op' => 'in', 'val' => ['A', 'B']],
        ['col' => 'NUMMER', 'op' => 'in', 'val' => ['1', '2']],
        ['col' => 'VERT', 'op' => 'notin', 'val' => ['X']],
        ['col' => 'NUMMER', 'op' => '>=', 'val' => '9000000'],
        ['col' => 'NUMMER', 'op' => '<=', 'val' => '9099999'],
        ['col' => 'REFERTE', 'op' => 'like', 'val' => 'abc%'],
        ['col' => 'OPVOLG_DAT', 'op' => '!=', 'val' => ''],
    ]);
});

it('turns MAX and MIN into a one-row read in key order', function () {
    expect(sent(fn ($db) => $db->table('TOFHEAD')->whereBetween('NUMMER', [1, 9])->orderBy('REFERTE')->max('NUMMER')))
        ->toMatchArray(['aggregate' => 'max', 'columns' => ['NUMMER'], 'order' => [['col' => 'NUMMER', 'dir' => 'desc']], 'limit' => 1])
        ->and(sent(fn ($db) => $db->table('TOFDETA')->min('NUMMER')))
        ->toMatchArray(['aggregate' => 'min', 'order' => [['col' => 'NUMMER', 'dir' => 'asc']], 'limit' => 1]);
});

it('turns COUNT into a zero-row read that asks for the matched count', function () {
    expect(sent(fn ($db) => $db->table('TOFHEAD')->where('STATUS', 'A')->orderBy('NUMMER')->count()))
        ->toMatchArray(['aggregate' => 'count', 'columns' => [], 'order' => [], 'limit' => 0]);
});

it('turns exists() into a one-row read', function () {
    expect(sent(fn ($db) => $db->table('TOFHEAD')->where('NUMMER', 1)->exists()))
        ->toMatchArray(['aggregate' => 'exists', 'limit' => 1]);
});

it('compiles insert, update and delete', function () {
    expect(sent(fn ($db) => $db->table('X')->insert(['A' => 1, 'B' => null])))
        ->toBe(['op' => 'insert', 'file' => 'X', 'columns' => ['A', 'B'], 'rows' => [['1', '']]])
        ->and(sent(fn ($db) => $db->table('X')->where('A', 1)->update(['B' => 'y'])))
        ->toBe(['op' => 'update', 'file' => 'X', 'set' => ['B' => 'y'], 'where' => [['col' => 'A', 'op' => '=', 'val' => '1']]])
        ->and(sent(fn ($db) => $db->table('X')->where('A', 1)->delete()))
        ->toBe(['op' => 'delete', 'file' => 'X', 'where' => [['col' => 'A', 'op' => '=', 'val' => '1']]]);
});

it('refuses what one RMS file read cannot do', function (callable $query) {
    $query(gateway()->table('TOFHEAD'));
})->throws(UnsupportedGatewayQuery::class)->with([
    'join' => [fn ($q) => $q->join('TOFDETA', 'TOFDETA.TOFHEAD_NUMMER', '=', 'TOFHEAD.NUMMER')->toSql()],
    'group by' => [fn ($q) => $q->groupBy('STATUS')->toSql()],
    'raw where' => [fn ($q) => $q->whereRaw('1 = 1')->toSql()],
    'raw order' => [fn ($q) => $q->orderByRaw('NUMMER desc')->toSql()],
    'where exists' => [fn ($q) => $q->whereExists(fn ($s) => $s->from('TOFDETA'))->toSql()],
    'column of another file' => [fn ($q) => $q->where('TOFDETA.NUMMER', 1)->toSql()],
    'mixed AND/OR' => [fn ($q) => $q->where('A', 1)->orWhere('B', 2)->where('C', 3)->toSql()],
    'lock' => [fn ($q) => $q->lockForUpdate()->toSql()],
    'insertOrIgnore' => [fn ($q) => $q->insertOrIgnore(['A' => 1])],
    'sum' => [fn ($q) => $q->sum('PRIJS')],
    'increment' => [fn ($q) => $q->where('A', 1)->increment('TELLER')],
]);
