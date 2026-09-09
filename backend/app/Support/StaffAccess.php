<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffAccess
{
    public static function user(Request $request): User
    {
        $plainToken = $request->bearerToken();
        abort_unless($plainToken, 401, 'Your staff session has expired. Please sign in again.');

        $token = DB::table('staff_access_tokens')
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('expires_at', '>', now())
            ->first();
        abort_unless($token, 401, 'Your staff session has expired. Please sign in again.');

        $user = User::with(['roles', 'department'])->find($token->user_id);
        abort_unless($user, 401, 'Your staff account is no longer available.');
        abort_if($user->is_active === false, 401, 'Your account has been deactivated. Contact HR.');

        DB::table('staff_access_tokens')->where('id', $token->id)->update([
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public static function hasAnyRole(User $user, array $roles): bool
    {
        return $user->roles->pluck('role_name')->intersect($roles)->isNotEmpty();
    }
}
