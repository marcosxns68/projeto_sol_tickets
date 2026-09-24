<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('department_user_access', function (Blueprint $table) {
            $table->boolean('notify_email')->default(false);
            $table->boolean('notify_whatsapp')->default(false);
            $table->boolean('notify_push')->default(false);
            $table->timestamp('last_seen_at')->nullable();
        });

        // Preserva os seguidores atuais no e-mail e no sininho.
        // WhatsApp exige número próprio do usuário, não ativa sem consentimento.
        DB::table('department_user_access')
            ->where('follow_department', true)
            ->whereIn('access_level', ['view', 'edit'])
            ->update([
                'notify_email' => true,
                'notify_push' => true,
                'last_seen_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('department_user_access', function (Blueprint $table) {
            $table->dropColumn(['notify_email', 'notify_whatsapp', 'notify_push', 'last_seen_at']);
        });
    }
};
