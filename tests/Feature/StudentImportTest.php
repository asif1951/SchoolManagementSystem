<?php

namespace Tests\Feature;

use App\Models\BackgroundTask;
use App\Models\SchoolClass;
use App\Models\Shift;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\StudentImporter;
use App\Services\StudentRegistry;
use App\Support\Spreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;
use ZipArchive;

class StudentImportTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private SchoolClass $class;
    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->class = $this->makeClass('Class 6', 'A');
        $this->session = (string) $this->class->academic_year;
    }

    /** one row in the template's column order, with the named columns overridden */
    private function row(array $over = []): array
    {
        $base = array_fill_keys(StudentImporter::COLUMNS, '');
        $base = array_merge($base, ['class' => 'Class 6', 'section' => 'A', 'session' => $this->session], $over);

        return array_map(fn ($c) => $base[$c], StudentImporter::COLUMNS);
    }

    private function file(array $rows, string $name = 'students.xlsx', ?array $header = null): UploadedFile
    {
        $table = array_merge([$header ?? StudentImporter::COLUMNS], $rows);

        return UploadedFile::fake()->createWithContent($name, str_ends_with($name, '.csv') ? Spreadsheet::csv($table) : Spreadsheet::xlsx($table));
    }

    /** uploaded files still on disk (the CSV reports of refused rows are results, not uploads) */
    private function uploadsLeft(): array
    {
        return array_values(array_filter(Storage::disk('local')->allFiles(), fn ($f) => ! str_ends_with($f, '.csv')));
    }

    private function upload(array $rows, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post('/student-module/import', ['file' => $this->file($rows), 'session' => $this->session] + $extra);
    }

    private function preview(array $rows): StudentImport
    {
        $this->upload($rows)->assertSessionHasNoErrors();

        return StudentImport::latest('id')->firstOrFail();
    }

    private function good(int $n): array
    {
        $names = ['Aaliya', 'Bashir', 'Chandra', 'Dilruba', 'Emon', 'Farhana', 'Golam', 'Habiba', 'Imran', 'Jahid', 'Kabir', 'Laila'];

        return $this->row([
            'name_en' => $names[$n % count($names)] . ' ' . chr(65 + intdiv($n, count($names))) . 'han', 'gender' => 'Male',
            'dob' => now()->subYears(11)->addDays($n)->toDateString(), 'guardian_mobile' => '0171' . str_pad((string) ($n + 1), 7, '0', STR_PAD_LEFT), 'roll_number' => (string) ($n + 1),
        ]);
    }

    // ------------------------------------------------------------------
    // the spreadsheet reader / writer
    // ------------------------------------------------------------------

    public function test_xlsx_and_csv_round_trip_including_bangla_and_awkward_characters(): void
    {
        $rows = [['name', 'note'], ['রহিম উদ্দিন', 'a < b & "c" > d'], ['Karim', "line one\nline two"], ['', '0017']];

        foreach (['xlsx' => Spreadsheet::xlsx($rows), 'csv' => Spreadsheet::csv($rows)] as $ext => $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'rt') . ".{$ext}";
            file_put_contents($path, $bytes);

            $this->assertSame($rows, Spreadsheet::read($path), $ext);
            @unlink($path);
        }
    }

    public function test_formulas_are_defanged_when_writing(): void
    {
        $bytes = Spreadsheet::xlsx([['=cmd|calc', '+1', '-2', '@SUM(A1)', 'safe']]);
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, $bytes);

        $this->assertSame([["'=cmd|calc", "'+1", "'-2", "'@SUM(A1)", 'safe']], Spreadsheet::read($path));
        $this->assertStringContainsString("'=1+1", Spreadsheet::csv([['=1+1']]));
        @unlink($path);
    }

    public function test_csv_variants_are_understood(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cv');

        file_put_contents($path, "\xEF\xBB\xBFname;gender\r\nRahim;Male\r\n\r\nKarim;Male\r\n");        // BOM, semicolons, a blank line
        $this->assertSame([['name', 'gender'], ['Rahim', 'Male'], ['Karim', 'Male']], Spreadsheet::read($path));

        file_put_contents($path, "name\tgender\nRahim\tMale\n");                                         // tabs
        $this->assertSame([['name', 'gender'], ['Rahim', 'Male']], Spreadsheet::read($path));
        @unlink($path);
    }

    public function test_excel_date_serials_become_dates(): void
    {
        $this->assertSame('2023-03-15', Spreadsheet::serialToDate('45000'));
        $this->assertSame('2014-05-02', Spreadsheet::serialToDate('2014-05-02'));       // already a date: untouched
        $this->assertSame('12', Spreadsheet::serialToDate('12'));                         // too small to be a date
    }

    public function test_unsafe_or_unreadable_files_are_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        $attempt = function (string $why) use ($path) {
            try {
                Spreadsheet::read($path);
                $this->fail("{$why} was accepted");
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage(), $why);
            }
        };

        // binary rubbish
        file_put_contents($path, random_bytes(2000) . chr(0));
        $attempt('binary');

        // a workbook that declares entities (the billion-laughs doorway)
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><workbook/>');
        $zip->close();
        $attempt('doctype');

        // too many rows
        file_put_contents($path, "name\n" . implode("\n", array_fill(0, Spreadsheet::MAX_ROWS + 5, 'x')));
        $attempt('too many rows');

        @unlink($path);
    }

    // ------------------------------------------------------------------
    // the flow: upload → preview → confirm
    // ------------------------------------------------------------------

    public function test_the_template_is_downloadable_and_can_be_filled_in_and_read_back(): void
    {
        foreach (['xlsx', 'csv'] as $format) {
            $r = $this->actingAs($this->admin)->get("/student-module/import/template?format={$format}")->assertOk();
            $path = tempnam(sys_get_temp_dir(), 'tp') . ".{$format}";
            file_put_contents($path, $r->getContent());

            $rows = Spreadsheet::read($path);
            $this->assertSame(StudentImporter::COLUMNS, $rows[0], $format);
            $this->assertCount(2, $rows);                                                       // header + an example
            @unlink($path);
        }
    }

    public function test_upload_shows_a_preview_and_saves_nothing(): void
    {
        $this->upload([$this->good(0), $this->good(1), $this->row(['name_en' => 'Missing Class', 'class' => 'Class 99'])])
            ->assertRedirect();

        $import = StudentImport::firstOrFail();
        $this->assertSame([3, 2, 1], [$import->total_rows, $import->valid_rows, $import->invalid_rows]);
        $this->assertSame('previewed', $import->status);

        $this->assertSame(0, Student::count());                                                  // never saved without the confirm step
        $this->assertSame(0, StudentProfile::count());

        $this->actingAs($this->admin)->get("/student-module/import/{$import->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('StudentModule/ImportPreview')->where('batch.valid', 2)->where('batch.invalid', 1)
                ->where('invalidRows.0.row', 4)                                                  // the spreadsheet's own row number
                ->where('invalidRows.0.name', 'Missing Class'));
    }

    public function test_confirming_saves_the_valid_rows_and_only_those(): void
    {
        $import = $this->preview([$this->good(0), $this->row(['name_en' => 'Nobody', 'class' => 'Nowhere']), $this->good(1)]);

        $this->actingAs($this->admin)->post("/student-module/import/{$import->id}/confirm")->assertRedirect("/student-module/import/{$import->id}");

        $this->assertSame(2, Student::count());
        $this->assertSame(2, StudentProfile::count());

        $import->refresh();
        $this->assertSame('done', $import->status);
        $this->assertSame(2, $import->summary['created']);
        $this->assertSame('done', $import->task->status);
        $this->assertSame(100, $import->task->percent);

        $s = Student::orderBy('id')->first();
        $this->assertSame($this->class->id, $s->class_id);
        $this->assertSame('1', $s->roll_number);
        $this->assertSame('active', $s->status);

        $this->assertSame([], $this->uploadsLeft());                                             // the upload is not kept (only the CSV report of refused rows may remain)
        $this->assertNull($import->path);
    }

    public function test_a_larger_file_is_saved_in_chunks(): void
    {
        $rows = array_map(fn ($i) => $this->good($i), range(0, 119));
        $import = $this->preview($rows);

        $this->assertSame(120, $import->valid_rows);
        $this->actingAs($this->admin)->post("/student-module/import/{$import->id}/confirm");

        $this->assertSame(120, Student::count());
        $this->assertSame(120, $import->fresh()->task->processed);
    }

    public function test_a_confirmed_import_cannot_be_confirmed_again(): void
    {
        $import = $this->preview([$this->good(0)]);

        $this->actingAs($this->admin)->post("/student-module/import/{$import->id}/confirm");
        $this->actingAs($this->admin)->post("/student-module/import/{$import->id}/confirm")->assertSessionHas('error');

        $this->assertSame(1, Student::count());
        $this->assertSame(1, BackgroundTask::where('type', 'import')->count());
    }

    public function test_an_import_with_nothing_valid_cannot_be_confirmed(): void
    {
        $import = $this->preview([$this->row(['name_en' => 'No Class', 'class' => 'Nowhere'])]);

        $this->actingAs($this->admin)->post("/student-module/import/{$import->id}/confirm")->assertSessionHas('error');
        $this->assertSame(0, Student::count());
    }

    public function test_cancelling_removes_the_file_and_the_import_cannot_then_be_confirmed(): void
    {
        $import = $this->preview([$this->good(0)]);
        $this->assertNotEmpty(Storage::disk('local')->allFiles());

        $this->actingAs($this->admin)->post("/student-module/import/{$import->id}/cancel")->assertRedirect('/student-module/import');

        $this->assertSame('cancelled', $import->fresh()->status);
        $this->assertSame([], $this->uploadsLeft());
        $this->actingAs($this->admin)->post("/student-module/import/{$import->id}/confirm")->assertSessionHas('error');
        $this->assertSame(0, Student::count());
    }

    public function test_the_rows_are_checked_again_when_confirmed(): void
    {
        $import = $this->preview([
            $this->row(['name_en' => 'Aaliya Khan', 'gender' => 'Female', 'brn' => '20141234567890123', 'roll_number' => '1', 'dob' => '2014-05-02']),
            $this->good(1),
        ]);

        // between the preview and the confirm someone registers a child with the same BRN
        app(StudentRegistry::class)->admit([
            'name_en' => 'Already Here', 'dob' => '2010-01-01', 'brn' => '20141234567890123', 'guardian_mobile' => '01999999999',
            'class_id' => $this->class->id, 'roll_number' => '50',
        ], $this->admin);

        $this->actingAs($this->admin)->post("/student-module/import/{$import->id}/confirm");

        $this->assertSame(2, Student::count());                                                  // the existing one + the other row
        $summary = $import->fresh()->task->summary;
        $this->assertSame(1, $summary['created']);
        $this->assertSame(1, $summary['failed_count']);
        $this->assertSame(2, $summary['errors'][0]['row']);
        $this->assertStringContainsString('already', $summary['errors'][0]['reason']);
    }

    // ------------------------------------------------------------------
    // what makes a row invalid
    // ------------------------------------------------------------------

    private function reasons(array $rows): array
    {
        $import = $this->preview($rows);

        return collect($import->invalid)->pluck('reason', 'row')->all();
    }

    public function test_every_kind_of_bad_row_is_explained_with_its_row_number(): void
    {
        $reasons = $this->reasons([
            $this->row(['name_en' => '']),                                                                                       // row 2
            $this->row(['name_en' => 'Bad Gender', 'gender' => 'robot']),                                                          // 3
            $this->row(['name_en' => 'Bad Date', 'dob' => 'next tuesday']),                                                        // 4
            $this->row(['name_en' => 'Future Child', 'dob' => now()->addYear()->toDateString()]),                                  // 5
            $this->row(['name_en' => 'Bad Phone', 'guardian_mobile' => '12345']),                                                  // 6
            $this->row(['name_en' => 'Short Brn', 'brn' => '2014123456789012']),                                                   // 7
            $this->row(['name_en' => 'Unknown Class', 'class' => 'Class 99']),                                                     // 8
            $this->row(['name_en' => 'Wrong Section', 'section' => 'Z']),                                                          // 9
            $this->row(['name_en' => 'Wrong Session', 'session' => '1999']),                                                       // 10
        ]);

        $this->assertStringContainsString('name', strtolower($reasons[2]));
        $this->assertStringContainsString('gender', $reasons[3]);
        $this->assertStringContainsString('valid date', $reasons[4]);
        $this->assertStringContainsString('future', $reasons[5]);
        $this->assertStringContainsString('mobile', $reasons[6]);
        $this->assertStringContainsString('17 digits', $reasons[7]);
        $this->assertStringContainsString('No class "Class 99 A"', $reasons[8]);       // the class and section as written
        $this->assertStringContainsString('Class 6 Z', $reasons[9]);
        $this->assertStringContainsString('session 1999', $reasons[10]);
        $this->assertCount(9, $reasons);
    }

    public function test_rows_that_clash_with_students_already_in_the_system_are_refused(): void
    {
        $existing = app(StudentRegistry::class)->admit([
            'name_en' => 'Rahim Uddin', 'dob' => '2014-05-02', 'brn' => '20141234567890123', 'guardian_mobile' => '01712345678',
            'class_id' => $this->class->id, 'roll_number' => '7',
        ], $this->admin);

        $reasons = $this->reasons([
            $this->row(['name_en' => 'Brn Twin', 'dob' => '2012-02-02', 'brn' => '20141234567890123', 'guardian_mobile' => '01911111111']),          // the BRN exists
            $this->row(['name_en' => 'Rahim Udin', 'dob' => '2014-05-02', 'guardian_mobile' => '01922222222']),                                         // a likely duplicate
            $this->row(['name_en' => 'Roll Clash', 'dob' => '2012-03-03', 'guardian_mobile' => '01933333333', 'roll_number' => '7']),                   // the roll is taken
        ]);

        $this->assertStringContainsString($existing->student_id, $reasons[2]);
        $this->assertStringContainsString('already registered', $reasons[2]);
        $this->assertStringContainsString($existing->student_id, $reasons[3]);
        $this->assertStringContainsString('Roll 7', $reasons[4]);
    }

    public function test_rows_that_repeat_each_other_inside_the_file_are_refused(): void
    {
        $reasons = $this->reasons([
            $this->row(['name_en' => 'First One', 'dob' => '2012-01-01', 'brn' => '20141234567890123', 'guardian_mobile' => '01911111111', 'roll_number' => '1']),    // 2: fine
            $this->row(['name_en' => 'Second One', 'dob' => '2012-02-02', 'brn' => '20141234567890123', 'guardian_mobile' => '01922222222', 'roll_number' => '2']),   // 3: same BRN as row 2
            $this->row(['name_en' => 'Third One', 'dob' => '2012-03-03', 'guardian_mobile' => '01933333333', 'roll_number' => '1']),                                    // 4: same roll as row 2
            $this->row(['name_en' => 'Fourth One', 'dob' => '2012-04-04', 'guardian_mobile' => '01944444444', 'roll_number' => '4']),                                   // 5: fine
            $this->row(['name_en' => 'fourth  one', 'dob' => '2012-04-04', 'guardian_mobile' => '01955555555', 'roll_number' => '5']),                                  // 6: same child as row 5
        ]);

        $this->assertSame([3, 4, 6], array_keys($reasons));
        $this->assertStringContainsString('row 2', $reasons[3]);
        $this->assertStringContainsString('Birth Registration', $reasons[3]);
        $this->assertStringContainsString('row 2', $reasons[4]);
        $this->assertStringContainsString('row 5', $reasons[6]);
    }

    public function test_a_row_is_not_blocked_by_an_earlier_row_that_was_itself_refused(): void
    {
        $reasons = $this->reasons([
            $this->row(['name_en' => 'Bad One', 'gender' => 'robot', 'brn' => '20141234567890123', 'roll_number' => '1']),                                  // refused for its gender
            $this->row(['name_en' => 'Good One', 'dob' => '2012-01-01', 'brn' => '20141234567890123', 'guardian_mobile' => '01911111111', 'roll_number' => '1']),  // so it may keep the BRN and the roll
        ]);

        $this->assertSame([2], array_keys($reasons));
    }

    public function test_a_class_in_several_shifts_needs_the_shift_named(): void
    {
        $day = Shift::where('name', 'Day')->firstOrFail();
        $this->makeClass('Class 6', 'A', null, ['shift_id' => $day->id]);

        $reasons = $this->reasons([
            $this->good(0),
            $this->row(['name_en' => 'With Shift', 'dob' => '2012-01-01', 'guardian_mobile' => '01911111111', 'roll_number' => '9', 'shift' => 'Day']),
        ]);

        $this->assertSame([2], array_keys($reasons));
        $this->assertStringContainsString('shift', $reasons[2]);
    }

    public function test_other_column_names_and_a_csv_file_work(): void
    {
        $header = ['Student Name', 'Sex', 'Date of Birth', 'Mobile', 'Class', 'Section', 'Roll No'];
        $file = UploadedFile::fake()->createWithContent('school.csv', Spreadsheet::csv([
            $header, ['Rahim Uddin', 'M', '2014-05-02', '01712345678', 'Class 6', 'A', '3'], ['Fatema Khatun', 'female', '2014-06-06', '01812345678', 'class 6', 'a', '4'],
        ]));

        $this->actingAs($this->admin)->post('/student-module/import', ['file' => $file, 'session' => $this->session])->assertSessionHasNoErrors();

        $import = StudentImport::firstOrFail();
        $this->assertSame([2, 0], [$import->valid_rows, $import->invalid_rows]);

        $this->actingAs($this->admin)->post("/student-module/import/{$import->id}/confirm");
        $this->assertEqualsCanonicalizing(['Male', 'Female'], StudentProfile::pluck('gender')->all());
        $this->assertSame(['3', '4'], Student::orderBy('id')->pluck('roll_number')->all());
    }

    // ------------------------------------------------------------------
    // the file itself, permissions, limits
    // ------------------------------------------------------------------

    public function test_a_file_that_cannot_be_used_is_refused_with_a_message(): void
    {
        $post = fn (UploadedFile $f) => $this->actingAs($this->admin)->post('/student-module/import', ['file' => $f, 'session' => $this->session]);

        // the wrong kind of file is refused at once
        $post(UploadedFile::fake()->createWithContent('students.exe', 'MZ'))->assertSessionHasErrors('file');
        $this->assertSame(0, StudentImport::count());

        // a file that looks right but cannot be read is refused by the background check, with the reason, and is not kept
        foreach ([
            UploadedFile::fake()->createWithContent('students.xlsx', random_bytes(300) . chr(0)),
            UploadedFile::fake()->createWithContent('empty.csv', "name,class\n"),                                          // a header and nothing else
            $this->file([$this->good(0)], 'ok.xlsx', ['Name', 'Gender']),                                                  // no class column
        ] as $file) {
            $post($file)->assertRedirect();
            $import = StudentImport::latest('id')->firstOrFail();

            $this->assertSame('failed', $import->status);
            $this->assertNotEmpty($import->summary['error']);
            $this->assertNull($import->path);
        }

        $this->assertSame([], $this->uploadsLeft());                                                                         // nothing left behind
    }

    public function test_only_people_with_the_import_permission_can_use_it(): void
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);

        $this->actingAs($teacher)->get('/student-module/import')->assertForbidden();
        $this->actingAs($teacher)->get('/student-module/import/template')->assertForbidden();
        $this->upload([$this->good(0)], [], $teacher)->assertForbidden();
        $this->assertSame(0, StudentImport::count());
    }

    public function test_an_import_belongs_to_the_person_who_uploaded_it(): void
    {
        $import = $this->preview([$this->good(0)]);

        \App\Models\Role::create(['slug' => 'importer2', 'name' => 'Importer', 'abilities' => ['students.import_students'], 'is_system' => false]);
        $stranger = $this->makeUser('importer2');

        $this->actingAs($stranger)->get("/student-module/import/{$import->id}")->assertForbidden();
        $this->actingAs($stranger)->post("/student-module/import/{$import->id}/confirm")->assertForbidden();
        $this->actingAs($stranger)->post("/student-module/import/{$import->id}/cancel")->assertForbidden();
        $this->actingAs($stranger)->get("/student-module/import/{$import->id}/report")->assertForbidden();
        $this->assertSame(0, Student::count());
    }

    public function test_the_rows_to_fix_report_is_a_safe_csv(): void
    {
        $import = $this->preview([$this->row(['name_en' => '=HYPERLINK(1)', 'class' => 'Nowhere'])]);

        $csv = $this->actingAs($this->admin)->get("/student-module/import/{$import->id}/report")->assertOk()->streamedContent();

        $this->assertStringContainsString('Row,Name,Reason', $csv);
        $this->assertStringContainsString("'=HYPERLINK(1)", $csv);
    }

    public function test_uploads_are_rate_limited(): void
    {
        $last = null;
        foreach (range(1, 11) as $i) {
            $last = $this->actingAs($this->admin)->post('/student-module/import', ['file' => UploadedFile::fake()->createWithContent('x.exe', 'x'), 'session' => $this->session]);
        }

        $last->assertStatus(429);
    }
}
