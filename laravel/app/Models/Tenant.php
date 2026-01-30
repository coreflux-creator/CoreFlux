<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subdomain',
        'parent_id',
    ];

    /**
     * Get the parent tenant
     */
    public function parent()
    {
        return $this->belongsTo(Tenant::class, 'parent_id');
    }

    /**
     * Get child tenants
     */
    public function children()
    {
        return $this->hasMany(Tenant::class, 'parent_id');
    }

    /**
     * Get all users belonging to this tenant
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_tenants')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get modules enabled for this tenant
     */
    public function modules()
    {
        return $this->belongsToMany(Module::class, 'tenant_modules')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }

    /**
     * Get only enabled modules
     */
    public function enabledModules()
    {
        return $this->modules()->wherePivot('is_enabled', true);
    }
}
