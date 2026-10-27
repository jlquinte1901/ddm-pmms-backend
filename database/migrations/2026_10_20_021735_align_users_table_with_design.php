<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The framework's users table already ran, so it is aligned with the
     * confirmed schema here instead of editing a migration that has run.
     * `role_id` is added as nullable first because SQLite cannot add a
     * NOT NULL column without a default, then tightened to NOT NULL with a
     * rebuild and given its foreign key.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'full_name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('id');
            $table->string('section', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable(false)->change();
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['role_id', 'section', 'is_active', 'last_login_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('full_name', 'name');
        });
    }
};
