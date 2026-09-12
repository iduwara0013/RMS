<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vacancy_form_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vacancy_id');
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('questions');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['vacancy_id', 'version']);
            $table->foreign('vacancy_id')->references('vacancy_id')->on('vacancies');
        });
        Schema::create('application_form_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id')->unique();
            $table->unsignedBigInteger('form_version_id');
            $table->longText('answers');
            $table->timestamps();
            $table->foreign('application_id')->references('application_id')->on('applications');
            $table->foreign('form_version_id')->references('id')->on('vacancy_form_versions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_form_submissions');
        Schema::dropIfExists('vacancy_form_versions');
    }
};
