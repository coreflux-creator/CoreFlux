<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantModuleController extends Controller
{
    /**
     * Get enabled modules for the current tenant
     */
    public function index(Request $request, $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        // Verify user has access to this tenant
        $user = $request->user();
        if (!$user->isMasterAdmin() && !$user->tenants->contains('id', $tenantId)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $modules = $tenant->modules()->get()->map(function ($module) {
            return [
                'id' => $module->id,
                'name' => $module->name,
                'key' => $module->key,
                'description' => $module->description,
                'icon' => $module->icon,
                'is_enabled' => (bool) $module->pivot->is_enabled,
            ];
        });

        return response()->json($modules);
    }
}
