<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->string('created_by')->nullable();
            $t->string('status', 20)->default('draft');
            $t->json('items');
            $t->unsignedSmallInteger('current_wave')->nullable();
            $t->unsignedSmallInteger('scheduled_wave')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->string('scheduled_by')->nullable();
            $t->timestamps();
        });

        Schema::create('deployment_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('firewall_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('wave')->default(1);
            $t->string('status', 20)->default('pending');
            $t->json('check')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->longText('undo')->nullable();
            $t->json('result')->nullable();
            $t->unsignedInteger('changed')->default(0);
            $t->text('message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['deployment_id', 'firewall_id'], 'deploy_targets_dep_fw_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_targets');
        Schema::dropIfExists('deployments');
    }
};
