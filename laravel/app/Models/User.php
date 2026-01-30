<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['name', 'first_name', 'last_name', 'email', 'password', 'role'];
    protected $hidden = ['password', 'password_hash', 'remember_token'];

    public function getAuthPassword()
    {
        return $this->password_hash ?? $this->password;
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'user_tenants')->withPivot('role');
    }

    public function isMasterAdmin(): bool
    {
        return $this->role === 'master_admin';
    }
}
