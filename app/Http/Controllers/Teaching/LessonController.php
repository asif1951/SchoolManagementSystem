<?php

namespace App\Http\Controllers\Teaching;

use App\Models\Teaching\LessonPlan;
use App\Models\Teaching\TeachingLog;
use App\Services\Teaching\Access;
use App\Services\Teaching\DailyLog;
use App\Services\Teaching\Rows;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Lesson plans (optional): written once, reused as templates, copied to other sections. */
class LessonController extends TeachingController
{
    public function __construct(private Rows $rows)
    {
    }

    public function index(Request $request, DailyLog $daily)
    {
        $user = $this->need($request);
        $only = Access::limitTo($user);
        $tab = $request->input('tab') === 'templates' ? 'templates' : 'lessons';
        $q = trim((string) $request->input('q'));

        $page = LessonPlan::query()->with(['subject:id,name', 'owner:id,name', 'chapter:id,name'])
            ->where('is_template', $tab === 'templates')
            // templates are shared for reading; lesson plans are the owner's (or visible to those who see all)
            ->when($only && $tab === 'lessons', fn ($w) => $w->where('owner_id', $only))
            ->when($q !== '', fn ($w) => $w->where('title', 'like', "%{$q}%"))
            ->orderByDesc('lesson_date')->orderByDesc('id')->simplePaginate(25)->withQueryString();

        $classIds = collect($page->items())->pluck('class_id')->filter()->unique()->all();
        $classes = DB::table('classes')->whereIn('id', $classIds)->get()->keyBy('id');

        // a new lesson plan made from a daily log entry starts with what the log knows
        $prefill = null;

        if ($logId = (int) $request->input('log')) {
            $log = TeachingLog::with(['topic', 'item.chapter'])->findOrFail($logId);
            abort_unless(Access::writeFor($user, $log->teacher_id), 403);
            $prefill = LessonPlan::where('log_id', $log->id)->first()?->toArray() ?? ['id' => null, 'log_id' => $log->id, 'class_id' => $log->class_id, 'subject_id' => $log->subject_id,
                'chapter_id' => $log->item?->chapter_id, 'topic_id' => $log->topic_id, 'lesson_date' => $log->log_date->toDateString(), 'duration' => 40,
                'title' => $log->topic?->name ?? $log->item?->chapter?->name ?? '', 'homework' => $log->homework ? ($log->next_plan ?? '') : '', 'is_template' => false];
        }

        // the classes and subjects the user may write for
        $mine = DB::table('class_subject_teachers as t')->join('classes as c', 'c.id', '=', 't.class_id')->join('subjects as s', 's.name', '=', 't.subject')
            ->whereNull('t.ends_on')->when(! $user->canDo('teaching.manage_all'), fn ($w) => $w->where('t.user_id', $user->id))
            ->distinct()->orderByDesc('c.academic_year')->orderBy('c.class_name')->orderBy('c.section')->limit(300)
            ->get(['c.id as class_id', 's.id as subject_id', 's.name as subject', 'c.academic_year', 'c.class_name', 'c.class_title', 'c.section', 'c.section_title'])
            ->map(fn ($r) => ['class_id' => (int) $r->class_id, 'subject_id' => (int) $r->subject_id, 'label' => "{$r->subject} · {$this->rows->label($r)} · {$r->academic_year}"]);

        return Inertia::render('Teaching/Lessons', [
            'tab' => $tab, 'q' => $q,
            'lessons' => ['data' => collect($page->items())->map(fn (LessonPlan $l) => $l->only(['id', 'title', 'class_id', 'subject_id', 'chapter_id', 'topic_id', 'log_id', 'duration', 'is_template', ...LessonPlan::TEXTS]) + [
                'lesson_date' => $l->lesson_date?->toDateString(), 'subject' => $l->subject?->name, 'owner' => $l->owner?->name, 'chapter' => $l->chapter?->name,
                'class' => isset($classes[$l->class_id]) ? $this->rows->label($classes[$l->class_id]) : null,
                'mine' => Access::writeFor($user, $l->owner_id),
            ])->all(), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'targets' => $mine, 'prefill' => $prefill,
            'canWrite' => $user->canDo('teaching.own') || $user->canDo('teaching.manage_all'),
            'flash' => $this->flash(),
        ]);
    }

    private function data(Request $request): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:200'], 'class_id' => ['nullable', 'integer', 'exists:classes,id'], 'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'chapter_id' => ['nullable', 'integer', 'exists:syllabus_chapters,id'], 'topic_id' => ['nullable', 'integer', 'exists:syllabus_topics,id'],
            'lesson_date' => ['nullable', 'date_format:Y-m-d'], 'duration' => ['nullable', 'integer', 'between:5,600'], 'is_template' => ['boolean'],
        ];

