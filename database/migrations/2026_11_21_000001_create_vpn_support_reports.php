<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Support tickets sent from the CFBTEL VPN Windows app (Diagnostics tab), with the diagnostic report. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vpn_support_reports')) {
            return;
        }
        Schema::create('vpn_support_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies', 'id', 'vpn_sr_company_fk')->nullOnDelete();
            $t->unsignedBigInteger('vpn_device_id')->nullable(); // the device may be removed later
            $t->string('device_name', 64)->nullable();
            $t->string('person', 190)->nullable();
            $t->string('contact_name', 100)->nullable();
            $t->string('contact_email', 190);
            $t->string('subject', 150);
            $t->text('message');
            $t->string('computer', 64)->nullable();
            $t->string('device_id', 32)->nullable();
            $t->string('app', 40)->nullable();
            $t->unsignedSmallInteger('problems')->default(0);
            $t->unsignedSmallInteger('warnings')->default(0);
            $t->longText('report');
            $t->string('ip', 45)->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->string('email_error', 300)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'created_at'], 'vpn_sr_company_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_support_reports');
    }
};
