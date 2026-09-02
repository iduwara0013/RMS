<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $table) { $table->id('interview_id'); $table->unsignedBigInteger('application_id'); $table->date('interview_date'); $table->time('interview_time'); $table->string('location'); $table->string('status', 40)->default('Scheduled'); $table->timestamps(); $table->foreign('application_id')->references('application_id')->on('applications'); });
        Schema::create('interview_evaluations', function (Blueprint $table) { $table->id('evaluation_id'); $table->unsignedBigInteger('interview_id'); $table->decimal('score', 5, 2); $table->text('comments'); $table->string('recommendation', 40); $table->dateTime('evaluated_at'); $table->foreign('interview_id')->references('interview_id')->on('interviews'); });
    }
    public function down(): void { Schema::dropIfExists('interview_evaluations'); Schema::dropIfExists('interviews'); }
};
