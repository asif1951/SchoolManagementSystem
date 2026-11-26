<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BackgroundTask;
use App\Models\Teaching\SyllabusChapter;
use App\Models\Teaching\SyllabusTopic;
use App\Services\Teaching\SyllabusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesTeachingData;
use Tests\TestCase;

class TeachingSyllabusTest extends TestCase
{
    use RefreshDatabase, MakesTeachingData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTeaching();
    }

    private function set(array $more = []): array
    {
        return ['academic_year' => '2026', 'class_name' => 'Class 6', 'subject_id' => $this->math->id] + $more;
    }

    public function test_chapters_and_topics_are_typed_once_and_listed_in_order(): void
    {
        $this->actingAs($this->head)->post(route('teaching.syllabus.store'), $this->set(['text' => "Numbers | 8\n- Natural\n- Fractions\nAlgebra"]))->assertSessionHasNoErrors();

        $chapters = app(SyllabusService::class)->chapters('2026', 'Class 6', $this->math->id);
        $this->assertSame(['Numbers', 'Algebra'], $chapters->pluck('name')->all());
        $this->assertSame(8, $chapters[0]->periods);
        $this->assertSame(['Natural', 'Fractions'], $chapters[0]->topics->pluck('name')->all());
        $this->assertSame(2, AuditLog::where('event', 'syllabus.created')->count());

        $this->actingAs($this->head)->get(route('teaching.syllabus', ['session' => '2026', 'class' => 'Class 6', 'subject' => $this->math->id]))
            ->assertInertia(fn (Assert $p) => $p->component('Teaching/Syllabus')->has('chapters', 2)->where('canEdit', true));
    }

    public function test_reordering_deleting_and_bringing_back(): void
    {
        [$a, $b, $c] = $this->syllabus()->all();

        $this->actingAs($this->admin)->post(route('teaching.syllabus.reorder'), $this->set(['ids' => [$c->id, $a->id, $b->id]]))->assertSessionHasNoErrors();
        $this->assertSame(['Geometry', 'Numbers', 'Algebra'], app(SyllabusService::class)->chapters('2026', 'Class 6', $this->math->id)->pluck('name')->all());

        $this->actingAs($this->admin)->delete(route('teaching.syllabus.destroy', $b));
        $this->assertSoftDeleted('syllabus_chapters', ['id' => $b->id]);
        $this->actingAs($this->admin)->post(route('teaching.syllabus.restore', $b->id));
        $this->assertNull(SyllabusChapter::find($b->id)->deleted_at);

        $topics = $a->topics;
        $this->actingAs($this->admin)->post(route('teaching.syllabus.topics.reorder', $a), ['ids' => [$topics[1]->id, $topics[0]->id]])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['Fractions', 'Natural numbers'], $a->topics()->get()->pluck('name')->all());
        $this->assertSame('Fractions', $a->topics()->first()->name);
    }

    public function test_it_copies_to_other_classes_and_the_next_session_without_duplicating(): void
    {
        $this->syllabus();
        $svc = app(SyllabusService::class);

        $this->actingAs($this->head)->post(route('teaching.syllabus.copy'), $this->set(['to_year' => '2027', 'to_classes' => ['Class 6', 'Class 7']]))->assertSessionHasNoErrors();

        $this->assertCount(3, $svc->chapters('2027', 'Class 6', $this->math->id));
        $this->assertSame(['Natural numbers', 'Fractions'], $svc->chapters('2027', 'Class 7', $this->math->id)[0]->topics->pluck('name')->all());
        $this->assertSame(0, $svc->copy('2026', 'Class 6', $this->math->id, '2027', ['Class 6']));           // already there
    }

    public function test_a_session_without_its_own_syllabus_uses_the_earlier_one(): void
    {
        $this->syllabus();

        $this->assertSame('2026', app(SyllabusService::class)->effectiveYear('2028', 'Class 6', $this->math->id));
        $this->assertNull(app(SyllabusService::class)->effectiveYear('2025', 'Class 6', $this->math->id));
    }

    public function test_excel_import_previews_problems_and_saves_only_good_rows(): void
    {
        Storage::fake('local');
        // (CSV, so that the formula reaches the checker as typed)
        $file = UploadedFile::fake()->createWithContent('s.csv', implode("
", [
            'Class,Subject,Chapter,Topic,Periods',
            'Class 6,Mathematics,Numbers,Natural,5',
            'class 6,mathematics,Numbers,Fractions,',
            'Class 99,Mathematics,X,,',
            'Class 6,Alchemy,X,,',
            'Class 6,Mathematics,=HYPERLINK(1),,',
        ]));

        $res = $this->actingAs($this->head)->postJson(route('teaching.syllabus.preview'), ['file' => $file])->assertOk();
        $res->assertJsonPath('valid', 2)->assertJsonPath('total', 5);
        $this->assertCount(3, $res->json('errors'));
        $this->assertSame(0, SyllabusChapter::count());                       // a preview saves nothing

        // another person cannot confirm my upload
        $this->actingAs($this->admin)->postJson(route('teaching.syllabus.confirm'), ['token' => $res->json('token'), 'academic_year' => '2026'])->assertNotFound();

        $task = $this->actingAs($this->head)->postJson(route('teaching.syllabus.confirm'), ['token' => $res->json('token'), 'academic_year' => '2026'])->assertOk()->json('task');

        $this->assertSame('done', BackgroundTask::find($task)->status);
        $this->assertSame(1, SyllabusChapter::count());
        $this->assertSame(2, SyllabusTopic::count());
        $this->actingAs($this->head)->getJson(route('teaching.tasks.show', $task))->assertOk();
        $this->actingAs($this->teacher)->getJson(route('teaching.tasks.show', $task))->assertForbidden();
    }

    public function test_only_those_allowed_may_change_the_syllabus(): void
    {
        $c = $this->syllabus()->first();

        $this->actingAs($this->teacher)->post(route('teaching.syllabus.store'), $this->set(['text' => 'Hack']))->assertForbidden();
        $this->actingAs($this->teacher)->delete(route('teaching.syllabus.destroy', $c))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('teaching.syllabus', ['session' => '2026']))->assertOk()->assertInertia(fn (Assert $p) => $p->where('canEdit', false));
        $this->actingAs($this->makeUser())->get(route('teaching.syllabus'))->assertForbidden();

        // a single person can be given the permission on top of the role
        $this->teacher->forceFill(['extra_abilities' => ['teaching.syllabus']])->save();
        $this->actingAs($this->teacher->fresh())->post(route('teaching.syllabus.store'), $this->set(['text' => 'Allowed']))->assertSessionHasNoErrors();
    }
}
