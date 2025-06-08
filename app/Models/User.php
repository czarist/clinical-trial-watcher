<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'plan_id',
        'is_admin',
        'trial_ends_at',
        'subscription_ends_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'    => 'datetime',
        'trial_ends_at'        => 'datetime',
        'subscription_ends_at' => 'datetime',
    ];

    // Relacionamento com o plano atual
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    // Relacionamento com as subscriptions
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    // Relacionamento com roles (muitos para muitos)
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    // Helper: verificar se usuário tem um role específico
    public function hasRole($roleName)
    {
        return $this->roles()->where('name', $roleName)->exists();
    }
}
