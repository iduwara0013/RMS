<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Interview;
use App\Models\PanelEvaluation;
use App\Models\User;
use App\Support\StaffAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InterviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->header('X-User-Role');
        $query = Interview::with([
            'application.candidate', 'application.vacancy.department',
            'assignments.member:id,name', 'criteria',
            'panelEvaluations.member:id,name', 'panelEvaluations.scores',
        ])->latest('interview_date');

        if ($role === 'Interview Panel Member') {
            $query->whereHas('assignments', fn ($q) => $q->where('user_id', $user->id));
        }
        if ($role === 'Head of Department') {
            abort_unless($user->department_id, 403, 'Your account is not assigned to a department.');
            $query->whereHas('application.vacancy', fn ($q) => $q->where('department_id', $user->department_id));
        }

        $interviews = $query->get();
        $legacy = DB::table('interview_evaluations')->whereIn('interview_id', $interviews->pluck('interview_id'))->get()->groupBy('interview_id');
        $interviews = $interviews->map(function (Interview $interview) use ($user, $role, $legacy) {
            $submitted = $interview->panelEvaluations->where('status', 'Submitted');
            $legacyEvaluation = $legacy->get($interview->interview_id)?->first();
            $interview->setAttribute('combined_score', $submitted->isNotEmpty() ? round($submitted->avg('weighted_score'), 2) : ($legacyEvaluation ? (float) $legacyEvaluation->score : null));
            $interview->setAttribute('completed_evaluations', $submitted->count() ?: ($legacyEvaluation ? 1 : 0));
            $interview->setAttribute('assigned_members', $interview->assignments->count());
            $interview->setAttribute('my_evaluation', $interview->panelEvaluations->firstWhere('evaluator_id', $user->id));
            if ($legacyEvaluation && $submitted->isEmpty()) {
                $interview->panelEvaluations->push((object) [
                    'id' => 'legacy-'.$legacyEvaluation->evaluation_id, 'evaluator_id' => null,
                    'weighted_score' => (float) $legacyEvaluation->score,
                    'recommendation' => $legacyEvaluation->recommendation, 'comments' => $legacyEvaluation->comments,
                    'status' => 'Submitted', 'submitted_at' => $legacyEvaluation->evaluated_at,
                    'member' => (object) ['id' => null, 'name' => 'Legacy panel evaluation'], 'scores' => [],
                ]);
            }
            if ($role === 'Interview Panel Member') {
                $interview->setRelation('panelEvaluations', $interview->panelEvaluations->where('evaluator_id', $user->id)->values());
                $interview->setHidden(array_merge($interview->getHidden(), ['combined_score']));
            }
            return $interview;
        });

        $members = [];
        if (StaffAccess::hasAnyRole($user, ['HR Manager'])) {
            $members = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('role_name', 'Interview Panel Member'))->orderBy('name')->get(['id', 'name', 'department_id']);
        }
        return response()->json(['interviews' => $interviews, 'panel_members' => $members]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can schedule interviews.');
        if ($request->filled('application_id') && ! $request->has('application_ids')) $request->merge(['application_ids' => [(int) $request->input('application_id')]]);
        $data = $request->validate([
            'application_ids' => ['required', 'array', 'min:1'],
            'application_ids.*' => ['required', 'integer', 'distinct', 'exists:applications,application_id'],
            'panel_member_ids' => ['required', 'array', 'min:1'],
            'panel_member_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'criteria' => ['required', 'array', 'min:1', 'max:12'],
            'criteria.*.name' => ['required', 'string', 'max:150', 'distinct'],
            'criteria.*.weight' => ['required', 'numeric', 'gt:0', 'max:100'],
            'interview_date' => ['required', 'date', 'after_or_equal:today'],
            'interview_time' => ['required', 'date_format:H:i'],
            'location' => ['required', 'string', 'max:255'],
        ]);
        abort_if(abs(collect($data['criteria'])->sum('weight') - 100) > 0.001, 422, 'Scorecard weights must total exactly 100%.');
        $memberCount = User::whereIn('id', $data['panel_member_ids'])->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('role_name', 'Interview Panel Member'))->count();
        abort_unless($memberCount === count($data['panel_member_ids']), 422, 'Every assigned user must be an active Interview Panel Member.');
        $applications = Application::whereIn('application_id', $data['application_ids'])->get();
        abort_if($applications->contains(fn ($application) => $application->status !== 'Shortlisted'), 422, 'Every selected candidate must still be shortlisted.');
        abort_if(Interview::whereIn('application_id', $data['application_ids'])->exists(), 422, 'One or more selected candidates already have an interview.');

        $interviews = DB::transaction(function () use ($data, $applications, $request) {
            return $applications->map(function (Application $application) use ($data, $request) {
                $interview = Interview::create(['application_id' => $application->application_id, 'interview_date' => $data['interview_date'], 'interview_time' => $data['interview_time'], 'location' => $data['location'], 'status' => 'Scheduled']);
                foreach ($data['panel_member_ids'] as $memberId) $interview->assignments()->create(['user_id' => $memberId, 'assigned_by' => $request->user()->id]);
                foreach ($data['criteria'] as $order => $criterion) $interview->criteria()->create(['name' => $criterion['name'], 'weight' => $criterion['weight'], 'sort_order' => $order + 1]);
                $application->update(['status' => 'Interview Scheduled']);
                return $interview;
            });
        });
        return response()->json(['message' => $interviews->count().' interview(s) scheduled with the selected panel and scorecard.', 'interviews' => $interviews], 201);
    }

    public function evaluate(Request $request, Interview $interview): JsonResponse
    {
        $user = $request->user();
        abort_unless($request->header('X-User-Role') === 'Interview Panel Member', 403, 'Only interview panel members can evaluate.');
        abort_unless($interview->assignments()->where('user_id', $user->id)->exists(), 403, 'You are not assigned to this interview.');
        abort_unless(in_array($interview->status, ['Scheduled', 'In Progress'], true) && $interview->application->status === 'Interview Scheduled', 422, 'This interview is no longer awaiting evaluation.');
        $existing = PanelEvaluation::where('interview_id', $interview->interview_id)->where('evaluator_id', $user->id)->first();
        abort_if($existing?->status === 'Submitted', 422, 'Your evaluation is locked. Ask HR to reopen it if a correction is required.');
        $criteria = $interview->criteria()->get();
        abort_if($criteria->isEmpty(), 422, 'This interview has no scorecard criteria.');
        $data = $request->validate(['scores' => ['required', 'array'], 'scores.*' => ['required', 'numeric', 'min:0', 'max:100'], 'comments' => ['required', 'string', 'max:2000'], 'recommendation' => ['required', 'in:Recommended,Not recommended,Keep in reserve']]);
        $expected = $criteria->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $received = collect(array_keys($data['scores']))->map(fn ($id) => (string) $id)->sort()->values()->all();
        abort_unless($expected === $received, 422, 'Provide one score for every scorecard criterion.');
        $weighted = round($criteria->sum(fn ($criterion) => ((float) $data['scores'][$criterion->id]) * ((float) $criterion->weight) / 100), 2);

        DB::transaction(function () use ($interview, $user, $data, $criteria, $weighted, $existing) {
            $evaluation = PanelEvaluation::updateOrCreate(
                ['interview_id' => $interview->interview_id, 'evaluator_id' => $user->id],
                ['weighted_score' => $weighted, 'recommendation' => $data['recommendation'], 'comments' => $data['comments'], 'status' => 'Submitted', 'submitted_at' => now()]
            );
            foreach ($criteria as $criterion) $evaluation->scores()->updateOrCreate(['criterion_id' => $criterion->id], ['score' => $data['scores'][$criterion->id]]);
            $assigned = $interview->assignments()->count();
            $completed = PanelEvaluation::where('interview_id', $interview->interview_id)->where('status', 'Submitted')->count();
            $interview->update(['status' => $completed === $assigned ? 'Evaluated' : 'In Progress']);
            if ($completed === $assigned) $interview->application()->update(['status' => 'Evaluated']);
        });
        return response()->json(['message' => "Evaluation submitted and locked. Your weighted score is {$weighted}/100."]);
    }

    public function reopen(Request $request, Interview $interview): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can reopen evaluations.');
        $data = $request->validate(['evaluator_id' => ['required', 'integer']]);
        $evaluation = PanelEvaluation::where('interview_id', $interview->interview_id)->where('evaluator_id', $data['evaluator_id'])->where('status', 'Submitted')->first();
        abort_unless($evaluation, 422, 'No submitted evaluation exists for this assigned panel member.');
        DB::transaction(function () use ($evaluation, $interview, $request) {
            $evaluation->update(['status' => 'Reopened', 'reopened_at' => now(), 'reopened_by' => $request->user()->id]);
            $interview->update(['status' => 'In Progress']);
            $interview->application()->update(['status' => 'Interview Scheduled']);
        });
        return response()->json(['message' => 'Evaluation reopened. The assigned panel member can correct and resubmit it.']);
    }
}
