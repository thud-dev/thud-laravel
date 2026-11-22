<?php

declare(strict_types=1);

namespace Thud\Expectations;

use Carbon\CarbonImmutable;
use Closure;
use Throwable;
use Thud\Recording\RunContext;
use Thud\Recording\RunStack;

/**
 * Evaluates a run's expectations once it has succeeded, and records the results on the run.
 *
 * During-run checks use the run's counters; state checks run their closure. A state check with
 * within(n) is handed to $defer, which queues a VerifyOutcome job n seconds later, and recorded
 * as pending. Under Thud::fake() it is evaluated immediately instead.
 *
 * Recording is paused while checks run, so their own queries never count as side effects, and a
 * throwing check is recorded as `error`: nothing here can reach the host app.
 */
final class Evaluator
{
    public const MAX_EXPECTATIONS = 100;

    public bool $evaluateDeferredNow = false;

    public function __construct(private readonly RunStack $stack) {}

    /**
     * @param  array<array-key, mixed>  $expectations
     * @param  string  $owner  used for default labels: "{$owner}::expectations()#index"
     * @param  Closure(int $index, string $label, int $delaySeconds): void  $defer
     */
    public function evaluate(RunContext $run, array $expectations, string $owner, Closure $defer): void
    {
        $this->stack->paused(function () use ($run, $expectations, $owner, $defer): void {
            $index = 0;

            foreach (array_slice(array_values($expectations), 0, self::MAX_EXPECTATIONS) as $expectation) {
                $label = self::label($expectation, $owner, $index);

                if (! $expectation instanceof Expectation) {
                    $run->expectations[] = Result::error('Not an expectation; build it with Thud\\Expectations\\Expect')->toPayload($label);
                } elseif ($expectation instanceof StateCheck && $expectation->delaySeconds() > 0 && ! $this->evaluateDeferredNow) {
                    $run->expectations[] = $this->defer($expectation, $index, $label, $defer)->toPayload($label);
                } else {
                    $run->expectations[] = self::check($expectation, $run)->toPayload($label);
                }

                $index++;
            }
        });
    }

    /**
     * Evaluates one expectation now, turning any exception into an `error` result.
     */
    public static function check(Expectation $expectation, RunContext $run): Result
    {
        try {
            return $expectation->evaluate($run);
        } catch (Throwable $e) {
            return Result::error('Threw '.class_basename($e));
        }
    }

    public static function label(mixed $expectation, string $owner, int $index): string
    {
        $label = $expectation instanceof Expectation ? $expectation->getLabel() : null;

        return $label ?? mb_substr(class_basename($owner)."::expectations()#{$index}", 0, 191);
    }

    /**
     * @param  Closure(int $index, string $label, int $delaySeconds): void  $defer
     */
    private function defer(StateCheck $expectation, int $index, string $label, Closure $defer): Result
    {
        try {
            $defer($index, $label, $expectation->delaySeconds());
        } catch (Throwable $e) {
            return Result::error('Could not queue verification: '.class_basename($e));
        }

        return Result::pending(CarbonImmutable::now('UTC')->addSeconds($expectation->delaySeconds()));
    }
}
