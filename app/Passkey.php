<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passkey (WebAuthn credential) of an account, made on one address
 * (rp_id): only offered and accepted there. See App\Services\Passkeys.
 */
class Passkey extends Model
{
    protected $table = 'passkeys';

    protected $guarded = [];

    protected $hidden = ['public_key', 'credential_id', 'credential_hash'];

    protected $casts = [
        'user_id' => 'integer',
        'counter' => 'integer',
        'backup_eligible' => 'boolean',
        'backup_status' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function transportList(): array
    {
        $list = json_decode((string) $this->transports, true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
