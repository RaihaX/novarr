<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A reader profile. One is the library's owner (app setting owner_user_id,
 * role admin); everyone else is a reader. Stage 1 of multi-user: nobody signs
 * in yet, every request reads as the owner (App\Support\CurrentReader).
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_READER = 'reader';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'username', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
        'disabled_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A reader's own state goes with them (no foreign keys, like the
        // chapter / novel hooks).
        static::deleted(function (self $user) {
            app(\App\Services\ReadState::class)->forgetUser((int) $user->id);
            ReadingPosition::where('user_id', $user->id)->delete();
            Bookmark::where('user_id', $user->id)->delete();
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
