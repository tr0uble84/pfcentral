<?php

namespace App\Models\Vpn;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's Microsoft 365: its tenant, connected either by approving CFBTEL's shared app (admin consent)
 * or with the company's own app registration (ms_client_id + ms_client_secret in its own Entra).
 */
class VpnCompany extends Model
{
    protected $table = 'vpn_companies';
    protected $fillable = ['company_id', 'ms_tenant_id', 'ms_tenant_name', 'ms_client_id', 'ms_client_secret', 'email_domains',
        'ms_app_object_id', 'ms_secret_key_id', 'ms_secret_expires_at', 'ms_auto_setup_at', 'access_group_id', 'access_group_name', 'access_groups', 'mfa_mode'];
    protected $hidden = ['ms_client_secret'];
    protected $casts = ['ms_client_secret' => 'encrypted', 'ms_secret_expires_at' => 'datetime', 'ms_auto_setup_at' => 'datetime', 'access_groups' => 'array'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public static function forCompany(Company $company): self
    {
        return static::firstOrCreate(['company_id' => $company->id]);
    }

    public function hasOwnApp(): bool
    {
        return $this->ms_tenant_id && $this->ms_client_id && $this->getRawOriginal('ms_client_secret');
    }

    /** Microsoft 365 groups whose members get VPN access. @return array<int, array{id: string, name: string}> */
    public function companyGroups(): array
    {
        return array_values(array_filter((array) ($this->access_groups ?? []), fn ($g) => is_array($g) && !empty($g['id'])));
    }

    /** @return string[] lower-case domains, e.g. ["acme.com"] */
    public function domains(): array
    {
        return array_values(array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', (string) $this->email_domains))));
    }

    /** The company whose listed email domains include this address's domain. */
    public static function forEmail(string $email): ?self
    {
        $domain = strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));
        if ($domain === '') {
            return null;
        }
        return static::whereNotNull('email_domains')->get()->first(fn (self $vc) => in_array($domain, $vc->domains(), true));
    }
}
