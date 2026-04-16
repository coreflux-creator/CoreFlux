<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');
        
        $stats = [
            'active_users' => 0,
            'this_month' => 0,
            'revenue' => 0,
            'completed' => 0,
        ];
        
        if ($tenantId) {
            // Get stats for specific tenant
            $stats['active_users'] = DB::table('user_tenants')
                ->where('tenant_id', $tenantId)
                ->count();
        }
        
        return response()->json($stats);
    }
}
