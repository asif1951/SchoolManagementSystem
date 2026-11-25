<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebsiteHero;
use App\Models\WebsiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSchoolData;
use Tests\TestCase;

class WebsiteSettingsTest extends TestCase
{
    use RefreshDatabase, MakesSchoolData;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        cache()->forget('website_settings');
        cache()->forget('website_hero');
    }

    private function save(array $over = [])
    {
        return $this->actingAs($this->admin)->post(route('website-management.settings.update'), array_merge(['school_name' => 'Childhome Academy'], $over));
    }

    public function test_an_uploaded_logo_is_stored_and_the_site_shows_it_straight_away(): void
    {
        Storage::fake('public');

        // the homepage is visited first, which warms the 5-minute cache with "no logo"
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->where('school.logo', null));

        $this->save(['school_logo' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertSessionHasNoErrors();

        $path = WebsiteSetting::current()->school_logo;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // no waiting for the cache to expire: the homepage and the admin layout get the new logo now
        $this->get('/')->assertInertia(fn ($page) => $page->where('school.logo', $path)->where('school.name', 'Childhome Academy'));
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('school.logo', $path));
    }

    public function test_replacing_the_logo_removes_the_old_file_and_shows_the_new_one(): void
    {
        Storage::fake('public');

        $this->save(['school_logo' => UploadedFile::fake()->image('one.png')]);
        $first = WebsiteSetting::current()->school_logo;

        $this->get('/')->assertInertia(fn ($page) => $page->where('school.logo', $first));

        $this->save(['school_logo' => UploadedFile::fake()->image('two.png')]);
        $second = WebsiteSetting::current()->school_logo;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        $this->get('/')->assertInertia(fn ($page) => $page->where('school.logo', $second));
    }

    public function test_saving_without_a_school_name_is_refused_and_nothing_is_stored(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post(route('website-management.settings.update'), [
            'school_name' => '', 'school_logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertSessionHasErrors('school_name');

        $this->assertNull(WebsiteSetting::current()->school_logo);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_file_that_is_not_an_image_or_is_too_big_is_refused_with_an_error_on_the_logo(): void
    {
        Storage::fake('public');

        $this->save(['school_logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')])->assertSessionHasErrors('school_logo');
        $this->save(['school_logo' => UploadedFile::fake()->image('huge.png')->size(3000)])->assertSessionHasErrors('school_logo');

        $this->assertNull(WebsiteSetting::current()->school_logo);
    }

    public function test_saving_other_settings_keeps_the_existing_logo(): void
    {
        Storage::fake('public');

        $this->save(['school_logo' => UploadedFile::fake()->image('logo.png')]);
        $path = WebsiteSetting::current()->school_logo;

        $this->save(['school_name' => 'Renamed School'])->assertSessionHasNoErrors();

        $this->assertSame($path, WebsiteSetting::current()->school_logo);
        Storage::disk('public')->assertExists($path);
    }

    public function test_changing_the_hero_also_refreshes_the_cached_copy(): void
    {
        cache()->put('website_hero', 'stale', 300);

        WebsiteHero::current()->update(['hero_title' => 'Welcome']);

        $this->assertFalse(cache()->has('website_hero'));
    }
}
