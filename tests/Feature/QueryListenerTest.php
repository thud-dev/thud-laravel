<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Thud\Recording\Listeners\QueryListener;
use Thud\Recording\RunContext;
use Thud\Recording\RunStack;

it('classifies writes by their first keyword', function (string $sql, ?string $kind): void {
    expect(QueryListener::classify($sql))->toBe($kind);
})->with([
    ['insert into "orders" ("status") values (?)', 'insert'],
    ['  INSERT IGNORE INTO `orders` (`status`) values (?)', 'insert'],
    ['replace into orders values (?)', 'insert'],
    ['update "orders" set "status" = ?', 'update'],
    ['delete from "orders" where "id" = ?', 'delete'],
    ['select * from "orders"', null],
    ['with x as (select 1) select * from x', null],
]);

it('finds the target table, without quotes, schema or case', function (string $kind, string $sql, string $table): void {
    expect(QueryListener::table($kind, $sql))->toBe($table);
})->with([
    ['insert', 'insert into "jobs" ("queue") values (?)', 'jobs'],
    ['insert', 'insert into `app`.`Failed_Jobs` (`uuid`) values (?)', 'failed_jobs'],
    ['insert', 'insert or ignore into "cache" ("key") values (?)', 'cache'],
    ['insert', 'insert into [dbo].[sessions] ([id]) values (?)', 'sessions'],
    ['update', 'update `jobs` set `reserved_at` = ?', 'jobs'],
    ['delete', 'delete from "jobs" where "id" = ?', 'jobs'],
    ['delete', 'delete `o` from `orders` as `o`', 'orders'],
]);

it('strips the connection table prefix before checking ignore_tables', function (): void {
    config(['database.connections.prefixed' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'app_',
    ]]);

    $db = DB::connection('prefixed');
    $db->getSchemaBuilder()->create('jobs', fn (Blueprint $table) => $table->id());
    $db->getSchemaBuilder()->create('orders', fn (Blueprint $table) => $table->id());

    $run = new RunContext('job', 'PrefixTest');
    app(RunStack::class)->push($run);

    $db->table('jobs')->insert(['id' => 1]); // app_jobs: ignored
    $db->table('orders')->insert(['id' => 1]); // app_orders: counted

    expect($run->metric('db_insert'))->toBe(1);
});
