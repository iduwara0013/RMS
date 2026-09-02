<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InterviewController extends Controller
{
    public function index(): JsonResponse { return response()->json(['interviews' => Interview::with(['application.candidate', 'application.vacancy'])->latest('interview_date')->get()]); }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can schedule interviews.');
        $data = $request->validate(['application_id' => ['required', 'integer'], 'interview_date' => ['required', 'date'], 'interview_time' => ['required'], 'location' => ['required', 'string', 'max:255']]);
        $application = Application::findOrFail($data['application_id']);
        abort_unless($application->status === 'Shortlisted', 422, 'Only shortlisted candidates can be scheduled.');
        $interview = DB::transaction(function () use ($data, $application) {
            $interview = Interview::create($data + ['status' => 'Scheduled']);
            $application->update(['status' => 'Interview Scheduled']);
            return $interview;
        });
        return response()->json(['message' => 'Interview scheduled successfully.', 'interview' => $interview], 201);
    }

    public function evaluate(Request $request, Interview $interview): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'Interview Panel Member', 403, 'Only interview panel members can evaluate.');
        $data = $request->validate(['score' => ['required', 'numeric', 'min:0', 'max:100'], 'comments' => ['required', 'string', 'max:2000'], 'recommendation' => ['required', 'in:Recommended,Not recommended,Keep in reserve']]);
        DB::table('interview_evaluations')->insert(['interview_id' => $interview->interview_id, 'score' => $data['score'], 'comments' => $data['comments'], 'recommendation' => $data['recommendation'], 'evaluated_at' => now()]);
        $interview->update(['status' => 'Evaluated']);
        $interview->application()->update(['status' => 'Evaluated']);
        return response()->json(['message' => 'Interview evaluation saved.']);
    }
}
