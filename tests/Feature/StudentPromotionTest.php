<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BackgroundTask;
use App\Models\PromotionItem;
use App\Models\PromotionRun;
use App\Models\ResultInput;
use App\Models\SchoolClass;
use App\Models\StudyGroup;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentPromotion;
use App\Services\StudentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class StudentPromotionTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private StudentPromotion $promotion;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->promotion = app(StudentPromotion::class);
    }

    private function cls(string $name, string $section, int $year, array $attrs = []): SchoolClass
    {
        return $this->makeClass($name, $section, $year, $attrs);
    }

    /** a distinct child in a class (the duplicate rules would otherwise stop look-alikes) */
    private function kid(SchoolClass $class, string $name, ?string $roll = null): Student
    {
        $this->n++;

        return app(StudentRegistry::class)->admit([
            'name_en' => $name, 'dob' => now()->subYears(11)->addDays($this->n)->toDateString(),
            'guardian_mobile' => '0171' . str_pad((string) $this->n, 7, '0', STR_PAD_LEFT), 'class_id' => $class->id, 'roll_number' => $roll,
        ], $this->admin);
    }

    private function marks(Student $s, int $math, int $english): void
    {
        foreach (['Math' => $math, 'English' => $english] as $subject => $mark) {
            ResultInput::create(['student_id' => $s->id, 'class_id' => $s->class_id, 'subject' => $subject, 'exam_type' => 'final', 'academic_year' => (string) $s->academic_year,
                'marks' => $mark, 'total_marks' => 0, 'grade' => 'A', 'gpa' => 4]);
        }
    }

    /** the usual school: Class 5 → 6 → 7 in both sessions, two sections each */
    private function school(): array
    {
        $c = [];
        foreach ([2026, 2027] as $year) {
            foreach (['Class 5', 'Class 6', 'Class 7'] as $name) {
                foreach (['A', 'B'] as $sec) {
                    $c[$year][$name][$sec] = $this->cls($name, $sec, $year);
                }
            }
        }

        return $c;
    }

    private function plan(string $class = 'Class 5', array $opts = []): array
    {
        return $this->promotion->plan('2026', '2027', $class, $opts);
    }

    private function entry(array $plan, string $fromLabel, string $name): array
    {
        $class = collect($plan['classes'])->firstWhere('from', $fromLabel);

        return collect($class['students'])->firstWhere('name', $name);
    }

    // ------------------------------------------------------------------
    // the plan
    // ------------------------------------------------------------------

    public function test_each_section_moves_to_the_same_section_of_the_next_class(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 5']['A'], 'Aaliya', '1');
        $this->kid($c[2026]['Class 5']['B'], 'Bashir', '1');

        $plan = $this->plan();

        $this->assertSame(['Class 5 A', 'Class 5 B'], array_column($plan['classes'], 'from'));
        $this->assertSame(['Class 6 A', 'Class 6 B'], array_column($plan['classes'], 'to'));
        $this->assertSame($c[2027]['Class 6']['A']->id, $plan['classes'][0]['to_class_id']);
        $this->assertSame(2, $plan['totals']['promote']);
    }

    public function test_class_order_is_natural_so_class_2_comes_before_class_10(): void
    {
        $this->assertSame(['Class 2', 'Class 9', 'Class 10'], $this->promotion->classOrder(['Class 10', 'Class 2', 'Class 9']));

        config(['student.class_order' => ['Nursery', 'KG', 'Class 1']]);
        $this->assertSame(['Nursery', 'KG', 'Class 1'], $this->promotion->classOrder(['Class 1', 'KG', 'Nursery']));
    }

    public function test_sequential_rolls_follow_the_old_roll_order_and_skip_rolls_already_taken(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 5']['A'], 'Third', '30');
        $this->kid($c[2026]['Class 5']['A'], 'First', '4');
        $this->kid($c[2026]['Class 5']['A'], 'Second', '12');
        $this->kid($c[2027]['Class 6']['A'], 'Already There', '1');                 // roll 1 is taken in the new class

        $plan = $this->plan('Class 5');
        $rolls = collect($plan['classes'][0]['students'])->pluck('new_roll', 'name')->all();

        $this->assertEquals(['First' => '2', 'Second' => '3', 'Third' => '4'], $rolls);                // (the list is in entry order; the rolls are what matter)
    }

    public function test_keep_mode_keeps_the_old_roll_unless_it_is_taken(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 5']['A'], 'Keeps', '7');
        $this->kid($c[2026]['Class 5']['A'], 'Clashes', '9');
        $this->kid($c[2027]['Class 6']['A'], 'Already There', '9');

        $rolls = collect($this->plan('Class 5', ['roll_mode' => 'keep'])['classes'][0]['students'])->pluck('new_roll', 'name')->all();

        $this->assertSame('7', $rolls['Keeps']);
        $this->assertSame('1', $rolls['Clashes']);                                  // 9 is taken, so the next free roll
    }

    public function test_result_mode_gives_roll_1_to_the_top_of_the_final_examination(): void
    {
        $c = $this->school();
        $low = $this->kid($c[2026]['Class 5']['A'], 'Low Marks', '1');
        $top = $this->kid($c[2026]['Class 5']['A'], 'Top Marks', '2');
        $mid = $this->kid($c[2026]['Class 5']['A'], 'Mid Marks', '3');
        $this->marks($low, 50, 50);
        $this->marks($top, 95, 95);
        $this->marks($mid, 70, 70);

        $rolls = collect($this->plan('Class 5', ['roll_mode' => 'result'])['classes'][0]['students'])->pluck('new_roll', 'name')->all();

        $this->assertEquals(['Top Marks' => '1', 'Mid Marks' => '2', 'Low Marks' => '3'], $rolls);
    }

    public function test_students_who_are_not_active_or_already_promoted_are_left_out_with_a_reason(): void
    {
        $c = $this->school();
        $ok = $this->kid($c[2026]['Class 5']['A'], 'Active Kid', '1');
        $this->kid($c[2026]['Class 5']['A'], 'Left School', '2')->update(['status' => 'transferred']);
        $this->kid($c[2026]['Class 5']['A'], 'Dropped Out', '3')->update(['status' => 'dropped']);
        $this->kid($c[2026]['Class 5']['A'], 'Suspended Kid', '4')->update(['status' => 'suspended']);
        $done = $this->kid($c[2026]['Class 5']['A'], 'Done Already', '5');
        app(StudentRegistry::class)->enroll($done->profile, ['class_id' => $c[2027]['Class 6']['A']->id, 'roll_number' => '8']);

        $plan = $this->plan();

        $this->assertSame('promote', $this->entry($plan, 'Class 5 A', 'Active Kid')['action']);
        $this->assertStringContainsString('Transferred out', $this->entry($plan, 'Class 5 A', 'Left School')['reason']);
        $this->assertStringContainsString('Dropped out', $this->entry($plan, 'Class 5 A', 'Dropped Out')['reason']);
        $this->assertStringContainsString('Suspended', $this->entry($plan, 'Class 5 A', 'Suspended Kid')['reason']);
        $this->assertStringContainsString('Already enrolled for 2027', $this->entry($plan, 'Class 5 A', 'Done Already')['reason']);
        $this->assertSame(1, $plan['totals']['promote']);
        $this->assertSame(4, $plan['totals']['skipped']);
        $this->assertNotNull($ok);
    }

    public function test_failed_students_are_held_back_only_when_asked(): void
    {
        $c = $this->school();
        $pass = $this->kid($c[2026]['Class 5']['A'], 'Passer', '1');
        $fail = $this->kid($c[2026]['Class 5']['A'], 'Failer', '2');
        $this->marks($pass, 80, 80);
        $this->marks($fail, 10, 10);

        $this->assertSame('promote', $this->entry($this->plan(), 'Class 5 A', 'Failer')['action']);                     // default: everyone active

        $held = $this->plan('Class 5', ['exclude_failed' => true]);
        $this->assertSame('skipped', $this->entry($held, 'Class 5 A', 'Failer')['action']);
        $this->assertStringContainsString('Failed', $this->entry($held, 'Class 5 A', 'Failer')['reason']);
        $this->assertSame('promote', $this->entry($held, 'Class 5 A', 'Passer')['action']);
    }

    public function test_the_final_class_has_no_next_class_unless_graduating_is_chosen(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 7']['A'], 'Last Year Kid', '1');

        $plan = $this->plan('Class 7');
        $this->assertTrue($plan['classes'][0]['final_class']);
        $this->assertSame('skipped', $this->entry($plan, 'Class 7 A', 'Last Year Kid')['action']);

        $grad = $this->plan('Class 7', ['graduate_last' => true]);
        $this->assertSame('graduate', $this->entry($grad, 'Class 7 A', 'Last Year Kid')['action']);
    }

    public function test_a_missing_target_class_blocks_that_section_with_a_clear_message(): void
    {
        $this->cls('Class 5', 'A', 2026);
        $this->cls('Class 6', 'A', 2026);
        $this->cls('Class 6', 'A', 2027);                                  // there is no Class 6 B in 2027, and no Class 7 for the 2026 Class 6
        $b = $this->cls('Class 5', 'B', 2026);
        $this->cls('Class 6', 'B', 2026);
        $this->kid($b, 'Stranded', '1');

        $plan = $this->plan('Class 5');
        $blocked = collect($plan['classes'])->firstWhere('from', 'Class 5 B');

        $this->assertNull($blocked['to_class_id']);
        $this->assertStringContainsString('No Class 6 section B in 2027', $blocked['blocked']);
        $this->assertSame('blocked', $blocked['students'][0]['action']);
        $this->assertSame(1, $plan['totals']['blocked']);
    }

    public function test_study_groups_map_to_the_same_group_and_an_ungrouped_class_must_be_placed_by_hand(): void
    {
        $science = StudyGroup::create(['name' => 'Science']);
        $arts = StudyGroup::create(['name' => 'Arts']);

        $this->cls('Class 8', 'A', 2026);
        $this->cls('Class 9', 'A', 2026, ['study_group_id' => $science->id]);
        $this->cls('Class 9', 'A', 2026, ['study_group_id' => $arts->id]);
        $this->cls('Class 10', 'A', 2026, ['study_group_id' => $science->id]);
        $this->cls('Class 8', 'A', 2027);
        $this->cls('Class 9', 'A', 2027, ['study_group_id' => $science->id]);
        $this->cls('Class 9', 'A', 2027, ['study_group_id' => $arts->id]);
        $scienceTen = $this->cls('Class 10', 'A', 2027, ['study_group_id' => $science->id]);
        $this->cls('Class 10', 'A', 2027, ['study_group_id' => $arts->id]);

        $nine = SchoolClass::where('academic_year', '2026')->where('class_name', 'Class 9')->where('study_group_id', $science->id)->first();
        $this->kid($nine, 'Science Kid', '1');
        $eight = SchoolClass::where('academic_year', '2026')->where('class_name', 'Class 8')->first();
        $this->kid($eight, 'Eight Kid', '1');

        $promoted = collect($this->promotion->plan('2026', '2027', 'Class 9')['classes'])->firstWhere('from', 'Class 9 A (Science)');
        $this->assertSame($scienceTen->id, $promoted['to_class_id']);                                      // Science → Science

        $ungrouped = collect($this->promotion->plan('2026', '2027', 'Class 8')['classes'])->first();
        $this->assertNull($ungrouped['to_class_id']);
        $this->assertStringContainsString('Manual promotion', $ungrouped['blocked']);
    }

    // ------------------------------------------------------------------
    // running it
    // ------------------------------------------------------------------

    public function test_a_run_adds_enrollments_for_the_new_session_and_leaves_the_old_ones_alone(): void
    {
        $c = $this->school();
        $a = $this->kid($c[2026]['Class 5']['A'], 'Aaliya', '5');
        $b = $this->kid($c[2026]['Class 5']['B'], 'Bashir', '3');

        $run = $this->promotion->execute($this->plan('Class 5'), $this->admin);

        $new = Student::where('academic_year', 2027)->orderBy('id')->get();
        $this->assertCount(2, $new);

        $na = $new->firstWhere('profile_id', $a->profile_id);
        $this->assertSame($c[2027]['Class 6']['A']->id, $na->class_id);
        $this->assertSame('1', $na->roll_number);
        $this->assertSame($a->id, $na->promoted_from_id);
        $this->assertSame($a->student_id, $na->student_id);                                   // the same permanent code
        $this->assertSame('Aaliya', $na->student_name);

        // the old enrollment is exactly as it was
        $old = $a->fresh();
        $this->assertSame([$c[2026]['Class 5']['A']->id, '5', 'active', 2026], [$old->class_id, $old->roll_number, $old->status, (int) $old->academic_year]);

        $this->assertSame([2, 0, 0], [$run->promoted, $run->skipped, $run->graduated]);
        $this->assertSame($this->admin->id, $run->user_id);
        $this->assertSame(['2026', '2027', 'auto'], [$run->from_session, $run->to_session, $run->mode]);
        $this->assertSame(2, PromotionItem::where('run_id', $run->id)->where('action', 'promoted')->count());
        $this->assertNotNull(PromotionItem::where('run_id', $run->id)->first()->to_enrollment_id);

        $log = AuditLog::forRecord($run)->where('event', 'promotion')->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(2, $log->changes['promoted']);
        $this->assertNotNull($b);
    }

    public function test_running_the_same_promotion_twice_changes_nothing_the_second_time(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 5']['A'], 'Aaliya', '1');
        $this->kid($c[2026]['Class 5']['A'], 'Bashir', '2');

        $this->promotion->execute($this->plan('Class 5'), $this->admin);
        $after = Student::count();

        $second = $this->promotion->execute($this->plan('Class 5'), $this->admin);

        $this->assertSame($after, Student::count());                                         // nobody was enrolled twice
        $this->assertSame([0, 2], [$second->promoted, $second->skipped]);
        $this->assertSame(2, Student::where('academic_year', 2027)->count());

        // and a stale plan carried out again is also harmless
        $stale = $this->plan('Class 5');
        $this->promotion->execute($stale, $this->admin);
        $this->promotion->execute($stale, $this->admin);
        $this->assertSame(2, Student::where('academic_year', 2027)->count());
    }

    public function test_the_database_itself_refuses_a_second_enrollment_in_a_session(): void
    {
        $c = $this->school();
        $kid = $this->kid($c[2026]['Class 5']['A'], 'Aaliya', '1');
        $this->promotion->execute($this->plan('Class 5'), $this->admin);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Student::create(['profile_id' => $kid->profile_id, 'student_id' => $kid->student_id, 'student_name' => 'Aaliya', 'academic_year' => 2027,
            'class_id' => $c[2027]['Class 6']['B']->id, 'status' => 'active', 'admission_date' => now()->toDateString()]);
    }

    public function test_a_roll_taken_after_the_preview_is_simply_skipped_over(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 5']['A'], 'First', '1');
        $this->kid($c[2026]['Class 5']['A'], 'Second', '2');
        $plan = $this->plan('Class 5');

        // after the preview, someone takes the roll the second student was going to get: the run works from live data
        $this->kid($c[2027]['Class 6']['A'], 'Late Arrival', '2');

        $this->promotion->execute($plan, $this->admin);

        $rolls = Student::where('class_id', $c[2027]['Class 6']['A']->id)->pluck('roll_number')->all();
        $this->assertCount(3, $rolls);
        $this->assertSame($rolls, array_values(array_unique($rolls)));                          // nobody shares a roll
    }

    public function test_if_anything_fails_nobody_is_promoted(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 5']['A'], 'First', '1');
        $this->kid($c[2026]['Class 5']['A'], 'Second', '2');
        $plan = $this->plan('Class 5');

        // a student of another class already holds the code the first student would take in 2027: the run fails part-way
        $first = Student::where('student_name', 'First')->firstOrFail();
        $other = $this->kid($c[2027]['Class 6']['B'], 'Other', '9');
        DB::table('students')->where('id', $other->id)->update(['student_id' => $first->student_id]);

        try {
            $this->promotion->execute($plan, $this->admin);
            $this->fail('the run should have failed');
        } catch (ValidationException) {
        }

        $this->assertSame(0, PromotionRun::count());                                           // the run left no trace
        $this->assertSame(1, Student::where('academic_year', 2027)->count());                  // only the one that was already there: nobody was moved
    }

    public function test_the_final_class_can_be_marked_passed_out(): void
    {
        $c = $this->school();
        $kid = $this->kid($c[2026]['Class 7']['A'], 'Last Year Kid', '1');

        $run = $this->promotion->execute($this->plan('Class 7', ['graduate_last' => true]), $this->admin);

        $kid->refresh();
        $this->assertSame('passed_out', $kid->status);
        $this->assertSame(now()->toDateString(), $kid->status_changed_on->toDateString());
        $this->assertSame(1, $run->graduated);
        $this->assertSame(0, Student::where('academic_year', 2027)->count());                  // nobody to enroll
    }

    public function test_all_classes_can_be_promoted_in_one_run(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 5']['A'], 'Five A', '1');
        $this->kid($c[2026]['Class 5']['B'], 'Five B', '1');
        $this->kid($c[2026]['Class 6']['A'], 'Six A', '1');
        $this->kid($c[2026]['Class 7']['A'], 'Seven A', '1');                                  // the final class: stays

        $run = $this->promotion->execute($this->promotion->plan('2026', '2027', null), $this->admin);

        $this->assertSame(3, $run->promoted);
        $this->assertSame(1, $run->skipped);
        $this->assertSame(['Class 6', 'Class 6', 'Class 7'], Student::where('academic_year', 2027)->with('schoolClass')->get()->map(fn ($s) => $s->schoolClass->class_name)->sort()->values()->all());
    }

    // ------------------------------------------------------------------
    // manual
    // ------------------------------------------------------------------

    public function test_manual_decisions_promote_repeat_and_drop_in_one_run(): void
    {
        $c = $this->school();
        $go = $this->kid($c[2026]['Class 5']['A'], 'Goes Up', '1');
        $stay = $this->kid($c[2026]['Class 5']['A'], 'Stays Back', '2');
        $leave = $this->kid($c[2026]['Class 5']['A'], 'Leaves', '3');

        $run = $this->promotion->applyManual('2026', '2027', [
            ['enrollment_id' => $go->id, 'action' => 'promote', 'class_id' => $c[2027]['Class 6']['B']->id, 'roll_number' => '12'],
            ['enrollment_id' => $stay->id, 'action' => 'repeat'],
            ['enrollment_id' => $leave->id, 'action' => 'drop'],
        ], $this->admin);

        $this->assertSame([1, 1, 1], [$run->promoted, $run->repeated, $run->dropped]);

        $up = Student::where('profile_id', $go->profile_id)->where('academic_year', 2027)->first();
        $this->assertSame([$c[2027]['Class 6']['B']->id, '12'], [$up->class_id, $up->roll_number]);

        $again = Student::where('profile_id', $stay->profile_id)->where('academic_year', 2027)->first();
        $this->assertSame($c[2027]['Class 5']['A']->id, $again->class_id);                       // the same class, in the new session

        $this->assertSame('dropped', $leave->fresh()->status);
        $this->assertNull(Student::where('profile_id', $leave->profile_id)->where('academic_year', 2027)->first());
        $this->assertEqualsCanonicalizing(['promoted', 'repeated', 'dropped'], PromotionItem::where('run_id', $run->id)->pluck('action')->all());
    }

    public function test_one_bad_decision_cancels_the_whole_manual_run(): void
    {
        $c = $this->school();
        $a = $this->kid($c[2026]['Class 5']['A'], 'Good Decision', '1');
        $b = $this->kid($c[2026]['Class 5']['A'], 'Bad Decision', '2');
        $this->kid($c[2027]['Class 6']['A'], 'Holds Roll', '9');

        try {
            $this->promotion->applyManual('2026', '2027', [
                ['enrollment_id' => $a->id, 'action' => 'promote', 'class_id' => $c[2027]['Class 6']['A']->id, 'roll_number' => '1'],
                ['enrollment_id' => $b->id, 'action' => 'promote', 'class_id' => $c[2027]['Class 6']['A']->id, 'roll_number' => '9'],     // taken
            ], $this->admin);
            $this->fail('the clash should have stopped the run');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Bad Decision', $e->errors()['decisions'][0]);
            $this->assertStringContainsString('Roll 9', $e->errors()['decisions'][0]);
        }

        $this->assertSame(1, Student::where('academic_year', 2027)->count());                  // the good decision was undone as well
        $this->assertSame(0, PromotionRun::count());
    }

    public function test_manual_decisions_are_validated(): void
    {
        $c = $this->school();
        $a = $this->kid($c[2026]['Class 5']['A'], 'Aaliya', '1');
        $left = $this->kid($c[2026]['Class 5']['A'], 'Left', '2');
        $left->update(['status' => 'transferred']);
        $next = $this->kid($c[2027]['Class 6']['A'], 'Wrong Session', '5');

        $attempt = function (array $decisions) {
            try {
                $this->promotion->applyManual('2026', '2027', $decisions, $this->admin);
                return null;
            } catch (ValidationException $e) {
                return $e->errors()['decisions'][0];
            }
        };

        $this->assertStringContainsString('at least one', $attempt([]));
        $this->assertStringContainsString('twice', $attempt([['enrollment_id' => $a->id, 'action' => 'drop'], ['enrollment_id' => $a->id, 'action' => 'drop']]));
        $this->assertStringContainsString('not enrolled for 2026', $attempt([['enrollment_id' => $next->id, 'action' => 'drop']]));
        $this->assertStringContainsString('transferred out', strtolower($attempt([['enrollment_id' => $left->id, 'action' => 'drop']])));
        $this->assertStringContainsString('choose a class', $attempt([['enrollment_id' => $a->id, 'action' => 'promote']]));
        $this->assertStringContainsString('choose a class', $attempt([['enrollment_id' => $a->id, 'action' => 'promote', 'class_id' => $c[2026]['Class 6']['A']->id]]));    // a class of the wrong session
        $this->assertStringContainsString('unknown action', $attempt([['enrollment_id' => $a->id, 'action' => 'teleport']]));

        $this->assertSame('active', $a->fresh()->status);
    }

    // ------------------------------------------------------------------
    // over HTTP
    // ------------------------------------------------------------------

    public function test_only_people_with_the_promotion_permission_can_use_it(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);

        foreach (['/student-module/promotion/auto', '/student-module/promotion/manual'] as $url) {
            $this->actingAs($teacher)->get($url)->assertForbidden();
        }
        $this->actingAs($teacher)->post('/student-module/promotion/auto', ['from' => '2026', 'to' => '2027', 'roll_mode' => 'sequential'])->assertForbidden();
        $this->actingAs($teacher)->post('/student-module/promotion/manual', ['from' => '2026', 'to' => '2027'])->assertForbidden();
    }

    public function test_the_preview_page_shows_the_plan_and_changes_nothing(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 5']['A'], 'Aaliya', '1');

        $props = $this->actingAs($this->admin)->get('/student-module/promotion/auto?from=2026&to=2027&class_name=Class 5&preview=1')->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $props['plan']['totals']['promote']);
        $this->assertSame('Class 6 A', $props['plan']['classes'][0]['to']);
        $this->assertContains('Class 5', $props['classNames']);
        $this->assertSame(0, Student::where('academic_year', 2027)->count());
        $this->assertSame(0, PromotionRun::count());
    }

    public function test_confirming_runs_the_promotion_and_records_it(): void
    {
        $c = $this->school();
        $this->kid($c[2026]['Class 5']['A'], 'Aaliya', '1');
        $this->kid($c[2026]['Class 5']['A'], 'Bashir', '2');

        $this->actingAs($this->admin)->post('/student-module/promotion/auto', ['from' => '2026', 'to' => '2027', 'class_name' => 'Class 5', 'roll_mode' => 'sequential'])
            ->assertRedirect()->assertSessionHas('task');

        $task = BackgroundTask::where('type', 'promotion')->firstOrFail();
        $this->assertSame('done', $task->status);
        $this->assertSame(2, $task->summary['promoted']);
        $this->assertSame(2, Student::where('academic_year', 2027)->count());

        $run = PromotionRun::findOrFail($task->summary['run_id']);
        $this->assertSame($task->id, $run->task_id);

        // pressing confirm again does nothing more
        $this->actingAs($this->admin)->post('/student-module/promotion/auto', ['from' => '2026', 'to' => '2027', 'class_name' => 'Class 5', 'roll_mode' => 'sequential'])
            ->assertSessionHas('error');
        $this->assertSame(2, Student::where('academic_year', 2027)->count());
    }

    public function test_the_promotion_choices_are_validated(): void
    {
        $post = fn (array $d) => $this->actingAs($this->admin)->post('/student-module/promotion/auto', $d + ['roll_mode' => 'sequential']);

        $post(['from' => '2027', 'to' => '2026'])->assertSessionHasErrors('to');                   // backwards
        $post(['from' => '2026', 'to' => '2026'])->assertSessionHasErrors('to');
        $post(['from' => '26', 'to' => '2027'])->assertSessionHasErrors('from');
        $post(['from' => '2026', 'to' => '2027', 'roll_mode' => 'random'])->assertSessionHasErrors('roll_mode');
        $this->assertSame(0, BackgroundTask::count());
    }

    public function test_the_new_sessions_classes_can_be_created_from_the_old_ones(): void
    {
        $this->cls('Class 5', 'A', 2026);
        $this->cls('Class 6', 'A', 2026);

        $this->actingAs($this->admin)->get('/student-module/promotion/auto?from=2026&to=2027&preview=1')->assertOk()
            ->assertViewHas('page', fn ($page) => $page['props']['targetHasClasses'] === false && $page['props']['plan'] === null);

        $this->actingAs($this->admin)->post('/student-module/promotion/auto/prepare', ['from' => '2026', 'to' => '2027'])->assertRedirect();

        $this->assertSame(2, SchoolClass::where('academic_year', '2027')->count());
    }

    public function test_manual_promotion_over_http(): void
    {
        $c = $this->school();
        $a = $this->kid($c[2026]['Class 5']['A'], 'Aaliya', '1');

        $page = $this->actingAs($this->admin)->get("/student-module/promotion/manual?from=2026&to=2027&class_id={$c[2026]['Class 5']['A']->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['Aaliya'], collect($page['students'])->pluck('name')->all());
        $this->assertCount(6, $page['targets']);

        $this->actingAs($this->admin)->post('/student-module/promotion/manual', [
            'from' => '2026', 'to' => '2027', 'decisions' => [['enrollment_id' => $a->id, 'action' => 'promote', 'class_id' => $c[2027]['Class 6']['A']->id, 'roll_number' => '4']],
        ])->assertSessionHas('success', fn ($m) => str_contains($m, '1 promoted'));

        $this->assertSame('4', Student::where('academic_year', 2027)->firstOrFail()->roll_number);

        $this->actingAs($this->admin)->post('/student-module/promotion/manual', ['from' => '2026', 'to' => '2027', 'decisions' => [['enrollment_id' => $a->id, 'action' => 'fly']]])
            ->assertSessionHasErrors('decisions.0.action');
        $this->actingAs($this->admin)->post('/student-module/promotion/manual', ['from' => '2026', 'to' => '2027', 'decisions' => []])->assertSessionHasErrors('decisions');
    }
}
