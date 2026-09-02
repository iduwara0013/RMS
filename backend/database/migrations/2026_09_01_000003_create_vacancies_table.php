<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id('vacancy_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('title');
            $table->text('description');
            $table->string('vacancy_type', 80)->default('Permanent');
            $table->date('opening_date');
            $table->date('closing_date');
            $table->string('status', 40)->default('Draft');
            $table->dateTime('hod_approved_at')->nullable();
            $table->dateTime('md_approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'opening_date', 'closing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
