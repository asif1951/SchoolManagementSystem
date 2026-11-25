<?php

namespace App\Http\Controllers\StudentModule;

use App\Http\Controllers\Controller;
use App\Jobs\CheckStudentImport;
use App\Jobs\ProcessStudentImport;
use App\Models\BackgroundTask;
use App\Models\SchoolClass;
use App\Models\StudentImport;
use App\Services\StudentImporter;
use App\Support\Normalize;
use App\Support\SheetStream;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;

/**
 * Import Student:  upload → every row is checked → preview (valid count; invalid rows with number and reason)
 * → confirm → saved in the background with visible progress. Nothing is saved without the confirm step.
 */
class ImportController extends Controller
{
    public function __construct(private StudentImporter $importer)
    {
    }

    public function index(Request $request)
    {
        $years = SchoolClass::query()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->all();

        return Inertia::render('StudentModule/Import', [
            'sessions' => $years ?: [(string) now()->year],
            'recent'   => StudentImport::where('user_id', $request->user()->id)->latest('id')->limit(8)->get()
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->original_name, 'status' => $i->status, 'total' => $i->total_rows, 'valid' => $i->valid_rows, 'invalid' => $i->invalid_rows,
                                   'created' => $i->summary['created'] ?? null, 'at' => $i->created_at?->format('d M Y, h:i A')]),
            'columns'  => StudentImporter::COLUMNS,
            'maxRows'  => null,   // no limit: files are streamed
            'flash'    => session()->only(['success', 'error']),
        ]);
    }

    public function template(Request $request)
    {
        $example = SchoolClass::query()->where('status', 'active')->orderByDesc('academic_year')->orderBy('class_name')->first();
        $rows = $this->importer->template($example);

        if ($request->input('format') === 'csv') {
            return response(Spreadsheet::csv($rows), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="student-import-template.csv"']);
        }

        return response(Spreadsheet::xlsx($rows, 'Students'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="student-import-template.xlsx"',
        ]);
    }

    /**
     * Upload: the file is stored and its check is queued (a file of any size is streamed, never loaded whole);
     * the screen shows the progress and then the preview.
     */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'file'    => ['required', 'file', 'max:' . (int) (SheetStream::MAX_BYTES / 1024)],
            'session' => ['required', 'digits:4'],
        ]);

        $file = $request->file('file');

        if (! in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'csv', 'txt'], true)) {
            return back()->withErrors(['file' => 'Upload an Excel (.xlsx) or CSV file.']);
        }

        $path = $file->storeAs('student-imports', Str::uuid() . '.dat', 'local');

        $import = StudentImport::create([
            'user_id' => $request->user()->id, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'path' => $path,
            'session' => $data['session'], 'status' => 'checking',
        ]);

        $task = BackgroundTask::queue('import_check', $request->user(), ['import_id' => $import->id]);
        $import->update(['task_id' => $task->id]);

        CheckStudentImport::dispatch($task->id);

        return redirect()->route('sm.import.show', $import->id);
    }

    public function show(Request $request, StudentImport $import)
    {
        $this->authorizeImport($request, $import);

        return Inertia::render('StudentModule/ImportPreview', [
            'batch' => [
                'id' => $import->id, 'name' => $import->original_name, 'session' => $import->session, 'status' => $import->status,
                'total' => $import->total_rows, 'valid' => $import->valid_rows, 'invalid' => $import->invalid_rows, 'summary' => $import->summary,
                'task_id' => $import->task_id,
            ],
            'invalidRows' => array_slice($import->invalid ?? [], 0, 200),
            'checkTaskId' => $import->status === 'checking' ? $import->task_id : null,
            'flash'       => session()->only(['success', 'error']),
        ]);
    }

    public function confirm(Request $request, StudentImport $import)
    {
        $this->authorizeImport($request, $import);

        if ($import->status !== 'previewed' || $import->valid_rows < 1 || ! $import->path) {
            return back()->with('error', 'This import cannot be confirmed.');
        }

        $task = BackgroundTask::queue('import', $request->user(), ['import_id' => $import->id], $import->valid_rows);
        $import->update(['status' => 'running', 'task_id' => $task->id]);

        ProcessStudentImport::dispatch($task->id);

        return redirect()->route('sm.import.show', $import->id);
    }

    public function cancel(Request $request, StudentImport $import)
    {
        $this->authorizeImport($request, $import);

        if ($import->status === 'previewed') {
            if ($import->path) {
                Storage::disk('local')->delete($import->path);
            }

            $import->update(['status' => 'cancelled', 'path' => null]);
        }

        return redirect()->route('sm.import.index');
    }

    /** the rows the check refused, with the reason, as a safe CSV (the whole list, however long) */
    public function report(Request $request, StudentImport $import)
    {
        $this->authorizeImport($request, $import);

        $file = "student-imports/{$import->id}-errors.csv";

        if (Storage::disk('local')->exists($file)) {
            return Storage::disk('local')->download($file, "import-{$import->id}-rows-to-fix.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->streamDownload(function () use ($import) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Row', 'Name', 'Reason'], ',', '"', '');

            foreach ($import->invalid ?? [] as $r) {
                fputcsv($out, [$r['row'], Normalize::csvCell($r['name'] ?? ''), Normalize::csvCell($r['reason'] ?? '')], ',', '"', '');
            }

            fclose($out);
        }, "import-{$import->id}-rows-to-fix.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeImport(Request $request, StudentImport $import): void
    {
        abort_unless($import->user_id === $request->user()->id || $request->user()->canDo('users.manage'), 403);
    }
}
