<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = ['name', 'subdomain', 'parent_id'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_tenants')->withPivot('role');
    }

    public function modules()
    {
        return $this->belongsToMany(Module::class, 'tenant_modules')->withPivot('is_enabled');
    }
}
