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
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'password_hash',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * For compatibility with existing DB that uses password_hash column
     */
    public function getAuthPassword()
    {
        return $this->password_hash ?? $this->password;
    }

    /**
     * Get all tenants this user belongs to
     */
    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'user_tenants')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Check if user is master admin
     */
    public function isMasterAdmin(): bool
    {
        return $this->role === 'master_admin';
    }

    /**
     * Get user's role in a specific tenant
     */
    public function roleInTenant(Tenant $tenant): ?string
    {
        $pivot = $this->tenants()->where('tenant_id', $tenant->id)->first();
        return $pivot?->pivot?->role;
    }
}
