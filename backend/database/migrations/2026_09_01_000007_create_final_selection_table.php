<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('final_selections', function (Blueprint $table) {
            $table->id('selection_id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('selected_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('status', 40)->default('Pending MD Approval');
            $table->text('comments')->nullable();
            $table->dateTime('selected_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->foreign('application_id')->references('application_id')->on('applications');
            $table->foreign('selected_by')->references('id')->on('users');
            $table->foreign('approved_by')->references('id')->on('users');
        });
    }

    public function down(): void { Schema::dropIfExists('final_selections'); }
};
