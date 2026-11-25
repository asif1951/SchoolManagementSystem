<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\StudentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

/** Classes and sections may carry names of their own (Class 1 = "Podma", section A = "Rose"); all of it optional. */
class ClassTitlesTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private function payload(array $over = []): array
    {
        $teacher = $this->makeUser(User::ROLE_TEACHER);

        return $over + ['className' => 'Class 1', 'section' => 'A', 'academicYear' => (string) now()->year, 'classTeacher' => $teacher->id, 'status' => 'active'];
    }

    public function test_titles_are_optional_and_labels_fall_back_to_the_names(): void
    {
        $plain = $this->makeClass('Class 2', 'B');

        $this->assertSame('Class 2', $plain->class_label);
        $this->assertSame('B', $plain->section_label);
        $this->assertSame('Class 2 — B', $plain->full_label);

        $plain->update(['class_title' => 'Jamuna', 'section_title' => 'Rose']);

        $this->assertSame('Jamuna (Class 2)', $plain->fresh()->class_label);
        $this->assertSame('Rose (B)', $plain->fresh()->section_label);
        $this->assertSame('Jamuna (Class 2) — Rose (B)', $plain->fresh()->full_label);

        // a title equal to the name adds nothing
        $this->assertSame('Class 2', SchoolClass::joinLabel('Class 2', 'class 2'));
    }

    public function test_a_class_can_be_created_with_titles_and_several_sections_get_a_title_each(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('classesmanagement.store'), $this->payload(['classTitle' => 'Podma', 'section' => 'A, B', 'sectionTitle' => 'Rose, Lily']))->assertSessionHasNoErrors();

        $a = SchoolClass::where('class_name', 'Class 1')->where('section', 'A')->firstOrFail();
        $b = SchoolClass::where('class_name', 'Class 1')->where('section', 'B')->firstOrFail();

        $this->assertSame(['Podma', 'Rose'], [$a->class_title, $a->section_title]);
        $this->assertSame(['Podma', 'Lily'], [$b->class_title, $b->section_title]);
    }

    public function test_creating_without_titles_still_works_and_editing_can_add_or_clear_them(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $teacher = $this->makeUser(User::ROLE_TEACHER);

        $this->actingAs($admin)->post(route('classesmanagement.store'), $this->payload(['classTeacher' => $teacher->id]))->assertSessionHasNoErrors();
        $class = SchoolClass::where('class_name', 'Class 1')->firstOrFail();
        $this->assertNull($class->class_title);

        $edit = fn (array $o) => $this->actingAs($admin)->put(route('classesmanagement.update', $class->id), $this->payload(['classTeacher' => $teacher->id] + $o))->assertSessionHasNoErrors();

        $edit(['classTitle' => 'Podma', 'sectionTitle' => 'Rose']);
        $this->assertSame(['Podma', 'Rose'], [$class->fresh()->class_title, $class->fresh()->section_title]);

        $edit(['classTitle' => '', 'sectionTitle' => '']);
        $this->assertSame([null, null], [$class->fresh()->class_title, $class->fresh()->section_title]);
    }

    public function test_the_class_list_can_be_searched_by_title(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->makeClass('Class 3', 'A')->update(['class_title' => 'Meghna']);
        $this->makeClass('Class 4', 'A');

        $this->actingAs($admin)->get(route('classesmanagement.index', ['search' => 'Meghna']))
            ->assertInertia(fn ($p) => $p->has('classes.data', 1)->where('classes.data.0.class_label', 'Meghna (Class 3)'));
    }

    public function test_the_student_module_shows_the_titles(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->makeClass('Class 1', 'A')->update(['class_title' => 'Podma', 'section_title' => 'Rose']);

        $this->actingAs($admin)->get('/student-module/students/create')
            ->assertInertia(fn ($p) => $p->where('classes.0.label', 'Podma (Class 1) — Rose (A)'));
    }

    public function test_an_import_may_name_the_class_by_its_title(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $class = $this->makeClass('Class 1', 'A');
        $class->update(['class_title' => 'Podma', 'section_title' => 'Rose']);
        $year = (string) $class->academic_year;

        $importer = app(StudentImporter::class);
        $row = fn (string $name, string $cls, string $sec) => ['row' => 2, 'raw' => ['name_en' => $name, 'class' => $cls, 'section' => $sec, 'dob' => '2015-02-03', 'guardian_mobile' => '01712345678', 'gender' => 'Male']];

        $r = $importer->check([$row('By Title', 'Podma', 'Rose'), $row('By Name', 'Class 1', 'A'), $row('Mixed', 'Podma', 'A'), $row('Wrong', 'Padma', 'Rose')], $admin, $year);

        $this->assertCount(3, $r['valid']);
        $this->assertCount(1, $r['invalid']);
        $this->assertSame($class->id, $r['valid'][0]['data']['class_id']);
    }
}
