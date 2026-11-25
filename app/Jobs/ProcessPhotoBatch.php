<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\StudentPhotoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Match a batch of photos (loose files or one ZIP) to students by student code — "26100001.jpg" belongs to
 * the student whose code is 26100001 — and store each as that student's photo.
 *
 * Runs on the queue (or inline when no worker is set up) and reports progress through its BackgroundTask.
 * The ZIP is never extracted to disk by name: each entry is read as a stream into a temporary file, so a
 * hostile path ("../../x", "/etc/…") cannot write anywhere, and size limits stop a zip bomb.
 */
class ProcessPhotoBatch implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const MAX_ENTRIES = 2000;
    public const MAX_TOTAL_BYTES = 300 * 1024 * 1024;
    private const MAX_RATIO = 100;                                   // uncompressed ÷ compressed

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public int $taskId)
    {
    }

    public function handle(StudentPhotoService $photos): void
    {
        $task = BackgroundTask::findOrFail($this->taskId);
        $by = $task->user_id ? User::find($task->user_id) : null;
        $dir = $task->payload['dir'] ?? null;

        $matched = [];
        $unmatched = [];

        try {
            $items = $this->collect($task, $unmatched);
            $task->start(count($items) + count($unmatched));
            $task->processed = count($unmatched);                       // refused entries count as handled

            foreach ($items as $item) {
                $this->one($item, $by, $photos, $matched, $unmatched);
                $task->advance();
                @unlink($item['path']);
            }

            $task->finish([
                'matched' => $matched, 'unmatched' => $unmatched,
                'matched_count' => count($matched), 'unmatched_count' => count($unmatched),
            ]);
        } catch (Throwable $e) {
            $task->fail($e);
        } finally {
            if ($dir) {
                Storage::disk('local')->deleteDirectory($dir);          // the uploaded files are never kept
            }
        }
    }

    /**
     * Turn the upload into a list of [name, path-of-a-temporary-file]. Unsafe or unusable entries go straight to $unmatched.
     *
     * @return array<int,array{name:string,path:string}>
     */
    private function collect(BackgroundTask $task, array &$unmatched): array
    {
        $items = [];
        $disk = Storage::disk('local');

        foreach ($task->payload['files'] ?? [] as $f) {
            $items[] = ['name' => $f['name'], 'path' => $disk->path($f['path'])];
        }

        if (! ($zipRel = $task->payload['zip'] ?? null)) {
            return $items;
        }

        $zip = new ZipArchive();

        if ($zip->open($disk->path($zipRel), ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('The ZIP file could not be opened.');
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new \RuntimeException('The ZIP holds more than ' . self::MAX_ENTRIES . ' files. Split it into smaller ZIPs.');
        }

        $total = 0;
        $maxBytes = (int) config('student.photo_max_kb', 2048) * 1024;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = (string) $st['name'];

            if (str_ends_with($name, '/')) {
                continue;                                               // a folder
            }

            if ($this->unsafeName($name)) {
                $unmatched[] = ['file' => $name, 'reason' => 'Unsafe file path in the ZIP; skipped.'];
                continue;
            }

            if (str_starts_with(basename($name), '.') || str_starts_with($name, '__MACOSX/')) {
                continue;                                               // hidden / Mac metadata
            }

            $total += (int) $st['size'];

            if ($total > self::MAX_TOTAL_BYTES) {
                $zip->close();
                throw new \RuntimeException('The ZIP expands to more than ' . (self::MAX_TOTAL_BYTES / 1048576) . ' MB; it was refused.');
            }

            if ($st['size'] > $maxBytes) {
                $unmatched[] = ['file' => $name, 'reason' => 'The photo is larger than ' . (int) ($maxBytes / 1024) . ' KB.'];
                continue;
            }

            if ($st['comp_size'] > 0 && $st['size'] / $st['comp_size'] > self::MAX_RATIO && $st['size'] > 1048576) {
                $unmatched[] = ['file' => $name, 'reason' => 'Suspicious compression ratio; skipped.'];
                continue;
            }

            // stream into a temporary file, with a hard stop at the size limit (the header's size can lie)
            $in = $zip->getStream($name);
            $tmp = tempnam(sys_get_temp_dir(), 'ph');
            $out = fopen($tmp, 'wb');
            $written = 0;

            while ($in && ! feof($in)) {
                $chunk = fread($in, 65536);
                $written += strlen((string) $chunk);

                if ($written > $maxBytes) {
                    break;
                }

                fwrite($out, (string) $chunk);
            }

            fclose($out);
            $in && fclose($in);

            if ($written > $maxBytes) {
                @unlink($tmp);
                $unmatched[] = ['file' => $name, 'reason' => 'The photo is larger than ' . (int) ($maxBytes / 1024) . ' KB.'];
                continue;
            }

            $items[] = ['name' => basename($name), 'path' => $tmp];
        }

        $zip->close();

        return $items;
    }

    /** zip-slip: parent folders, absolute paths, drive letters, NUL bytes */
    private function unsafeName(string $name): bool
    {
        return str_contains($name, "\0")
            || str_starts_with($name, '/') || str_starts_with($name, '\\')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || in_array('..', preg_split('#[\\\\/]+#', $name) ?: [], true);
    }

    private function one(array $item, ?User $by, StudentPhotoService $photos, array &$matched, array &$unmatched): void
    {
        $code = trim(pathinfo($item['name'], PATHINFO_FILENAME));
        $profile = $code === '' ? null : StudentProfile::where('code', $code)->first();

        if (! $profile) {
            $unmatched[] = ['file' => $item['name'], 'reason' => "No student has the code \"{$code}\"."];
            return;
        }

        try {
            $photos->save($profile, $item['path'], $by, $item['name'], 'import');
            $matched[] = ['file' => $item['name'], 'code' => $profile->code, 'name' => $profile->name_en];
        } catch (\InvalidArgumentException $e) {
            $unmatched[] = ['file' => $item['name'], 'reason' => $e->getMessage()];
        }
    }
}
