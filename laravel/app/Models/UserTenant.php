<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTenant extends Model
{
    protected $table = 'user_tenants';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'role',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
