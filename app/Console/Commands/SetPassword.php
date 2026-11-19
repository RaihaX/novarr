<?php

namespace App\Console\Commands;

use App\Support\CurrentReader;
use App\User;
use Illuminate\Console\Command;

/**
 * Set an account's password from the server (the owner's by default) — the
 * way back in when it is forgotten, and how the owner gets a first password
 * on a server without the web UI. Prompts twice, or --password= for scripts.
 */
class SetPassword extends Command
{
    public const MIN_LENGTH = 8;

    protected $signature = 'novarr:password
        {username? : the account (default: the owner)}
        {--password= : the new password (no prompt; for scripts)}';

    protected $description = 'Set the password of an account (the owner by default)';

    public function handle(): int
    {
        $username = $this->argument('username');
        $user = $username === null ? User::find(CurrentReader::ownerId()) : User::findByUsername($username);
        if ($user === null) {
            $this->error($username === null ? 'There is no owner account: run php artisan migrate.' : "No account signs in as \"{$username}\".");

            return self::FAILURE;
        }

        $password = $this->option('password');
        if ($password === null) {
            $password = (string) $this->secret("New password for {$user->name} ({$user->username})");
            $again = (string) $this->secret('Same password again');
            if ($password !== $again) {
                $this->error('The two passwords do not match. Nothing changed.');

                return self::FAILURE;
            }
        }

        if (mb_strlen((string) $password) < self::MIN_LENGTH) {
            $this->error('Use at least ' . self::MIN_LENGTH . ' characters. Nothing changed.');

            return self::FAILURE;
        }

        $user->setNewPassword((string) $password);
        // Browsers signed in with the old password are signed out; the
        // account's apps and devices (tokens) stay.
        $user->signOutEverywhere(keepTokens: true);
        $this->info("Password set for {$user->name}. They sign in as \"{$user->username}\"; other browsers are signed out.");

        return self::SUCCESS;
    }
}
