<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things student management needed before real data goes in:
 *
 *  - a status lifecycle (when and why a student left or was suspended), instead of a bare
 *    "active / inactive" and a permanent delete;
 *  - an audit log: who created, changed or deleted a record, and what changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('students', 'status_changed_on')) {
            Schema::table('students', function (Blueprint $table) {
                $table->date('status_changed_on')->nullable()->after('status');
                $table->string('status_reason', 255)->nullable()->after('status_changed_on');
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event', 20);                       // created | updated | deleted
                $table->string('auditable_type', 80);
                $table->unsignedBigInteger('auditable_id');
                $table->string('label', 255)->nullable();          // e.g. the student's name, so a deleted record is still readable
                $table->json('changes')->nullable();               // updated: {field: {from, to}} · created/deleted: the record
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['auditable_type', 'auditable_id', 'created_at'], 'idx_audit_record');
                $table->index('created_at', 'idx_audit_when');
                $table->index(['user_id', 'created_at'], 'idx_audit_user');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');

        if (Schema::hasColumn('students', 'status_changed_on')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropColumn(['status_changed_on', 'status_reason']);
            });
        }
    }
};
