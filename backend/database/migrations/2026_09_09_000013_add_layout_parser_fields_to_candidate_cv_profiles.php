<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('candidate_cv_profiles')) {
            return;
        }

        Schema::table('candidate_cv_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('candidate_cv_profiles', 'projects')) {
                $table->text('projects')->nullable();
            }
            if (! Schema::hasColumn('candidate_cv_profiles', 'confidence_score')) {
                $table->decimal('confidence_score', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('candidate_cv_profiles', 'review_status')) {
                $table->string('review_status', 30)->nullable();
            }
            if (! Schema::hasColumn('candidate_cv_profiles', 'parser_version')) {
                $table->string('parser_version', 50)->nullable();
            }
            if (! Schema::hasColumn('candidate_cv_profiles', 'parser_metadata')) {
                $table->text('parser_metadata')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('candidate_cv_profiles')) {
            return;
        }

        $columns = array_values(array_filter(
            ['projects', 'confidence_score', 'review_status', 'parser_version', 'parser_metadata'],
            fn (string $column) => Schema::hasColumn('candidate_cv_profiles', $column)
        ));
        if ($columns) {
            Schema::table('candidate_cv_profiles', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
