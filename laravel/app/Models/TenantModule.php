<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantModule extends Model
{
    protected $table = 'tenant_modules';

    protected $fillable = [
        'tenant_id',
        'module_id',
        'module_key',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }
}
