<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('department_user_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('access_level', 16)->default('send');
            $table->boolean('follow_department')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'department_id']);
            $table->index(['department_id', 'access_level']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('requester_user_id')->nullable()->after('requester_email')->constrained('users')->nullOnDelete();
        });

        DB::table('users')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                $now = now();
                $rows = [];
                foreach ($users as $user) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'department_id' => $user->department_id,
                        'access_level' => 'view',
                        'follow_department' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    DB::table('department_user_access')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requester_user_id');
        });

        Schema::dropIfExists('department_user_access');
    }
};
