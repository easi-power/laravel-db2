<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Easi\DB2\Database\Query\Processors\DB2Processor;
use Easi\DB2\Database\Query\Processors\DB2ZOSProcessor;
use Illuminate\Database\Query\Builder;
use Tests\Unit\Helpers\FakePDO;

function makeDb2ConnectionWithResults(array $selectResults, array $config = []): DB2Connection
{
    $pdo = new FakePDO();
    $pdo->selectResults = $selectResults;

    return new DB2Connection($pdo, 'TESTDB', '', array_merge([
        'driver' => 'db2_ibmi_odbc',
        'schema' => 'test',
    ], $config));
}

function unusedBuilder(): Builder
{
    return (new \ReflectionClass(Builder::class))->newInstanceWithoutConstructor();
}

describe('processSelect', function () {
    it('trims string columns and leaves other types untouched', function () {
        $processor = new DB2Processor();

        $results = $processor->processSelect(unusedBuilder(), [
            (object) ['NAME' => '  Ada  ', 'AGE' => 36, 'ACTIVE' => true, 'NOTES' => null],
        ]);

        expect($results[0]->NAME)->toBe('Ada')
            ->and($results[0]->AGE)->toBe(36)
            ->and($results[0]->ACTIVE)->toBeTrue()
            ->and($results[0]->NOTES)->toBeNull();
    });

    it('preserves object rows as objects', function () {
        $processor = new DB2Processor();

        $results = $processor->processSelect(unusedBuilder(), [
            (object) ['ID' => 1],
        ]);

        expect($results[0])->toBeObject();
    });

    it('preserves array rows as arrays', function () {
        $processor = new DB2Processor();

        $results = $processor->processSelect(unusedBuilder(), [
            ['ID' => 1],
        ]);

        expect($results[0])->toBeArray()
            ->and($results[0]['ID'])->toBe(1);
    });

    it('maps over every row in the result set', function () {
        $processor = new DB2Processor();

        $results = $processor->processSelect(unusedBuilder(), [
            (object) ['NAME' => ' Ada '],
            (object) ['NAME' => ' Grace '],
        ]);

        expect($results)->toHaveCount(2)
            ->and($results[0]->NAME)->toBe('Ada')
            ->and($results[1]->NAME)->toBe('Grace');
    });

    it('returns an empty array when given no results', function () {
        $processor = new DB2Processor();

        expect($processor->processSelect(unusedBuilder(), []))->toBe([]);
    });

    it('converts from the configured encoding to utf-8 for strings only', function () {
        $processor = new DB2Processor(['from_encoding' => 'ISO8859-1']);

        $latin1 = iconv('utf-8', 'ISO8859-1', 'café');

        $results = $processor->processSelect(unusedBuilder(), [
            (object) ['NAME' => $latin1, 'AGE' => 5, 'NOTES' => null],
        ]);

        expect($results[0]->NAME)->toBe('café')
            ->and($results[0]->AGE)->toBe(5)
            ->and($results[0]->NOTES)->toBeNull();
    });

    it('does not attempt encoding conversion when from_encoding is not configured', function () {
        $processor = new DB2Processor();

        $results = $processor->processSelect(unusedBuilder(), [
            (object) ['NAME' => 'café'],
        ]);

        expect($results[0]->NAME)->toBe('café');
    });
});

describe('processInsertGetId', function () {
    it('returns the generated id as an int when the sequence key matches exactly', function () {
        $connection = makeDb2ConnectionWithResults([(object) ['id' => '42']]);
        $processor = new DB2Processor($connection->getConfig());

        $id = $processor->processInsertGetId(
            $connection->query(),
            'insert into SAMPLE_TABLE (name) values (?)',
            ['Ada'],
        );

        expect($id)->toBe(42)->toBeInt();
    });

    it('falls back to the uppercased sequence key when DB2 returns uppercase columns', function () {
        $connection = makeDb2ConnectionWithResults([(object) ['ID' => '7']]);
        $processor = new DB2Processor($connection->getConfig());

        $id = $processor->processInsertGetId(
            $connection->query(),
            'insert into SAMPLE_TABLE (name) values (?)',
            ['Ada'],
        );

        expect($id)->toBe(7);
    });

    it('respects a custom sequence name', function () {
        $connection = makeDb2ConnectionWithResults([(object) ['CUSTOM_SEQ' => '99']]);
        $processor = new DB2Processor($connection->getConfig());

        $id = $processor->processInsertGetId(
            $connection->query(),
            'insert into SAMPLE_TABLE (name) values (?)',
            ['Ada'],
            'CUSTOM_SEQ',
        );

        expect($id)->toBe(99);
    });

    it('leaves non-numeric generated ids untouched', function () {
        $connection = makeDb2ConnectionWithResults([(object) ['id' => 'not-numeric-guid']]);
        $processor = new DB2Processor($connection->getConfig());

        $id = $processor->processInsertGetId(
            $connection->query(),
            'insert into SAMPLE_TABLE (name) values (?)',
            ['Ada'],
        );

        expect($id)->toBe('not-numeric-guid');
    });

    it('returns all column values for a composite sequence', function () {
        $connection = makeDb2ConnectionWithResults([(object) ['A' => 1, 'B' => 2]]);
        $processor = new DB2Processor($connection->getConfig());

        $id = $processor->processInsertGetId(
            $connection->query(),
            'insert into SAMPLE_TABLE (a, b) values (?, ?)',
            [1, 2],
            ['A', 'B'],
        );

        expect($id)->toBe(['A' => 1, 'B' => 2]);
    });
});

describe('DB2ZOSProcessor::processInsertGetId', function () {
    it('leaves non-numeric generated ids untouched', function () {
        $connection = makeDb2ConnectionWithResults([(object) ['id' => 'not-numeric-guid']], [
            'driver' => 'db2_zos_odbc',
        ]);
        $processor = new DB2ZOSProcessor();

        $id = $processor->processInsertGetId(
            $connection->query(),
            'insert into SAMPLE_TABLE (name) values (?)',
            ['Ada'],
        );

        expect($id)->toBe('not-numeric-guid');
    });
});
