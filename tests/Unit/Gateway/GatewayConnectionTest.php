<?php

namespace Tests\Unit\Gateway;

use Easi\DB2\Database\Gateway\GatewayConnection;
use Easi\DB2\Database\Gateway\Transport\FakeTransport;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

class GatewayQuote extends Model
{
    protected $connection = 'rms';

    protected $table = 'TOFHEAD';

    protected $primaryKey = 'NUMMER';

    public $incrementing = false;

    public $timestamps = false;

    public function lines(): HasMany
    {
        return $this->hasMany(GatewayQuoteLine::class, 'TOFHEAD_NUMMER', 'NUMMER');
    }
}

class GatewayQuoteLine extends Model
{
    protected $connection = 'rms';

    protected $table = 'TOFDETA';

    public $timestamps = false;
}

beforeEach(function () {
    $this->fake = (new FakeTransport(['TOFHEAD' => ['NUMMER'], 'TOFDETA' => ['TOFHEAD_NUMMER', 'NUMMER']]))
        ->seed('TOFHEAD', [
            ['NUMMER' => 9000001, 'REFERTE' => 'Pomp A', 'STATUS' => 'O'],
            ['NUMMER' => 9000002, 'REFERTE' => 'Pomp B', 'STATUS' => 'G'],
            ['NUMMER' => 9000010, 'REFERTE' => 'Kraan', 'STATUS' => 'O'],
        ])
        ->seed('TOFDETA', [
            ['TOFHEAD_NUMMER' => 9000001, 'NUMMER' => 1, 'PRIJS' => '1250.00'],
            ['TOFHEAD_NUMMER' => 9000001, 'NUMMER' => 2, 'PRIJS' => '300.00'],
            ['TOFHEAD_NUMMER' => 9000002, 'NUMMER' => 1, 'PRIJS' => '10.00'],
        ]);

    $this->db = new GatewayConnection($this->fake, ['driver' => 'vms_rms_gateway', 'name' => 'rms']);
    Model::setConnectionResolver(new ConnectionResolver(['rms' => $this->db]));
});

it('reads rows as objects, in key order', function () {
    $lines = $this->db->table('TOFDETA')->where('TOFHEAD_NUMMER', 9000001)->orderByDesc('NUMMER')->get();

    expect($lines->pluck('NUMMER')->all())->toBe(['2', '1'])
        ->and($lines->first()->PRIJS)->toBe('300.00');
});

it('answers count, exists, max and min from plain reads', function () {
    $quotes = fn () => $this->db->table('TOFHEAD');

    expect($quotes()->where('STATUS', 'O')->count())->toBe(2)
        ->and($quotes()->where('NUMMER', 9000002)->exists())->toBeTrue()
        ->and($quotes()->where('NUMMER', 1)->exists())->toBeFalse()
        ->and($quotes()->whereBetween('NUMMER', [9000000, 9000009])->max('NUMMER'))->toBe('9000002')
        ->and($quotes()->min('NUMMER'))->toBe('9000001')
        ->and($quotes()->where('NUMMER', 1)->max('NUMMER'))->toBeNull();
});

it('serves both reads paginate() makes: the matched count and the requested page', function () {
    $query = $this->db->table('TOFHEAD')->orderBy('NUMMER');

    expect($query->getCountForPagination())->toBe(3)
        ->and($query->forPage(2, 2)->pluck('NUMMER')->all())->toBe(['9000010']);
});

it('applies distinct to the returned rows', function () {
    expect($this->db->table('TOFHEAD')->distinct()->pluck('STATUS')->sort()->values()->all())->toBe(['G', 'O']);
});

it('reports a duplicate key as a QueryException with SQLSTATE 23505', function () {
    try {
        $this->db->table('TOFHEAD')->insert(['NUMMER' => 9000001, 'REFERTE' => 'dup']);
        $this->fail('No exception');
    } catch (QueryException $e) {
        // App code matches the SQLSTATE class '23' as a string to retry a key collision.
        expect($e->getCode())->toBe('23505');
    }
});

