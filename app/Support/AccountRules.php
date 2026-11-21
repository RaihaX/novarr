<?php

namespace App\Support;

use App\Services\BreachedPasswords;
use App\User;
use Illuminate\Support\Str;

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

    /** The shortest new password. */
    public const PASSWORD_MIN = 12;

    public const PASSWORD_SHORT = 'Use at least ' . self::PASSWORD_MIN . ' characters.';

    public const PASSWORD_LONG = 'Use at most 255 characters.';

    public const PASSWORD_IS_A_NAME = 'Choose a password that is not your name, sign-in name or email.';

    /**
     * A new password: PASSWORD_MIN to 255 characters, no rules about which
     * characters, not one of $names (the account's display name, sign-in
     * name and email, case-insensitive), and not in a known data leak
     * (App\Services\BreachedPasswords; asked last, only when the rest pass).
     * Typed twice when $confirmed. Existing passwords are never re-checked.
     *
     * @param array<int, string|null> $names
     */
    public static function password(bool $confirmed = false, array $names = []): array
    {
        $names = array_values(array_filter(array_map(
            fn($name) => mb_strtolower(trim((string) $name)),
            $names
        ), fn(string $name) => $name !== ''));

        return array_values(array_filter([
            'bail', 'required', 'string', $confirmed ? 'confirmed' : null,
            function ($attribute, $value, $fail) {
                if (mb_strlen((string) $value) < self::PASSWORD_MIN) {
                    $fail(self::PASSWORD_SHORT);
                }
            },
            function ($attribute, $value, $fail) {
                if (mb_strlen((string) $value) > 255) {
                    $fail(self::PASSWORD_LONG);
                }
            },
            function ($attribute, $value, $fail) use ($names) {
                if (in_array(mb_strtolower(trim((string) $value)), $names, true)) {
                    $fail(self::PASSWORD_IS_A_NAME);
                }
            },
            function ($attribute, $value, $fail) {
                if (app(BreachedPasswords::class)->appears((string) $value)) {
                    $fail(BreachedPasswords::MESSAGE);
                }
            },
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
