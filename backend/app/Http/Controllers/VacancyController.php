<?php

namespace App\Http\Controllers;

use App\Models\Vacancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VacancyController extends Controller
{
    public function published(): JsonResponse
    {
        $vacancies = Vacancy::query()
            ->with('department')
            ->where('status', 'Published')
            ->whereIn('audience', ['External', 'Both'])
            ->whereDate('closing_date', '>=', now())
            ->orderBy('closing_date')
            ->get();

        return response()->json(['vacancies' => $vacancies]);
    }

    public function showPublic(Vacancy $vacancy): JsonResponse
    {
        abort_unless($vacancy->status === 'Published' && in_array($vacancy->audience, ['External', 'Both'], true), 404);
        return response()->json(['vacancy' => $vacancy->load(['department', 'applicationForm'])]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Vacancy::query()->with('department')->latest();

        if ($request->header('X-User-Role') === 'Head of Department') {
            $departmentId = (int) $request->header('X-User-Department-Id');
            abort_unless($departmentId > 0, 403, 'Your user account is not assigned to a department.');
            $query->where('department_id', $departmentId);
        }

        return response()->json(['vacancies' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can create vacancies.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'vacancy_type' => ['required', 'string', 'max:80'],
            'vacancy_grade' => ['required', 'in:A,B,C'],
            'audience' => ['required', 'in:Internal,External,Both'],
            'opening_date' => ['required', 'date'],
            'closing_date' => ['required', 'date', 'after_or_equal:opening_date'],
            'department_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('Department', 'department_id')->where('is_active', true)],
        ]);
        $vacancy = Vacancy::create($data + ['status' => 'Draft']);
        return response()->json(['vacancy' => $vacancy], 201);
    }

    public function update(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless(in_array($vacancy->status, ['Draft', 'Rejected'], true), 422, 'Only draft or returned vacancies can be edited.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'vacancy_type' => ['required', 'string', 'max:80'],
            'vacancy_grade' => ['required', 'in:A,B,C'],
            'audience' => ['required', 'in:Internal,External,Both'],
            'opening_date' => ['required', 'date'],
            'closing_date' => ['required', 'date', 'after_or_equal:opening_date'],
            'department_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('Department', 'department_id')->where('is_active', true)],
        ]);
        $vacancy->update($data + ['status' => 'Draft', 'hr_approved_at' => null, 'hod_approved_at' => null, 'md_approved_at' => null]);
        return response()->json(['message' => 'Vacancy corrected. Submit it for approval again.', 'vacancy' => $vacancy->fresh('department')]);
    }

    public function submit(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403);
        abort_unless($vacancy->status === 'Draft' || $vacancy->status === 'Rejected', 422, 'Only draft vacancies can be submitted.');
        $vacancy->update([
            'status' => 'Pending HOD Approval',
            'hr_approved_at' => now(),
            'hod_approved_at' => null,
            'md_approved_at' => null,
            'rejection_reason' => null,
        ]);
        return response()->json(['message' => 'HR approved the vacancy and submitted it to the department HOD.', 'vacancy' => $vacancy->fresh('department')]);
    }

    public function hodApprove(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'Head of Department', 403);
        abort_unless($vacancy->status === 'Pending HOD Approval', 422, 'Vacancy is not awaiting HOD approval.');
        $departmentId = (int) $request->header('X-User-Department-Id');
        abort_unless($departmentId > 0 && $departmentId === (int) $vacancy->department_id, 403, 'Only the HOD of the selected department can approve this vacancy.');

        $requiresMdApproval = $vacancy->vacancy_grade === 'A';
        $vacancy->update([
            'status' => $requiresMdApproval ? 'Pending MD Approval' : 'Approved',
            'hod_approved_at' => now(),
        ]);

        return response()->json([
            'message' => $requiresMdApproval
                ? 'Grade A vacancy sent to the Managing Director for final approval.'
                : "Grade {$vacancy->vacancy_grade} vacancy approved. HR can now publish it.",
            'vacancy' => $vacancy->fresh('department'),
        ]);
    }

    public function mdApprove(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'Managing Director', 403);
        abort_unless($vacancy->status === 'Pending MD Approval', 422, 'Vacancy is not awaiting final approval.');
        abort_unless($vacancy->vacancy_grade === 'A', 422, 'Managing Director approval is only required for Grade A vacancies.');
        $vacancy->update(['status' => 'Approved', 'md_approved_at' => now()]);
        return response()->json(['message' => 'Vacancy finally approved. HR can now publish it.', 'vacancy' => $vacancy->fresh()]);
    }

    public function reject(Request $request, Vacancy $vacancy): JsonResponse
    {
        $role = $request->header('X-User-Role');
        abort_unless(in_array($role, ['Head of Department', 'Managing Director'], true), 403);
        abort_unless(in_array($vacancy->status, ['Pending HOD Approval', 'Pending MD Approval'], true), 422, 'Vacancy cannot be rejected at this stage.');
        if ($role === 'Head of Department') {
            $departmentId = (int) $request->header('X-User-Department-Id');
            abort_unless($vacancy->status === 'Pending HOD Approval' && $departmentId > 0 && $departmentId === (int) $vacancy->department_id, 403, 'Only the HOD of the selected department can send back this vacancy.');
        }
        if ($role === 'Managing Director') {
            abort_unless($vacancy->vacancy_grade === 'A' && $vacancy->status === 'Pending MD Approval', 403, 'This vacancy does not require Managing Director approval.');
        }
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
        $vacancy->update(['status' => 'Rejected', 'rejection_reason' => $data['rejection_reason']]);
        return response()->json(['message' => 'Vacancy sent back for changes.', 'vacancy' => $vacancy->fresh()]);
    }

    public function publish(Vacancy $vacancy): JsonResponse
    {
        abort_unless(request()->header('X-User-Role') === 'HR Manager', 403);
        abort_unless($vacancy->status === 'Approved', 422, 'Only finally approved vacancies can be published.');
        abort_if($vacancy->closing_date->lt(today()), 422, 'Update the expired closing date before publication.');
        $vacancy->update(['status' => 'Published']);

        return response()->json([
            'message' => 'Vacancy published successfully.',
            'vacancy' => $vacancy->fresh(),
        ]);
    }

    public function cancel(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can cancel vacancies.');
        abort_unless(
            in_array($vacancy->status, ['Draft', 'Rejected', 'Pending HOD Approval', 'Pending MD Approval', 'Approved'], true),
            422,
            'Only an unpublished vacancy can be cancelled.'
        );

        $vacancy->update(['status' => 'Cancelled']);

        return response()->json([
            'message' => 'Vacancy cancelled and moved to Finished.',
            'vacancy' => $vacancy->fresh('department'),
        ]);
    }

    public function close(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can close published vacancies.');
        abort_unless($vacancy->status === 'Published', 422, 'Only a published vacancy can be closed.');

        $vacancy->update(['status' => 'Closed']);

        return response()->json([
            'message' => 'Vacancy closed. It is no longer accepting applications and was moved to Finished.',
            'vacancy' => $vacancy->fresh('department'),
        ]);
    }
}
