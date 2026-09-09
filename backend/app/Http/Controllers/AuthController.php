<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with(['roles', 'department'])->where('employee_pin', $data['identifier'])
            ->orWhere('employee_epf', $data['identifier'])
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid Employee PIN/EPF or password.'], 401);
        }

        $temporaryPasswordMatches = $user->first_login && in_array(
            $data['password'],
            [$user->employee_pin, $user->employee_epf],
            true
        );

        if (!$temporaryPasswordMatches && !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid Employee PIN/EPF or password.'], 401);
        }

        $plainToken = bin2hex(random_bytes(32));
        DB::table('staff_access_tokens')->where('user_id', $user->id)->delete();
        DB::table('staff_access_tokens')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHours(8),
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Login successful.',
            'first_login' => (bool) $user->first_login,
            'token' => $plainToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'employee_pin' => $user->employee_pin,
                'employee_epf' => $user->employee_epf,
                'department_id' => $user->department_id,
                'department_name' => $user->department?->department_name,
                'roles' => $user->roles->pluck('role_name')->values(),
            ],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::where('employee_pin', $data['identifier'])
            ->orWhere('employee_epf', $data['identifier'])
            ->firstOrFail();

        $user->update([
            'password' => Hash::make($data['password']),
            'first_login' => false,
        ]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $plainToken = $request->bearerToken();
        if ($plainToken) {
            DB::table('staff_access_tokens')->where('token_hash', hash('sha256', $plainToken))->delete();
        }

        return response()->json(['message' => 'Signed out.']);
    }
}
