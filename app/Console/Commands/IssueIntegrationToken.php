<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IssueIntegrationToken extends Command
{
    protected $signature = 'integration:token:issue {--user= : Numeric user ID} {--company= : Numeric company ID} {--all-companies : All-company inventory; requires a global administrator} {--name= : Integration name} {--days=30 : Lifetime, 1 to 90 days} {--never-expires : Explicit non-expiring token; requires a global administrator}';
    protected $description = 'Issue a company-scoped or explicit MSP-wide read-only token into a private JSON file';

    public function handle(): int
    {
        $userId = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $companyId = filter_var($this->option('company'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => config('integration-api.maximum_token_days')]]);
        $name = trim((string) $this->option('name'));
        $allCompanies = (bool) $this->option('all-companies');
        $neverExpires = (bool) $this->option('never-expires');
        if ($neverExpires && $this->input->hasParameterOption('--days')) {
            $this->error('Use either --days or --never-expires, not both.');
            return self::FAILURE;
        }
        if (! $userId || ! $days || $name === '' || strlen($name) > 100 ||
            ($allCompanies ? $this->option('company') !== null : ! $companyId)) {
            $this->error('Provide --user, either --company or --all-companies, --name (1-100 bytes), and either --days (1-90) or --never-expires.');
            return self::FAILURE;
        }

        $user = User::find($userId);
        if (! $user || ! in_array($user->role, ['admin', 'user', 'readonly'], true) ||
            ($neverExpires && ! $user->isGlobalAdmin()) ||
            ($allCompanies ? ! $user->isGlobalAdmin() :
                (! Company::whereKey($companyId)->exists() || (! $user->isGlobalAdmin() && (int) $user->company_id !== $companyId)))) {
            $this->error('User/company combination is not authorized.');
            return self::FAILURE;
        }

        $directory = storage_path('app/private/integration-tokens');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create private token directory.');
        }
        if (is_link($directory) || ! chmod($directory, 0700)) {
            throw new \RuntimeException('Cannot secure private token directory.');
        }
        $path = $directory.'/'.bin2hex(random_bytes(16)).'.json';
        $oldMask = umask(0077);
        try {
            DB::transaction(function () use ($user, $companyId, $allCompanies, $neverExpires, $name, $days, $path) {
                $abilities = $allCompanies ? ['inventory:read', 'inventory:read:all'] : ['inventory:read'];
                if ($neverExpires) {
                    $abilities[] = 'inventory:never-expires';
                }
                $issued = $user->createToken($name, $abilities, $neverExpires ? null : now()->addDays($days));
                $issued->accessToken->forceFill(['company_id' => $allCompanies ? null : $companyId])->save();
                $json = json_encode([
                    'token_id' => $issued->accessToken->id,
                    'company_id' => $allCompanies ? null : $companyId,
                    'all_companies' => $allCompanies,
                    'never_expires' => $neverExpires,
                    'expires_at' => $issued->accessToken->expires_at?->toISOString(),
                    'token' => $issued->plainTextToken,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
                $file = fopen($path, 'x');
                if (! $file) {
                    throw new \RuntimeException('Cannot create token file.');
                }
                try {
                    if (fwrite($file, $json) !== strlen($json)) {
                        throw new \RuntimeException('Cannot write complete token file.');
                    }
                } finally {
                    fclose($file);
                }
            });
        } catch (\Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        } finally {
            umask($oldMask);
        }
        $this->info('Token saved privately to: '.$path);
        $this->line('Transfer it securely to the integration, then remove this file. Do not paste it into chat.');

        return self::SUCCESS;
    }
}
