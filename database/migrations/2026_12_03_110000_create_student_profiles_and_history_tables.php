<?php

use App\Support\Normalize;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identity vs enrollment.
 *
 *  student_profiles          the permanent identity of a child (one row per child, ever)
 *  students                  stays the ENROLLMENT: one row per session. Gains profile_id + soft delete.
 *  student_field_histories   every change of every personal field: old, new, who, when, source
 *  student_change_requests   proposed changes to protected fields awaiting approval
 *  student_duplicate_overrides  who saved a likely duplicate anyway, and why
 *
 * Existing students are backfilled: one profile per student code, built from their latest enrollment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();                    // the permanent student code (8 digits today; older ids of other shapes stay valid)
            $table->string('name_en', 255);
            $table->string('name_bn', 255)->nullable();
            $table->string('name_normalized', 255)->index();         // for duplicate matching
            $table->char('brn', 17)->nullable()->unique();           // birth registration number: unique when present
            $table->date('dob')->nullable()->index();
            $table->string('gender', 20)->nullable()->index();
            $table->string('religion', 40)->nullable()->index();
            $table->string('blood_group', 10)->nullable();
            $table->string('father_name', 255)->nullable();
            $table->string('mother_name', 255)->nullable();
            $table->string('guardian_name', 255)->nullable();
            $table->string('guardian_mobile', 30)->nullable();
            $table->string('guardian_mobile_norm', 15)->nullable()->index();
            $table->string('guardian_relation', 50)->nullable();
            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('photo', 255)->nullable();
            $table->string('previous_school', 255)->nullable();
            $table->date('admission_date')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['name_normalized', 'dob'], 'idx_profile_dup_name_dob');
            $table->index(['guardian_mobile_norm', 'dob'], 'idx_profile_dup_mobile_dob');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('profile_id')->nullable()->after('id')->constrained('student_profiles')->restrictOnDelete();
            $table->softDeletes();
            $table->index('profile_id');
        });

        Schema::create('student_field_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->string('field', 50);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20);                            // screen | quick | import | approval | online | system
            $table->timestamp('created_at')->useCurrent();

            $table->index(['profile_id', 'created_at'], 'idx_history_profile');
            $table->index(['field', 'created_at'], 'idx_history_field');
        });

        Schema::create('student_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->string('field', 50);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('reason', 500);
            $table->string('status', 12)->default('pending');        // pending | approved | rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_remark', 500)->nullable();

            $table->index(['status', 'requested_at'], 'idx_change_status');
            $table->index(['profile_id', 'status'], 'idx_change_profile');
        });

        Schema::create('student_duplicate_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();      // the student saved anyway
            $table->foreignId('matched_profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();   // the record it looked like
            $table->string('context', 20);                           // entry | quick | import | online | change
            $table->string('reason', 500);
            $table->unsignedTinyInteger('score')->nullable();        // name similarity at the time
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at', 'idx_override_when');
        });

        $this->backfill();
    }

    /** One profile per student code, from the latest enrollment of that code. */
    private function backfill(): void
    {
        $rows = DB::table('students')->orderBy('student_id')->orderBy('academic_year', 'desc')->orderBy('id', 'desc')->get();

        $profileOf = [];

        foreach ($rows->chunk(500) as $chunk) {
            foreach ($chunk as $row) {
                if (isset($profileOf[$row->student_id])) {
                    continue;                                           // an older enrollment of a code already handled
                }

                $mobile = $row->guardian_phone ?: ($row->father_phone ?: $row->mother_phone);

                $profileOf[$row->student_id] = DB::table('student_profiles')->insertGetId([
                    'code'                 => $row->student_id,
                    'name_en'              => $row->student_name,
                    'name_normalized'      => Normalize::name($row->student_name),
                    'dob'                  => $row->date_of_birth,
                    'gender'               => $row->gender,
                    'blood_group'          => $row->blood_group,
                    'father_name'          => $row->father_name,
                    'mother_name'          => $row->mother_name,
                    'guardian_name'        => $row->guardian_name,
                    'guardian_mobile'      => $mobile,
                    'guardian_mobile_norm' => Normalize::mobile($mobile),
                    'guardian_relation'    => $row->guardian_relation,
                    'present_address'      => $row->present_address,
                    'permanent_address'    => $row->permanent_address,
                    'photo'                => $row->student_photo,
                    'admission_date'       => $row->admission_date,
                    'created_at'           => $row->created_at ?? now(),
                    'updated_at'           => now(),
                ]);
            }
        }

        foreach ($rows->groupBy('student_id') as $code => $group) {
            DB::table('students')->whereIn('id', $group->pluck('id'))->update(['profile_id' => $profileOf[$code]]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_duplicate_overrides');
        Schema::dropIfExists('student_change_requests');
        Schema::dropIfExists('student_field_histories');

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profile_id');
            $table->dropSoftDeletes();
        });

        Schema::dropIfExists('student_profiles');
    }
};