        foreach (LessonPlan::TEXTS as $f) {
            $rules[$f] = ['nullable', 'string', 'max:5000'];
        }

        return $request->validate($rules);
    }

    public function store(Request $request)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        $d = $this->data($request);
        $owner = $user->id;
        $log = $request->input('log_id') ? TeachingLog::findOrFail((int) $request->input('log_id')) : null;

        if ($log) {
            abort_unless(Access::writeFor($user, $log->teacher_id), 403);
            $d['log_id'] = $log->id;
            $owner = $log->teacher_id;
        }

        abort_unless($this->mayUse($user, $d), 403);
        LessonPlan::create($d + ['owner_id' => $owner]);

        return redirect()->route('teaching.lessons', ! empty($d['is_template']) ? ['tab' => 'templates'] : [])->with('success', __('teaching.msg.lesson_saved'));
    }

    /** a class plan is written only for a class and subject the writer teaches */
    private function mayUse($user, array $d): bool
    {
        return $user->canDo('teaching.manage_all') || empty($d['class_id']) || $this->rows->assigned($user->id, (int) $d['class_id'], (int) $d['subject_id']);
    }

    public function update(Request $request, LessonPlan $lesson)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        abort_unless(Access::writeFor($user, $lesson->owner_id), 403);
        $d = $this->data($request);
        abort_unless($this->mayUse($user, $d), 403);
        $lesson->update($d);

        return back()->with('success', __('teaching.msg.lesson_saved'));
    }

    /** copy to other sections, or take a template (anyone's) as one's own */
    public function copy(Request $request, LessonPlan $lesson)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        abort_unless($lesson->is_template || Access::writeFor($user, $lesson->owner_id), 403);
        $d = $request->validate(['class_ids' => ['required', 'array', 'min:1', 'max:20'], 'class_ids.*' => ['integer', 'exists:classes,id']]);

        foreach ($d['class_ids'] as $classId) {
            abort_unless($this->mayUse($user, ['class_id' => $classId, 'subject_id' => $lesson->subject_id]), 403);
        }

        DB::transaction(function () use ($lesson, $d, $user) {
            foreach ($d['class_ids'] as $classId) {
                $copy = $lesson->replicate(['log_id', 'lesson_date']);
                $copy->fill(['class_id' => $classId, 'owner_id' => $user->id, 'is_template' => false])->save();
            }
        });

        return back()->with('success', trans_choice('teaching.msg.lesson_copied', count($d['class_ids']), ['count' => count($d['class_ids'])]));
    }

    public function destroy(Request $request, LessonPlan $lesson)
    {
        $user = $this->need($request, 'teaching.own', 'teaching.manage_all');
        abort_unless(Access::writeFor($user, $lesson->owner_id), 403);
        $lesson->delete();

        return back()->with('success', __('teaching.msg.lesson_deleted'));
    }

    public function print(Request $request, LessonPlan $lesson, \App\Services\TabulationBuilder $school)
    {
        $user = $this->need($request);
        abort_unless($lesson->is_template || Access::seesAll($user) || $lesson->owner_id === $user->id, 403);
        $info = $school->school();
        $class = $lesson->class_id ? DB::table('classes')->find($lesson->class_id) : null;

        return view('print.teaching-lesson', ['l' => $lesson->load(['subject', 'owner', 'chapter']), 'classLabel' => $class ? $this->rows->label($class) : null,
            'school' => $info, 'frameColor' => $info['color'], 'orientation' => 'portrait', 'asked' => 'portrait', 'examTypes' => [], 'examValue' => '', 'keep' => []]);
    }
}
