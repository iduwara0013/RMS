<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('candidate_cv_profiles')) {
            return;
        }

        Schema::create('candidate_cv_profiles', function (Blueprint $table) {
            $table->id('cv_profile_id');
            $table->unsignedBigInteger('application_id')->unique();
            $table->text('professional_summary')->nullable();
            $table->text('skills')->nullable();
            $table->text('education')->nullable();
            $table->text('experience')->nullable();
            $table->text('certifications')->nullable();
            $table->text('languages')->nullable();
            $table->string('parse_status', 30)->default('Pending');
            $table->string('parse_message', 500)->nullable();
            $table->dateTime('extracted_at')->nullable();
            $table->timestamps();
            $table->foreign('application_id')->references('application_id')->on('applications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_cv_profiles');
    }
};
