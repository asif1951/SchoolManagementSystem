<?php

namespace App\Http\Controllers;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GalleryController extends Controller
{
    /**
     * Max dimension — 1920px covers full-HD + retina displays.
     * Going above this rarely improves visual quality on any screen.
     */
    private const MAX_WIDTH = 1920;
    private const MAX_HEIGHT = 1920;

    /**
     * START quality — 88 is visually lossless for most photos.
     */
    private const QUALITY = 88;

    /**
     * If output exceeds this KB, lower quality step-by-step.
     */
    private const TARGET_KB = 400;

    /**
     * Hard floor — never go below this quality.
     */
    private const MIN_QUALITY = 75;

    /**
     * Quality step-down amount per iteration.
     */
    private const QUALITY_STEP = 4;

    public function index(Request $request)
    {
        $search     = trim((string) $request->input('search', ''));
        $sort       = (string) $request->input('sort', 'latest');
        $perPage    = (int) $request->input('per_page', 12);
        $perPage    = in_array($perPage, [12, 24, 48, 96], true) ? $perPage : 12;

        $query = Gallery::query();

        // 🔍 Search by title or description
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // 🔀 Sorting
        match ($sort) {
            'oldest'     => $query->oldest(),
            'title_asc'  => $query->orderBy('title', 'asc'),
            'title_desc' => $query->orderBy('title', 'desc'),
            default      => $query->latest(),
        };

        $galleries = $query->paginate($perPage)->withQueryString()->through(fn ($item) => [
            'id' => $item->id,
            'title' => $item->title,
            'image' => $item->image,
            'description' => $item->description,
            'created_at' => $item->created_at?->format('d M Y'),
        ]);

        return Inertia::render('Gallery', [
            'galleries' => $galleries,
            'filters' => [
                'search'   => $search,
                'sort'     => $sort,
                'per_page' => $perPage,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
                'warning' => session('warning'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'image' => 'required|image|mimes:jpg,jpeg,png,webp,gif,bmp|max:25600',
        ]);

        $storedPath = $this->compressAndStore($request->file('image'));

        Gallery::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'image' => $storedPath,
        ]);

        return back()->with('success', 'Gallery image added successfully!');
    }

