<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class StaffManagementController extends Controller
{
    private function administrator(Request $request): bool
    {
        return StaffAccess::hasAnyRole($request->user(), ['System Administrator']);
    }

    private function lockRecords(): void
    {
        // Serialize management changes, including concurrent last-admin changes.
        Department::orderBy('department_id')->lockForUpdate()->get();
        User::orderBy('id')->lockForUpdate()->get();
    }

    private function editable(Request $request, User $user): void
    {
        abort_if(!$this->administrator($request) && $user->roles()->where('role_name', 'System Administrator')->exists(), 403, 'Only administrators can change administrator accounts.');
    }

    private function revoke(User $user): void
    {
        foreach (['staff_access_tokens', 'internal_access_tokens', 'internal_otp_challenges'] as $table) {
            if (Schema::hasTable($table)) DB::table($table)->where('user_id', $user->id)->delete();
        }
    }

    public function index(Request $request)
    {
        return response()->json([
            // Keep role-less employees available for assignment, but exclude
            // legacy external-only accounts without deleting applicant history.
            'users' => User::with(['roles', 'department'])->where(function ($query) {
                $query->whereDoesntHave('roles', fn ($roles) => $roles->where('role_name', 'External Candidate'))
                    ->orWhereHas('roles', fn ($roles) => $roles->where('role_name', '!=', 'External Candidate'));
            })->orderBy('name')->get(['id', 'name', 'employee_pin', 'employee_epf', 'phone', 'email', 'department_id', 'is_active', 'first_login']),
            'departments' => Department::orderBy('department_name')->get(),
            'roles' => Role::where('role_name', '!=', 'External Candidate')->when(!$this->administrator($request), fn ($q) => $q->where('role_name', '!=', 'System Administrator'))->orderBy('role_name')->get(),
        ]);
    }

    public function saveUser(Request $request, ?int $staff = null)
    {
        $this->lockRecords();
        $user = $staff ? User::findOrFail($staff) : new User;
        if ($staff) $this->editable($request, $user);
        if ($staff) {
            abort_if($user->roles()->where('role_name', 'External Candidate')->exists()
                && !$user->roles()->where('role_name', '!=', 'External Candidate')->exists(), 422, 'External applicants are not employees. Manage their applications in Applications.');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_pin' => ['required', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'employee_epf' => ['required', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff)],
            'department_id' => ['nullable', 'integer', Rule::exists('Department', 'department_id')->where('is_active', true)],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['required', 'integer', 'distinct', 'exists:roles,role_id'],
            'is_active' => ['required', 'boolean'],
        ]);
        $roles = Role::whereIn('role_id', $data['role_ids'])->pluck('role_name');
        abort_if($roles->contains('External Candidate'), 422, 'External candidates apply through the public portal, not a staff account.');
        abort_if(!$this->administrator($request) && $roles->contains('System Administrator'), 403, 'Only administrators can assign the administrator role.');
        abort_if($roles->contains('Head of Department') && empty($data['department_id']), 422, 'Select the department this HOD will approve for.');
        abort_if($roles->contains('Internal Employee') && empty($data['phone']), 422, 'Internal employees need a registered phone for OTP verification.');
        $identifiers = [$data['employee_pin'], $data['employee_epf']];
        $duplicate = User::where(fn ($q) => $q->whereIn('employee_pin', $identifiers)->orWhereIn('employee_epf', $identifiers))->when($staff, fn ($q) => $q->where('id', '!=', $staff))->exists();
        abort_if($duplicate, 422, 'This PIN or EPF already identifies another employee.');
        if ($staff && $staff === $request->user()->id) {
            abort_unless($data['is_active'] && $roles->intersect(['HR Manager', 'System Administrator'])->isNotEmpty(), 422, 'You cannot deactivate your own account or remove your own management access.');
        }
        if ($staff && $user->is_active && $user->roles()->where('role_name', 'System Administrator')->exists() && (!$data['is_active'] || !$roles->contains('System Administrator'))) {
            abort_unless(User::where('id', '!=', $staff)->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('role_name', 'System Administrator'))->exists(), 422, 'Keep at least one active administrator.');
        }
        unset($data['role_ids']);
        if (!$staff) {
            $data['password'] = $data['employee_pin'];
            $data['first_login'] = true;
        }
        $user->fill($data)->save();
        $user->roles()->sync($request->input('role_ids'));
        if ($staff) $this->revoke($user);
        return response()->json(['message' => $staff ? 'Employee updated. Their existing sessions have been signed out.' : 'Employee created. First-time password is their PIN or EPF; a password change is required.'], $staff ? 200 : 201);
    }

    public function setActive(Request $request, int $staff)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $this->lockRecords();
        $user = User::findOrFail($staff);
        $this->editable($request, $user);
        $active = (bool) $data['is_active'];
        abort_if(!$active && $staff === $request->user()->id, 422, 'You cannot deactivate your own account.');
        if (!$active && $user->is_active && $user->roles()->where('role_name', 'System Administrator')->exists()) {
            abort_unless(User::where('id', '!=', $staff)->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('role_name', 'System Administrator'))->exists(), 422, 'Keep at least one active administrator.');
        }
        if ($active && $user->department_id) {
            abort_unless(Department::where('department_id', $user->department_id)->where('is_active', true)->exists(), 422, 'Assign an active department using Edit before reactivating this employee.');
        }
        $user->update(['is_active' => $active]);
        if (!$active) $this->revoke($user);
        return response()->json(['message' => $active
            ? 'Employee reactivated. They can sign in again.'
            : 'Employee deactivated and signed out. Recruitment history is preserved. Find them using the Inactive status filter.']);
    }

    public function resetPassword(Request $request, int $staff)
    {
        $this->lockRecords();
        $user = User::findOrFail($staff);
        $this->editable($request, $user);
        abort_if($staff === $request->user()->id, 422, 'Use the password-change screen for your own account.');
        $user->update(['password' => $user->employee_pin, 'first_login' => true]);
        $this->revoke($user);
        return response()->json(['message' => 'Password reset. The employee must use their PIN or EPF once, then choose a new password. Existing sessions were revoked.']);
    }

    public function saveDepartment(Request $request, ?int $department = null)
    {
        $this->lockRecords();
        $record = $department ? Department::findOrFail($department) : new Department;
        $data = $request->validate([
            'department_name' => ['required', 'string', 'max:255', Rule::unique('Department', 'department_name')->ignore($department, 'department_id')],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($department && !$data['is_active']) {
            abort_if(User::where('department_id', $department)->where('is_active', true)->exists(), 422, 'Reassign or deactivate active employees before deactivating this department.');
            abort_if(DB::table('vacancies')->where('department_id', $department)->whereNotIn('status', ['Closed', 'Cancelled', 'Rejected'])->exists(), 422, 'Finish the department’s active vacancies before deactivating it.');
        }
        $record->fill($data)->save();
        return response()->json(['message' => 'Department saved. Assign its HOD in Users by selecting this department and the Head of Department role.'], $department ? 200 : 201);
    }
}
