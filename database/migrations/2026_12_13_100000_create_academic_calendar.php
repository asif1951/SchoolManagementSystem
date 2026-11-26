<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Academic Calendar: every date of the school year, stored once as a Gregorian date.
 *
 * The Teaching Plan's own holiday list moves into it (and its table goes), and every exam that has dates gets an event,
 * so there is one place for dates. Weekly off days stay where they always were (routine_settings.working_days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('title', 150);
            $table->string('title_bn', 150)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('audience', 10)->default('all');           // all | classes | teachers
            $table->string('note', 500)->nullable();
            $table->boolean('off')->default(false);                    // no school for its audience on these days
            $table->boolean('tentative')->default(false);
            $table->string('repeat', 8)->default('none');              // none | english | bangla
            $table->json('skip_years')->nullable();                    // years in which a repeating event does not happen
            $table->unsignedTinyInteger('follows_day')->nullable();    // a make-up day follows the routine of this weekday
            $table->foreignId('exam_id')->nullable()->unique()->constrained('exams')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_on', 'ends_on'], 'idx_calendar_range');
            $table->index(['repeat', 'starts_on'], 'idx_calendar_repeat');
            $table->index(['type', 'starts_on'], 'idx_calendar_type');
        });

        Schema::create('calendar_event_class', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->primary(['event_id', 'class_id']);
            $table->index('class_id');
        });

        $now = now();
        $row = fn (array $d) => $d + ['audience' => 'all', 'repeat' => 'none', 'tentative' => false, 'created_at' => $now, 'updated_at' => $now];

        if (Schema::hasTable('teaching_holidays')) {
            foreach (DB::table('teaching_holidays')->orderBy('id')->get() as $h) {
                DB::table('calendar_events')->insert($row(['type' => 'holiday', 'title' => mb_substr($h->title, 0, 150), 'starts_on' => $h->starts_on, 'ends_on' => $h->ends_on, 'off' => true]));
            }

            Schema::drop('teaching_holidays');
        }

        foreach (DB::table('exams')->whereNotNull('starts_on')->get() as $e) {
            DB::table('calendar_events')->insert($row(['type' => 'exam', 'title' => mb_substr($e->name, 0, 150), 'starts_on' => $e->starts_on, 'ends_on' => $e->ends_on ?? $e->starts_on, 'off' => false, 'exam_id' => $e->id]));
        }

        // everyone on the staff may read the calendar; the head teacher also manages it
        foreach (DB::table('roles')->get(['id', 'slug', 'abilities']) as $role) {
            $abilities = json_decode((string) $role->abilities, true) ?: [];

            if ($role->slug === 'user' || in_array('*', $abilities, true)) {
                continue;
            }

            $add = $role->slug === 'head_teacher' ? ['calendar.view', 'calendar.manage', 'calendar.close'] : ['calendar.view'];
            DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode(array_values(array_unique([...$abilities, ...$add]))), 'updated_at' => $now]);
        }

        Cache::forget('roles.map');
    }

    public function down(): void
    {
        Schema::create('teaching_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('title', 120);
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
        });

        foreach (DB::table('calendar_events')->where('type', 'holiday')->get() as $e) {
            DB::table('teaching_holidays')->insert(['starts_on' => $e->starts_on, 'ends_on' => $e->ends_on, 'title' => mb_substr($e->title, 0, 120), 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::dropIfExists('calendar_event_class');
        Schema::dropIfExists('calendar_events');

        foreach (DB::table('roles')->get(['id', 'abilities']) as $role) {
            $abilities = array_values(array_filter(json_decode((string) $role->abilities, true) ?: [], fn ($a) => ! str_starts_with($a, 'calendar.')));
            DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode($abilities)]);
        }

        Cache::forget('roles.map');
    }
};
