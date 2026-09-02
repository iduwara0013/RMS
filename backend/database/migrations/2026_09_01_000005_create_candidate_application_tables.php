<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) { $table->id('candidate_id'); $table->string('nic', 30)->unique(); $table->string('name'); $table->string('email'); $table->string('phone', 30); $table->string('address', 500); $table->timestamps(); });
        Schema::create('applications', function (Blueprint $table) { $table->id('application_id'); $table->unsignedBigInteger('candidate_id'); $table->unsignedBigInteger('vacancy_id'); $table->dateTime('submitted_at'); $table->string('status', 40)->default('Submitted'); $table->timestamps(); $table->unique(['candidate_id', 'vacancy_id']); $table->foreign('candidate_id')->references('candidate_id')->on('candidates'); $table->foreign('vacancy_id')->references('vacancy_id')->on('vacancies'); });
        Schema::create('documents', function (Blueprint $table) { $table->id('document_id'); $table->unsignedBigInteger('application_id'); $table->string('document_type', 60); $table->string('file_name'); $table->string('file_path'); $table->dateTime('uploaded_at'); $table->foreign('application_id')->references('application_id')->on('applications'); });
    }
    public function down(): void { Schema::dropIfExists('documents'); Schema::dropIfExists('applications'); Schema::dropIfExists('candidates'); }
};
