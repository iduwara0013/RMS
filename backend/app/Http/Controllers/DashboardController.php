<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $role = $request->header('X-User-Role', '');
        $departmentId = (int) $request->header('X-User-Department-Id');

        $applicationQuery = DB::table('applications as a')
            ->join('vacancies as v', 'v.vacancy_id', '=', 'a.vacancy_id');
        $vacancyQuery = DB::table('vacancies as v');
        $interviewQuery = DB::table('interviews as i')
            ->join('applications as a', 'a.application_id', '=', 'i.application_id')
            ->join('vacancies as v', 'v.vacancy_id', '=', 'a.vacancy_id');

        if ($role === 'Head of Department') {
            if ($departmentId > 0) {
                $applicationQuery->where('v.department_id', $departmentId);
                $vacancyQuery->where('v.department_id', $departmentId);
                $interviewQuery->where('v.department_id', $departmentId);
            } else {
                $applicationQuery->whereRaw('1 = 0');
                $vacancyQuery->whereRaw('1 = 0');
                $interviewQuery->whereRaw('1 = 0');
            }
        }

        $applicationCount = fn (array $statuses): int => (int) (clone $applicationQuery)
            ->whereIn('a.status', $statuses)
            ->count();
        $vacancyCount = fn (array $statuses): int => (int) (clone $vacancyQuery)
            ->whereIn('v.status', $statuses)
            ->count();
        $interviewCount = fn (array $statuses): int => (int) (clone $interviewQuery)
            ->whereIn('i.status', $statuses)
            ->count();

        $applications = (int) (clone $applicationQuery)->count();
        $activeApplications = $applicationCount(['Submitted', 'Verified', 'Shortlisted', 'Interview Scheduled', 'Evaluated', 'Final Selection Pending']);
        $publishedVacancies = (int) (clone $vacancyQuery)
            ->where('v.status', 'Published')
            ->whereDate('v.closing_date', '>=', today())
            ->count();
        $closingSoon = (int) (clone $vacancyQuery)
            ->where('v.status', 'Published')
            ->whereBetween('v.closing_date', [today(), today()->copy()->addDays(7)])
            ->count();
        $upcomingInterviews = (int) (clone $interviewQuery)
            ->where('i.status', 'Scheduled')
            ->whereDate('i.interview_date', '>=', today())
            ->count();
        $selectedCandidates = $applicationCount(['Selected']);
        $notifications = (int) DB::table('candidate_notifications')->count();

        $hodApprovals = $vacancyCount(['Pending HOD Approval']);
        $mdVacancyApprovals = $vacancyCount(['Pending MD Approval']);
        $mdCandidateApprovals = (int) DB::table('final_selections')->where('status', 'Pending MD Approval')->count();
        $hrVacancyActions = $vacancyCount(['Draft', 'Rejected', 'Approved']);
        $hrApplicationActions = $applicationCount(['Submitted', 'Verified', 'Evaluated']);
        $panelEvaluations = $interviewCount(['Scheduled']);
        $dataEntryChecks = $applicationCount(['Submitted']);

        $pending = match ($role) {
            'System Administrator' => [
                ['icon' => '+', 'label' => 'Vacancy workflows in progress', 'description' => 'Monitor vacancies that have not reached a final state.', 'count' => $vacancyCount(['Draft', 'Pending HOD Approval', 'Pending MD Approval', 'Approved', 'Published', 'Rejected']), 'href' => '/dashboard/vacancies'],
                ['icon' => '▣', 'label' => 'Candidate notifications', 'description' => 'Review all communications recorded by the system.', 'count' => $notifications, 'href' => '/dashboard/notifications'],
            ],
            'HR Manager' => [
                ['icon' => '◌', 'label' => 'Applications to verify', 'description' => 'Review newly submitted candidate records.', 'count' => $applicationCount(['Submitted']), 'href' => '/dashboard/applications'],
                ['icon' => '★', 'label' => 'Candidates ready to shortlist', 'description' => 'Move verified candidates to the interview stage.', 'count' => $applicationCount(['Verified']), 'href' => '/dashboard/applications'],
                ['icon' => '+', 'label' => 'Vacancies to action', 'description' => 'Submit, revise, or publish vacancy records.', 'count' => $hrVacancyActions, 'href' => '/dashboard/vacancies'],
                ['icon' => '◆', 'label' => 'Evaluated candidates', 'description' => 'Prepare final selections for MD approval.', 'count' => $applicationCount(['Evaluated']), 'href' => '/dashboard/selections'],
            ],
            'Head of Department' => [
                ['icon' => '✓', 'label' => 'Vacancies awaiting your approval', 'description' => 'Review vacancy requests for your department.', 'count' => $hodApprovals, 'href' => '/dashboard/vacancies'],
                ['icon' => '◌', 'label' => 'Department applications in progress', 'description' => 'Review candidates applying to your department.', 'count' => $activeApplications, 'href' => '/dashboard/applications'],
            ],
            'Managing Director' => [
                ['icon' => '+', 'label' => 'Grade A vacancies awaiting approval', 'description' => 'Complete the final vacancy approval step.', 'count' => $mdVacancyApprovals, 'href' => '/dashboard/vacancies'],
                ['icon' => '◆', 'label' => 'Candidate selections awaiting approval', 'description' => 'Review final recommendations and interview scores.', 'count' => $mdCandidateApprovals, 'href' => '/dashboard/selections'],
            ],
            'Data Entry Operator' => [
                ['icon' => '✎', 'label' => 'New application records to check', 'description' => 'Open submitted candidate details and confirm completeness.', 'count' => $dataEntryChecks, 'href' => '/dashboard/applications'],
            ],
            'Interview Panel Member' => [
                ['icon' => '▤', 'label' => 'Interviews awaiting evaluation', 'description' => 'Record a separate score and recommendation for each candidate.', 'count' => $panelEvaluations, 'href' => '/dashboard/interviews'],
            ],
            default => [],
        };

        $openTasks = match ($role) {
            'System Administrator' => $vacancyCount(['Draft', 'Pending HOD Approval', 'Pending MD Approval', 'Approved', 'Published', 'Rejected']) + $notifications,
            'HR Manager' => $hrVacancyActions + $hrApplicationActions,
            'Head of Department' => $hodApprovals,
            'Managing Director' => $mdVacancyApprovals + $mdCandidateApprovals,
            'Data Entry Operator' => $dataEntryChecks,
            'Interview Panel Member' => $panelEvaluations,
            default => 0,
        };

        $metricCards = match ($role) {
            'System Administrator' => [
                $this->metric('staff', 'Staff accounts', (int) DB::table('users')->count(), 'Registered system users', 'red'),
                $this->metric('vacancies', 'Active vacancies', $publishedVacancies, 'Published and accepting applications', 'navy'),
                $this->metric('applications', 'Active applications', $activeApplications, 'Currently moving through recruitment', 'yellow'),
                $this->metric('notifications', 'Notifications', $notifications, 'Candidate messages recorded', 'green'),
            ],
            'Head of Department' => [
                $this->metric('approvals', 'My pending approvals', $hodApprovals, 'Vacancies from your department', 'red'),
                $this->metric('applications', 'Department applications', $activeApplications, 'Candidates currently in progress', 'navy'),
                $this->metric('vacancies', 'Open department vacancies', $publishedVacancies, 'Published and accepting applications', 'yellow'),
                $this->metric('selected', 'Selected candidates', $selectedCandidates, 'Completed selections for your department', 'green'),
            ],
            'Managing Director' => [
                $this->metric('approvals', 'Final approvals', $mdVacancyApprovals + $mdCandidateApprovals, 'Vacancies and selections awaiting you', 'red'),
                $this->metric('grade-a', 'Grade A vacancies', $mdVacancyApprovals, 'Awaiting executive approval', 'navy'),
                $this->metric('selections', 'Candidate selections', $mdCandidateApprovals, 'Awaiting final confirmation', 'yellow'),
                $this->metric('selected', 'Selected candidates', $selectedCandidates, 'Approved recruitment outcomes', 'green'),
            ],
            'Data Entry Operator' => [
                $this->metric('new', 'New applications', $dataEntryChecks, 'Submitted records to check', 'red'),
                $this->metric('active', 'Active applications', $activeApplications, 'All candidates in progress', 'navy'),
                $this->metric('internal', 'Internal applicants', (int) (clone $applicationQuery)->where('a.applicant_type', 'Internal')->count(), 'Employee applications received', 'yellow'),
                $this->metric('external', 'External applicants', (int) (clone $applicationQuery)->where('a.applicant_type', 'External')->count(), 'Public applications received', 'green'),
            ],
            'Interview Panel Member' => [
                $this->metric('pending', 'Awaiting evaluation', $panelEvaluations, 'Interviews requiring your score', 'red'),
                $this->metric('upcoming', 'Upcoming interviews', $upcomingInterviews, 'Scheduled from today', 'navy'),
                $this->metric('evaluated', 'Evaluations completed', $interviewCount(['Evaluated']), 'Interview records completed', 'yellow'),
                $this->metric('selected', 'Selected candidates', $selectedCandidates, 'Candidates approved after interview', 'green'),
            ],
            default => [
                $this->metric('tasks', 'My pending tasks', $openTasks, 'Needs your action', 'red'),
                $this->metric('applications', 'Active applications', $activeApplications, 'Still in progress', 'navy'),
                $this->metric('interviews', 'Upcoming interviews', $upcomingInterviews, 'Scheduled from today', 'yellow'),
                $this->metric('closing', 'Closing this week', $closingSoon, 'Published vacancies', 'green'),
            ],
        };

        $funnel = [
            ['key' => 'submitted', 'label' => 'Applied', 'count' => $applicationCount(['Submitted'])],
            ['key' => 'verified', 'label' => 'Verified', 'count' => $applicationCount(['Verified'])],
            ['key' => 'shortlisted', 'label' => 'Shortlisted', 'count' => $applicationCount(['Shortlisted'])],
            ['key' => 'evaluated', 'label' => 'Interviewed', 'count' => $applicationCount(['Interview Scheduled', 'Evaluated'])],
            ['key' => 'selected', 'label' => 'Selected', 'count' => $selectedCandidates],
        ];

        return response()->json([
            'open_tasks' => $openTasks,
            'applications' => $applications,
            'active_applications' => $activeApplications,
            'published_vacancies' => $publishedVacancies,
            'closing_soon' => $closingSoon,
            'scheduled_interviews' => $upcomingInterviews,
            'system_status' => 'Online',
            'metric_cards' => $metricCards,
            'funnel' => $funnel,
            'pending_tasks' => $pending,
        ]);
    }

    /** @return array{key: string, label: string, value: int, help: string, tone: string} */
    private function metric(string $key, string $label, int $value, string $help, string $tone): array
    {
        return compact('key', 'label', 'value', 'help', 'tone');
    }
}
