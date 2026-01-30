<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function index()
    {
        return response()->json(Module::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);
        return response()->json(Module::create($validated), 201);
    }

    public function update(Request $request, Module $module)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'key' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
        ]);
        $module->update($validated);
        return response()->json($module);
    }

    public function destroy(Module $module)
    {
        $module->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
