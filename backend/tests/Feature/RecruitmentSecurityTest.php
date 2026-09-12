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
        Schema::table('users', function (Blueprint $t) { $t->string('email')->nullable(); $t->string('phone')->nullable(); });
        Schema::table('Department', fn (Blueprint $t) => $t->text('description')->nullable());
        (require database_path('migrations/2026_09_09_000014_add_management_status.php'))->up();
        foreach (['2026_09_01_000003_create_vacancies_table.php', '2026_09_01_000005_create_candidate_application_tables.php', '2026_09_01_000006_create_interview_tables.php', '2026_09_01_000007_create_final_selection_table.php', '2026_09_01_000008_create_candidate_notifications_table.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::table('vacancies', function (Blueprint $t) { $t->string('vacancy_grade')->default('A'); $t->string('audience')->default('External'); $t->dateTime('hr_approved_at')->nullable(); });
        (require database_path('migrations/2026_09_07_000010_create_internal_employee_access.php'))->up();
        (require database_path('migrations/2026_09_10_000015_create_vacancy_form_versions.php'))->up();
        (require database_path('migrations/2026_09_10_000016_create_structured_interview_panels.php'))->up();
        (require database_path('migrations/2026_09_10_000017_repair_duplicate_applications.php'))->up();
        DB::table('Department')->insert(['department_id' => 1, 'department_name' => 'IT']);
        DB::table('vacancies')->insert(['vacancy_id' => 1, 'department_id' => 1, 'title' => 'Engineer', 'description' => 'Engineering', 'vacancy_type' => 'Permanent', 'opening_date' => today(), 'closing_date' => today()->addDays(10), 'status' => 'Pending HOD Approval']);
        DB::table('candidates')->insert(['candidate_id' => 1, 'nic' => 'test', 'name' => 'Test Candidate', 'email' => 'candidate@example.com', 'phone' => '000', 'address' => 'Test']);
        DB::table('applications')->insert(['application_id' => 1, 'candidate_id' => 1, 'vacancy_id' => 1, 'submitted_at' => now(), 'status' => 'Submitted']);
    }

    public function test_management_enforces_roles_and_admin_account_protection(): void
    {
        $this->getJson('/api/management')->assertUnauthorized();
        $panel = $this->token('Interview Panel Member');
        $this->getJson('/api/management', ['Authorization' => 'Bearer '.$panel])->assertForbidden();
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $admin = $this->token('System Administrator');
        $adminId = DB::table('staff_access_tokens')->where('token_hash', hash('sha256', $admin))->value('user_id');
        $roleId = DB::table('roles')->where('role_name', 'System Administrator')->value('role_id');
        $data = ['name' => 'New admin', 'employee_pin' => '10001', 'employee_epf' => '20001', 'email' => null, 'phone' => null, 'department_id' => null, 'role_ids' => [$roleId], 'is_active' => true];
        $this->postJson('/api/management/users', $data, $hr)->assertForbidden();
        $this->putJson('/api/management/users/'.$adminId, $data, $hr)->assertForbidden();
        $this->postJson('/api/management/users/'.$adminId.'/reset-password', [], $hr)->assertForbidden();
        $this->getJson('/api/management', $hr)->assertOk()->assertJsonCount(2, 'roles');
    }

    public function test_employee_creation_hod_assignment_reset_and_deactivation(): void
    {
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $hodRole = DB::table('roles')->insertGetId(['role_name' => 'Head of Department'], 'role_id');
        $data = ['name' => 'IT Head', 'employee_pin' => '40400200', 'employee_epf' => '40400201', 'phone' => '0772345678', 'email' => 'head@example.com', 'department_id' => 1, 'role_ids' => [$hodRole], 'is_active' => true];
        $this->postJson('/api/management/users', $data, $hr)->assertCreated();
        $employee = DB::table('users')->where('employee_pin', '40400200')->first();
        $this->assertTrue((bool) $employee->first_login);
        $login = $this->postJson('/api/login', ['identifier' => '40400201', 'password' => '40400200'])->assertOk()->assertJsonPath('user.department_id', 1)->assertJsonPath('user.roles.0', 'Head of Department');
        $token = ['Authorization' => 'Bearer '.$login->json('token')];
        $this->getJson('/api/vacancies/all', $token)->assertForbidden();
        $this->postJson('/api/change-password', ['identifier' => '40400201', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'], $token)->assertOk();
        $this->getJson('/api/vacancies/all', $token)->assertOk()->assertJsonCount(1, 'vacancies');
        $this->postJson('/api/management/users/'.$employee->id.'/reset-password', [], $hr)->assertOk();
        $this->getJson('/api/vacancies/all', $token)->assertUnauthorized();
        $this->assertTrue((bool) DB::table('users')->where('id', $employee->id)->value('first_login'));
        $data['is_active'] = false;
        $this->putJson('/api/management/users/'.$employee->id, $data, $hr)->assertOk();
        $this->postJson('/api/login', ['identifier' => '40400201', 'password' => '40400200'])->assertUnauthorized();
        $data['is_active'] = true;
        $this->putJson('/api/management/users/'.$employee->id, $data, $hr)->assertOk();
        $this->postJson('/api/login', ['identifier' => '40400201', 'password' => '40400200'])->assertOk();
    }

    public function test_department_deactivation_and_identifier_validation(): void
    {
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $data = ['department_name' => 'IT', 'description' => 'IT team', 'is_active' => false];
        $this->putJson('/api/management/departments/1', $data, $hr)->assertUnprocessable();
        $this->postJson('/api/management/departments', ['department_name' => 'Finance', 'description' => '', 'is_active' => true], $hr)->assertCreated();
        $id = DB::table('Department')->where('department_name', 'Finance')->value('department_id');
        $this->putJson('/api/management/departments/'.$id, ['department_name' => 'Finance', 'description' => '', 'is_active' => false], $hr)->assertOk();
        $this->getJson('/api/departments', $hr)->assertOk()->assertJsonCount(1, 'departments');
        $roleId = DB::table('roles')->value('role_id');
        DB::table('users')->insert(['name' => 'Existing', 'employee_pin' => '888', 'employee_epf' => '999', 'password' => bcrypt('secret')]);
        $employee = ['name' => 'Duplicate', 'employee_pin' => '999', 'employee_epf' => '777', 'phone' => null, 'email' => null, 'department_id' => 1, 'role_ids' => [$roleId], 'is_active' => true];
        $this->postJson('/api/management/users', $employee, $hr)->assertUnprocessable();
        $employee['employee_pin'] = '123'; $employee['department_id'] = $id;
        $this->postJson('/api/management/users', $employee, $hr)->assertUnprocessable();
    }

    public function test_last_administrator_cannot_be_removed_even_with_hr_role(): void
    {
        $token = $this->token('System Administrator');
        $id = DB::table('users')->value('id');
        $hrRole = DB::table('roles')->insertGetId(['role_name' => 'HR Manager'], 'role_id');
        $data = ['name' => 'Admin', 'employee_pin' => '12345', 'employee_epf' => '54321', 'phone' => null, 'email' => null, 'department_id' => null, 'role_ids' => [$hrRole], 'is_active' => true];
        $this->putJson('/api/management/users/'.$id, $data, ['Authorization' => 'Bearer '.$token])->assertUnprocessable();
    }

    public function test_deactivate_button_revokes_access_and_preserves_employee(): void
    {
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $panelToken = $this->token('Interview Panel Member');
        $id = DB::table('staff_access_tokens')->where('token_hash', hash('sha256', $panelToken))->value('user_id');
        $before = DB::table('users')->where('id', $id)->first();
        $this->patchJson('/api/management/users/'.$id.'/active', ['is_active' => false])->assertUnauthorized();
        $this->patchJson('/api/management/users/'.$id.'/active', ['is_active' => false], ['Authorization' => 'Bearer '.$panelToken])->assertForbidden();
        $this->patchJson('/api/management/users/'.$id.'/active', ['is_active' => false], $hr)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $id, 'is_active' => false, 'password' => $before->password, 'name' => $before->name]);
        $this->getJson('/api/interviews', ['Authorization' => 'Bearer '.$panelToken])->assertUnauthorized();
        $this->patchJson('/api/management/users/'.$id.'/active', ['is_active' => true], $hr)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $id, 'is_active' => true]);
        $this->postJson('/api/login', ['identifier' => $before->employee_pin, 'password' => 'secret123'])->assertOk();
        $hrId = DB::table('users')->where('name', 'HR Manager')->value('id');
        $this->patchJson('/api/management/users/'.$hrId.'/active', ['is_active' => false], $hr)->assertUnprocessable();
        $this->token('System Administrator');
        $adminId = DB::table('users')->where('name', 'System Administrator')->value('id');
        $this->patchJson('/api/management/users/'.$adminId.'/active', ['is_active' => false], $hr)->assertForbidden();
    }

    public function test_directory_excludes_external_only_accounts_and_keeps_roleless_employees(): void
    {
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $externalToken = $this->token('External Candidate');
        $externalId = DB::table('staff_access_tokens')->where('token_hash', hash('sha256', $externalToken))->value('user_id');
        $id = DB::table('users')->insertGetId(['name' => 'Existing employee', 'employee_pin' => '70001', 'employee_epf' => '70002', 'password' => bcrypt('secret123')]);
        $result = $this->getJson('/api/management', $hr)->assertOk()->assertJsonCount(2, 'users');
        $this->assertContains($id, array_column($result->json('users'), 'id'));
        $this->assertNotContains($externalId, array_column($result->json('users'), 'id'));
        $this->assertDatabaseHas('users', ['id' => $externalId]);
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_role_assignment_updates_existing_employee_without_duplicate_or_password_reset(): void
    {
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $password = bcrypt('secret123');
        $id = DB::table('users')->insertGetId(['name' => 'Existing employee', 'employee_pin' => '70001', 'employee_epf' => '70002', 'password' => $password, 'first_login' => false]);
        $roleId = DB::table('roles')->insertGetId(['role_name' => 'Head of Department'], 'role_id');
        $data = ['name' => 'Existing employee', 'employee_pin' => '70001', 'employee_epf' => '70002', 'email' => null, 'phone' => null, 'department_id' => 1, 'role_ids' => [$roleId], 'is_active' => true];
        $this->putJson('/api/management/users/'.$id, $data, $hr)->assertOk();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', ['id' => $id, 'password' => $password, 'first_login' => false, 'department_id' => 1]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
    }

    public function test_form_builder_permissions_validation_and_immutable_versions(): void
    {
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $hod = ['Authorization' => 'Bearer '.$this->token('Head of Department', 1)];
        $data = ['base_version_id' => 0, 'title' => 'Engineer requirements', 'questions' => [['id' => 'qualification', 'label' => 'Qualification', 'type' => 'select', 'required' => true, 'options' => ['Degree', 'Diploma']]]];
        $this->getJson('/api/vacancies/1/form')->assertUnauthorized();
        $this->postJson('/api/vacancies/1/form', $data, $hod)->assertForbidden();
        $first = $this->postJson('/api/vacancies/1/form', $data, $hr)->assertCreated()->assertJsonPath('form.version', 1)->json('form.id');
        $this->postJson('/api/vacancies/1/form', $data, $hr)->assertConflict();
        $data['base_version_id'] = $first; $data['questions'][0]['label'] = 'Highest qualification';
        $this->postJson('/api/vacancies/1/form', $data, $hr)->assertCreated()->assertJsonPath('form.version', 2);
        $this->assertSame('Qualification', \App\Models\VacancyFormVersion::find($first)->questions[0]['label']);
        $this->getJson('/api/vacancies/1/form', $hr)->assertOk()->assertJsonCount(2, 'versions');
        DB::table('vacancies')->update(['status' => 'Closed']);
        $this->postJson('/api/vacancies/1/form', $data, $hr)->assertUnprocessable();
    }

    private function applicationPayload(int $formId): array
    {
        return ['nic' => 'NEW123', 'name' => 'Applicant', 'email' => 'applicant@example.com', 'phone' => '0771234567', 'address' => 'Colombo', 'cv' => \Illuminate\Http\UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'), 'form_version_id' => $formId];
    }

    public function test_candidate_can_apply_once_per_vacancy_and_to_other_vacancies(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->mock(\App\Services\CvProfileExtractor::class, fn ($mock) => $mock->shouldReceive('extractAndStore')->once()->andReturn(new \App\Models\CvProfile));
        DB::table('vacancies')->where('vacancy_id', 1)->update(['status' => 'Published']);
        DB::table('vacancies')->insert([
            'vacancy_id' => 2, 'department_id' => 1, 'title' => 'Network Administrator',
            'description' => 'Manage the network.', 'vacancy_type' => 'Permanent',
            'opening_date' => today(), 'closing_date' => today()->addDays(10), 'status' => 'Published',
        ]);
        $payload = fn () => [
            'nic' => 'test', 'name' => 'Test Candidate', 'email' => 'candidate@example.com',
            'phone' => '000', 'address' => 'Test',
            'cv' => \Illuminate\Http\UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
        ];

        $this->post('/api/vacancies/1/applications', $payload(), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'An application already exists for this candidate and vacancy.');
        $this->post('/api/vacancies/2/applications', $payload(), ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertDatabaseCount('candidates', 1);
        $this->assertDatabaseCount('applications', 2);
        $this->assertDatabaseHas('applications', ['candidate_id' => 1, 'vacancy_id' => 1]);
        $this->assertDatabaseHas('applications', ['candidate_id' => 1, 'vacancy_id' => 2]);
    }

    public function test_custom_answers_uploads_and_version_are_saved_and_reviewable(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->mock(\App\Services\CvProfileExtractor::class, fn ($mock) => $mock->shouldReceive('extractAndStore')->once()->andReturn(new \App\Models\CvProfile));
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        DB::table('vacancies')->update(['status' => 'Published']);
        $form = $this->postJson('/api/vacancies/1/form', ['base_version_id' => 0, 'title' => 'Extra details', 'questions' => [
            ['id' => 'experience', 'label' => 'Years experience', 'type' => 'number', 'required' => true],
            ['id' => 'certificate', 'label' => 'Degree certificate', 'type' => 'file', 'required' => true],
        ]], $hr)->assertCreated()->json('form');
        $this->getJson('/api/vacancies/1')->assertOk()->assertJsonPath('vacancy.application_form.id', $form['id']);
        $payload = $this->applicationPayload($form['id']);
        $this->post('/api/vacancies/1/applications', $payload, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['answers.experience', 'answer_files.certificate']);
        $payload['answers'] = ['experience' => '3'];
        $payload['answer_files'] = ['certificate' => \Illuminate\Http\UploadedFile::fake()->create('degree.pdf', 10, 'application/pdf')];
        $id = $this->post('/api/vacancies/1/applications', $payload, ['Accept' => 'application/json'])->assertCreated()->json('application_id');
        $submission = \App\Models\ApplicationFormSubmission::where('application_id', $id)->firstOrFail();
        $this->assertSame('3', $submission->answers['experience']);
        $this->assertSame('degree.pdf', $submission->answers['certificate']['file_name']);
        $this->assertDatabaseCount('documents', 2);
        $this->assertCount(2, \Illuminate\Support\Facades\Storage::disk('local')->allFiles('candidate-documents'));
        $this->postJson('/api/vacancies/1/form', ['base_version_id' => $form['id'], 'title' => 'New questions', 'questions' => []], $hr)->assertCreated();
        $this->assertEquals($form['id'], $submission->fresh()->form_version_id);
        $result = $this->getJson('/api/applications', $hr)->assertOk()->json('applications');
        $saved = collect($result)->firstWhere('application_id', $id);
        $this->assertSame('Years experience', $saved['form_submission']['form_version']['questions'][0]['label']);
        $this->assertSame('3', $saved['form_submission']['answers']['experience']);
    }

    public function test_stale_form_wrong_answers_and_unsafe_files_are_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        DB::table('vacancies')->update(['status' => 'Published']);
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $id = $this->postJson('/api/vacancies/1/form', ['base_version_id' => 0, 'title' => 'Eligibility', 'questions' => [
            ['id' => 'eligible', 'label' => 'Eligible?', 'type' => 'yesno', 'required' => true],
            ['id' => 'certificate', 'label' => 'Certificate', 'type' => 'file', 'required' => false],
        ]], $hr)->assertCreated()->json('form.id');
        $this->post('/api/vacancies/1/applications', $this->applicationPayload(0), ['Accept' => 'application/json'])->assertConflict();
        $payload = $this->applicationPayload($id); $payload['answers'] = ['eligible' => 'Maybe'];
        $this->post('/api/vacancies/1/applications', $payload, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('answers.eligible');
        $payload['answers'] = ['eligible' => 'No'];
        $payload['answer_files'] = ['certificate' => \Illuminate\Http\UploadedFile::fake()->create('script.php', 10, 'text/x-php')];
        $this->post('/api/vacancies/1/applications', $payload, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('answer_files.certificate');
        $this->assertDatabaseCount('application_form_submissions', 0);
        $this->assertDatabaseCount('applications', 1);
        $this->assertCount(0, \Illuminate\Support\Facades\Storage::disk('local')->allFiles());
    }

    public function test_internal_employee_uses_same_form_and_anonymous_cannot_view_it(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->mock(\App\Services\CvProfileExtractor::class, fn ($mock) => $mock->shouldReceive('extractAndStore')->once()->andReturn(new \App\Models\CvProfile));
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        DB::table('vacancies')->update(['status' => 'Published', 'audience' => 'Internal']);
        $id = $this->postJson('/api/vacancies/1/form', ['base_version_id' => 0, 'title' => 'Internal experience', 'questions' => [['id' => 'since', 'label' => 'Employment start date', 'type' => 'date', 'required' => true]]], $hr)->assertCreated()->json('form.id');
        $staffToken = $this->token('Internal Employee', 1);
        $userId = DB::table('staff_access_tokens')->where('token_hash', hash('sha256', $staffToken))->value('user_id');
        DB::table('users')->where('id', $userId)->update(['phone' => '0771234567']);
        DB::table('internal_access_tokens')->insert(['user_id' => $userId, 'token_hash' => hash('sha256', 'internal-session'), 'expires_at' => now()->addHour()]);
        $headers = ['Authorization' => 'Bearer internal-session', 'Accept' => 'application/json'];
        $this->getJson('/api/vacancies/1')->assertNotFound();
        $this->getJson('/api/internal/vacancies')->assertUnauthorized();
        $this->getJson('/api/internal/vacancies', $headers)->assertOk()->assertJsonPath('vacancies.0.application_form.id', $id);
        $payload = $this->applicationPayload($id);
        $this->post('/api/internal/vacancies/1/apply', $payload, $headers)->assertUnprocessable();
        $payload['answers'] = ['since' => '2020-01-15'];
        $this->post('/api/internal/vacancies/1/apply', $payload, $headers)->assertCreated();
        $this->assertDatabaseCount('application_form_submissions', 1);
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
        $token = $this->token('Interview Panel Member');
        $memberId = DB::table('staff_access_tokens')->where('token_hash', hash('sha256', $token))->value('user_id');
        DB::table('interview_panel_assignments')->insert(['interview_id' => 1, 'user_id' => $memberId, 'assigned_by' => $memberId]);
        $criterion = DB::table('interview_scorecard_criteria')->insertGetId(['interview_id' => 1, 'name' => 'Technical', 'weight' => 100, 'sort_order' => 1]);
        $headers = ['Authorization' => 'Bearer '.$token];
        $data = ['scores' => [$criterion => 85], 'comments' => 'Good', 'recommendation' => 'Recommended'];
        $this->postJson('/api/interviews/1/evaluate', $data, $headers)->assertOk();
        $this->postJson('/api/interviews/1/evaluate', $data, $headers)->assertUnprocessable();
        $this->assertDatabaseHas('panel_evaluations', ['interview_id' => 1, 'evaluator_id' => $memberId, 'weighted_score' => 85, 'status' => 'Submitted']);
        $this->assertDatabaseHas('panel_evaluation_scores', ['criterion_id' => $criterion, 'score' => 85]);
        $this->assertDatabaseHas('interviews', ['interview_id' => 1, 'status' => 'Evaluated']);
    }

    public function test_structured_panel_assignment_combined_scores_visibility_and_reopen(): void
    {
        DB::table('applications')->update(['status' => 'Shortlisted']);
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $firstToken = $this->token('Interview Panel Member');
        $secondToken = $this->token('Interview Panel Member');
        $unassignedToken = $this->token('Interview Panel Member');
        $memberId = fn ($token) => DB::table('staff_access_tokens')->where('token_hash', hash('sha256', $token))->value('user_id');
        $schedule = [
            'application_ids' => [1], 'panel_member_ids' => [$memberId($firstToken), $memberId($secondToken)],
            'interview_date' => today()->toDateString(), 'interview_time' => '09:30', 'location' => 'Conference room',
            'criteria' => [
                ['name' => 'Technical knowledge', 'weight' => 60],
                ['name' => 'Communication', 'weight' => 40],
            ],
        ];
        $this->postJson('/api/interviews', $schedule, $hr)->assertCreated();
        $criteria = DB::table('interview_scorecard_criteria')->orderBy('sort_order')->pluck('id');
        $this->getJson('/api/interviews', ['Authorization' => 'Bearer '.$unassignedToken])->assertOk()->assertJsonCount(0, 'interviews');
        $this->postJson('/api/interviews/1/evaluate', ['scores' => [$criteria[0] => 80, $criteria[1] => 90], 'recommendation' => 'Recommended', 'comments' => 'Strong candidate.'], ['Authorization' => 'Bearer '.$unassignedToken])->assertForbidden();
        $first = ['scores' => [$criteria[0] => 80, $criteria[1] => 90], 'recommendation' => 'Recommended', 'comments' => 'Strong technical result.'];
        $second = ['scores' => [$criteria[0] => 90, $criteria[1] => 70], 'recommendation' => 'Keep in reserve', 'comments' => 'Good overall result.'];
        $this->postJson('/api/interviews/1/evaluate', $first, ['Authorization' => 'Bearer '.$firstToken])->assertOk();
        $this->assertDatabaseHas('interviews', ['interview_id' => 1, 'status' => 'In Progress']);
        $this->postJson('/api/interviews/1/evaluate', $second, ['Authorization' => 'Bearer '.$secondToken])->assertOk();
        $this->assertDatabaseHas('interviews', ['interview_id' => 1, 'status' => 'Evaluated']);
        $this->assertDatabaseHas('applications', ['application_id' => 1, 'status' => 'Evaluated']);
        $this->getJson('/api/interviews', $hr)->assertOk()->assertJsonPath('interviews.0.combined_score', 83)->assertJsonCount(2, 'interviews.0.panel_evaluations');
        $hod = ['Authorization' => 'Bearer '.$this->token('Head of Department', 1)];
        $this->getJson('/api/interviews', $hod)->assertOk()->assertJsonPath('interviews.0.combined_score', 83)->assertJsonCount(2, 'interviews.0.panel_evaluations');
        $otherHod = ['Authorization' => 'Bearer '.$this->token('Head of Department', 2)];
        $this->getJson('/api/interviews', $otherHod)->assertOk()->assertJsonCount(0, 'interviews');
        $md = ['Authorization' => 'Bearer '.$this->token('Managing Director')];
        $this->getJson('/api/interviews', $md)->assertOk()->assertJsonPath('interviews.0.combined_score', 83)->assertJsonCount(2, 'interviews.0.panel_evaluations');
        $this->getJson('/api/rankings', $hr)->assertOk()->assertJsonPath('rankings.0.score', 83);
        $this->postJson('/api/interviews/1/reopen', ['evaluator_id' => $memberId($firstToken)], $md)->assertForbidden();
        $this->postJson('/api/interviews/1/reopen', ['evaluator_id' => $memberId($firstToken)], $hr)->assertOk();
        $this->assertDatabaseHas('panel_evaluations', ['evaluator_id' => $memberId($firstToken), 'status' => 'Reopened']);
        $this->assertDatabaseHas('applications', ['application_id' => 1, 'status' => 'Interview Scheduled']);
        $first['scores'] = [$criteria[0] => 100, $criteria[1] => 100];
        $this->postJson('/api/interviews/1/evaluate', $first, ['Authorization' => 'Bearer '.$firstToken])->assertOk();
        $this->getJson('/api/interviews', $hr)->assertOk()->assertJsonPath('interviews.0.combined_score', 91);
        $this->assertNotNull(DB::table('panel_evaluations')->where('evaluator_id', $memberId($firstToken))->value('reopened_at'));
    }

    public function test_interview_schedule_requires_active_panel_and_exact_weights(): void
    {
        DB::table('applications')->update(['status' => 'Shortlisted']);
        $hr = ['Authorization' => 'Bearer '.$this->token('HR Manager')];
        $panelToken = $this->token('Interview Panel Member');
        $panelId = DB::table('staff_access_tokens')->where('token_hash', hash('sha256', $panelToken))->value('user_id');
        $data = ['application_ids' => [1], 'panel_member_ids' => [$panelId], 'interview_date' => today()->toDateString(), 'interview_time' => '10:00', 'location' => 'Room 1', 'criteria' => [['name' => 'Technical', 'weight' => 90]]];
        $this->postJson('/api/interviews', $data, $hr)->assertUnprocessable();
        DB::table('users')->where('id', $panelId)->update(['is_active' => false]);
        $data['criteria'][0]['weight'] = 100;
        $this->postJson('/api/interviews', $data, $hr)->assertUnprocessable();
        $this->assertDatabaseCount('interviews', 0);
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
