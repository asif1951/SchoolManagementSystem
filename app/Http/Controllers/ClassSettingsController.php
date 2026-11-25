<?php

namespace App\Http\Controllers;

use App\Models\Medium;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Admin screen for the two lists every class picks from: shifts and mediums.
 */
class ClassSettingsController extends Controller
{
    /** the two lists, keyed by the URL segment */
    private const KINDS = ['shifts' => Shift::class, 'mediums' => Medium::class];

    public function index()
    {
        $rows = fn (string $model) => $model::query()
            ->withCount('classes')
            ->ordered()
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'sort_order' => $r->sort_order,
                'is_active' => $r->is_active, 'classes_count' => $r->classes_count,
            ]);

        return Inertia::render('ClassSettings', [
            'shifts'  => $rows(Shift::class),
            'mediums' => $rows(Medium::class),
            'flash'   => session()->only(['success', 'error']),
        ]);
    }

    public function store(Request $request, string $kind)
    {
        $model = $this->model($kind);
        $data  = $this->validated($request, $kind);
        $data['sort_order'] ??= ((int) $model::max('sort_order')) + 1;

        $row = $model::create($data);

        return back()->with('success', "\"{$row->name}\" added.");
    }

    public function update(Request $request, string $kind, int $id)
    {
        $row = $this->model($kind)::findOrFail($id);

        $data = $this->validated($request, $kind, $row->id);

        // the last active entry can't be switched off: a new class would have nothing to pick
        if (($data['is_active'] ?? true) === false && $row->is_active && $this->model($kind)::active()->count() <= 1) {
            return back()->with('error', 'At least one ' . rtrim($kind, 's') . ' must stay active.');
        }

        $row->update($data);

        return back()->with('success', "\"{$row->name}\" updated.");
    }

    public function destroy(string $kind, int $id)
    {
        $row = $this->model($kind)::findOrFail($id);

        if ($row->classes()->exists()) {
            return back()->with('error', "\"{$row->name}\" is used by classes. Deactivate it instead.");
        }

        if ($this->model($kind)::active()->where('id', '!=', $row->id)->doesntExist()) {
            return back()->with('error', 'At least one ' . rtrim($kind, 's') . ' must stay.');
        }

        $name = $row->name;
        $row->delete();

        return back()->with('success', "\"{$name}\" deleted.");
    }

    private function model(string $kind): string
    {
        return self::KINDS[$kind] ?? abort(404);
    }

    private function validated(Request $request, string $kind, ?int $ignore = null): array
    {
        $request->merge(['name' => trim((string) preg_replace('/\s+/u', ' ', (string) $request->input('name')))]);

        return $request->validate([
            'name'       => ['required', 'string', 'max:60', Rule::unique($kind, 'name')->ignore($ignore)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'is_active'  => ['boolean'],
        ]);
    }
}
