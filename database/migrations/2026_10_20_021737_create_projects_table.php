<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `assigned_pe_id` is the single persisted owner of the project;
     * `project_manager` is display text from the paper form and is never used
     * for authorization. `effective_finish_date` is a stored generated column
     * (COALESCE of the revised and planned finish dates) so the 35-day
     * indicator filters can use an index; it never overwrites
     * `planned_finish_date`. The monitoring indicator itself is computed at
     * read time and is never stored.
     *
     * `status` holds one of: not_started, ongoing, suspended, postponed,
     * completed, terminated. Progress is an unsigned tinyint kept between 0 and
     * 100 by validation and backed enums, since check constraints are not
     * portable across the sqlite test driver and MySQL.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_category_id')->constrained('project_categories');
            $table->foreignId('assigned_pe_id')->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedSmallInteger('implementation_year');
            $table->string('project_name');
            $table->string('contract_number', 100)->nullable()->unique();
            $table->string('location');
            $table->string('region', 100)->nullable();
            $table->string('lgu', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('implementing_office', 150)->nullable();
            $table->string('project_manager', 100)->nullable();
            $table->string('fund_source', 100);
            $table->decimal('contract_amount', 15, 2);
            $table->decimal('revised_amount', 15, 2)->nullable();
            $table->date('planned_start_date');
            $table->date('planned_finish_date');
            $table->date('revised_finish_date')->nullable();
            $table->date('effective_finish_date')->storedAs('COALESCE(revised_finish_date, planned_finish_date)');
            $table->date('actual_start_date')->nullable();
            $table->date('actual_finish_date')->nullable();
            $table->string('contractor', 150)->nullable();
            $table->string('status', 20)->default('not_started');
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->date('paused_since')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'effective_finish_date']);
            $table->index(['assigned_pe_id', 'status']);
            $table->index('implementation_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
