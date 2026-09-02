<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vacancies')) return;
        Schema::table('vacancies', function (Blueprint $table) {
            if (!Schema::hasColumn('vacancies', 'hod_approved_at')) $table->dateTime('hod_approved_at')->nullable();
            if (!Schema::hasColumn('vacancies', 'md_approved_at')) $table->dateTime('md_approved_at')->nullable();
            if (!Schema::hasColumn('vacancies', 'rejection_reason')) $table->text('rejection_reason')->nullable();
        });
    }
    public function down(): void {}
};
