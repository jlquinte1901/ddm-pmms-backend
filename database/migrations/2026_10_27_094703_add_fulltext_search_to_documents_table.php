<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SQLite, used by the test suite, does not support FULLTEXT indexes. The
     * production MySQL schema gets the index used by document search.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->fullText(['title', 'description', 'reference_no'], 'documents_fulltext_search');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropFullText('documents_fulltext_search');
        });
    }
};
