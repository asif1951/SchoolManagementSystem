<?php

namespace App\Services;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Student photos: checked as real images, cropped square, resized, compressed, stored as WebP under a
 * random name, and the previous photo deleted. Used by the single upload, the bulk upload and the ZIP upload.
 */
class StudentPhotoService
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** a photo larger than this many pixels is refused before it is decoded (a decompression-bomb guard) */
    private const MAX_PIXELS = 36_000_000;

    /**
     * Why this file cannot be used as a photo, or null when it can.
     * The type is read from the file's own bytes, never trusted from its name or the browser.
     */
    public function problem(string $path, ?string $originalName = null): ?string
    {
        if (! is_file($path)) {
            return 'The file could not be read.';
        }

        $maxKb = (int) config('student.photo_max_kb', 2048);

        if (filesize($path) > $maxKb * 1024) {
            return "The photo is larger than {$maxKb} KB.";
        }

        $ext = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));

        if ($originalName !== null && ! in_array($ext, self::EXTENSIONS, true)) {
            return 'Only JPG, PNG or WebP photos are accepted.';
        }

        // the type comes from the file's own first bytes, read with an ordinary stream (finfo::file() cannot open a
        // file that an upload still holds open on Windows)
        $head = (string) @file_get_contents($path, false, null, 0, 8192);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);

        if (! in_array($mime, self::MIME, true)) {
            return 'This is not a JPG, PNG or WebP image.';
        }

        $size = @getimagesize($path);

        if (! $size || $size[0] < 1 || $size[1] < 1) {
            return 'This file is not a readable image.';
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            return 'The image has too many pixels; please reduce it.';
        }

        return null;
    }

    /**
     * Store $path as the student's photo and return the stored path.
     *
     * @throws \InvalidArgumentException  when the file is not an acceptable photo
     */
    public function save(StudentProfile $profile, string $path, ?User $by, ?string $originalName = null, string $source = 'screen'): string
    {
        if ($problem = $this->problem($path, $originalName)) {
            throw new \InvalidArgumentException($problem);
        }

        $px = (int) config('student.photo_size', 400);

        try {
            $encoded = (new ImageManager(new Driver()))->read($path)->cover($px, $px)->toWebp(82)->toString();
        } catch (Throwable $e) {
            throw new \InvalidArgumentException('This image could not be processed.', 0, $e);
        }

        $new = 'students/' . Str::random(40) . '.webp';           // a random name: never the uploaded name
        Storage::disk('public')->put($new, $encoded);

        $old = $profile->photo;

        StudentProfile::inSource($source, fn () => $profile->update(['photo' => $new]), $by);

        if ($old && str_starts_with($old, 'students/') && Storage::disk('public')->exists($old)) {
            Storage::disk('public')->delete($old);                // replaced, not piled up
        }

        return $new;
    }
}
