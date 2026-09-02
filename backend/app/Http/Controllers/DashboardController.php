<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $role = $request->header('X-User-Role', '');
        $applications = (int) DB::table('applications')->count();
        $tasks = match ($role) {
            'HR Manager' => (int) DB::table('vacancies')->whereIn('status', ['Draft', 'Rejected', 'Approved'])->count() + (int) DB::table('applications')->whereIn('status', ['Submitted', 'Verified', 'Evaluated'])->count(),
            'Head of Department' => (int) DB::table('vacancies')->where('status', 'Pending HOD Approval')->count(),
            'Managing Director' => (int) DB::table('vacancies')->where('status', 'Pending MD Approval')->count() + (int) DB::table('final_selections')->where('status', 'Pending MD Approval')->count(),
            'Interview Panel Member' => (int) DB::table('interviews')->where('status', 'Scheduled')->count(),
            default => 0,
        };
        return response()->json(['open_tasks' => $tasks, 'applications' => $applications, 'system_status' => 'Online']);
    }
}
