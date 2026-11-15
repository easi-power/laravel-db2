<?php

namespace Tests\Unit;

use Easi\DB2\Database\DB2Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\Unit\Helpers\FakePDO;

class ExistsTestProduct extends Model
{
    protected $connection = 'db2';

    protected $table = 'products';

    public $timestamps = false;

    public function orders(): HasMany
    {
        return $this->hasMany(ExistsTestOrder::class, 'product_id');
    }
}

class ExistsTestOrder extends Model
{
    protected $connection = 'db2';

    protected $table = 'orders';

    public $timestamps = false;
}

function useExistsTestConnection(array $config = [], array $selectResults = []): DB2Connection
{
    $pdo = new FakePDO();
    $pdo->selectResults = $selectResults;

    $connection = new DB2Connection($pdo, 'TESTDB', '', $config + ['driver' => 'db2_ibmi_odbc', 'schema' => 'test']);

    Model::setConnectionResolver(new ConnectionResolver(['db2' => $connection]));

    return $connection;
}

function openOrdersQuery()
{
    return ExistsTestProduct::withExists(['orders' => fn ($query) => $query->where('status', 'open')])
        ->where('sku', 'ABC-1');
}

it('compiles withExists through a scalar subquery, keeping EXISTS out of the select list', function () {
    useExistsTestConnection();

    expect(ExistsTestProduct::withExists('orders')->toSql())->toBe(
        'select products.*, (select count(*) from (values (1)) as d(x) where exists(select * from orders where products.id = orders.product_id)) as orders_exists from products'
    );
});

// DB2 binds positional markers in order, so the subquery's binding has to come first.
it('binds a constrained withExists before the outer where', function () {
    useExistsTestConnection();

    $query = openOrdersQuery();

    expect($query->toSql())->toBe(
        'select products.*, (select count(*) from (values (1)) as d(x) where exists(select * from orders where products.id = orders.product_id and status = ?)) as orders_exists from products where sku = ?'
    )->and($query->getBindings())->toBe(['open', 'ABC-1']);
});

it('keeps the exists column and its binding through the row_number offset rewrite', function () {
    useExistsTestConnection();

    $query = openOrdersQuery()->orderBy('sku')->skip(10)->take(5);

    expect($query->toSql())->toBe(
        'select * from (select products.*, (select count(*) from (values (1)) as d(x) where exists(select * from orders where products.id = orders.product_id and status = ?)) as orders_exists,  row_number() over (order by sku asc) as row_num from products where sku = ?) as temp_table where row_num between 11 and 15'
    )->and($query->getBindings())->toBe(['open', 'ABC-1']);
});

it('keeps the exists column with offset compatibility mode off', function () {
    useExistsTestConnection(['offset_compatibility_mode' => false]);

    $query = openOrdersQuery()->orderBy('sku')->skip(10)->take(5);

    expect($query->toSql())->toBe(
        'select products.*, (select count(*) from (values (1)) as d(x) where exists(select * from orders where products.id = orders.product_id and status = ?)) as orders_exists from products where sku = ? order by sku asc limit 5 offset 10'
    )->and($query->getBindings())->toBe(['open', 'ABC-1']);
});

// ODBC hands the count back as a string.
it('casts the 1/0 the driver returns to a bool', function () {
    useExistsTestConnection(selectResults: [
        (object) ['id' => 1, 'orders_exists' => '1'],
        (object) ['id' => 2, 'orders_exists' => '0'],
    ]);

    $products = ExistsTestProduct::withExists('orders')->get();

    expect($products[0]->orders_exists)->toBeTrue()
        ->and($products[1]->orders_exists)->toBeFalse();
});
