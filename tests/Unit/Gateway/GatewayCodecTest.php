<?php

namespace Tests\Unit\Gateway;

use Easi\DB2\Database\Gateway\GatewayCodec;
use Easi\DB2\Database\Gateway\GatewayException;

describe('requests', function () {
    it('round-trips a select, flattening OR groups into numbered groups', function () {
        $frame = GatewayCodec::encodeRequest([
            'op' => 'select', 'file' => 'TOFDETA', 'columns' => ['NUMMER', 'PRIJS'],
            'where' => [
                ['col' => 'TOFHEAD_NUMMER', 'op' => '=', 'val' => '9000001'],
                ['any' => [['col' => 'OMSCHR', 'op' => 'like', 'val' => '%pomp%'], ['col' => 'NUMMER', 'op' => 'in', 'val' => ['1', '2']]]],
            ],
            'order' => [['col' => 'NUMMER', 'dir' => 'asc']],
            'limit' => 15, 'offset' => 30,
        ]);

        expect(GatewayCodec::decodeRequest($frame))->toBe([
            'op' => 'select', 'file' => 'TOFDETA', 'limit' => 15, 'offset' => 30, 'count' => false,
            'columns' => ['NUMMER', 'PRIJS'],
            'order' => [['NUMMER', 'asc']],
            'where' => [
                [0, 'TOFHEAD_NUMMER', '=', ['9000001']],
                [1, 'OMSCHR', 'like', ['%pomp%']],
                [1, 'NUMMER', 'in', ['1', '2']],
            ],
            'rows' => [], 'set' => [],
        ]);
    });

    it('asks for the matched count only for a count aggregate', function () {
        $frame = GatewayCodec::encodeRequest(['op' => 'select', 'file' => 'TOFHEAD', 'columns' => [], 'aggregate' => 'count', 'limit' => 0]);

        expect(GatewayCodec::decodeRequest($frame))->toMatchArray(['count' => true, 'limit' => 0]);
    });

    it('keeps an empty IN list and an empty value apart', function () {
        $frame = GatewayCodec::encodeRequest(['op' => 'delete', 'file' => 'X', 'where' => [
            ['col' => 'A', 'op' => 'in', 'val' => []],
            ['col' => 'B', 'op' => '=', 'val' => ''],
        ]]);

        expect(GatewayCodec::decodeRequest($frame)['where'])->toBe([[0, 'A', 'in', []], [0, 'B', '=', ['']]]);
    });

    it('carries insert rows and update assignments', function () {
        $insert = GatewayCodec::decodeRequest(GatewayCodec::encodeRequest(['op' => 'insert', 'file' => 'X', 'columns' => ['A', 'B'], 'rows' => [['1', 'x'], ['2', 'y']]]));
        $update = GatewayCodec::decodeRequest(GatewayCodec::encodeRequest(['op' => 'update', 'file' => 'X', 'set' => ['B' => 'z'], 'where' => [['col' => 'A', 'op' => '=', 'val' => '1']]]));

        expect($insert['rows'])->toBe([['1', 'x'], ['2', 'y']])
            ->and($update['set'])->toBe(['B' => 'z'])
            ->and($update['where'])->toBe([[0, 'A', '=', ['1']]]);
    });

    it('prefixes the payload with its length in bytes, not characters', function () {
        $frame = GatewayCodec::encodeRequest(['op' => 'select', 'file' => 'X', 'where' => [['col' => 'A', 'op' => '=', 'val' => 'café']]]);

        expect((int) substr($frame, 0, 8))->toBe(strlen($frame) - 8);
    });

    it('refuses a value that contains a separator', function () {
        GatewayCodec::encodeRequest(['op' => 'select', 'file' => 'X', 'where' => [['col' => 'A', 'op' => '=', 'val' => "a\x1Fb"]]]);
    })->throws(\InvalidArgumentException::class);
});

describe('responses', function () {
    it('decodes rows and strips the space padding of fixed-width fields', function () {
        $frame = GatewayCodec::encodeResponse(['columns' => ['NUMMER', 'NAAM'], 'rows' => [['1', 'ACME      ']], 'count' => 40, 'truncated' => true]);

        expect(GatewayCodec::decodeResponse($frame))->toBe([
            'columns' => ['NUMMER', 'NAAM'], 'rows' => [['1', 'ACME']], 'count' => 40, 'truncated' => true, 'affected' => 0,
        ]);
    });

    it('decodes an empty result', function () {
        expect(GatewayCodec::decodeResponse(GatewayCodec::encodeResponse(['columns' => ['A']])))
            ->toMatchArray(['rows' => [], 'count' => 0]);
    });

    it('turns an error frame into an exception that keeps the SQLSTATE as a string', function () {
        try {
            GatewayCodec::decodeResponse(GatewayCodec::encodeError('23505', 'Duplicate key'));
            $this->fail('No exception');
        } catch (GatewayException $e) {
            expect($e->getCode())->toBe('23505')->and($e->getMessage())->toBe('Duplicate key');
        }
    });

    it('rejects a frame whose length prefix is wrong', function () {
        GatewayCodec::decodeResponse('00000099OK');
    })->throws(\UnexpectedValueException::class);
});
