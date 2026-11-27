<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The attendance module. Attendance is kept as sessions (a class on a date; in period-wise mode, one per period) with
 * one record per student. The rows of the old `attendances` table are COPIED into the new tables as daily sessions;
 * the old table is left exactly as it was, as the archive. Nothing is deleted.
 */
return new class extends Migration
{
    private const NEW_ABILITIES = [
        'teacher'      => ['attendance.edit:own', 'attendance.leave:own', 'attendance.reports:own', 'attendance.export:own', 'attendance.print:own'],
        'head_teacher' => ['attendance.view', 'attendance.reports', 'attendance.export', 'attendance.print', 'attendance.leave', 'attendance.leave_approve'],
    ];

    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $t) {
            $t->id();
            $t->json('values');
            $t->timestamps();
        });

        // which mode applies from which date; scope 0 is the school default, otherwise the class id
        Schema::create('attendance_modes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('scope')->default(0);
            $t->string('mode', 8)->nullable();                       // daily | period | null = follow the school default
            $t->date('effective_from');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['scope', 'effective_from'], 'uniq_att_mode');
        });

        Schema::create('attendance_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $t->string('academic_year', 20);
            $t->date('date');
            $t->string('mode', 8);                                    // daily | period
            $t->unsignedBigInteger('slot_key')->default(0);           // 0 for daily, the period id for period-wise
            // what the timetable said at that time, kept so that later changes never rewrite history
            $t->unsignedBigInteger('period_id')->nullable();
            $t->string('period_name', 60)->nullable();
            $t->string('starts_at', 8)->nullable();
            $t->string('ends_at', 8)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('subject_name', 120)->nullable();
            $t->unsignedBigInteger('teacher_id')->nullable();         // the teacher the routine named
            $t->string('teacher_name', 120)->nullable();
            $t->boolean('substitute')->default(false);                // taken by someone else
            $t->string('day_type', 10)->default('working');           // working | makeup | weekly | holiday | vacation | closure
            $t->boolean('late_submission')->default(false);
            $t->string('source', 8)->default('app');                  // app | legacy
            $t->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['class_id', 'date', 'slot_key'], 'uniq_att_session');
            $t->index(['date', 'class_id'], 'idx_att_session_date');
            $t->index(['academic_year', 'class_id'], 'idx_att_session_year');
        });

        Schema::create('student_leaves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $t->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $t->string('type', 60);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('reason', 500);
            $t->string('attachment')->nullable();
            $t->string('attachment_name')->nullable();
            $t->string('status', 10)->default('pending');             // pending | approved | rejected | cancelled
            $t->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('remark', 500)->nullable();
            $t->timestamps();
            $t->index(['student_id', 'status', 'starts_on'], 'idx_leave_student');
            $t->index(['status', 'class_id'], 'idx_leave_status');
        });

        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_id')->constrained('attendance_sessions')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $t->unsignedBigInteger('class_id');
            $t->date('date');
            $t->unsignedBigInteger('slot_key')->default(0);
            $t->string('status', 8);                                  // present | absent | late | leave
            $t->string('remark', 255)->nullable();
            $t->foreignId('leave_id')->nullable()->constrained('student_leaves')->nullOnDelete();
            $t->timestamps();
            $t->unique(['student_id', 'date', 'slot_key'], 'uniq_att_student_scope');
            $t->index(['session_id', 'status'], 'idx_att_rec_session');
            $t->index(['class_id', 'date', 'student_id'], 'idx_att_rec_class');
        });

        // when a student changes class inside a session, so that rosters of earlier dates stay right
        Schema::create('student_class_moves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $t->unsignedBigInteger('from_class_id')->nullable();
            $t->unsignedBigInteger('to_class_id')->nullable();
            $t->date('moved_on');
            $t->timestamps();
            $t->index(['student_id', 'moved_on']);
            $t->index(['from_class_id', 'moved_on']);
        });

        $this->copyHistory();
        $this->grant(true);
    }

    /** every old row becomes a record of a daily session; the counts must match or nothing is kept */
    private function copyHistory(): void
    {
        $old = DB::table('attendances')->count();

        if (! $old) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s');

        DB::transaction(function () use ($old, $now) {
            DB::statement("INSERT INTO attendance_sessions (class_id, academic_year, date, mode, slot_key, day_type, source, taken_by, submitted_at, created_at, updated_at)
                SELECT class_id, MAX(academic_year), attendance_date, 'daily', 0, 'working', 'legacy', MAX(marked_by), MAX(updated_at), COALESCE(MIN(created_at), '{$now}'), COALESCE(MAX(updated_at), '{$now}')
                FROM attendances GROUP BY class_id, attendance_date");

            DB::statement("INSERT INTO attendance_records (session_id, student_id, class_id, date, slot_key, status, created_at, updated_at)
                SELECT s.id, a.student_id, a.class_id, a.attendance_date, 0, a.status, a.created_at, a.updated_at
                FROM attendances a JOIN attendance_sessions s ON s.class_id = a.class_id AND s.date = a.attendance_date AND s.slot_key = 0");

            $copied = DB::table('attendance_records')->count();

            if ($copied !== $old) {
                throw new RuntimeException("Attendance history was not copied completely ({$copied} of {$old} rows). Nothing was changed in the old table.");
            }
        });
    }

    private function grant(bool $add): void
    {
        foreach (self::NEW_ABILITIES as $slug => $abilities) {
            $role = DB::table('roles')->where('slug', $slug)->first();

            if (! $role) {
                continue;
            }

            $have = json_decode((string) $role->abilities, true) ?: [];
            $have = $add ? array_values(array_unique([...$have, ...$abilities])) : array_values(array_diff($have, $abilities));
            DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode($have), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $this->grant(false);

        foreach (['student_class_moves', 'attendance_records', 'student_leaves', 'attendance_sessions', 'attendance_modes', 'attendance_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
