<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// PFCentral brand: move the sidebar from the old default grey to CFBTEL navy, unless someone picked their own colors.
return new class extends Migration {
    public function up(): void
    {
        $this->swap('sidebar_bg', '#1f2937', '#0E2238');
        $this->swap('sidebar_text', '#d1d5db', '#CBD5E1');
    }

    public function down(): void
    {
        $this->swap('sidebar_bg', '#0E2238', '#1f2937');
        $this->swap('sidebar_text', '#CBD5E1', '#d1d5db');
    }

    private function swap(string $key, string $from, string $to): void
    {
        DB::table('system_settings')->where('key', $key)->whereRaw('LOWER(value) = ?', [strtolower($from)])->update(['value' => $to]);
    }
};
