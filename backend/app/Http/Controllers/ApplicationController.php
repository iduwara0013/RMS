<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Vacancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function store(Request $request, Vacancy $vacancy): JsonResponse
    {
        abort_unless($vacancy->status === 'Published' && $vacancy->closing_date->isFuture(), 422, 'This vacancy is no longer accepting applications.');
        $data = $request->validate(['nic' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'phone' => ['required', 'string', 'max:30'], 'address' => ['required', 'string', 'max:500'], 'cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120']]);
        $candidate = Candidate::where('nic', $data['nic'])->first();
        abort_if($candidate && Application::where('vacancy_id', $vacancy->vacancy_id)->where('candidate_id', $candidate->candidate_id)->exists(), 422, 'An application already exists for this candidate and vacancy.');
        $application = DB::transaction(function () use ($data, $vacancy, $request) {
            $candidate = Candidate::updateOrCreate(['nic' => $data['nic']], collect($data)->only(['nic', 'name', 'email', 'phone', 'address'])->all());
            $application = Application::create(['candidate_id' => $candidate->candidate_id, 'vacancy_id' => $vacancy->vacancy_id, 'submitted_at' => now(), 'status' => 'Submitted']);
            $path = $request->file('cv')->store('candidate-documents', 'public');
            DB::table('documents')->insert(['application_id' => $application->application_id, 'document_type' => 'CV', 'file_name' => $request->file('cv')->getClientOriginalName(), 'file_path' => $path, 'uploaded_at' => now()]);
            return $application;
        });
        return response()->json(['message' => 'Application submitted successfully.', 'application_id' => $application->application_id], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Application::with(['candidate', 'vacancy'])->latest('submitted_at');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        return response()->json(['applications' => $query->get()]);
    }

    public function updateStatus(Request $request, Application $application): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can update application status.');
        $data = $request->validate(['status' => ['required', 'in:Verified,Rejected,Shortlisted']]);
        abort_unless(in_array($data['status'], ['Verified', 'Rejected', 'Shortlisted'], true), 422);
        $application->update(['status' => $data['status']]);
        return response()->json(['message' => "Application marked {$data['status']}.", 'application' => $application->fresh(['candidate', 'vacancy'])]);
    }
}
