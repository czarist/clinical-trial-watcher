<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A digest sent (or being sent) to one recipient. Unique per digest and user.
 */
class DigestDelivery extends Model
{
    protected $fillable = ['digest_id', 'user_id', 'claimed_at', 'sent_at'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function digest(): BelongsTo
    {
        return $this->belongsTo(Digest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
