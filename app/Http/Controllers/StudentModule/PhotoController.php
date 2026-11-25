<?php

namespace App\Http\Controllers\StudentModule;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPhotoBatch;
use App\Models\BackgroundTask;
use App\Models\StudentProfile;
use App\Services\StudentPhotoService;
use App\Support\Normalize;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Student Image Upload: one photo for one student (with preview), or many at once — loose files or one
 * ZIP — matched to students by student code. Bulk work runs as a background task with visible progress.
 */
class PhotoController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('StudentModule/Images', [
            'recent'  => BackgroundTask::where('type', 'photo_batch')->where('user_id', $request->user()->id)->latest('id')->limit(8)->get()->map->toProgress(),
            'maxKb'   => (int) config('student.photo_max_kb'),
            'maxZip'  => ProcessPhotoBatch::MAX_ENTRIES,
            'task'    => session('task'),
            'flash'   => session()->only(['success', 'error']),
        ]);
    }

    /** typeahead for the single upload: a few students whose code starts with, or name starts with, the text */
    public function find(Request $request)
    {
        $q = trim((string) $request->input('q'));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json(
            StudentProfile::query()
                ->where(fn ($w) => $w->where('code', 'like', $q . '%')->orWhere('name_normalized', 'like', Normalize::name($q) . '%'))
                ->orderBy('name_normalized')
                ->limit(10)
                ->get(['id', 'code', 'name_en', 'photo'])
                ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name_en, 'photo' => $p->photo])
        );
    }

    public function storeOne(Request $request, StudentProfile $profile, StudentPhotoService $photos)
    {
        $request->validate(['photo' => ['required', 'file', 'max:' . (int) config('student.photo_max_kb', 2048)]]);

        $file = $request->file('photo');

        try {
            $photos->save($profile, $file->getRealPath(), $request->user(), $file->getClientOriginalName());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        return back()->with('success', "Photo saved for {$profile->name_en}.");
    }

    public function storeBulk(Request $request)
    {
        $request->validate([
            'files'   => ['nullable', 'array', 'max:200'],
            'files.*' => ['file', 'max:' . (int) config('student.photo_max_kb', 2048)],
            'zip'     => ['nullable', 'file', 'mimes:zip', 'max:102400'],
        ]);

        if (! $request->hasFile('files') && ! $request->hasFile('zip')) {
            return back()->withErrors(['files' => 'Choose photos or a ZIP file first.']);
        }

        // a ZIP is recognised by what is inside it, not by its name
        if ($request->hasFile('zip')) {
            $head = (string) @file_get_contents($request->file('zip')->getRealPath(), false, null, 0, 4);

            if (! in_array($head, ["PK" . chr(3) . chr(4), "PK" . chr(5) . chr(6)], true)) {          // a ZIP, or an empty ZIP
                return back()->withErrors(['zip' => 'This is not a ZIP file.']);
            }
        }

        // the upload waits in a private folder under random names until the task has used it
        $dir = 'photo-batches/' . Str::uuid();
        $payload = ['dir' => $dir, 'files' => [], 'zip' => null];

        foreach ($request->file('files', []) as $f) {
            $stored = $f->storeAs($dir, Str::random(24) . '.dat', 'local');
            $payload['files'][] = ['name' => $f->getClientOriginalName(), 'path' => $stored];
        }

        if ($request->hasFile('zip')) {
            $payload['zip'] = $request->file('zip')->storeAs($dir, Str::random(24) . '.zip', 'local');
        }

        $task = BackgroundTask::queue('photo_batch', $request->user(), $payload, count($payload['files']));

        ProcessPhotoBatch::dispatch($task->id);

        return redirect()->route('sm.images.index')->with('task', $task->id);
    }
}
