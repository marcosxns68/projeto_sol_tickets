<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('statuses')->where('system_key', 'waiting_customer')
            ->update(['name' => 'Aguardando solicitante', 'updated_at' => now()]);

        if (!DB::table('statuses')->where('system_key', 'requester_replied')->exists()) {
            DB::table('statuses')->insert([
                'name' => 'Solicitante respondeu',
                'system_key' => 'requester_replied',
                'category' => 'in_progress',
                'color' => '#2563EB',
                'position' => 5,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('statuses')->where('system_key', 'waiting_customer')
            ->update(['name' => 'Aguardando cliente', 'updated_at' => now()]);

        $status = DB::table('statuses')->where('system_key', 'requester_replied')->first();
        if ($status && !DB::table('tickets')->where('status_id', $status->id)->exists()) {
            DB::table('statuses')->where('id', $status->id)->delete();
        }
    }
};
