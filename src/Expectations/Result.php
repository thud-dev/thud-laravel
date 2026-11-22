<?php

declare(strict_types=1);

namespace Thud\Expectations;

use DateTimeInterface;

/**
 * Outcome of one expectation: passed, failed, error, or pending (evaluated later by VerifyOutcome).
 * Messages are short descriptions (max 200 chars) and never contain data values.
 */
final class Result
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const ERROR = 'error';

    public const PENDING = 'pending';

    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?DateTimeInterface $dueAt = null,
    ) {}

    public static function passed(): self
    {
        return new self(self::PASSED);
    }

    public static function failed(?string $message = null): self
    {
        return new self(self::FAILED, self::trim($message));
    }

    public static function error(?string $message = null): self
    {
        return new self(self::ERROR, self::trim($message));
    }

    public static function pending(DateTimeInterface $dueAt): self
    {
        return new self(self::PENDING, null, $dueAt);
    }

    public static function when(bool $passed, string $failure): self
    {
        return $passed ? self::passed() : self::failed($failure);
    }

    public function passes(): bool
    {
        return $this->status === self::PASSED;
    }

    /**
     * @return array{label: string, result: string, message?: string, due_at?: string}
     */
    public function toPayload(string $label): array
    {
        return array_filter([
            'label' => $label,
            'result' => $this->status,
            'message' => $this->message,
            'due_at' => $this->dueAt?->format('Y-m-d\TH:i:s\Z'),
        ], static fn ($value): bool => $value !== null);
    }

    private static function trim(?string $message): ?string
    {
        return $message === null ? null : mb_substr($message, 0, 200);
    }
}