it('returns affected counts for update and delete', function () {
    expect($this->db->table('TOFDETA')->where('TOFHEAD_NUMMER', 9000001)->update(['PRIJS' => '0.00']))->toBe(2)
        ->and($this->db->table('TOFDETA')->where('TOFHEAD_NUMMER', 9000001)->delete())->toBe(2)
        ->and($this->db->table('TOFDETA')->where('TOFHEAD_NUMMER', 9000001)->delete())->toBe(0);
});

it('runs transaction callbacks but rolls nothing back', function () {
    try {
        $this->db->transaction(function () {
            $this->db->table('TOFDETA')->where('TOFHEAD_NUMMER', 9000002)->delete();
            throw new \RuntimeException('half way');
        });
    } catch (\RuntimeException) {
    }

    expect($this->db->table('TOFDETA')->where('TOFHEAD_NUMMER', 9000002)->count())->toBe(0)
        ->and($this->db->transactionLevel())->toBe(0);
});

it('serves Eloquent: find, eager-loaded hasMany, save', function () {
    $quotes = GatewayQuote::query()->with('lines')->whereIn('NUMMER', [9000001, 9000002])->orderBy('NUMMER')->get();

    expect($quotes->map(fn ($q) => $q->lines->count())->all())->toBe([2, 1]);

    $quote = GatewayQuote::find(9000010);
    $quote->REFERTE = 'Kraan XL';
    $quote->save();

    expect(GatewayQuote::find(9000010)->REFERTE)->toBe('Kraan XL')
        ->and(end($this->fake->requests))->toMatchArray(['op' => 'select', 'file' => 'TOFHEAD']);
});

it('has no schema builder', function () {
    $this->db->getSchemaBuilder();
})->throws(\LogicException::class);

it('converts text to and from the files\' encoding, and refuses what it cannot hold', function () {
    $db = new GatewayConnection($this->fake, ['driver' => 'vms_rms_gateway', 'name' => 'rms', 'encoding' => 'ISO-8859-1']);

    $db->table('TOFHEAD')->insert(['NUMMER' => 9000020, 'REFERTE' => 'Église Müller', 'STATUS' => 'O']);

    expect(end($this->fake->requests)['rows'][0])->toContain("\xC9glise M\xFCller")
        ->and($db->table('TOFHEAD')->where('REFERTE', 'Église Müller')->value('REFERTE'))->toBe('Église Müller');

    $sent = count($this->fake->requests);
    expect(fn () => $db->table('TOFHEAD')->where('NUMMER', 9000020)->update(['REFERTE' => '5 €']))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('22021'))
        ->and(count($this->fake->requests))->toBe($sent);
});

it('retries a select after a lost connection, a write only when it never arrived', function () {
    $flaky = new class($this->fake) implements \Easi\DB2\Database\Gateway\Transport\GatewayTransport
    {
        public ?string $failNext = null;

        public int $sends = 0;

        public function __construct(private readonly FakeTransport $inner) {}

        public function send(string $frame): string
        {
            $this->sends++;
            if ($this->failNext !== null) {
                [$message, $this->failNext] = [$this->failNext, null];
                throw new \RuntimeException("Lost the RMS gateway at vms:7300 while {$message}.");
            }

            return $this->inner->send($frame);
        }
    };
    $db = new GatewayConnection($flaky, ['driver' => 'vms_rms_gateway', 'name' => 'rms']);

    $flaky->failNext = 'reading';
    expect($db->table('TOFHEAD')->where('NUMMER', 9000001)->value('REFERTE'))->toBe('Pomp A')
        ->and($flaky->sends)->toBe(2);

    $flaky->failNext = 'sending';
    expect($db->table('TOFHEAD')->where('NUMMER', 9000001)->update(['STATUS' => 'G']))->toBe(1)
        ->and($flaky->sends)->toBe(4);

    $flaky->failNext = 'reading';
    expect(fn () => $db->table('TOFHEAD')->where('NUMMER', 9000001)->update(['STATUS' => 'O']))->toThrow(QueryException::class)
        ->and($flaky->sends)->toBe(5);
});
