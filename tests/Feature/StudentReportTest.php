<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentRegistry;
use App\Services\StudentReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class StudentReportTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private SchoolClass $six;
    private SchoolClass $seven;
    private string $year;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->six = $this->makeClass('Class 6', 'A');
        $this->seven = $this->makeClass('Class 7', 'A');
        $this->year = (string) $this->six->academic_year;
    }

    private function kid(SchoolClass $class, string $gender = 'Male', ?string $brn = null, string $name = 'Rafi'): Student
    {
        $this->n++;

        return app(StudentRegistry::class)->admit([
            'name_en' => $name . ' ' . chr(64 + $this->n) . 'ahman', 'dob' => now()->subYears(12)->addDays($this->n)->toDateString(), 'gender' => $gender,
            'religion' => $this->n % 2 ? 'Islam' : 'Hindu', 'guardian_mobile' => '0174' . str_pad((string) $this->n, 7, '0', STR_PAD_LEFT),
            'class_id' => $class->id, 'roll_number' => (string) $this->n, 'brn' => $brn,
        ], $this->admin);
    }

    /** the content of a downloaded file (exports are files, not in-memory responses) */
    private function body($response): string
    {
        return (string) file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    private function userWith(array $abilities): User
    {
        $slug = 'rp_' . substr(md5(json_encode($abilities)), 0, 8);
        Role::firstOrCreate(['slug' => $slug], ['name' => $slug, 'abilities' => $abilities, 'is_system' => false]);

        return $this->makeUser($slug);
    }

    public function test_report_filters_by_class_gender_and_status(): void
    {
        $this->kid($this->six, 'Male');
        $this->kid($this->six, 'Female');
        $this->kid($this->seven, 'Female');

        $get = fn (array $q) => $this->actingAs($this->admin)->get(route('sm.reports.index', ['session' => $this->year] + $q));

        $get([])->assertInertia(fn (Assert $p) => $p->component('StudentModule/StudentReport')->where('rows.total', 3));
        $get(['class_name' => 'Class 6'])->assertInertia(fn (Assert $p) => $p->where('rows.total', 2));
        $get(['class_name' => 'Class 6', 'gender' => 'Female'])->assertInertia(fn (Assert $p) => $p->where('rows.total', 1));
        $get(['status' => 'transferred'])->assertInertia(fn (Assert $p) => $p->where('rows.total', 0));
        $get(['gender' => 'bogus'])->assertInertia(fn (Assert $p) => $p->where('rows.total', 3)); // an invalid filter is ignored, not an error
    }

    public function test_only_chosen_columns_come_back_and_unknown_ones_are_ignored(): void
    {
        $this->kid($this->six);

        $this->actingAs($this->admin)->get(route('sm.reports.index', ['session' => $this->year, 'columns' => ['name_en', 'roll', 'password', 'x']]))
            ->assertInertia(fn (Assert $p) => $p->where('columns', ['name_en', 'roll'])->has('rows.data.0', fn (Assert $r) => $r->has('id')->has('name_en')->has('roll')->missing('code')));
    }

    public function test_brn_is_masked_without_the_permission(): void
    {
        $this->kid($this->six, 'Male', '20261234567890123');
        $viewer = $this->userWith(['students.reports']);
        $full = $this->userWith(['students.reports', 'students.view_full_brn']);

        $q = ['session' => $this->year, 'columns' => ['brn']];

        $masked = $this->actingAs($viewer)->get(route('sm.reports.index', $q))->viewData('page')['props']['rows']['data'][0]['brn'];
        $this->assertNotSame('20261234567890123', $masked);
        $this->assertStringContainsString('•', $masked);
        $this->assertStringEndsWith('123', $masked);

        $this->actingAs($full)->get(route('sm.reports.index', $q))->assertInertia(fn (Assert $p) => $p->where('rows.data.0.brn', '20261234567890123'));

        // the export obeys the same rule
        $csv = $this->actingAs($viewer)->get(route('sm.reports.export', $q + ['format' => 'csv']))->getContent();
        $this->assertStringNotContainsString('20261234567890123', $csv);
    }

    public function test_scoped_user_sees_only_their_classes(): void
    {
        $this->kid($this->six);
        $this->kid($this->seven);
        $teacher = $this->userWith(['students.reports:own']);
        $this->six->assignTeacher($teacher);

        $this->actingAs($teacher)->get(route('sm.reports.index', ['session' => $this->year]))->assertInertia(fn (Assert $p) => $p->where('rows.total', 1));

        $csv = $this->body($this->actingAs($teacher)->get(route('sm.reports.export', ['session' => $this->year, 'format' => 'csv', 'columns' => ['code']])));
        $this->assertCount(2, array_filter(preg_split('/\r?\n/', $csv)));   // the heading and one student
    }

    public function test_pages_need_the_permission(): void
    {
        $nobody = $this->userWith([]);

        foreach (['sm.reports.index', 'sm.reports.print', 'sm.reports.export', 'sm.statistics.index', 'sm.statistics.print'] as $name) {
            $this->actingAs($nobody)->get(route($name))->assertForbidden();
        }
    }

    public function test_export_is_formula_safe_and_logged(): void
    {
        $this->kid($this->six, 'Male', null, '=HYPERLINK("http://evil")');

        $csv = $this->actingAs($this->admin)->get(route('sm.reports.export', ['session' => $this->year, 'format' => 'csv', 'columns' => ['name_en']]))->getContent();

        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
        $this->assertStringNotContainsString("\n\"=HYPERLINK", $csv);
        $this->assertSame(1, AuditLog::where('event', 'report_exported')->count());

        $x = $this->actingAs($this->admin)->get(route('sm.reports.export', ['session' => $this->year]));
        $x->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertSame('PK', substr($this->body($x), 0, 2));
    }

    public function test_print_view_lists_the_students(): void
    {
        $s = $this->kid($this->six);

        $this->actingAs($this->admin)->get(route('sm.reports.print', ['session' => $this->year]))->assertOk()->assertSee($s->student_name)->assertSee('Student Report');
        // the print toolbar carries the chosen columns (an array) back as hidden fields
        $this->actingAs($this->admin)->get(route('sm.reports.print', ['session' => $this->year, 'columns' => ['name_en', 'roll']]))->assertOk()->assertSee('name="columns[]"', false);
        $this->actingAs($this->admin)->get(route('sm.statistics.print', ['session' => $this->year]))->assertOk()->assertSee('Student Statistics');
    }

    public function test_statistics_numbers(): void
    {
        $a = $this->kid($this->six, 'Male');
        $this->kid($this->six, 'Female', '20269999999999999');
        $this->kid($this->seven, 'Female');
        $gone = $this->kid($this->seven, 'Male');
        $gone->forceFill(['status' => 'transferred'])->save();

        $s = app(StudentReports::class)->statistics($this->year, $this->admin, true);

        $this->assertSame(4, $s['total']);
        $this->assertSame(3, $s['active']);
        $this->assertSame(1, $s['by_status']['transferred']);
        $this->assertSame(['Male' => 1, 'Female' => 2], $s['gender']);
        $this->assertSame(3, $s['new']);   // every child is in their first session
        $this->assertSame(0, $s['old']);
        $this->assertSame(2, $s['brn_missing']);
        $this->assertSame(['Class 6', 'Class 7'], array_column($s['classes'], 'class'));
        $this->assertSame([2, 1], array_column($s['classes'], 'total'));

        $this->actingAs($this->admin)->get(route('sm.statistics.index', ['session' => $this->year]))
            ->assertInertia(fn (Assert $p) => $p->component('StudentModule/StudentStatistics')->where('stats.active', 3));
    }

    public function test_continuing_students_are_counted_as_old(): void
    {
        $kid = $this->kid($this->six);
        $next = $this->makeClass('Class 7', 'B', (int) $this->year + 1);
        app(StudentRegistry::class)->enroll($kid->profile, ['class_id' => $next->id, 'roll_number' => '1', 'promoted_from_id' => $kid->id]);

        $s = app(StudentReports::class)->statistics((string) ((int) $this->year + 1), $this->admin, true);

        $this->assertSame(1, $s['old']);
        $this->assertSame(0, $s['new']);
    }

    public function test_a_school_of_ordinary_size_always_sees_live_statistics(): void
    {
        $this->kid($this->six);
        $svc = app(StudentReports::class);

        $this->assertSame(1, $svc->statistics($this->year, $this->admin)['active']);
        $this->kid($this->six);
        $this->assertSame(2, $svc->statistics($this->year, $this->admin)['active']);          // the new student is counted at once
    }

    public function test_report_query_count_does_not_grow_with_rows(): void
    {
        foreach (range(1, 12) as $i) {
            $this->kid($this->six);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('sm.reports.index', ['session' => $this->year]))->assertOk();
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThan(25, $queries, "the report ran $queries queries for 12 students");
    }
}