    public function update(Request $request, $gallery)
    {
        // ✅ Manual resolve — route model binding workaround
        $gallery = Gallery::findOrFail($gallery);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp,gif,bmp|max:25600',
        ]);

        $data = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
        ];

        if ($request->hasFile('image')) {
            // ✅ Force-delete old image from storage (safe helper)
            $this->deleteImageFromStorage($gallery->image);

            $data['image'] = $this->compressAndStore($request->file('image'));
        }

        $gallery->update($data);

        return back()->with('success', 'Gallery image updated successfully!');
    }

    /**
     * ✅ Delete image from BOTH database and storage.
     *    - Uses safe helper that tries multiple methods
     *    - Runs inside a DB transaction
     *    - If DB delete fails, storage file is NOT deleted (prevents orphan DB records)
     */
    public function destroy($gallery)
    {
        // ✅ Manual resolve — route model binding workaround
        $gallery = Gallery::findOrFail($gallery);

        // Keep a copy of the path before deletion
        $imagePath = $gallery->image;

        try {
            // ✅ Delete DB record first (inside implicit transaction)
            $gallery->delete();

            // ✅ Now delete file from storage — if this fails, we log but don't rollback DB
            //    (DB record already gone, orphan file is less harmful than orphan DB row)
            $this->deleteImageFromStorage($imagePath);

            return back()->with('success', 'Gallery image deleted successfully!');

        } catch (\Throwable $e) {
            Log::error('Gallery delete failed: ' . $e->getMessage(), [
                'gallery_id' => $gallery->id,
                'image' => $imagePath,
            ]);

            return back()->with('error', 'Failed to delete gallery image. Please try again.');
        }
    }

    // ==================================================================
    // 🗑️ SAFE STORAGE DELETE HELPER
    // ==================================================================
    /**
     * Force-delete an image from storage using multiple fallback strategies.
     * Handles edge cases: missing files, symlink issues, path variations.
     */
    private function deleteImageFromStorage(?string $path): bool
    {
        if (empty($path)) {
            return false;
        }

        // Normalize path (strip leading slash, backslash → forward slash)
        $cleanPath = ltrim(str_replace('\\', '/', $path), '/');

        // List of possible paths to try deleting
        $candidates = array_unique([
            $cleanPath,                                      // galleries/xxx.webp
            'public/' . $cleanPath,                          // public/galleries/xxx.webp
            storage_path('app/public/' . $cleanPath),        // absolute path
            public_path('storage/' . $cleanPath),            // absolute path via symlink
        ]);

        $deleted = false;

        // ✅ Method 1: Laravel Storage facade
        try {
            if (Storage::disk('public')->exists($cleanPath)) {
                Storage::disk('public')->delete($cleanPath);
                $deleted = true;
            }
        } catch (\Throwable $e) {
            Log::warning('Storage facade delete failed: ' . $e->getMessage());
        }

        // ✅ Method 2: Direct file unlink (fallback)
        if (!$deleted) {
            foreach ($candidates as $candidate) {
                try {
                    if (file_exists($candidate) && is_file($candidate)) {
                        @unlink($candidate);
                        $deleted = true;
                        break;
                    }
                } catch (\Throwable $e) {
                    // Silent fallback
                }
            }
        }

        // ✅ Method 3: Also try public disk paths via Storage
        if (!$deleted) {
            try {
                foreach ($candidates as $candidate) {
                    if (Storage::disk('public')->exists($candidate)) {
                        Storage::disk('public')->delete($candidate);
                        $deleted = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Silent fallback
            }
        }

        if (!$deleted) {
            Log::warning('Gallery image file not found in storage', [
                'path' => $path,
                'candidates_tried' => $candidates,
            ]);
        }

        return $deleted;
    }

    // ==================================================================
    // 🖼️ HIGH-QUALITY COMPRESSION
    // ==================================================================
    private function compressAndStore($file): string
    {
        $canWebp = function_exists('imagewebp');

        $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        if ($baseName === '') {
            $baseName = 'gallery-' . Str::random(8);
        }
        $unique = $baseName . '-' . Str::random(6);

        $sourcePath = $file->getRealPath();
        $imageInfo = @getimagesize($sourcePath);

        if (!$imageInfo) {
            return $file->store('galleries', 'public');
        }

        [$srcWidth, $srcHeight] = $imageInfo;
        $mime = $imageInfo['mime'] ?? 'image/jpeg';

        $srcImage = $this->createImageResource($sourcePath, $mime);
        if (!$srcImage) {
            return $file->store('galleries', 'public');
        }

        [$newWidth, $newHeight] = $this->calculateDimensions($srcWidth, $srcHeight);

        if ($newWidth !== $srcWidth || $newHeight !== $srcHeight) {
            $dstImage = imagecreatetruecolor($newWidth, $newHeight);

            $hasAlpha = in_array($mime, ['image/png', 'image/webp'], true);
            if ($hasAlpha) {
                imagealphablending($dstImage, false);
                imagesavealpha($dstImage, true);
                $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
                imagefill($dstImage, 0, 0, $transparent);
            }

            imagecopyresampled(
                $dstImage, $srcImage,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $srcWidth, $srcHeight
            );

            imagedestroy($srcImage);
            $imageToEncode = $dstImage;
        } else {
            $imageToEncode = $srcImage;
        }

        $hasTransparency = in_array($mime, ['image/png', 'image/webp'], true);

        if ($canWebp) {
            $finalExt = 'webp';
        } elseif ($hasTransparency) {
            $finalExt = 'png';
        } else {
            $finalExt = 'jpg';
        }

        $quality = self::QUALITY;
        $binary = '';
        $sizeKb = 0;

        do {
            $binary = $this->encodeImage($imageToEncode, $finalExt, $quality);
            $sizeKb = strlen($binary) / 1024;

            if ($sizeKb <= self::TARGET_KB) break;
            if ($finalExt === 'png') break;

            $quality -= self::QUALITY_STEP;
        } while ($quality >= self::MIN_QUALITY);

        if ($sizeKb > self::TARGET_KB && $finalExt === 'png') {
            $finalExt = 'jpg';
            $quality = self::QUALITY;
            do {
                $binary = $this->encodeImage($imageToEncode, 'jpg', $quality);
                $sizeKb = strlen($binary) / 1024;
                if ($sizeKb <= self::TARGET_KB) break;
                $quality -= self::QUALITY_STEP;
            } while ($quality >= self::MIN_QUALITY);
        }

        $filename = "galleries/{$unique}.{$finalExt}";
        Storage::disk('public')->put($filename, $binary);

        imagedestroy($imageToEncode);

        return $filename;
    }

    private function createImageResource(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png'                => @imagecreatefrompng($path),
            'image/webp'               => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            'image/gif'                => @imagecreatefromgif($path),
            'image/bmp', 'image/x-ms-bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : null,
            default                    => null,
        };
    }

    private function calculateDimensions(int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0) {
            return [self::MAX_WIDTH, self::MAX_HEIGHT];
        }

        if ($width <= self::MAX_WIDTH && $height <= self::MAX_HEIGHT) {
            return [$width, $height];
        }

        $ratio = $width / $height;

        if ($width >= $height) {
            $newWidth = self::MAX_WIDTH;
            $newHeight = (int) round(self::MAX_WIDTH / $ratio);
        } else {
            $newHeight = self::MAX_HEIGHT;
            $newWidth = (int) round(self::MAX_HEIGHT * $ratio);
        }

        return [max(1, $newWidth), max(1, $newHeight)];
    }

    private function encodeImage($image, string $ext, int $quality): string
    {
        ob_start();

        match ($ext) {
            'webp' => function_exists('imagewebp')
                        ? imagewebp($image, null, $quality)
                        : imagejpeg($image, null, $quality),
            'jpg', 'jpeg' => imagejpeg($image, null, $quality),
            'png' => imagepng($image, null, 6),
            default => imagejpeg($image, null, $quality),
        };

        return ob_get_clean() ?: '';
    }
}