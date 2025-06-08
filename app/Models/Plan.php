<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'billing_cycle',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
    ];

    // Relacionamento com users
    public function users()
    {
        return $this->hasMany(User::class);
    }

    // Relacionamento com subscriptions
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
