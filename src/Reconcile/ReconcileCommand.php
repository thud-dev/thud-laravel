<?php

declare(strict_types=1);

namespace Thud\Reconcile;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;
use Thud\Reconcile\Providers\Providers;
use Thud\Support\Safely;
use Thud\Transport\Transport;

/**
 * php artisan thud:reconcile {provider?} --hours=48
 *
 * Compares what each configured payment provider says happened with what this app's database
 * says, and sends Thud references and pass/fail only: never amounts, emails or card data.
 */
final class ReconcileCommand extends Command
{
    public const PLAN_BLOCK_KEY = 'thud:reconcile:needs-plan';

    protected $signature = 'thud:reconcile
        {provider? : stripe, paystack, paddle, lemon_squeezy or flutterwave (default: every configured provider)}
        {--hours=48 : How far back to check payments}';

    protected $description = 'Check payments and subscriptions at your payment providers against your database';

    public function handle(Providers $providers, Transport $transport, Cache $cache): int
    {
        if (Safely::run(fn () => $cache->has(self::PLAN_BLOCK_KEY), false) === true) {
            $this->components->warn('Payment reconciliation needs the Team plan or higher. Skipping until tomorrow.');

            return self::SUCCESS;
        }

        $requested = $this->argument('provider');
        $names = is_string($requested) ? [$requested] : Providers::configured();

        if ($names === []) {
            $this->components->warn('No payment provider is configured. Add a read-only key under payments in config/thud.php.');

            return self::SUCCESS;
        }

        $to = CarbonImmutable::now('UTC');
        $from = $to->subHours(max(1, (int) $this->option('hours')));
        $status = self::SUCCESS;

        foreach ($names as $name) {
            $provider = $providers->make($name);

            if ($provider === null) {
                $this->components->error("[{$name}] is not configured or not supported. Supported: ".implode(', ', Providers::SUPPORTED).'.');
                $status = self::FAILURE;

                continue;
            }

            try {
                $result = (new Reconciler)->run($provider, new Mapping((array) config("thud.payments.{$name}.map", [])), $from, $to);
            } catch (Throwable $e) {
                $this->components->error("{$name}: ".$e->getMessage());
                $status = self::FAILURE;

                continue;
            }

            $sent = $transport->sendPaymentCheck($result);

            if (($sent['status'] ?? null) === 402) {
                Safely::run(fn () => $cache->put(self::PLAN_BLOCK_KEY, true, now()->addDay()));
                $this->components->warn('Payment reconciliation needs the Team plan or higher.');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail(
                "<fg=gray>{$name}</> {$result['checked']} checked".($result['truncated'] ? ' (stopped after 5 minutes)' : ''),
                count($result['gaps']) === 0 ? '<fg=green>no gaps</>' : '<fg=yellow>'.count($result['gaps']).' '.(count($result['gaps']) === 1 ? 'gap' : 'gaps').'</>',
            );
        }

        return $status;
    }
}
