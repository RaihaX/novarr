<?php

namespace App\Auth;

use App\Services\RememberTokens;

/** The value of the remember-me cookie ("selector|token"), see App\Services\RememberTokens. */
final class RememberCookie
{
    public function __construct(private string $value)
    {
    }

    public function value(): string
    {
        return $this->value;
    }

    public function valid(): bool
    {
        return RememberTokens::looksValid($this->value);
    }
}
