<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['users', 'Department'] as $name) {
            if (!Schema::hasColumn($name, 'is_active')) {
                Schema::table($name, fn (Blueprint $table) => $table->boolean('is_active')->default(true));
            }
        }
    }

    public function down(): void
    {
        foreach (['users', 'Department'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('is_active'));
        }
    }
};
