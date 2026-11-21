<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VPN self-service (WireGuard + Microsoft 365 sign-in).
 *
 * - vpn_companies: a company's Microsoft 365 tenant (connected through admin consent).
 * - vpn_sites:     a company firewall with WireGuard set up (or adopted) for remote access.
 * - vpn_users:     people of that tenant who were given VPN access by a company admin.
 * - vpn_devices:   one WireGuard peer per device. Private keys are never stored.
 * - vpn_audit_logs: who did what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('ms_tenant_id', 36)->nullable()->unique();
            $table->string('ms_tenant_name')->nullable();
            $table->timestamps();
        });

        Schema::create('vpn_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('firewall_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending | ready | error
            $table->string('tunnel')->nullable();             // pfSense: tun_wg0 · OPNsense: instance uuid
            $table->string('tunnel_name')->nullable();
            $table->string('server_public_key', 64)->nullable();
            $table->string('subnet', 32)->nullable();
            $table->string('endpoint')->nullable();           // host:port clients connect to
            $table->string('dns')->nullable();
            $table->string('allowed_ips')->default('0.0.0.0/0, ::/0');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('vpn_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('ms_oid', 36);
            $table->string('upn');
            $table->string('name');
            $table->string('added_by')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'ms_oid']);
        });

        Schema::create('vpn_devices', function (Blueprint $table) {
            $table->id();
            // Kept (null) after the person is removed until the peer is gone from the firewall.
            $table->foreignId('vpn_user_id')->nullable()->constrained('vpn_users')->nullOnDelete();
            $table->foreignId('vpn_site_id')->constrained('vpn_sites')->cascadeOnDelete();
            $table->string('name', 32);
            $table->string('public_key', 44)->unique();
            $table->string('address', 15);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['vpn_site_id', 'address']);
        });

        Schema::create('vpn_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor');
            $table->string('action', 500);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_audit_logs');
        Schema::dropIfExists('vpn_devices');
        Schema::dropIfExists('vpn_users');
        Schema::dropIfExists('vpn_sites');
        Schema::dropIfExists('vpn_companies');
    }
};
