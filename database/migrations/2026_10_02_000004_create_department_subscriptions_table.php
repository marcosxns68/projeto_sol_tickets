<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('department_subscriptions')) {
            Schema::create('department_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('department_id')->constrained()->cascadeOnDelete();
                $table->boolean('notify_email')->default(false);
                $table->boolean('notify_whatsapp')->default(false);
                $table->boolean('notify_push')->default(false);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'department_id'], 'department_subscriptions_user_department_unique');
            });
        }

        if (Schema::hasTable('department_user_access')) {
            $rows = DB::table('department_user_access')
                ->where('follow_department', true)
                ->whereIn('access_level', ['view', 'edit'])
                ->get();

            foreach ($rows as $row) {
                DB::table('department_subscriptions')->updateOrInsert(
                    ['user_id' => $row->user_id, 'department_id' => $row->department_id],
                    [
                        'notify_email' => (bool) ($row->notify_email ?? false),
                        'notify_whatsapp' => (bool) ($row->notify_whatsapp ?? false),
                        'notify_push' => (bool) ($row->notify_push ?? false),
                        'last_seen_at' => $row->last_seen_at ?? null,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_subscriptions');
    }
};
