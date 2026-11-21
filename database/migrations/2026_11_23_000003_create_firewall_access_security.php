<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Secure front door: firewall web interface sessions through PFCentral, and the web interface lock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_gui_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_email');
            $table->foreignId('firewall_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('enter_hash', 64)->nullable()->unique();
            $table->timestamp('enter_expires_at')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('temp_user', 64);
            $table->boolean('temp_user_removed')->default(false);
            $table->longText('cookie_jar')->nullable(); // encrypted
            $table->json('actions')->nullable();
            $table->unsignedInteger('requests')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 64)->nullable();
            $table->timestamps();
            $table->index(['ended_at', 'temp_user_removed']);
        });

        Schema::create('firewall_gui_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firewall_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('locked')->default(false);
            $table->json('allowed')->nullable();
            $table->json('seen')->nullable();
            $table->string('port', 8)->nullable();
            $table->string('changed_by')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_gui_locks');
        Schema::dropIfExists('firewall_gui_sessions');
    }
};
