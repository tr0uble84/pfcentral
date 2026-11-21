<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Upgrade centre: the latest pre-check of each firewall, and upgrade rollouts in waves. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firmware_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firewall_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('running'); // running, ok, warn, fail, error
            $table->string('version', 64)->nullable();
            $table->string('available_version', 64)->nullable();
            $table->boolean('up_to_date')->nullable();
            $table->json('facts')->nullable();
            $table->json('checks')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('firmware_rollouts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete(); // null: made by a global admin
            $table->string('created_by');
            $table->string('status', 20)->default('draft'); // draft, running, attention, completed, stopped
            $table->unsignedTinyInteger('current_wave')->default(0);
            $table->timestamps();
        });

        Schema::create('firmware_rollout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firmware_rollout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('firewall_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('wave')->default(1);
            $table->string('status', 20)->default('pending');
            $table->string('from_version', 64)->nullable();
            $table->string('to_version', 64)->nullable();
            $table->string('backup_path')->nullable();
            $table->timestamp('backup_at')->nullable();
            $table->unsignedBigInteger('boot_before')->nullable();
            $table->unsignedSmallInteger('api_misses')->default(0);
            $table->boolean('api_reinstall_tried')->default(false);
            $table->text('message')->nullable();
            $table->text('log_tail')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['firewall_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firmware_rollout_items');
        Schema::dropIfExists('firmware_rollouts');
        Schema::dropIfExists('firmware_checks');
    }
};
