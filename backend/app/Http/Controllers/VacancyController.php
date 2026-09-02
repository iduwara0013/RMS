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
            ->where('status', 'Published')
            ->whereDate('opening_date', '<=', now())
            ->whereDate('closing_date', '>=', now())
            ->orderBy('closing_date')
            ->get();

        return response()->json(['vacancies' => $vacancies]);
    }

    public function showPublic(Vacancy $vacancy): JsonResponse
    {
        abort_unless($vacancy->status === 'Published', 404);
        return response()->json(['vacancy' => $vacancy]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['vacancies' => Vacancy::latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can create vacancies.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'vacancy_type' => ['required', 'string', 'max:80'],
            'opening_date' => ['required', 'date'],
            'closing_date' => ['required', 'date', 'after_or_equal:opening_date'],
            'department_id' => ['nullable', 'integer'],
        ]);
        $vacancy = Vacancy::create($data + ['status' => 'Draft']);
        return response()->json(['vacancy' => $vacancy], 201);
    }

    public function submit(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403);
        abort_unless($vacancy->status === 'Draft' || $vacancy->status === 'Rejected', 422, 'Only draft vacancies can be submitted.');
        $vacancy->update(['status' => 'Pending HOD Approval', 'rejection_reason' => null]);
        return response()->json(['message' => 'Vacancy submitted to Head of Department.', 'vacancy' => $vacancy->fresh()]);
    }

    public function hodApprove(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'Head of Department', 403);
        abort_unless($vacancy->status === 'Pending HOD Approval', 422, 'Vacancy is not awaiting HOD approval.');
        $vacancy->update(['status' => 'Pending MD Approval', 'hod_approved_at' => now()]);
        return response()->json(['message' => 'Vacancy sent for Managing Director approval.', 'vacancy' => $vacancy->fresh()]);
    }

    public function mdApprove(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'Managing Director', 403);
        abort_unless($vacancy->status === 'Pending MD Approval', 422, 'Vacancy is not awaiting final approval.');
        $vacancy->update(['status' => 'Approved', 'md_approved_at' => now()]);
        return response()->json(['message' => 'Vacancy finally approved. HR can now publish it.', 'vacancy' => $vacancy->fresh()]);
    }

    public function reject(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless(in_array($request->header('X-User-Role'), ['Head of Department', 'Managing Director'], true), 403);
        abort_unless(in_array($vacancy->status, ['Pending HOD Approval', 'Pending MD Approval'], true), 422, 'Vacancy cannot be rejected at this stage.');
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
        $vacancy->update(['status' => 'Rejected', 'rejection_reason' => $data['rejection_reason']]);
        return response()->json(['message' => 'Vacancy sent back for changes.', 'vacancy' => $vacancy->fresh()]);
    }

    public function publish(Vacancy $vacancy): JsonResponse
    {
        abort_unless(request()->header('X-User-Role') === 'HR Manager', 403);
        abort_unless($vacancy->status === 'Approved', 422, 'Only finally approved vacancies can be published.');
        $vacancy->update(['status' => 'Published']);

        return response()->json([
            'message' => 'Vacancy published successfully.',
            'vacancy' => $vacancy->fresh(),
        ]);
    }
}
