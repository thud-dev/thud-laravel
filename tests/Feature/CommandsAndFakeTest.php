<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;
use Thud\Facades\Thud;
use Thud\Tests\Fixtures\Jobs\ActivateSubscription;
use Thud\Tests\Fixtures\Jobs\ChildJob;
use Thud\Transport\Client;
use Thud\Transport\Transport;

/**
 * Points the client at its own host, so the suite's catch-all ingest fake doesn't answer first.
 */
function pingAnswers(int $status, array $body): void
{
    config(['thud.endpoint' => 'https://ping.thud.test/v1']);
    Http::fake(['ping.thud.test/*' => Http::response($body, $status)]);
    app()->forgetInstance(Client::class);
    app()->forgetInstance(Transport::class);
}

describe('thud:test', function (): void {
    it('reports the connected app', function (): void {
        pingAnswers(200, ['app' => 'billing-app', 'environment' => 'production']);

        $this->artisan('thud:test')->assertExitCode(0)->expectsOutputToContain('Connected as billing-app (production)');
    });

    it('explains a rejected token', function (): void {
        pingAnswers(401, ['message' => 'Invalid app token.']);

        $this->artisan('thud:test')->assertExitCode(1)->expectsOutputToContain('token was rejected');
    });

    it('explains a missing token', function (): void {
        app()->instance(Transport::class, new Client(app(Factory::class), null, 'https://ingest.thud.test/v1', 'testing'));

        $this->artisan('thud:test')->assertExitCode(1)->expectsOutputToContain('THUD_TOKEN is not set');
    });
});

describe('thud:install', function (): void {
    beforeEach(function (): void {
        $this->envDir = sys_get_temp_dir().'/thud-install-'.uniqid();
        mkdir($this->envDir);
        file_put_contents($this->envDir.'/.env', "APP_NAME=Shop\nTHUD_TOKEN=old\n");
        app()->useEnvironmentPath($this->envDir);
        pingAnswers(200, ['app' => 'shop', 'environment' => 'production']);
    });

    afterEach(function (): void {
        @unlink($this->envDir.'/.env');
        @rmdir($this->envDir);
    });

    it('writes the token to .env and tests the connection', function (): void {
        $this->artisan('thud:install', ['--token' => 'thd_new0123456789'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Connected as shop');

        expect(file_get_contents($this->envDir.'/.env'))->toBe("APP_NAME=Shop\nTHUD_TOKEN=thd_new0123456789\n");
    });

    it('rejects something that is not a Thud token', function (): void {
        $this->artisan('thud:install', ['--token' => 'sk_live_123'])->assertExitCode(1);

        expect(file_get_contents($this->envDir.'/.env'))->toContain('THUD_TOKEN=old');
    });
});

describe('Thud::fake()', function (): void {
    it('asserts expectations met, with within() checks evaluated immediately', function (): void {
        Thud::fake();
        $orderId = (int) DB::table('orders')->insertGetId(['status' => 'pending']);

        ActivateSubscription::dispatchSync($orderId);

        Thud::assertExpectationsMet(ActivateSubscription::class);
        Thud::assertRunRecorded(ActivateSubscription::class, fn ($run) => $run->metric('mail_sent') === 1
            && $run->expectation('Order is still active') === 'passed');
    });

    it('asserts a failed expectation', function (): void {
        Thud::fake();
        $orderId = (int) DB::table('orders')->insertGetId(['status' => 'pending']);

        ActivateSubscription::dispatchSync($orderId, false);

        Thud::assertExpectationFailed(ActivateSubscription::class, 'Order is active');
    });

    it('fails assertExpectationsMet when an expectation fails', function (): void {
        Thud::fake();
        $orderId = (int) DB::table('orders')->insertGetId(['status' => 'pending']);
        ActivateSubscription::dispatchSync($orderId, false);

        expect(fn () => Thud::assertExpectationsMet(ActivateSubscription::class))
            ->toThrow(ExpectationFailedException::class, 'Order is active: failed');
    });

    it('never calls the ingest API', function (): void {
        Thud::fake();

        ChildJob::dispatch();
        Thud::flush();

        Thud::assertRunRecorded(ChildJob::class);
        expect(ingestRequests())->toBe([]);
    });
});
