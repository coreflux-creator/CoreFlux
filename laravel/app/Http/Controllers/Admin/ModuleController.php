<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    /**
     * List all modules
     */
    public function index()
    {
        $modules = Module::orderBy('name')->get();
        return response()->json($modules);
    }

    /**
     * Create a new module
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:100|unique:modules',
            'description' => 'nullable|string',
            'icon' => 'nullable|string',
        ]);

        $module = Module::create($validated);

        return response()->json($module, 201);
    }

    /**
     * Update a module
     */
    public function update(Request $request, Module $module)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'key' => 'sometimes|string|max:100|unique:modules,key,' . $module->id,
            'description' => 'nullable|string',
            'icon' => 'nullable|string',
        ]);

        $module->update($validated);

        return response()->json($module);
    }

    /**
     * Delete a module
     */
    public function destroy(Module $module)
    {
        $module->delete();

        return response()->json(['message' => 'Module deleted']);
    }
}
