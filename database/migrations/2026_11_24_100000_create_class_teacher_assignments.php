<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class teacher becomes a real link to a user account instead of a free-text name,
 * and every change of class teacher is recorded (who taught which class, when).
 *
 *  - classes.class_teacher_id       → the CURRENT class teacher (fast lookups / permissions)
 *  - class_teacher_assignments      → full history, one row per teacher stint
 *
 * Classes are already one row per academic year, so "Asif taught Class 6 in 2026,
 * Keshob in 2027" is simply the assignment history of the two class rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('class_teacher_id')
                ->nullable()
                ->after('academic_year')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('class_teacher_id', 'idx_classes_teacher');
        });

        Schema::create('class_teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('teacher_name', 255);            // snapshot — survives renames/deletes
            $table->date('starts_on');
            $table->date('ends_on')->nullable();             // NULL = current
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['class_id', 'ends_on'], 'idx_cta_class_current');
            $table->index(['user_id', 'ends_on'], 'idx_cta_user_current');
        });

        // Back-fill from the old free-text column.
        $unmatched = [];

        foreach (DB::table('classes')->get(['id', 'class_teacher', 'created_at']) as $class) {
            $name = trim((string) $class->class_teacher);
            if ($name === '') {
                continue;
            }

            $users = DB::table('users')->where('name', $name)->get(['id', 'name']);

            if ($users->count() !== 1) {
                $unmatched[] = "class #{$class->id}: \"{$name}\" matches {$users->count()} users";
                continue;
            }

            DB::table('classes')->where('id', $class->id)->update(['class_teacher_id' => $users[0]->id]);

            DB::table('class_teacher_assignments')->insert([
                'class_id'     => $class->id,
                'user_id'      => $users[0]->id,
                'teacher_name' => $users[0]->name,
                'starts_on'    => substr((string) ($class->created_at ?? now()), 0, 10),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        if ($unmatched) {
            throw new RuntimeException(
                "Cannot convert class teachers — fix these names so each matches exactly one user, then re-run:\n  "
                . implode("\n  ", $unmatched)
            );
        }

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('class_teacher');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->string('class_teacher')->default('')->after('academic_year');
        });

        DB::statement('UPDATE classes c LEFT JOIN users u ON u.id = c.class_teacher_id SET c.class_teacher = COALESCE(u.name, \'\')');

        Schema::dropIfExists('class_teacher_assignments');

        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['class_teacher_id']);
            $table->dropIndex('idx_classes_teacher');
            $table->dropColumn('class_teacher_id');
        });
    }
};
