<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecruitmentSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('employee_pin'); $t->string('employee_epf'); $t->string('password'); $t->boolean('first_login')->default(false); $t->integer('department_id')->nullable(); $t->timestamps(); });
        Schema::create('Department', function (Blueprint $t) { $t->id('department_id'); $t->string('department_name'); });
        Schema::create('roles', function (Blueprint $t) { $t->id('role_id'); $t->string('role_name'); });
        Schema::create('user_roles', function (Blueprint $t) { $t->integer('user_id'); $t->integer('role_id'); });
        Schema::create('staff_access_tokens', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->string('token_hash'); $t->dateTime('expires_at'); $t->dateTime('last_used_at')->nullable(); $t->timestamps(); });
        foreach (['2026_09_01_000003_create_vacancies_table.php', '2026_09_01_000005_create_candidate_application_tables.php', '2026_09_01_000006_create_interview_tables.php', '2026_09_01_000007_create_final_selection_table.php', '2026_09_01_000008_create_candidate_notifications_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::table('vacancies', function (Blueprint $t) { $t->string('vacancy_grade')->default('A'); $t->string('audience')->default('External'); $t->dateTime('hr_approved_at')->nullable(); });
        DB::table('Department')->insert(['department_id' => 1, 'department_name' => 'IT']);
        DB::table('vacancies')->insert(['vacancy_id' => 1, 'department_id' => 1, 'title' => 'Engineer', 'description' => 'Engineering', 'vacancy_type' => 'Permanent', 'opening_date' => today(), 'closing_date' => today()->addDays(10), 'status' => 'Pending HOD Approval']);
        DB::table('candidates')->insert(['candidate_id' => 1, 'nic' => 'test', 'name' => 'Test Candidate', 'email' => 'candidate@example.com', 'phone' => '000', 'address' => 'Test']);
        DB::table('applications')->insert(['application_id' => 1, 'candidate_id' => 1, 'vacancy_id' => 1, 'submitted_at' => now(), 'status' => 'Submitted']);
    }

    private function token(string $role, ?int $department = null, bool $firstLogin = false): string
    {
        $id = DB::table('users')->insertGetId(['name' => $role, 'employee_pin' => uniqid(), 'employee_epf' => uniqid(), 'password' => bcrypt('secret123'), 'department_id' => $department, 'first_login' => $firstLogin]);
        $roleId = DB::table('roles')->insertGetId(['role_name' => $role], 'role_id');
        DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);
        $token = 'test-token-'.$id;
        DB::table('staff_access_tokens')->insert(['user_id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);
        return $token;
    }

    public function test_private_endpoints_reject_forged_role_without_token(): void
    {
        foreach (['interviews', 'rankings', 'final-selections', 'vacancies/all', 'dashboard/summary', 'notifications', 'applications/completed'] as $path) {
            $this->getJson('/api/'.$path, ['X-User-Role' => 'HR Manager'])->assertUnauthorized();
        }
        $this->postJson('/api/change-password', ['identifier' => 'someone', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertUnauthorized();
    }

    public function test_hod_cannot_forge_role_or_department(): void
    {
        $token = $this->token('Head of Department', 2);
        $headers = ['Authorization' => 'Bearer '.$token, 'X-User-Role' => 'HR Manager', 'X-User-Department-Id' => '1'];
        $this->getJson('/api/vacancies/all', $headers)->assertOk()->assertJsonCount(0, 'vacancies');
        $this->postJson('/api/vacancies/1/hod-approve', [], $headers)->assertForbidden();
        $this->postJson('/api/vacancies/1/cancel', [], $headers)->assertForbidden();
    }

    public function test_initial_password_and_expired_tokens_are_blocked(): void
    {
        $token = $this->token('HR Manager', null, true);
        $this->getJson('/api/interviews', ['Authorization' => 'Bearer '.$token])->assertForbidden();
        DB::table('staff_access_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/interviews', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_application_cannot_skip_verification(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $this->postJson('/api/applications/1/status', ['status' => 'Shortlisted'], $headers)->assertUnprocessable();
        $this->postJson('/api/applications/1/status', ['status' => 'Verified'], $headers)->assertOk();
        $this->postJson('/api/applications/1/status', ['status' => 'Shortlisted'], $headers)->assertOk();
    }

    public function test_evaluation_can_only_be_recorded_once(): void
    {
        DB::table('applications')->update(['status' => 'Interview Scheduled']);
        DB::table('interviews')->insert(['interview_id' => 1, 'application_id' => 1, 'interview_date' => today(), 'interview_time' => '09:00', 'location' => 'Office', 'status' => 'Scheduled']);
        $headers = ['Authorization' => 'Bearer '.$this->token('Interview Panel Member')];
        $data = ['score' => 85, 'comments' => 'Good', 'recommendation' => 'Recommended'];
        $this->postJson('/api/interviews/1/evaluate', $data, $headers)->assertOk();
        $this->postJson('/api/interviews/1/evaluate', $data, $headers)->assertUnprocessable();
        $this->assertSame(1, DB::table('interview_evaluations')->count());
    }

    public function test_finalization_does_not_duplicate_notifications(): void
    {
        DB::table('final_selections')->insert(['selection_id' => 1, 'application_id' => 1, 'status' => 'Approved']);
        $headers = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $this->postJson('/api/final-selections/1/finalize', [], $headers)->assertOk();
        $this->postJson('/api/final-selections/1/finalize', [], $headers)->assertConflict();
        $this->assertSame(1, DB::table('candidate_notifications')->count());
    }

    public function test_password_change_requires_own_session_and_logout_revokes_it(): void
    {
        $token = $this->token('HR Manager', null, true);
        $identifier = DB::table('users')->value('employee_pin');
        $headers = ['Authorization' => 'Bearer '.$token];
        $data = ['identifier' => 'another-user', 'password' => 'new-password123', 'password_confirmation' => 'new-password123'];
        $this->postJson('/api/change-password', $data, $headers)->assertForbidden();
        $data['identifier'] = $identifier;
        $this->postJson('/api/change-password', $data, $headers)->assertOk();
        $this->getJson('/api/interviews', $headers)->assertOk();
        $this->postJson('/api/logout', [], $headers)->assertOk();
        $this->getJson('/api/interviews', $headers)->assertUnauthorized();
    }

    public function test_grade_a_requires_md_approval_before_publication(): void
    {
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $hod = ['Authorization' => 'Bearer '.$this->token('Head of Department', 1)];
        $md = ['Authorization' => 'Bearer '.$this->token('Managing Director')];
        $this->postJson('/api/vacancies/1/publish', [], $hr)->assertUnprocessable();
        $this->postJson('/api/vacancies/1/hod-approve', [], $hod)->assertOk()->assertJsonPath('vacancy.status', 'Pending MD Approval');
        $this->postJson('/api/vacancies/1/publish', [], $hr)->assertUnprocessable();
        $this->postJson('/api/vacancies/1/md-approve', [], $md)->assertOk();
        $this->postJson('/api/vacancies/1/publish', [], $hr)->assertOk();
    }

    public function test_returned_vacancy_can_be_corrected_and_reapproved(): void
    {
        DB::table('vacancies')->update(['status' => 'Rejected']);
        $headers = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $data = ['title' => 'Revised engineer', 'description' => 'Revised', 'vacancy_type' => 'Permanent', 'vacancy_grade' => 'B', 'audience' => 'Both', 'department_id' => 1, 'opening_date' => today()->toDateString(), 'closing_date' => today()->addDays(20)->toDateString()];
        $this->patchJson('/api/vacancies/1', $data, $headers)->assertOk();
        $this->postJson('/api/vacancies/1/submit', [], $headers)->assertOk();
        $this->patchJson('/api/vacancies/1', $data, $headers)->assertUnprocessable();
        $hod = ['Authorization' => 'Bearer '.$this->token('Head of Department', 1)];
        $this->postJson('/api/vacancies/1/hod-approve', [], $hod)->assertOk()->assertJsonPath('vacancy.status', 'Approved');
        $this->postJson('/api/vacancies/1/hod-approve', [], $hod)->assertUnprocessable();
    }
}
