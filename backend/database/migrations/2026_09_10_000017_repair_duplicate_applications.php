<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('applications', 'duplicate_of_application_id')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->unsignedBigInteger('duplicate_of_application_id')->nullable();
                $table->foreign('duplicate_of_application_id')
                    ->references('application_id')->on('applications');
            });
        }

        $statusPriority = [
            'Selected' => 100,
            'Evaluated' => 90,
            'Interview Scheduled' => 80,
            'Shortlisted' => 70,
            'Verified' => 60,
            'Submitted' => 50,
            'Not Selected' => 40,
            'Rejected' => 30,
            'Duplicate' => 0,
        ];

        $groups = DB::table('applications')
            ->select('candidate_id', 'vacancy_id')
            ->whereNull('duplicate_of_application_id')
            ->groupBy('candidate_id', 'vacancy_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $records = DB::table('applications')
                ->where('candidate_id', $group->candidate_id)
                ->where('vacancy_id', $group->vacancy_id)
                ->whereNull('duplicate_of_application_id')
                ->get();

            $canonical = $records->sortByDesc(function ($application) use ($statusPriority) {
                $hasSelection = Schema::hasTable('final_selections')
                    && DB::table('final_selections')->where('application_id', $application->application_id)->exists();
                $hasInterview = Schema::hasTable('interviews')
                    && DB::table('interviews')->where('application_id', $application->application_id)->exists();

                return ($hasSelection ? 10000 : 0)
                    + ($hasInterview ? 1000 : 0)
                    + ($statusPriority[$application->status] ?? 10)
                    - ((int) $application->application_id / 1000000);
            })->first();

            DB::table('applications')
                ->where('candidate_id', $group->candidate_id)
                ->where('vacancy_id', $group->vacancy_id)
                ->where('application_id', '!=', $canonical->application_id)
                ->whereNull('duplicate_of_application_id')
                ->update([
                    'status' => 'Duplicate',
                    'duplicate_of_application_id' => $canonical->application_id,
                    'updated_at' => now(),
                ]);
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlsrv') {
            DB::statement("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'applications_candidate_vacancy_active_unique' AND object_id = OBJECT_ID(N'applications')) CREATE UNIQUE INDEX applications_candidate_vacancy_active_unique ON applications (candidate_id, vacancy_id) WHERE duplicate_of_application_id IS NULL");
        } elseif ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS applications_candidate_vacancy_active_unique ON applications (candidate_id, vacancy_id) WHERE duplicate_of_application_id IS NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlsrv') {
            DB::statement("IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'applications_candidate_vacancy_active_unique' AND object_id = OBJECT_ID(N'applications')) DROP INDEX applications_candidate_vacancy_active_unique ON applications");
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS applications_candidate_vacancy_active_unique');
        }

        if (Schema::hasColumn('applications', 'duplicate_of_application_id')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropForeign(['duplicate_of_application_id']);
                $table->dropColumn('duplicate_of_application_id');
            });
        }
    }
};
