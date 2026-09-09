<?php

namespace App\Http\Middleware;

use App\Support\StaffAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StaffSession
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = StaffAccess::user($request);
        abort_unless(StaffAccess::hasAnyRole($user, $roles), 403, 'You are not allowed to perform this operation.');
        abort_if($user->first_login && !$request->is('api/change-password', 'api/logout'), 403, 'Change your initial password before continuing.');
        $requested = $request->header('X-User-Role');
        $allowed = $user->roles->pluck('role_name')->intersect($roles);
        $role = $allowed->contains($requested) ? $requested : $allowed->first();
        // Existing controllers consume these values only after server verification.
        $request->headers->set('X-User-Role', $role);
        $request->headers->set('X-User-Department-Id', (string) $user->department_id);
        $request->setUserResolver(fn () => $user);
        if ($request->isMethod('GET')) return $next($request);

        return DB::transaction(function () use ($request, $next) {
            $route = $request->route();
            $vacancy = $route->parameter('vacancy');
            $application = $route->parameter('application');
            $interview = $route->parameter('interview');
            $selection = $route->parameter('selection');
            $applicationId = $application instanceof Model ? $application->getKey() : $application;
            if ($interview) $applicationId = DB::table('interviews')->where('interview_id', $interview instanceof Model ? $interview->getKey() : $interview)->value('application_id');
            if ($selection) $applicationId = DB::table('final_selections')->where('selection_id', $selection)->value('application_id');
            $ids = $request->input('application_ids', [$request->input('application_id', $applicationId)]);
            $vacancyIds = DB::table('applications')->whereIn('application_id', array_filter((array) $ids))->pluck('vacancy_id')->all();
            if ($vacancy) $vacancyIds[] = $vacancy instanceof Model ? $vacancy->getKey() : $vacancy;
            // Serialize changes for the same vacancy, including concurrent approvals.
            DB::table('vacancies')->whereIn('vacancy_id', $vacancyIds)->orderBy('vacancy_id')->lockForUpdate()->get();
            foreach ([$vacancy, $application, $interview] as $model) {
                if ($model instanceof Model) $model->refresh();
            }
            return $next($request);
        });
    }
}
