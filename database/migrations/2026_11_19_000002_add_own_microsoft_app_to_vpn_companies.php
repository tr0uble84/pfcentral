<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company may use its own Microsoft Entra app registration (in its own tenant) instead of approving
 * CFBTEL's shared app, and lists its work email domains so the staff page can pick the right app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_companies', function (Blueprint $table) {
            $table->string('ms_client_id', 36)->nullable()->after('ms_tenant_name');
            $table->text('ms_client_secret')->nullable()->after('ms_client_id'); // encrypted with the app key
            $table->string('email_domains', 500)->nullable()->after('ms_client_secret'); // "acme.com,acme.us"
        });
    }

    public function down(): void
    {
        Schema::table('vpn_companies', function (Blueprint $table) {
            $table->dropColumn(['ms_client_id', 'ms_client_secret', 'email_domains']);
        });
    }
};
