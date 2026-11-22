<?php

declare(strict_types=1);

namespace Thud\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Thud\Support\Safely;
use Thud\ThudServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ThudServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('mail.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');

        $app['config']->set('thud.token', 'test-token');
        $app['config']->set('thud.endpoint', 'https://ingest.thud.test/v1');
        $app['config']->set('thud.send_in_tests', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Safely::resetLogThrottle();

        Http::fake(['ingest.thud.test/*' => Http::response(null, 202)]);

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }
}
