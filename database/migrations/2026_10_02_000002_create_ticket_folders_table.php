<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ticket_folders')) {
            Schema::create('ticket_folders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('department_id')->constrained()->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('ticket_folders')->nullOnDelete();
                $table->string('name', 160);
                $table->integer('position')->default(0);
                $table->timestamps();

                $table->index(['department_id', 'parent_id', 'position'], 'ticket_folders_tree_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_folders');
    }
};
