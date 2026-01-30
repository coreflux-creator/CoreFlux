<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * List all users
     */
    public function index()
    {
        $users = User::with('tenants')->orderBy('email')->get()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => $user->role,
                'tenant_ids' => $user->tenants->pluck('id')->toArray(),
            ];
        });
        
        return response()->json($users);
    }

    /**
     * Create a new user
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'role' => 'nullable|string|in:user,admin,master_admin',
            'tenant_ids' => 'nullable|array',
            'tenant_ids.*' => 'exists:tenants,id',
        ]);

        $user = User::create([
            'name' => $validated['name'] ?? ($validated['first_name'] . ' ' . ($validated['last_name'] ?? '')),
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'password_hash' => Hash::make($validated['password']), // For legacy compatibility
            'role' => $validated['role'] ?? 'user',
        ]);

        // Assign tenants
        if (!empty($validated['tenant_ids'])) {
            foreach ($validated['tenant_ids'] as $tenantId) {
                UserTenant::create([
                    'user_id' => $user->id,
                    'tenant_id' => $tenantId,
                    'role' => 'user',
                ]);
            }
        }

        return response()->json($user, 201);
    }

    /**
     * Get a single user
     */
    public function show(User $user)
    {
        $user->load('tenants');
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => $user->role,
            'tenant_ids' => $user->tenants->pluck('id')->toArray(),
        ]);
    }

    /**
     * Update a user
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'role' => 'nullable|string|in:user,admin,master_admin',
            'tenant_ids' => 'nullable|array',
            'tenant_ids.*' => 'exists:tenants,id',
        ]);

        $updateData = collect($validated)->except(['password', 'tenant_ids'])->toArray();
        
        if (!empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
            $updateData['password_hash'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        // Update tenant assignments
        if (isset($validated['tenant_ids'])) {
            // Remove existing assignments
            UserTenant::where('user_id', $user->id)->delete();
            
            // Add new assignments
            foreach ($validated['tenant_ids'] as $tenantId) {
                UserTenant::create([
                    'user_id' => $user->id,
                    'tenant_id' => $tenantId,
                    'role' => 'user',
                ]);
            }
        }

        return response()->json($user);
    }

    /**
     * Delete a user
     */
    public function destroy(User $user)
    {
        // Prevent deleting self
        if (auth()->id() === $user->id) {
            return response()->json(['message' => 'Cannot delete yourself'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }
}
