<?php

namespace App\Support;

use App\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Validation rules shared by every place an account is made or changed
 * (Settings → Users, accepting an invite, the Account page), so a sign-in
 * name or password is held to the same rules everywhere.
 */
final class AccountRules
{
    public const USERNAME_FORMAT = 'Use 2 to 60 letters, numbers, dots, dashes or underscores, starting with a letter or number.';

    public const USERNAME_TAKEN = 'Someone already signs in with that name.';

    /** A sign-in name: User::USERNAME_PATTERN, unique (except $ignoreId). */
    public static function username(?int $ignoreId = null): array
    {
        return [
            'required', 'string', 'max:60',
            function ($attribute, $value, $fail) {
                if (!preg_match(User::USERNAME_PATTERN, User::normaliseUsername($value))) {
                    $fail(self::USERNAME_FORMAT);
                }
            },
            function ($attribute, $value, $fail) use ($ignoreId) {
                $taken = User::where('username', User::normaliseUsername($value))
                    ->when($ignoreId !== null, fn($q) => $q->where('id', '!=', $ignoreId))
                    ->exists();
                if ($taken) {
                    $fail(self::USERNAME_TAKEN);
                }
            },
        ];
    }

    /** A new password: at least 8 characters (typed twice when $confirmed). */
    public static function password(bool $confirmed = false): array
    {
        return array_values(array_filter([
            'required', 'string', $confirmed ? 'confirmed' : null, Password::min(8), 'max:255',
        ]));
    }

    /**
     * A free sign-in name to suggest for $name ("Sam Lee" → "sam.lee",
     * "sam.lee2" when taken). Never empty; always matches USERNAME_PATTERN.
     */
    public static function suggestUsername(string $name, string $email = ''): string
    {
        $base = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '.')->trim('.')->limit(50, '')->trim('.')->value();
        if (strlen($base) < 2) {
            $local = Str::of(Str::ascii(Str::before($email, '@')))->lower()->replaceMatches('/[^a-z0-9._-]+/', '')->trim('._-')->limit(50, '')->value();
            $base = strlen($local) >= 2 ? $local : 'reader';
        }

        $candidate = $base;
        for ($n = 2; User::where('username', $candidate)->exists(); $n++) {
            $candidate = $base . $n;
        }

        return $candidate;
    }
}
