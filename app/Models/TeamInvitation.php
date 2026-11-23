<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending invitation of a registered user, by email. It becomes a membership only when
 * that user accepts it.
 */
class TeamInvitation extends Model
{
    protected $fillable = ['team_id', 'email', 'invited_by'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
