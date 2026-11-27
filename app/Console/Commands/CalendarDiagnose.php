<?php

namespace App\Console\Commands;

use App\Services\AcademicCalendar;
use App\Services\CalendarEditor;
use App\Services\RoutineService;
use App\Support\SheetStream;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * When the calendar behaves differently on two servers: run this on both and compare. It shows the versions, whether
 * the code is the same, the settings that decide working days, and how each row of a file is read. It changes nothing.
 */
class CalendarDiagnose extends Command
{
    protected $signature = 'calendar:diagnose {file? : an .xlsx or .csv calendar file to read (nothing is saved)} {--rows=60 : how many rows of the file to show}';

    protected $description = 'Show why the Academic Calendar may differ between servers (read only)';

    /** the files that decide how a calendar file is read and shown */
    private const FILES = [
        'app/Services/CalendarEditor.php', 'app/Services/AcademicCalendar.php', 'app/Http/Controllers/CalendarController.php', 'app/Models/CalendarEvent.php',
        'app/Support/SheetStream.php', 'app/Support/Spreadsheet.php', 'app/Support/Normalize.php', 'app/Support/BanglaDate.php',
        'resources/js/Components/Calendar/BulkDialog.vue', 'resources/js/lib/calendar.js', 'resources/js/lib/bangla.js',
    ];

    public function handle(CalendarEditor $editor, AcademicCalendar $calendar, RoutineService $routines): int
    {
        $this->info('1. Server (command line; the website may use another PHP version, see cPanel)');
        $this->line('   PHP ' . PHP_VERSION . ' on ' . PHP_OS . ', ' . (PHP_INT_SIZE * 8) . '-bit, libxml ' . LIBXML_DOTTED_VERSION);
        $this->line('   extensions: ' . collect(['zip', 'xmlreader', 'simplexml', 'mbstring', 'dom'])->map(fn ($e) => $e . (extension_loaded($e) ? ' yes' : ' NO'))->implode(', '));
        $this->line('   database: ' . $this->try(fn () => DB::selectOne('select version() v')->v));
        $this->line('   app timezone ' . config('app.timezone') . ', config cached: ' . (app()->configurationIsCached() ? 'yes' : 'no') . ', today ' . $this->try(fn () => AcademicCalendar::today()));

        $this->info('2. Code (the same letters mean the same file; line endings are ignored)');

        foreach ([...self::FILES, 'public/build/manifest.json'] as $f) {
            $path = base_path($f);
            $this->line('   ' . (is_file($path) ? substr(md5(str_replace("\r", '', (string) file_get_contents($path))), 0, 10) : 'MISSING   ') . "  {$f}");
        }

        $this->info('3. Calendar data');
        $this->line('   calendar table: ' . $this->try(fn () => Schema::hasTable('calendar_events') ? 'present' : 'MISSING (run php artisan migrate)'));
        $this->line('   working weekdays (0 = Sunday): ' . $this->try(fn () => json_encode($routines->workingDays())));
        $this->line('   entries by type: ' . $this->try(fn () => json_encode(DB::table('calendar_events')->groupBy('type')->selectRaw('type, count(*) n')->pluck('n', 'type'))));
        $year = $this->try(fn () => substr(AcademicCalendar::today(), 0, 4));
        $this->line("   working days in {$year}: " . $this->try(fn () => $calendar->countWorkingDays("{$year}-01-01", "{$year}-12-31")));

        $this->info('4. Reading dates (expected → got)');

        foreach (['2026-03-26' => '2026-03-26', '26/03/2026' => '2026-03-26', '03/04/2026' => '2026-04-03', '46107' => '2026-03-26', '১২ চৈত্র ১৪৩২' => '2026-03-26'] as $typed => $expected) {
            $got = $this->try(fn () => $editor->readDate((string) $typed) ?? 'not understood');
            $this->line('   ' . ($got === $expected ? 'ok   ' : 'WRONG') . "  {$typed}  →  {$expected}" . ($got === $expected ? '' : "  got {$got}"));
        }

        return $this->argument('file') ? $this->readFile($editor, (string) $this->argument('file')) : self::SUCCESS;
    }

    private function readFile(CalendarEditor $editor, string $file): int
    {
        $path = is_file($file) ? $file : base_path($file);

        if (! is_file($path)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $this->info('5. The file: ' . basename($path) . ', ' . filesize($path) . ' bytes, fingerprint ' . substr(md5_file($path), 0, 10) . ', read as ' . SheetStream::kind($path));
        $limit = max(1, (int) $this->option('rows'));
        $shown = 0;

        foreach (SheetStream::rows($path) as $n => $cells) {
            $cells = array_pad(array_values($cells), 5, '');

            if ($n === 1 && $editor->readDate((string) $cells[0]) === null) {
                $this->line('   row 1 (headings): ' . implode(' | ', $cells));

                continue;
            }

            $checked = $editor->check([$n => array_slice($cells, 0, 5)])[0];
            $read = $checked['data'] ? "{$checked['data']['starts_on']} to {$checked['data']['ends_on']} as {$checked['data']['type']}" . ($checked['duplicate'] ? ' (already there)' : '') : "ERROR: {$checked['error']}";
            $this->line("   row {$n}: [" . implode('] [', array_slice($cells, 0, 4)) . "]  =>  {$read}");

            if (++$shown >= $limit) {
                $this->line('   … (use --rows= to see more)');
                break;
            }
        }

        return self::SUCCESS;
    }

    private function try(callable $fn): string
    {
        try {
            return (string) $fn();
        } catch (Throwable $e) {
            return 'ERROR: ' . $e->getMessage();
        }
    }
}
