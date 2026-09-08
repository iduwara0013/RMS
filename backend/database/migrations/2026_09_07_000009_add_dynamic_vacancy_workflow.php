<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('Department')) {
            Schema::create('Department', function (Blueprint $table) {
                $table->id('department_id');
                $table->string('department_name', 150)->unique();
                $table->text('description')->nullable();
            });
        }

        if (DB::table('Department')->count() === 0) {
            DB::table('Department')->insert([
                ['department_name' => 'Human Resources', 'description' => 'Human resources and administration'],
                ['department_name' => 'Operations', 'description' => 'Terminal and distribution operations'],
                ['department_name' => 'Engineering', 'description' => 'Engineering and maintenance'],
                ['department_name' => 'Finance', 'description' => 'Finance and accounting'],
                ['department_name' => 'Information Technology', 'description' => 'Information systems and technology'],
            ]);
        }

        if (! Schema::hasColumn('users', 'department_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('department_id')->nullable();
            });
        }

        Schema::table('vacancies', function (Blueprint $table) {
            if (! Schema::hasColumn('vacancies', 'vacancy_grade')) {
                $table->string('vacancy_grade', 1)->default('C');
            }
            if (! Schema::hasColumn('vacancies', 'audience')) {
                $table->string('audience', 20)->default('External');
            }
            if (! Schema::hasColumn('vacancies', 'hr_approved_at')) {
                $table->dateTime('hr_approved_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vacancies', function (Blueprint $table) {
            foreach (['vacancy_grade', 'audience', 'hr_approved_at'] as $column) {
                if (Schema::hasColumn('vacancies', $column)) $table->dropColumn($column);
            }
        });

        if (Schema::hasColumn('users', 'department_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('department_id');
            });
        }
    }
};
