<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InterviewController extends Controller
{
    public function index(): JsonResponse { return response()->json(['interviews' => Interview::with(['application.candidate', 'application.vacancy.department'])->latest('interview_date')->get()]); }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can schedule interviews.');
        if ($request->filled('application_id') && ! $request->has('application_ids')) {
            $request->merge(['application_ids' => [(int) $request->input('application_id')]]);
        }

        $data = $request->validate([
            'application_ids' => ['required', 'array', 'min:1'],
            'application_ids.*' => ['required', 'integer', 'distinct', 'exists:applications,application_id'],
            'interview_date' => ['required', 'date', 'after_or_equal:today'],
            'interview_time' => ['required', 'date_format:H:i'],
            'location' => ['required', 'string', 'max:255'],
        ]);

        $applications = Application::query()
            ->whereIn('application_id', $data['application_ids'])
            ->get();
        abort_if(
            $applications->contains(fn (Application $application) => $application->status !== 'Shortlisted'),
            422,
            'Every selected candidate must still be shortlisted.'
        );
        abort_if(
            Interview::query()->whereIn('application_id', $data['application_ids'])->exists(),
            422,
            'One or more selected candidates already have an interview.'
        );

        $interviews = DB::transaction(function () use ($data, $applications) {
            return $applications->map(function (Application $application) use ($data) {
                $interview = Interview::create([
                    'application_id' => $application->application_id,
                    'interview_date' => $data['interview_date'],
                    'interview_time' => $data['interview_time'],
                    'location' => $data['location'],
                    'status' => 'Scheduled',
                ]);
                $application->update(['status' => 'Interview Scheduled']);

                return $interview;
            });
        });

        $count = $interviews->count();
        return response()->json([
            'message' => $count === 1 ? 'Interview scheduled successfully.' : "$count interviews scheduled successfully.",
            'interviews' => $interviews,
        ], 201);
    }

    public function evaluate(Request $request, Interview $interview): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'Interview Panel Member', 403, 'Only interview panel members can evaluate.');
        abort_unless($interview->status === 'Scheduled' && $interview->application->status === 'Interview Scheduled', 422, 'This interview is no longer awaiting evaluation.');
        abort_if(DB::table('interview_evaluations')->where('interview_id', $interview->interview_id)->exists(), 422, 'An evaluation has already been recorded.');
        $data = $request->validate(['score' => ['required', 'numeric', 'min:0', 'max:100'], 'comments' => ['required', 'string', 'max:2000'], 'recommendation' => ['required', 'in:Recommended,Not recommended,Keep in reserve']]);
        DB::table('interview_evaluations')->insert(['interview_id' => $interview->interview_id, 'score' => $data['score'], 'comments' => $data['comments'], 'recommendation' => $data['recommendation'], 'evaluated_at' => now()]);
        $interview->update(['status' => 'Evaluated']);
        $interview->application()->update(['status' => 'Evaluated']);
        return response()->json(['message' => 'Interview evaluation saved.']);
    }
}
