<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('phone', 30)->nullable();
            });
        }

        if (! Schema::hasTable('internal_otp_challenges')) {
            Schema::create('internal_otp_challenges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('code_hash');
                $table->dateTime('expires_at');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->dateTime('verified_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'expires_at']);
            });
        }

        if (! Schema::hasTable('internal_access_tokens')) {
            Schema::create('internal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('token_hash', 64)->unique();
                $table->dateTime('expires_at');
                $table->dateTime('last_used_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'expires_at']);
            });
        }

        Schema::table('applications', function (Blueprint $table) {
            if (! Schema::hasColumn('applications', 'applicant_type')) {
                $table->string('applicant_type', 20)->default('External');
            }
            if (! Schema::hasColumn('applications', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_access_tokens');
        Schema::dropIfExists('internal_otp_challenges');
    }
};
