<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('interview_panel_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('interview_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_by');
            $table->timestamps();
            $table->unique(['interview_id', 'user_id']);
            $table->foreign('interview_id')->references('interview_id')->on('interviews');
        });
        Schema::create('interview_scorecard_criteria', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('interview_id');
            $table->string('name');
            $table->decimal('weight', 5, 2);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->foreign('interview_id')->references('interview_id')->on('interviews');
        });
        Schema::create('panel_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('interview_id');
            $table->unsignedBigInteger('evaluator_id');
            $table->decimal('weighted_score', 5, 2);
            $table->string('recommendation', 40);
            $table->text('comments');
            $table->string('status', 20)->default('Submitted');
            $table->dateTime('submitted_at');
            $table->dateTime('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestamps();
            $table->unique(['interview_id', 'evaluator_id']);
            $table->foreign('interview_id')->references('interview_id')->on('interviews');
        });
        Schema::create('panel_evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('panel_evaluation_id');
            $table->unsignedBigInteger('criterion_id');
            $table->decimal('score', 5, 2);
            $table->timestamps();
            $table->unique(['panel_evaluation_id', 'criterion_id']);
            $table->foreign('panel_evaluation_id')->references('id')->on('panel_evaluations');
            $table->foreign('criterion_id')->references('id')->on('interview_scorecard_criteria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_evaluation_scores');
        Schema::dropIfExists('panel_evaluations');
        Schema::dropIfExists('interview_scorecard_criteria');
        Schema::dropIfExists('interview_panel_assignments');
    }
};
