<?php

namespace Omaresmael\Thread\Pool;

/**
 * How one task ended: an encoded result payload, a timeout, or a crash.
 */
final readonly class Outcome
{
    private function __construct(
        public OutcomeType $type,
        public ?string $result = null,
        public ?string $reason = null,
    ) {}

    public static function result(string $result): self
    {
        return new self(OutcomeType::Result, result: $result);
    }

    public static function timeout(): self
    {
        return new self(OutcomeType::Timeout);
    }

    public static function crash(string $reason): self
    {
        return new self(OutcomeType::Crash, reason: $reason);
    }
}
