<?php

namespace Tests\Feature;

use App\Jobs\ProcessPhotoBatch;
use App\Models\BackgroundTask;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentFieldHistory;
use App\Models\User;
use App\Services\StudentRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;
use ZipArchive;

class StudentPhotoTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $this->class = $this->makeClass('Class 6', 'A');
    }

    private function student(string $name = 'Rahim Uddin', int $n = 1): Student
    {
        return app(StudentRegistry::class)->admit([
            'name_en' => $name, 'dob' => now()->subYears(12)->addDays($n)->toDateString(), 'guardian_mobile' => '0171' . str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'class_id' => $this->class->id, 'roll_number' => (string) $n,
        ], $this->admin);
    }

    /** the bytes of a real JPEG */
    private function jpeg(int $w = 300, int $h = 200): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 160));
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zt');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $content === null ? $zip->addEmptyDir($name) : $zip->addFromString($name, $content);
        }

        $zip->close();

        return UploadedFile::fake()->createWithContent('photos.zip', (string) file_get_contents($path));
    }

    private function task(): BackgroundTask
    {
        return BackgroundTask::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------
    // one photo
    // ------------------------------------------------------------------

    public function test_a_photo_is_cropped_square_resized_stored_as_webp_under_a_random_name(): void
    {
        $s = $this->student();

        $this->actingAs($this->admin)->post("/student-module/images/{$s->profile_id}", ['photo' => UploadedFile::fake()->image('my holiday.jpg', 800, 600)])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $path = $s->profile->fresh()->photo;

        $this->assertMatchesRegularExpression('#^students/[A-Za-z0-9]{40}\.webp$#', $path);          // never the uploaded name
        Storage::disk('public')->assertExists($path);

        [$w, $h, , ] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([400, 400], [$w, $h]);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer(Storage::disk('public')->get($path)));

        $this->assertSame($path, $s->fresh()->student_photo);                                         // the enrollment copy follows
        $this->assertSame('photo', StudentFieldHistory::where('profile_id', $s->profile_id)->latest('id')->value('field'));
    }

    public function test_replacing_a_photo_deletes_the_old_file(): void
    {
        $s = $this->student();
        $post = fn () => $this->actingAs($this->admin)->post("/student-module/images/{$s->profile_id}", ['photo' => UploadedFile::fake()->image('p.png', 500, 500)]);

        $post();
        $first = $s->profile->fresh()->photo;
        $post();
        $second = $s->profile->fresh()->photo;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->assertCount(1, Storage::disk('public')->allFiles('students'));                         // not piling up
    }

    public function test_only_real_images_are_accepted(): void
    {
        $s = $this->student();
        $try = fn (UploadedFile $f) => $this->actingAs($this->admin)->post("/student-module/images/{$s->profile_id}", ['photo' => $f]);

        // a script or text file given an image name
        $try(UploadedFile::fake()->createWithContent('face.jpg', '<?php system($_GET["c"]); ?>'))->assertSessionHasErrors('photo');
        // a real image given a dangerous name
        $try(UploadedFile::fake()->createWithContent('shell.php', $this->jpeg()))->assertSessionHasErrors('photo');
        // a type that is not allowed
        $try(UploadedFile::fake()->createWithContent('anim.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')))->assertSessionHasErrors('photo');
        $try(UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertSessionHasErrors('photo');
        // too big
        $try(UploadedFile::fake()->image('huge.jpg')->size(3000))->assertSessionHasErrors('photo');

        $this->assertNull($s->profile->fresh()->photo);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_photo_is_needed_and_the_permission_too(): void
    {
        $s = $this->student();

        $this->actingAs($this->admin)->post("/student-module/images/{$s->profile_id}", [])->assertSessionHasErrors('photo');
        $this->actingAs($this->makeUser(User::ROLE_TEACHER))->post("/student-module/images/{$s->profile_id}", ['photo' => UploadedFile::fake()->image('p.jpg')])->assertForbidden();
        $this->actingAs($this->makeUser(User::ROLE_TEACHER))->get('/student-module/images')->assertForbidden();
        $this->actingAs($this->admin)->post('/student-module/images/999999', ['photo' => UploadedFile::fake()->image('p.jpg')])->assertNotFound();
    }

    public function test_finding_a_student_by_code_or_name(): void
    {
        $s = $this->student('Rahim Uddin');
        $this->student('Karim Ali', 2);

        $find = fn (string $q) => collect($this->actingAs($this->admin)->getJson('/student-module/images/find?q=' . urlencode($q))->assertOk()->json())->pluck('name')->all();

        $this->assertSame(['Rahim Uddin'], $find($s->student_id));
        $this->assertSame(['Rahim Uddin'], $find('rahi'));
        $this->assertSame([], $find('r'));                                  // one letter is too little
    }

    // ------------------------------------------------------------------
    // many photos
    // ------------------------------------------------------------------

    public function test_loose_photos_are_matched_by_student_code_and_the_rest_reported(): void
    {
        $a = $this->student('Rahim Uddin', 1);
        $b = $this->student('Karim Ali', 2);
        $c = $this->student('Sabina Akter', 3);

        $this->actingAs($this->admin)->post('/student-module/images/bulk', ['files' => [
            UploadedFile::fake()->image("{$a->student_id}.jpg", 500, 700),
            UploadedFile::fake()->image("{$b->student_id}.png", 300, 300),
            UploadedFile::fake()->image('99999999.jpg'),                      // nobody has this code
            UploadedFile::fake()->createWithContent("{$c->student_id}.jpg", 'not an image at all'),      // the right code, but not a picture
        ]])->assertRedirect('/student-module/images')->assertSessionHas('task');

        $task = $this->task();
        $this->assertSame('done', $task->status);
        $this->assertSame(100, $task->percent);
        $this->assertSame(2, $task->summary['matched_count']);
        $this->assertSame(2, $task->summary['unmatched_count']);
        $this->assertEqualsCanonicalizing(['Rahim Uddin', 'Karim Ali'], array_column($task->summary['matched'], 'name'));

        $reasons = collect($task->summary['unmatched'])->pluck('reason', 'file');
        $this->assertStringContainsString('No student has the code', $reasons['99999999.jpg']);
        $this->assertStringContainsString('not a JPG, PNG or WebP', $reasons["{$c->student_id}.jpg"]);
        $this->assertNull($c->profile->fresh()->photo);

        $this->assertNotNull($a->profile->fresh()->photo);
        $this->assertNotNull($b->profile->fresh()->photo);
        $this->assertSame([], Storage::disk('local')->allFiles());              // the uploaded files are not kept
    }

    public function test_a_zip_is_matched_the_same_way(): void
    {
        $a = $this->student('Rahim Uddin', 1);

        $this->actingAs($this->admin)->post('/student-module/images/bulk', ['zip' => $this->zip([
            "{$a->student_id}.jpg" => $this->jpeg(),
            'folder/'              => null,
            'readme.txt'           => 'hello',
            'unknown.jpg'          => $this->jpeg(),
        ])])->assertSessionHas('task');

        $task = $this->task();
        $this->assertSame('done', $task->status);
        $this->assertSame(1, $task->summary['matched_count']);
        $this->assertSame(['readme.txt', 'unknown.jpg'], collect($task->summary['unmatched'])->pluck('file')->sort()->values()->all());
        $this->assertNotNull($a->profile->fresh()->photo);
    }

    public function test_zip_entries_that_try_to_escape_are_refused_and_nothing_is_written_outside(): void
    {
        $a = $this->student('Rahim Uddin', 1);

        $this->actingAs($this->admin)->post('/student-module/images/bulk', ['zip' => $this->zip([
            '../../evil.jpg'            => $this->jpeg(),
            '/etc/cron.d/evil.jpg'      => $this->jpeg(),
            'C:\\windows\\evil.jpg'     => $this->jpeg(),
            "sub/../../{$a->student_id}.jpg" => $this->jpeg(),                      // dressed up as a real code
            "{$a->student_id}.jpg"      => $this->jpeg(),
        ])]);

        $task = $this->task();
        $this->assertSame('done', $task->status);
        $this->assertSame(1, $task->summary['matched_count']);                       // only the honest entry
        $this->assertSame(4, $task->summary['unmatched_count']);
        $this->assertTrue(collect($task->summary['unmatched'])->every(fn ($u) => str_contains($u['reason'], 'Unsafe')));

        foreach (Storage::disk('public')->allFiles() as $f) {
            $this->assertStringStartsWith('students/', $f);
        }
    }

    public function test_a_zip_with_too_many_entries_is_refused_whole(): void
    {
        $entries = [];
        foreach (range(1, ProcessPhotoBatch::MAX_ENTRIES + 1) as $i) {
            $entries["f{$i}.txt"] = '';
        }

        $this->actingAs($this->admin)->post('/student-module/images/bulk', ['zip' => $this->zip($entries)]);

        $task = $this->task();
        $this->assertSame('failed', $task->status);
        $this->assertStringContainsString('more than ' . ProcessPhotoBatch::MAX_ENTRIES, $task->error);
        $this->assertSame([], Storage::disk('local')->allFiles());                  // cleaned up even on failure
    }

    public function test_a_photo_inside_a_zip_over_the_size_limit_is_skipped(): void
    {
        config(['student.photo_max_kb' => 1]);
        $a = $this->student('Rahim Uddin', 1);

        $this->actingAs($this->admin)->post('/student-module/images/bulk', ['zip' => $this->zip(["{$a->student_id}.jpg" => $this->jpeg(900, 900)])]);

        $task = $this->task();
        $this->assertSame(0, $task->summary['matched_count']);
        $this->assertStringContainsString('larger than 1 KB', $task->summary['unmatched'][0]['reason']);
        $this->assertNull($a->profile->fresh()->photo);
    }

    public function test_a_bulk_upload_needs_something_to_upload(): void
    {
        $this->actingAs($this->admin)->post('/student-module/images/bulk', [])->assertSessionHasErrors('files');
        $this->actingAs($this->admin)->post('/student-module/images/bulk', ['zip' => UploadedFile::fake()->createWithContent('x.zip', 'this is not a zip')])->assertSessionHasErrors('zip');
        $this->assertSame(0, BackgroundTask::count());
    }

    // ------------------------------------------------------------------
    // tasks
    // ------------------------------------------------------------------

    public function test_progress_can_be_read_by_its_owner_and_a_user_manager_only(): void
    {
        $a = $this->student();
        $this->actingAs($this->admin)->post('/student-module/images/bulk', ['files' => [UploadedFile::fake()->image("{$a->student_id}.jpg")]]);
        $task = $this->task();

        $this->actingAs($this->admin)->getJson("/student-module/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('status', 'done')->assertJsonPath('percent', 100)->assertJsonPath('summary.matched_count', 1);

        $other = $this->makeUser(User::ROLE_TEACHER);
        $this->actingAs($other)->getJson("/student-module/tasks/{$task->id}")->assertForbidden();
        $this->actingAs($other)->get("/student-module/tasks/{$task->id}/report")->assertForbidden();
    }

    public function test_the_not_processed_report_is_a_safe_csv(): void
    {
        $this->actingAs($this->admin)->post('/student-module/images/bulk', ['files' => [
            UploadedFile::fake()->createWithContent('=1+1.jpg', 'x'),
            UploadedFile::fake()->createWithContent('@SUM(1).jpg', 'x'),
        ]]);

        $csv = $this->actingAs($this->admin)->get('/student-module/tasks/' . $this->task()->id . '/report')->assertOk()->streamedContent();

        $this->assertStringContainsString('"Row / file",Reason', $csv);
        $this->assertStringContainsString("'=1+1.jpg", $csv);                            // a formula is defanged
        $this->assertStringContainsString("'@SUM(1).jpg", $csv);
        $this->assertDoesNotMatchRegularExpression('/^"?[=@]/m', $csv);                  // no line starts with a formula
    }

    public function test_a_failed_job_keeps_the_error_and_the_screen_can_see_it(): void
    {
        $task = BackgroundTask::queue('photo_batch', $this->admin, ['dir' => 'photo-batches/none', 'files' => [], 'zip' => 'photo-batches/none/missing.zip']);

        ProcessPhotoBatch::dispatch($task->id);

        $this->assertSame('failed', $task->fresh()->status);
        $this->assertStringContainsString('could not be opened', $task->fresh()->error);
        $this->actingAs($this->admin)->getJson("/student-module/tasks/{$task->id}")->assertJsonPath('status', 'failed');
    }
}
