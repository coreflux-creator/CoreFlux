<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index()
    {
        return response()->json(Tenant::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subdomain' => 'nullable|string|max:100',
            'parent_id' => 'nullable|exists:tenants,id',
        ]);
        return response()->json(Tenant::create($validated), 201);
    }

    public function show(Tenant $tenant)
    {
        return response()->json($tenant);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'subdomain' => 'nullable|string|max:100',
            'parent_id' => 'nullable|exists:tenants,id',
        ]);
        $tenant->update($validated);
        return response()->json($tenant);
    }

    public function destroy(Tenant $tenant)
    {
        $tenant->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function modules(Tenant $tenant)
    {
        $modules = $tenant->modules()->get()->map(fn($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'key' => $m->key,
            'is_enabled' => (bool) $m->pivot->is_enabled,
        ]);
        return response()->json($modules);
    }

    public function toggleModule(Request $request, Tenant $tenant, $moduleId)
    {
        $tenant->modules()->syncWithoutDetaching([
            $moduleId => ['is_enabled' => $request->boolean('is_enabled')]
        ]);
        return response()->json(['message' => 'Updated']);
    }
}
