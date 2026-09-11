<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->foreignId('department_id')->nullable()->after('company_id')->constrained('departments')->nullOnDelete();
            $table->text('webhook_secret_encrypted')->nullable()->after('webhook_secret');
            $table->timestamp('last_api_activity_at')->nullable()->after('active');
            $table->timestamp('last_webhook_attempt_at')->nullable()->after('last_api_activity_at');
            $table->timestamp('last_webhook_success_at')->nullable()->after('last_webhook_attempt_at');
            $table->string('last_webhook_status', 40)->nullable()->after('last_webhook_success_at');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->json('external_metadata')->nullable()->after('external_reference');
            $table->unique(['system_id', 'external_reference'], 'tickets_system_external_reference_unique');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained('systems')->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->cascadeOnDelete();
            $table->uuid('delivery_uuid')->unique();
            $table->string('event', 100);
            $table->json('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique('tickets_system_external_reference_unique');
            $table->dropColumn('external_metadata');
        });

        Schema::table('systems', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'webhook_secret_encrypted',
                'last_api_activity_at',
                'last_webhook_attempt_at',
                'last_webhook_success_at',
                'last_webhook_status',
            ]);
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }
};
