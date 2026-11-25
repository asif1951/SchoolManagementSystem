<?php

use App\Support\PersonId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One permanent 8-digit ID for everyone (see App\Support\PersonId).
 *
 *  users.uid      → every login account (teachers, staff, admins, public users)
 *  teachers.uid   → every public staff profile (the website's Teachers & Staff page)
 *  students.student_id → becomes the 8-digit ID. A student keeps ONE ID across all the years
 *                        they appear in (their rows share the same old id, so they share the new one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('type');
            $table->unsignedInteger('last')->default(0);
            $table->primary(['year', 'type']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->char('uid', 8)->nullable()->unique()->after('id');
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->char('uid', 8)->nullable()->unique()->after('id');
        });

        $yearOf = fn ($date) => $date ? (int) substr((string) $date, 0, 4) : (int) now()->year;

        // ---- login accounts ----
        foreach (DB::table('users')->whereNull('uid')->orderBy('id')->get(['id', 'role', 'created_at']) as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'uid' => PersonId::next(PersonId::typeForRole($user->role), $yearOf($user->created_at)),
            ]);
        }

        // ---- public staff profiles: share the ID of their login account when they have one ----
        $userUid = DB::table('users')->pluck('uid', 'email');

        foreach (DB::table('teachers')->whereNull('uid')->orderBy('id')->get(['id', 'email', 'created_at']) as $profile) {
            $uid = $profile->email ? ($userUid[$profile->email] ?? null) : null;

            if ($uid && DB::table('teachers')->where('uid', $uid)->exists()) {
                $uid = null;
            }

            DB::table('teachers')->where('id', $profile->id)->update([
                'uid' => $uid ?: PersonId::next(PersonId::TEACHER, $yearOf($profile->created_at)),
            ]);
        }

        // ---- students: one new ID per old ID (a student's rows in different years stay together) ----
        $rows = DB::table('students')->orderBy('academic_year')->orderBy('id')->get(['id', 'student_id', 'academic_year']);

        foreach ($rows->groupBy('student_id') as $oldId => $group) {
            if (PersonId::isValid((string) $oldId)) {
                PersonId::claim((string) $oldId);
                continue;
            }

            $new = PersonId::next(PersonId::STUDENT, (int) $group->first()->academic_year);

            DB::table('students')->whereIn('id', $group->pluck('id'))->update(['student_id' => $new]);
        }
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropUnique(['uid']);
            $table->dropColumn('uid');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uid']);
            $table->dropColumn('uid');
        });

        Schema::dropIfExists('id_counters');
    }
};
