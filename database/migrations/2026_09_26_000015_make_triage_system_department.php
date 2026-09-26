<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('system_key', 40)->nullable()->unique()->after('name');
        });

        $triage = DB::table('departments')
            ->whereRaw('LOWER(name) = ?', ['triagem'])
            ->orderBy('id')
            ->first();

        if ($triage) {
            DB::table('departments')->where('id', $triage->id)->update([
                'name' => 'Triagem',
                'system_key' => 'triage',
                'active' => true,
                'updated_at' => now(),
            ]);
            $triageId = (int) $triage->id;
        } else {
            $triageId = (int) DB::table('departments')->insertGetId([
                'name' => 'Triagem',
                'system_key' => 'triage',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Tickets que nunca foram distribuídos passam a pertencer explicitamente
        // à caixa padrão. A Triagem nunca mantém responsável.
        DB::table('tickets')->whereNull('department_id')->update([
            'department_id' => $triageId,
            'assignee_id' => null,
            'updated_at' => now(),
        ]);
        DB::table('tickets')->where('department_id', $triageId)
            ->whereNotNull('assignee_id')
            ->update(['assignee_id' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $triageId = DB::table('departments')->where('system_key', 'triage')->value('id');
        if ($triageId) {
            DB::table('tickets')->where('department_id', $triageId)->update(['department_id' => null]);
        }

        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['system_key']);
            $table->dropColumn('system_key');
        });
    }
};
