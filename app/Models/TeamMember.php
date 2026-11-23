<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user who accepted an invitation to a team (or the team's owner).
 */
class TeamMember extends Pivot
{
    protected $table = 'team_members';

    public $incrementing = true;

    protected $fillable = ['team_id', 'user_id', 'role', 'receives_digests'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'receives_digests' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
