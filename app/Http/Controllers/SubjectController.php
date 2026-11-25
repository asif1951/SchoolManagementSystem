<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Admin screen for the school's master list of subjects.
 */
class SubjectController extends Controller
{
    public function index()
    {
        $subjects = Subject::query()
            ->withCount('classes')
            ->ordered()
            ->get()
            ->map(fn (Subject $s) => [
                'id'            => $s->id,
                'name'          => $s->name,
                'code'          => $s->code,
                'short_name'    => $s->short_name,
                'description'   => $s->description,
                'sort_order'    => $s->sort_order,
                'is_active'     => $s->is_active,
                'classes_count' => $s->classes_count,
            ]);

        return Inertia::render('Subjects', [
            'subjects' => $subjects,
            'flash'    => session()->only(['success', 'error']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $data['name']       = Subject::normalize($data['name']);
        $data['sort_order'] = $data['sort_order'] ?? ((int) Subject::max('sort_order') + 1);

        $subject = Subject::create($data);

        return back()->with('success', "Subject \"{$subject->name}\" added.");
    }

    /** Add many subjects at once: one name per line (or separated by commas). Existing names are skipped. */
    public function storeMany(Request $request)
    {
        $data = $request->validate(['names' => ['required', 'string', 'max:5000']]);

        $names = collect(preg_split('/[\r\n,;]+/', $data['names']) ?: [])
            ->map(fn ($n) => Subject::normalize((string) $n))
            ->filter(fn ($n) => $n !== '' && mb_strlen($n) <= 100)
            ->unique(fn ($n) => mb_strtolower($n))
            ->values();

        if ($names->isEmpty()) {
            return back()->with('error', 'Type at least one subject name.');
        }

        $added = 0;
        $order = (int) Subject::max('sort_order');

        foreach ($names as $name) {
            if (Subject::query()->where('name', $name)->exists()) {
                continue;
            }
            Subject::create(['name' => $name, 'sort_order' => ++$order, 'is_active' => true]);
            $added++;
        }

        $skipped = $names->count() - $added;

        return back()->with('success', "{$added} subject(s) added" . ($skipped ? ", {$skipped} already existed." : '.'));
    }

    public function update(Request $request, Subject $subject)
    {
        $data = $this->validated($request, $subject);

        $data['name'] = Subject::normalize($data['name']);

        $subject->update($data);

        return back()->with('success', "Subject \"{$subject->name}\" updated.");
    }

    public function destroy(Subject $subject)
    {
        $classes = $subject->classes()->count();

        if ($classes > 0) {
            return back()->with('error', "\"{$subject->name}\" is used by {$classes} class(es). Remove it from them first, or deactivate it instead.");
        }

        // Marks and subject-teacher records still refer to the subject by name.
        $inUse = DB::table('results')->where('subject', $subject->name)->exists()
            || DB::table('class_subject_teachers')->where('subject', $subject->name)->exists();

        if ($inUse) {
            return back()->with('error', "\"{$subject->name}\" already has marks or teacher records, so it cannot be deleted. Deactivate it instead.");
        }

        $name = $subject->name;
        $subject->delete();

        return back()->with('success', "Subject \"{$name}\" deleted.");
    }

    // ------------------------------------------------------------------

    private function validated(Request $request, ?Subject $subject = null): array
    {
        // Compare the cleaned-up name, so " Math " / "math" cannot sneak past the unique check.
        $request->merge(['name' => Subject::normalize((string) $request->input('name'))]);

        return $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('subjects', 'name')->ignore($subject?->id)],
            'code'        => ['nullable', 'string', 'max:30', Rule::unique('subjects', 'code')->ignore($subject?->id)],
            'short_name'  => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:65000'],
            'is_active'   => ['boolean'],
        ]);
    }
}
