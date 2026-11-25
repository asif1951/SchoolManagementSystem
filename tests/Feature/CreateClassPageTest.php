<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

/** The Create Class page, the class list and its dialogs all ask for the same things, titles included. */
class CreateClassPageTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    public function test_the_create_page_renders_with_what_the_form_needs(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/classes')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('classes')->has('teachers')->has('subjectOptions')->has('shifts')->has('mediums')->has('studyGroups'));
    }

    public function test_a_class_can_be_created_from_the_create_page_with_titles(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $teacher = $this->makeUser(User::ROLE_TEACHER);

        $this->actingAs($admin)->post('/classes', [
            'className' => 'Class 2', 'classTitle' => 'Jamuna', 'section' => 'A, B', 'sectionTitle' => 'Rose, Lily',
            'academicYear' => (string) now()->year, 'classTeacher' => $teacher->id, 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $a = SchoolClass::where('class_name', 'Class 2')->where('section', 'A')->firstOrFail();
        $b = SchoolClass::where('class_name', 'Class 2')->where('section', 'B')->firstOrFail();

        $this->assertSame(['Jamuna', 'Rose', 'Jamuna (Class 2) — Rose (A)'], [$a->class_title, $a->section_title, $a->full_label]);
        $this->assertSame('Lily', $b->section_title);
    }

    public function test_the_class_list_carries_the_labels_the_screen_shows(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->makeClass('Class 5', 'A')->update(['class_title' => 'Padma', 'section_title' => 'Rose']);

        $this->actingAs($admin)->get('/classesmanagement')
            ->assertInertia(fn (Assert $p) => $p->component('classesmanagement')->where('classes.data.0.class_label', 'Padma (Class 5)')->where('classes.data.0.section_label', 'Rose (A)'));
    }
}
