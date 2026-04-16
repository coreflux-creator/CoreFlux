<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);

        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }
        
        $password = $user->password_hash ?? $user->password;

        if (!Hash::check($request->password, $password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;
        $tenants = $user->tenants->map(fn($t) => [
            'id' => $t->id, 'name' => $t->name, 'role' => $t->pivot->role
        ]);

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
            'tenants' => $tenants
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $tenants = $user->tenants->map(fn($t) => [
            'id' => $t->id, 'name' => $t->name, 'role' => $t->pivot->role
        ]);
        return response()->json(['user' => $user, 'tenants' => $tenants]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
