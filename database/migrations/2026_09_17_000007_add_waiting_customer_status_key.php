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

        $waitingCustomerId = DB::table('statuses')
            ->where(function ($query) {
                $query->where('system_key', 'waiting_customer')
                    ->orWhere('name', 'Aguardando cliente');
            })
            ->value('id');

        $inProgressId = DB::table('statuses')
            ->where('system_key', 'in_progress')
            ->value('id');

        if (!$waitingCustomerId || !$inProgressId) {
            return;
        }

        $alreadyAnswered = DB::table('tickets')
            ->where('status_id', $waitingCustomerId)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('comments')
                    ->whereColumn('comments.ticket_id', 'tickets.id')
                    ->where('comments.visibility', 'public')
                    ->whereIn('comments.source', ['integration', 'requester'])
                    ->whereColumn('comments.created_at', '>', 'tickets.updated_at');
            })
            ->pluck('id');

        if ($alreadyAnswered->isNotEmpty()) {
            DB::table('tickets')
                ->whereIn('id', $alreadyAnswered)
                ->update([
                    'status_id' => $inProgressId,
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);
        }
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
