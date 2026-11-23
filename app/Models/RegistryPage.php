<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The last response of a URL fetched from a registry: its validators for conditional
 * requests and where its body is stored.
 */
class RegistryPage extends Model
{
    protected $fillable = [
        'source',
        'url_hash',
        'url',
        'status',
        'etag',
        'last_modified',
        'content_type',
        'content_hash',
        'size',
        'storage_path',
        'fetched_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'size' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }
}
