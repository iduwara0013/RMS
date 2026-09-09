<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_access_tokens')) {
            Schema::create('staff_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('token_hash', 64)->unique();
                $table->dateTime('expires_at');
                $table->dateTime('last_used_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'expires_at']);
            });
        }

        if (! Schema::hasTable('document_access_logs')) {
            Schema::create('document_access_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('application_id');
                $table->unsignedBigInteger('user_id');
                $table->string('action', 20);
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->dateTime('accessed_at');
                $table->index(['document_id', 'accessed_at']);
                $table->index(['user_id', 'accessed_at']);
            });
        }

        if (Schema::hasTable('documents')) {
            DB::table('documents')->select(['document_id', 'file_path'])->orderBy('document_id')->chunkById(100, function ($documents) {
                foreach ($documents as $document) {
                    if (! $document->file_path || Storage::disk('local')->exists($document->file_path) || ! Storage::disk('public')->exists($document->file_path)) {
                        continue;
                    }
                    if (Storage::disk('local')->put($document->file_path, Storage::disk('public')->get($document->file_path))) {
                        Storage::disk('public')->delete($document->file_path);
                    }
                }
            }, 'document_id');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_logs');
        Schema::dropIfExists('staff_access_tokens');
    }
};
