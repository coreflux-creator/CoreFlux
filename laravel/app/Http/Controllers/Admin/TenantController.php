<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /**
     * List all tenants
     */
    public function index()
    {
        $tenants = Tenant::with('parent')->orderBy('name')->get();
        return response()->json($tenants);
    }

    /**
     * Create a new tenant
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subdomain' => 'nullable|string|max:100|unique:tenants',
            'parent_id' => 'nullable|exists:tenants,id',
        ]);

        $tenant = Tenant::create($validated);

        return response()->json($tenant, 201);
    }

    /**
     * Get a single tenant
     */
    public function show(Tenant $tenant)
    {
        $tenant->load(['parent', 'children', 'users']);
        return response()->json($tenant);
    }

    /**
     * Update a tenant
     */
    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'subdomain' => 'nullable|string|max:100|unique:tenants,subdomain,' . $tenant->id,
            'parent_id' => 'nullable|exists:tenants,id',
        ]);

        // Prevent circular parent reference
        if (isset($validated['parent_id']) && $validated['parent_id'] == $tenant->id) {
            return response()->json(['message' => 'Tenant cannot be its own parent'], 422);
        }

        $tenant->update($validated);

        return response()->json($tenant);
    }

    /**
     * Delete a tenant
     */
    public function destroy(Tenant $tenant)
    {
        // Check for child tenants
        if ($tenant->children()->exists()) {
            return response()->json([
                'message' => 'Cannot delete tenant with sub-tenants'
            ], 422);
        }

        $tenant->delete();

        return response()->json(['message' => 'Tenant deleted']);
    }

    /**
     * Get modules for a tenant
     */
    public function modules(Tenant $tenant)
    {
        $modules = $tenant->modules()->get()->map(function ($module) {
            return [
                'id' => $module->id,
                'module_id' => $module->id,
                'name' => $module->name,
                'key' => $module->key,
                'description' => $module->description,
                'is_enabled' => (bool) $module->pivot->is_enabled,
            ];
        });

        return response()->json($modules);
    }

    /**
     * Toggle module for a tenant
     */
    public function toggleModule(Request $request, Tenant $tenant, $moduleId)
    {
        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        // Sync the module with the tenant
        $tenant->modules()->syncWithoutDetaching([
            $moduleId => ['is_enabled' => $validated['is_enabled']]
        ]);

        return response()->json(['message' => 'Module updated']);
    }
}
