<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->boolean('protected')->default(false); $t->timestamps(); });
        Schema::create('permissions', function (Blueprint $t) { $t->id(); $t->string('key')->unique(); $t->string('name'); $t->string('group'); $t->timestamps(); });
        Schema::create('permission_role', function (Blueprint $t) { $t->foreignId('role_id')->constrained()->cascadeOnDelete(); $t->foreignId('permission_id')->constrained()->cascadeOnDelete(); $t->primary(['role_id','permission_id']); });
        Schema::create('departments', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->boolean('active')->default(true); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password');
            $t->foreignId('role_id')->nullable()->constrained()->nullOnDelete(); $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('active')->default(true); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('document')->nullable(); $t->boolean('active')->default(true); $t->timestamps(); });
        Schema::create('systems', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete(); $t->string('name'); $t->string('base_url')->nullable();
            $t->string('api_token_hash', 64)->nullable()->unique(); $t->string('webhook_url')->nullable(); $t->string('webhook_secret')->nullable(); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('statuses', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('category'); $t->string('color', 7)->default('#6B7280'); $t->unsignedSmallInteger('position')->default(0); $t->boolean('active')->default(true); $t->timestamps(); });
        Schema::create('labels', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('color', 7)->default('#7C3AED'); $t->boolean('system')->default(false); $t->timestamps(); });
        Schema::create('tickets', function (Blueprint $t) {
            $t->id(); $t->char('number', 8)->unique(); $t->enum('origin', ['internal','integration'])->default('internal');
            $t->string('title'); $t->longText('description'); $t->enum('priority', ['low','normal','high','urgent'])->default('normal');
            $t->foreignId('status_id')->constrained(); $t->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete(); $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete(); $t->foreignId('system_id')->nullable()->constrained()->nullOnDelete();
            $t->string('requester_name')->nullable(); $t->string('requester_email')->nullable(); $t->string('external_requester_id')->nullable();
            $t->timestamp('due_at')->nullable()->index(); $t->timestamp('completed_at')->nullable(); $t->timestamp('trashed_at')->nullable()->index();
            $t->string('external_reference')->nullable(); $t->timestamps(); $t->index(['status_id','assignee_id']);
        });
        Schema::create('ticket_participants', function (Blueprint $t) {
            $t->id(); $t->foreignId('ticket_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['collaborator','follower']); $t->boolean('notify_status')->default(false); $t->boolean('notify_comments')->default(false); $t->boolean('notify_attachments')->default(false);
            $t->timestamps(); $t->unique(['ticket_id','user_id']);
        });
        Schema::create('label_ticket', function (Blueprint $t) { $t->foreignId('ticket_id')->constrained()->cascadeOnDelete(); $t->foreignId('label_id')->constrained()->cascadeOnDelete(); $t->primary(['ticket_id','label_id']); });
        Schema::create('ticket_user_preferences', function (Blueprint $t) { $t->id(); $t->foreignId('ticket_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->string('color', 7)->nullable(); $t->unique(['ticket_id','user_id']); });
        Schema::create('checklist_items', function (Blueprint $t) { $t->id(); $t->foreignId('ticket_id')->constrained()->cascadeOnDelete(); $t->string('text'); $t->boolean('completed')->default(false); $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('completed_at')->nullable(); $t->unsignedSmallInteger('position')->default(0); $t->timestamps(); });
        Schema::create('comments', function (Blueprint $t) { $t->id(); $t->foreignId('ticket_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $t->enum('visibility', ['public','internal'])->default('internal'); $t->longText('body'); $t->string('source')->default('web'); $t->string('message_id')->nullable()->unique(); $t->timestamps(); });
        Schema::create('attachments', function (Blueprint $t) { $t->id(); $t->foreignId('ticket_id')->constrained()->cascadeOnDelete(); $t->foreignId('comment_id')->nullable()->constrained()->cascadeOnDelete(); $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete(); $t->string('disk')->default('local'); $t->string('path'); $t->string('original_name'); $t->string('mime_type'); $t->unsignedBigInteger('size'); $t->timestamp('expires_at')->index(); $t->timestamp('deleted_at')->nullable(); $t->timestamps(); });
        Schema::create('audit_logs', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $t->nullableMorphs('auditable'); $t->string('event'); $t->json('old_values')->nullable(); $t->json('new_values')->nullable(); $t->ipAddress('ip_address')->nullable(); $t->text('justification')->nullable(); $t->timestamps(); });
        Schema::create('time_entries', function (Blueprint $t) { $t->id(); $t->foreignId('ticket_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->unsignedInteger('minutes'); $t->text('note')->nullable(); $t->date('worked_on'); $t->timestamps(); });
        Schema::create('recurrences', function (Blueprint $t) { $t->id(); $t->foreignId('source_ticket_id')->constrained('tickets')->cascadeOnDelete(); $t->string('frequency'); $t->unsignedSmallInteger('interval')->default(1); $t->json('weekdays')->nullable(); $t->timestamp('next_run_at')->index(); $t->timestamp('ends_at')->nullable(); $t->boolean('active')->default(true); $t->timestamps(); });
        Schema::create('settings', function (Blueprint $t) { $t->id(); $t->string('key')->unique(); $t->text('value')->nullable(); $t->timestamps(); });
        Schema::create('notifications', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('type'); $t->morphs('notifiable'); $t->text('data'); $t->timestamp('read_at')->nullable(); $t->timestamps(); });
        Schema::create('jobs', function (Blueprint $t) { $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
        Schema::create('sessions', function (Blueprint $t) { $t->string('id')->primary(); $t->foreignId('user_id')->nullable()->index(); $t->string('ip_address',45)->nullable(); $t->text('user_agent')->nullable(); $t->longText('payload'); $t->integer('last_activity')->index(); });
        Schema::create('password_reset_tokens', function (Blueprint $t) { $t->string('email')->primary(); $t->string('token'); $t->timestamp('created_at')->nullable(); });
        Schema::create('cache', function (Blueprint $t) { $t->string('key')->primary(); $t->mediumText('value'); $t->integer('expiration'); });
        Schema::create('cache_locks', function (Blueprint $t) { $t->string('key')->primary(); $t->string('owner'); $t->integer('expiration'); });
        Schema::create('failed_jobs', function (Blueprint $t) { $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent(); });
    }

    public function down(): void
    {
        foreach (['failed_jobs','cache_locks','cache','password_reset_tokens','sessions','jobs','notifications','settings','recurrences','time_entries','audit_logs','attachments','comments','checklist_items','ticket_user_preferences','label_ticket','ticket_participants','tickets','labels','statuses','systems','companies','users','departments','permission_role','permissions','roles'] as $table) Schema::dropIfExists($table);
    }
};
