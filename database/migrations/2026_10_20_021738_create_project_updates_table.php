<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only progress reports. `previous_progress` is the project value
     * before this report, so a change from 40% to 90% stays traceable.
     * `updated_at` is intentionally absent.
     */
    public function up(): void
    {
        Schema::create('project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects');
            $table->foreignId('reported_by')->constrained('users');
            $table->date('report_date');
            $table->unsignedTinyInteger('previous_progress');
            $table->unsignedTinyInteger('progress_percentage');
            $table->text('accomplament')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_updates');
    }
};
