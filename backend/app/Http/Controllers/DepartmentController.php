<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'departments' => Department::query()->where('is_active', true)->orderBy('department_name')->get(),
        ]);
    }
}
