<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('candidate_notifications', function (Blueprint $table) {
            $table->id('notification_id');
            $table->unsignedBigInteger('application_id');
            $table->string('notification_type', 40);
            $table->string('recipient_email');
            $table->string('subject');
            $table->text('message');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->foreign('application_id')->references('application_id')->on('applications');
        });
    }

    public function down(): void { Schema::dropIfExists('candidate_notifications'); }
};
