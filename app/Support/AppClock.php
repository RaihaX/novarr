<?php

namespace App\Support;

use Psr\Clock\ClockInterface;

/** A PSR-20 clock on Laravel's now(), so tests' travel() moves it too. */
final class AppClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
