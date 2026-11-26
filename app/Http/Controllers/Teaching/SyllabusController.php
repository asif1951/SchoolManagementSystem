<?php

namespace App\Http\Controllers\Teaching;

use App\Jobs\ImportSyllabus;
use App\Models\BackgroundTask;
use App\Models\Teaching\SyllabusChapter;
use App\Models\Teaching\SyllabusTopic;
use App\Services\Teaching\SyllabusService;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

class SyllabusController extends TeachingController
{
    public function __construct(private SyllabusService $syllabus)
    {
    }

    public function index(Request $request)
    {
        $user = $this->need($request);
        $years = $this->years();
        $year = $this->year($request, $years);

        // classes of the session with the subjects offered to any of their sections
        $offered = DB::table('classes as c')->join('class_subjects as cs', 'cs.class_id', '=', 'c.id')->join('subjects as s', 's.id', '=', 'cs.subject_id')
            ->where('c.academic_year', $year)->distinct()->get(['c.class_name', 's.id', 's.name'])->groupBy('class_name');
        $classNames = $offered->keys()->sort(fn ($a, $b) => strnatcasecmp($a, $b))->values()->all();

        $class = in_array($request->input('class'), $classNames, true) ? (string) $request->input('class') : ($classNames[0] ?? null);
        $subjects = $class ? $offered[$class]->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values() : collect();
        $counts = $class ? SyllabusChapter::where('academic_year', $year)->where('class_name', $class)->selectRaw('subject_id, COUNT(*) n')->groupBy('subject_id')->pluck('n', 'subject_id') : collect();
        $subjectId = (int) $request->input('subject');
        $subjectId = $subjects->firstWhere('id', $subjectId)?->id ?? $subjects->first()?->id;

        $chapters = $class && $subjectId ? $this->syllabus->chapters($year, $class, $subjectId) : collect();
        $earlier = $class && $subjectId && $chapters->isEmpty() ? $this->syllabus->effectiveYear($year, $class, $subjectId) : null;

        return Inertia::render('Teaching/Syllabus', [
            'years' => $years, 'session' => $year, 'classNames' => $classNames, 'className' => $class,
            'subjects' => $subjects->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'chapters' => (int) ($counts[$s->id] ?? 0)])->all(),
            'subjectId' => $subjectId ? (int) $subjectId : null,
            'chapters' => $chapters->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'periods' => $c->periods,
                'topics' => $c->topics->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()])->values(),
            'earlier' => $earlier,
            'canEdit' => $user->canDo('teaching.syllabus'),
            'flash' => $this->flash(),
        ]);
    }

    private function setRules(): array
    {
        return ['academic_year' => ['required', 'string', 'max:10'], 'class_name' => ['required', 'string', 'max:100'], 'subject_id' => ['required', 'integer', 'exists:subjects,id']];
    }

    public function store(Request $request)
    {
        $this->need($request, 'teaching.syllabus');
        $d = $request->validate($this->setRules() + ['text' => ['required', 'string', 'max:20000']]);
        $n = $this->syllabus->addLines($d['academic_year'], $d['class_name'], (int) $d['subject_id'], $d['text']);

        return back()->with('success', trans_choice('teaching.msg.added', $n, ['count' => $n]));
    }

    public function update(Request $request, SyllabusChapter $chapter)
    {
        $this->need($request, 'teaching.syllabus');
        $d = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:200'], 'periods' => ['sometimes', 'integer', 'between:0,500']]);
        $this->syllabus->updateChapter($chapter, $d);

        return back();
    }

    public function destroy(Request $request, SyllabusChapter $chapter)
    {
        $this->need($request, 'teaching.syllabus');
        $this->syllabus->deleteChapter($chapter);

        return back()->with('success', __('teaching.msg.chapter_deleted'));
    }

    public function restore(Request $request, int $chapter)
    {
        $this->need($request, 'teaching.syllabus');
        $this->syllabus->restoreChapter(SyllabusChapter::onlyTrashed()->findOrFail($chapter));

        return back()->with('success', __('teaching.msg.restored'));
    }

    public function reorder(Request $request)
    {
        $this->need($request, 'teaching.syllabus');
        $d = $request->validate($this->setRules() + ['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);
        $this->syllabus->reorderChapters($d['academic_year'], $d['class_name'], (int) $d['subject_id'], array_map('intval', $d['ids']));

        return back();
    }

    public function storeTopic(Request $request, SyllabusChapter $chapter)
    {
        $this->need($request, 'teaching.syllabus');
        $d = $request->validate(['name' => ['required', 'string', 'max:200']]);
        SyllabusTopic::create(['chapter_id' => $chapter->id, 'name' => trim($d['name']), 'position' => (int) $chapter->topics()->max('position') + 1]);
        \App\Models\AuditLog::record($chapter, 'syllabus.topic_add', ['topic' => $d['name']]);

        return back();
    }

    public function updateTopic(Request $request, SyllabusTopic $topic)
    {
        $this->need($request, 'teaching.syllabus');
        $d = $request->validate(['name' => ['required', 'string', 'max:200']]);
        \App\Models\AuditLog::record($topic->chapter, 'syllabus.topic_edit', ['from' => $topic->name, 'to' => $d['name']]);
        $topic->update(['name' => trim($d['name'])]);

        return back();
    }

    public function destroyTopic(Request $request, SyllabusTopic $topic)
    {
        $this->need($request, 'teaching.syllabus');
        \App\Models\AuditLog::record($topic->chapter, 'syllabus.topic_del', ['topic' => $topic->name]);
        $topic->delete();

        return back()->with('success', __('teaching.msg.topic_deleted'));
    }

    public function restoreTopic(Request $request, int $topic)
    {
        $this->need($request, 'teaching.syllabus');
        SyllabusTopic::onlyTrashed()->findOrFail($topic)->restore();

        return back()->with('success', __('teaching.msg.restored'));
    }

    public function reorderTopics(Request $request, SyllabusChapter $chapter)
    {
        $this->need($request, 'teaching.syllabus');
        $d = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);
        $this->syllabus->reorderTopics($chapter, array_map('intval', $d['ids']));

        return back();
    }

    public function copy(Request $request)
    {
        $this->need($request, 'teaching.syllabus');
        $d = $request->validate($this->setRules() + [
            'to_year' => ['required', 'string', 'regex:/^\d{4}(-\d{2,4})?$/'], 'to_classes' => ['required', 'array', 'min:1', 'max:60'], 'to_classes.*' => ['string', 'max:100'],
        ]);
        $n = $this->syllabus->copy($d['academic_year'], $d['class_name'], (int) $d['subject_id'], $d['to_year'], $d['to_classes']);

        return back()->with('success', trans_choice('teaching.msg.copied', $n, ['count' => $n]));
    }

    // ---- Excel import: upload → preview → confirm (saved by a background job) -------------------------------------

    public function template(Request $request)
    {
        $this->need($request, 'teaching.syllabus');

        return response(Spreadsheet::xlsx($this->syllabus->template(), 'Syllabus'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="syllabus-template.xlsx"',
        ]);
    }

    public function preview(Request $request)
    {
        $user = $this->need($request, 'teaching.syllabus');
        $request->validate(['file' => ['required', 'file', 'max:10240', Rule::file()->extensions(['xlsx', 'csv', 'txt'])]]);

        $token = (string) Str::uuid();
        $path = $request->file('file')->storeAs('syllabus-imports', "{$user->id}-{$token}.dat", 'local');

        try {
            $result = $this->syllabus->parse(Storage::disk('local')->path($path));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($path);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'token' => $token, 'total' => $result['total'], 'valid' => count($result['rows']), 'errors' => $result['errors'],
            'sample' => array_slice($result['rows'], 0, 50),
        ]);
    }

    public function confirm(Request $request)
    {
        $user = $this->need($request, 'teaching.syllabus');
        $d = $request->validate(['token' => ['required', 'uuid'], 'academic_year' => ['required', 'string', 'regex:/^\d{4}(-\d{2,4})?$/']]);
        $path = "syllabus-imports/{$user->id}-{$d['token']}.dat";       // the user's own upload only

        abort_unless(Storage::disk('local')->exists($path), 404);

        $task = BackgroundTask::queue('syllabus_import', $user, ['file' => $path, 'year' => $d['academic_year']]);
        ImportSyllabus::dispatch($task->id);

        return response()->json(['task' => $task->id]);
    }
}
