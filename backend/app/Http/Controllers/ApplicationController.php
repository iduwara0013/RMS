<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Document;
use App\Models\Vacancy;
use App\Services\CvProfileExtractor;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ApplicationController extends Controller
{
    public function store(Request $request, Vacancy $vacancy, CvProfileExtractor $extractor): JsonResponse
    {
        $acceptingApplications = $vacancy->status === 'Published'
            && in_array($vacancy->audience, ['External', 'Both'], true)
            && $vacancy->opening_date->startOfDay()->lte(today())
            && $vacancy->closing_date->endOfDay()->gte(now());

        abort_unless($acceptingApplications, 422, 'This vacancy is not yet open or is no longer accepting applications.');
        $data = $request->validate(['nic' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'phone' => ['required', 'string', 'max:30'], 'address' => ['required', 'string', 'max:500'], 'cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120']]);
        $candidate = Candidate::where('nic', $data['nic'])->first();
        abort_if($candidate && Application::where('vacancy_id', $vacancy->vacancy_id)->where('candidate_id', $candidate->candidate_id)->exists(), 422, 'An application already exists for this candidate and vacancy.');
        [$application, $document] = DB::transaction(function () use ($data, $vacancy, $request) {
            $candidate = Candidate::updateOrCreate(['nic' => $data['nic']], collect($data)->only(['nic', 'name', 'email', 'phone', 'address'])->all());
            $application = Application::create(['candidate_id' => $candidate->candidate_id, 'vacancy_id' => $vacancy->vacancy_id, 'applicant_type' => 'External', 'submitted_at' => now(), 'status' => 'Submitted']);
            $path = $request->file('cv')->store('candidate-documents', 'local');
            $document = Document::create(['application_id' => $application->application_id, 'document_type' => 'CV', 'file_name' => $request->file('cv')->getClientOriginalName(), 'file_path' => $path, 'uploaded_at' => now()]);
            return [$application, $document];
        });
        $extractor->extractAndStore($application, $document);
        return response()->json(['message' => 'Application submitted successfully.', 'application_id' => $application->application_id], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = StaffAccess::user($request);
        abort_unless(StaffAccess::hasAnyRole($user, ['HR Manager', 'Data Entry Operator', 'Head of Department', 'Managing Director']), 403, 'You are not allowed to view applications.');

        $query = Application::with([
            'candidate',
            'vacancy.department',
            'documents' => fn ($documents) => $documents->select(['document_id', 'application_id', 'document_type', 'file_name', 'uploaded_at']),
        ])->latest('submitted_at');
        if ($user->roles->contains('role_name', 'Head of Department')) {
            abort_unless($user->department_id, 403, 'Your account is not assigned to a department.');
            $query->whereHas('vacancy', fn ($vacancies) => $vacancies->where('department_id', $user->department_id));
        }
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        return response()->json(['applications' => $query->get()]);
    }

    public function completed(Request $request): JsonResponse
    {
        abort_unless(
            in_array($request->header('X-User-Role'), ['HR Manager', 'System Administrator', 'Managing Director'], true),
            403,
            'You are not allowed to view completed candidate records.'
        );

        $applications = Application::with(['candidate', 'vacancy.department'])
            ->whereIn('status', ['Selected', 'Not Selected'])
            ->latest('updated_at')
            ->get();

        return response()->json(['candidates' => $applications]);
    }

    public function updateStatus(Request $request, Application $application): JsonResponse
    {
        $user = StaffAccess::user($request);
        abort_unless($user->roles->contains('role_name', 'HR Manager'), 403, 'Only HR Manager can update application status.');
        $data = $request->validate(['status' => ['required', 'in:Verified,Rejected,Shortlisted']]);
        abort_unless(in_array($data['status'], ['Verified', 'Rejected', 'Shortlisted'], true), 422);
        $allowed = ['Submitted' => ['Verified', 'Rejected'], 'Verified' => ['Shortlisted', 'Rejected']];
        abort_unless(in_array($data['status'], $allowed[$application->status] ?? [], true), 422, 'This application cannot move to that stage.');
        $application->update(['status' => $data['status']]);
        return response()->json(['message' => "Application marked {$data['status']}.", 'application' => $application->fresh(['candidate', 'vacancy'])]);
    }

    public function cvProfile(Request $request, Application $application, CvProfileExtractor $extractor): JsonResponse
    {
        $user = StaffAccess::user($request);
        abort_unless(StaffAccess::hasAnyRole($user, ['HR Manager', 'Head of Department', 'Managing Director']), 403, 'You are not allowed to access extracted CV details.');

        $application->loadMissing(['vacancy', 'cvProfile']);
        if ($user->roles->contains('role_name', 'Head of Department')) {
            abort_unless(
                $user->department_id && (int) $user->department_id === (int) $application->vacancy->department_id,
                403,
                'You can only access CV details submitted to your department.'
            );
        }

        $document = $application->documents()->where('document_type', 'CV')->first();
        $hasParserVersion = Schema::hasColumn('candidate_cv_profiles', 'parser_version');
        $parseMessage = (string) ($application->cvProfile?->parse_message ?? '');
        $hasCurrentMarker = str_starts_with($parseMessage, '[parser:layout-v1]')
            || str_starts_with($parseMessage, '[parser:php-fallback-v3]');
        $needsExtraction = ! $application->cvProfile || (
            $hasParserVersion
                ? ! in_array($application->cvProfile->parser_version, ['layout-v1', 'php-fallback-v3'], true)
                : ! $hasCurrentMarker
        );
        if ($document && $needsExtraction) {
            $application->setRelation('cvProfile', $extractor->extractAndStore($application, $document));
        }

        if ($document) {
            DB::table('document_access_logs')->insert([
                'document_id' => $document->document_id,
                'application_id' => $application->application_id,
                'user_id' => $user->id,
                'action' => 'ProfileView',
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'accessed_at' => now(),
            ]);
        }

        $profile = $application->cvProfile?->toArray();
        if ($profile) {
            $profile['parse_message'] = preg_replace('/^\[parser:[^\]]+\]\s*/', '', (string) ($profile['parse_message'] ?? ''));
        }

        return response()->json(['cv_profile' => $profile]);
    }

    public function document(Request $request, Application $application, Document $document)
    {
        $user = StaffAccess::user($request);
        abort_unless(StaffAccess::hasAnyRole($user, ['HR Manager', 'Head of Department', 'Managing Director']), 403, 'You are not allowed to access candidate documents.');
        abort_unless((int) $document->application_id === (int) $application->application_id, 404);

        $application->loadMissing('vacancy');
        if ($user->roles->contains('role_name', 'Head of Department')) {
            abort_unless(
                $user->department_id && (int) $user->department_id === (int) $application->vacancy->department_id,
                403,
                'You can only access CVs submitted to your department.'
            );
        }

        $disk = Storage::disk('local')->exists($document->file_path) ? 'local' : 'public';
        abort_unless(Storage::disk($disk)->exists($document->file_path), 404, 'CV file was not found.');
        $download = $request->boolean('download');

        DB::table('document_access_logs')->insert([
            'document_id' => $document->document_id,
            'application_id' => $application->application_id,
            'user_id' => $user->id,
            'action' => $download ? 'Download' : 'View',
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'accessed_at' => now(),
        ]);

        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($download) {
            return Storage::disk($disk)->download($document->file_path, basename($document->file_name), $headers);
        }

        $safeName = str_replace(['"', "\r", "\n"], '', basename($document->file_name));
        return response()->file(Storage::disk($disk)->path($document->file_path), $headers + [
            'Content-Disposition' => 'inline; filename="'.$safeName.'"',
        ]);
    }
}
