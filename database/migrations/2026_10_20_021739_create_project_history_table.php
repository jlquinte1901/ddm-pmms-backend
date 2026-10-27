<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only business history for one project. Field changes are grouped
     * by `change_set_id`; creation writes one row with the full snapshot.
     * Money is stored as a decimal string and dates as YYYY-MM-DD inside the
     * JSON values. `updated_at` and soft deletes are intentionally absent.
     */
    public function up(): void
    {
        Schema::create('project_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects');
            $table->foreignId('user_id')->constrained('users');
            $table->char('change_set_id', 36);
            $table->string('action', 50);
            $table->string('field_name', 100)->nullable();
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_history');
    }
};
