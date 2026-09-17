<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('statuses')
            ->where('name', 'Aguardando cliente')
            ->whereNull('system_key')
            ->update([
                'system_key' => 'waiting_customer',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('statuses')
            ->where('name', 'Aguardando cliente')
            ->where('system_key', 'waiting_customer')
            ->update([
                'system_key' => null,
                'updated_at' => now(),
            ]);
    }
};
