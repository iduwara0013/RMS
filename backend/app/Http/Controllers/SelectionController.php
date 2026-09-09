<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SelectionController extends Controller
{
    public function rankings(Request $request): JsonResponse
    {
        $rows = DB::table('applications as a')
            ->join('candidates as c', 'c.candidate_id', '=', 'a.candidate_id')
            ->join('vacancies as v', 'v.vacancy_id', '=', 'a.vacancy_id')
            ->leftJoin('Department as d', 'd.department_id', '=', 'v.department_id')
            ->join('interviews as i', 'i.application_id', '=', 'a.application_id')
            ->join('interview_evaluations as e', 'e.interview_id', '=', 'i.interview_id')
            ->where('a.status', 'Evaluated')
            ->when($request->filled('vacancy_id'), fn ($q) => $q->where('a.vacancy_id', $request->integer('vacancy_id')))
            ->select('a.application_id', 'a.vacancy_id', 'c.name as candidate_name', 'c.email', 'v.title as vacancy_title', 'v.department_id', 'd.department_name', 'e.score', 'e.recommendation', 'e.comments')
            ->orderByDesc('e.score')->get();

        $ranked = $rows->groupBy('vacancy_id')->flatMap(function ($group) {
            return $group->values()->map(fn ($row, $index) => [
                'application_id' => $row->application_id, 'vacancy_id' => $row->vacancy_id,
                'candidate_name' => $row->candidate_name, 'email' => $row->email,
                'vacancy_title' => $row->vacancy_title, 'department_id' => $row->department_id,
                'department_name' => $row->department_name, 'score' => $row->score,
                'recommendation' => $row->recommendation, 'comments' => $row->comments, 'rank' => $index + 1,
            ]);
        })->values();
        return response()->json(['rankings' => $ranked]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['selections' => DB::table('final_selections as f')
            ->join('applications as a', 'a.application_id', '=', 'f.application_id')
            ->join('candidates as c', 'c.candidate_id', '=', 'a.candidate_id')
            ->join('vacancies as v', 'v.vacancy_id', '=', 'a.vacancy_id')
            ->leftJoin('Department as d', 'd.department_id', '=', 'v.department_id')
            ->leftJoin('interviews as i', 'i.application_id', '=', 'a.application_id')
            ->leftJoin('interview_evaluations as e', 'e.interview_id', '=', 'i.interview_id')
            ->select(
                'f.*',
                'a.status as application_status',
                'c.name as candidate_name',
                'c.email',
                'v.title as vacancy_title',
                'v.department_id',
                'd.department_name',
                'e.score as interview_score',
                'e.recommendation as interview_recommendation',
                'e.comments as interview_comments'
            )
            ->latest('f.created_at')
            ->get()]);
    }

    public function nominate(Request $request): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can submit a final selection.');
        $data = $request->validate(['application_id' => ['required', 'integer'], 'comments' => ['nullable', 'string', 'max:2000']]);
        $application = Application::findOrFail($data['application_id']);
        abort_unless($application->status === 'Evaluated', 422, 'Only evaluated candidates can be selected.');
        abort_if(DB::table('final_selections')->where('application_id', $application->application_id)->whereIn('status', ['Pending MD Approval', 'Approved'])->exists(), 422, 'This candidate already has a final selection.');
        $id = DB::table('final_selections')->insertGetId(['application_id' => $application->application_id, 'selected_by' => null, 'status' => 'Pending MD Approval', 'comments' => $data['comments'] ?? null, 'selected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $application->update(['status' => 'Final Selection Pending']);
        return response()->json(['message' => 'Final selection submitted for Managing Director approval.', 'selection_id' => $id], 201);
    }

    public function approve(Request $request, int $selection): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'Managing Director', 403, 'Only Managing Director can approve final selection.');
        $record = DB::table('final_selections')->where('selection_id', $selection)->first();
        abort_unless($record && $record->status === 'Pending MD Approval', 422, 'This selection is not awaiting approval.');
        DB::transaction(function () use ($record, $selection) {
            DB::table('final_selections')->where('selection_id', $selection)->update(['status' => 'Approved', 'approved_at' => now(), 'updated_at' => now()]);
            Application::where('application_id', $record->application_id)->update(['status' => 'Selected']);
        });
        return response()->json(['message' => 'Final selection approved successfully.']);
    }

    public function reject(Request $request, int $selection): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'Managing Director', 403, 'Only Managing Director can reject final selection.');
        $record = DB::table('final_selections')->where('selection_id', $selection)->first();
        abort_unless($record && $record->status === 'Pending MD Approval', 422, 'This selection is not awaiting approval.');
        DB::table('final_selections')->where('selection_id', $selection)->update(['status' => 'Rejected', 'approved_at' => now(), 'updated_at' => now()]);
        Application::where('application_id', $record->application_id)->update(['status' => 'Evaluated']);
        return response()->json(['message' => 'Final selection rejected and returned for review.']);
    }

    public function recommendation(Request $request, int $selection): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can generate appointment recommendations.');
        $record = DB::table('final_selections as f')->join('applications as a', 'a.application_id', '=', 'f.application_id')->join('candidates as c', 'c.candidate_id', '=', 'a.candidate_id')->join('vacancies as v', 'v.vacancy_id', '=', 'a.vacancy_id')->where('f.selection_id', $selection)->select('f.selection_id', 'f.status', 'f.approved_at', 'c.name as candidate_name', 'c.email', 'v.title as vacancy_title')->first();
        abort_unless($record && $record->status === 'Approved', 422, 'Only approved selections can generate an appointment recommendation.');
        return response()->json(['recommendation' => $record]);
    }

    public function finalize(Request $request, int $selection): JsonResponse
    {
        abort_unless($request->header('X-User-Role') === 'HR Manager', 403, 'Only HR Manager can close a vacancy.');
        $record = DB::table('final_selections as f')->join('applications as a', 'a.application_id', '=', 'f.application_id')->join('candidates as c', 'c.candidate_id', '=', 'a.candidate_id')->join('vacancies as v', 'v.vacancy_id', '=', 'a.vacancy_id')->where('f.selection_id', $selection)->select('f.status as selection_status', 'a.application_id', 'a.vacancy_id', 'c.name as selected_name', 'c.email as selected_email', 'v.title as vacancy_title')->first();
        abort_unless($record && $record->selection_status === 'Approved', 422, 'Only an approved selection can close a vacancy.');
        $result = DB::transaction(function () use ($record) {
            $others = DB::table('applications as a')->join('candidates as c', 'c.candidate_id', '=', 'a.candidate_id')->where('a.vacancy_id', $record->vacancy_id)->where('a.application_id', '!=', $record->application_id)->select('a.application_id', 'a.status', 'c.email')->get();
            DB::table('applications')->where('application_id', $record->application_id)->update(['status' => 'Selected']);
            DB::table('applications')->where('vacancy_id', $record->vacancy_id)->where('application_id', '!=', $record->application_id)->update(['status' => 'Not Selected']);
            DB::table('vacancies')->where('vacancy_id', $record->vacancy_id)->update(['status' => 'Closed', 'updated_at' => now()]);
            $now = now(); $notifications = [['application_id' => $record->application_id, 'notification_type' => 'Appointment', 'recipient_email' => $record->selected_email, 'subject' => 'Appointment recommendation - '.$record->vacancy_title, 'message' => 'Congratulations '.$record->selected_name.'. You have been selected for '.$record->vacancy_title.'.', 'sent_at' => $now, 'created_at' => $now, 'updated_at' => $now]];
            foreach ($others as $other) { $notifications[] = ['application_id' => $other->application_id, 'notification_type' => 'Not Selected', 'recipient_email' => $other->email, 'subject' => 'Application update - '.$record->vacancy_title, 'message' => 'Thank you for applying for '.$record->vacancy_title.'. We regret to inform you that you were not selected.', 'sent_at' => $now, 'created_at' => $now, 'updated_at' => $now]; }
            if ($notifications) DB::table('candidate_notifications')->insert($notifications);
            return count($others) + 1;
        });
        return response()->json(['message' => "Vacancy closed and {$result} candidate notification(s) recorded."]); 
    }

    public function notifications(Request $request): JsonResponse
    {
        abort_unless(in_array($request->header('X-User-Role'), ['HR Manager', 'System Administrator'], true), 403, 'You are not allowed to view notifications.');
        $rows = DB::table('candidate_notifications as n')->join('applications as a', 'a.application_id', '=', 'n.application_id')->join('candidates as c', 'c.candidate_id', '=', 'a.candidate_id')->join('vacancies as v', 'v.vacancy_id', '=', 'a.vacancy_id')->select('n.notification_id', 'n.notification_type', 'n.recipient_email', 'n.subject', 'n.message', 'n.sent_at', 'c.name as candidate_name', 'v.title as vacancy_title')->latest('n.sent_at')->get();
        return response()->json(['notifications' => $rows]);
    }
}
