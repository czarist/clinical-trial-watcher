<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    // Relacionamento com usuários
    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    // Relacionamento com permissions
    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }
}
