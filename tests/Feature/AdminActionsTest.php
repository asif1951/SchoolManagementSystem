<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Notice;
use App\Models\SchoolClass;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

/**
 * Regression tests for admin actions that used to be wired through closures
 * passing the wrong arguments to their controllers (so they threw TypeErrors).
 */
class AdminActionsTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private function admin(): User
    {
        return $this->makeUser(User::ROLE_ADMIN);
    }

    public function test_messages_can_be_marked_read_unread_and_deleted(): void
    {
        $msg = ContactMessage::create(['name' => 'A', 'email' => 'a@example.com', 'message' => 'hi', 'status' => 'new']);

        $this->actingAs($this->admin())->post("/website-management/messages/{$msg->id}/read")->assertSessionHas('success');
        $this->assertSame('read', $msg->fresh()->status);

        $this->actingAs($this->admin())->post("/website-management/messages/{$msg->id}/unread")->assertSessionHas('success');
        $this->assertSame('new', $msg->fresh()->status);

        $this->actingAs($this->admin())->delete("/website-management/messages/{$msg->id}")->assertSessionHas('success');
        $this->assertModelMissing($msg);
    }

    public function test_notice_featured_flag_toggles_and_notice_can_be_deleted(): void
    {
        $notice = Notice::create(['title' => 'Holiday', 'notice_date' => now()->toDateString(), 'featured' => false, 'status' => 'published']);

        $this->actingAs($this->admin())->post("/website-management/notices/{$notice->id}/toggle-featured")->assertSessionHas('success');
        $this->assertTrue((bool) $notice->fresh()->featured);

        $this->actingAs($this->admin())->delete("/website-management/notices/{$notice->id}")->assertSessionHas('success');
        $this->assertModelMissing($notice);
    }

    public function test_teacher_profile_can_be_deleted(): void
    {
        $teacher = Teacher::create(['name' => 'Mr. X', 'status' => 'active']);

        $this->actingAs($this->admin())->delete("/website-management/teachers-staff/{$teacher->id}")->assertSessionHas('success');
        $this->assertModelMissing($teacher);
    }

    public function test_empty_class_can_be_deleted_via_both_class_screens(): void
    {
        $a = $this->makeClass('Class 1', 'A');
        $b = $this->makeClass('Class 2', 'A');

        $this->actingAs($this->admin())->delete("/classes/{$a->id}")->assertSessionHas('success');
        $this->actingAs($this->admin())->delete("/classesmanagement/{$b->id}")->assertSessionHas('success');

        $this->assertSame(0, SchoolClass::count());
    }

    public function test_class_with_students_is_protected_on_both_class_screens(): void
    {
        $a = $this->makeClass('Class 1', 'A');
        $this->makeStudent($a);

        $this->actingAs($this->admin())->delete("/classes/{$a->id}")->assertSessionHas('error');
        $this->actingAs($this->admin())->delete("/classesmanagement/{$a->id}")->assertSessionHas('error');

        $this->assertModelExists($a);
    }

    public function test_fee_group_can_be_deleted_together_with_its_discount_policies(): void
    {
        $class   = $this->makeClass();
        $student = $this->makeStudent($class);
        $year    = (string) $class->academic_year;

        $feeId = DB::table('fee_structures')->insertGetId([
            'class_id' => $class->id, 'academic_year' => $year, 'fee_name' => 'Tuition',
            'amount' => 500, 'cycle' => 'Monthly', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('student_fee_policies')->insert([
            'student_id' => $student->id, 'fee_structure_id' => $feeId, 'academic_year' => $year,
            'discount_type' => 'percent', 'discount_value' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->delete('/fees/destroy-group', ['class_id' => $class->id, 'academic_year' => $year])
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('fee_structures')->count());
        $this->assertSame(0, DB::table('student_fee_policies')->count());
    }

    public function test_discount_policy_rejects_fees_from_another_class_and_percentages_over_100(): void
    {
        $mine    = $this->makeClass('Class 1', 'A');
        $other   = $this->makeClass('Class 2', 'A');
        $student = $this->makeStudent($mine);
        $year    = (string) $mine->academic_year;

        $row = fn ($classId) => DB::table('fee_structures')->insertGetId([
            'class_id' => $classId, 'academic_year' => $year, 'fee_name' => 'Tuition',
            'amount' => 500, 'cycle' => 'Monthly', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ownFee     = $row($mine->id);
        $foreignFee = $row($other->id);

        $save = fn (int $feeId, string $type, float $value) => $this->actingAs($this->admin())->post('/fees/policy/save', [
            'student_id' => $student->id, 'academic_year' => $year,
            'policies' => [['fee_structure_id' => $feeId, 'discount_type' => $type, 'discount_value' => $value]],
        ]);

        $save($foreignFee, 'percent', 10)->assertSessionHas('error');
        $save($ownFee, 'percent', 150)->assertSessionHas('error');
        $this->assertSame(0, DB::table('student_fee_policies')->count());

        $save($ownFee, 'percent', 10)->assertSessionHas('success');
        $this->assertSame(1, DB::table('student_fee_policies')->count());
    }
}
