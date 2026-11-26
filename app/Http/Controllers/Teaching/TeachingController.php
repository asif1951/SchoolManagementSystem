<?php

namespace App\Http\Controllers\Teaching;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Teaching\Access;
use App\Services\Teaching\Calendar;
use Illuminate\Http\Request;

/** Shared by the module's controllers. */
abstract class TeachingController extends Controller
{
    /** stop unless the user holds at least one of the abilities (none given: any use of the module) */
    protected function need(Request $request, string ...$abilities): User
    {
        $user = $request->user();
        $ok = $abilities ? collect($abilities)->contains(fn ($a) => $user->canDo($a)) : Access::uses($user);
        abort_unless($ok, 403);

        return $user;
    }

    /** @return array<int,string> sessions that have classes, newest first */
    protected function years(): array
    {
        return SchoolClass::query()->toBase()->distinct()->orderByDesc('academic_year')->pluck('academic_year')->map(fn ($y) => (string) $y)->all()
            ?: [substr(Calendar::today(), 0, 4)];
    }

    /** the session asked for, else this calendar year's, else the newest */
    protected function year(Request $request, ?array $years = null): string
    {
        $years ??= $this->years();
        $asked = (string) $request->input('session', '');

        if (preg_match('/^\d{4}(-\d{2,4})?$/', $asked)) {
            return $asked;
        }

        $now = substr(Calendar::today(), 0, 4);

        return in_array($now, $years, true) ? $now : $years[0];
    }

    /** the unread messages of the module for this person */
    protected function notices(User $user): array
    {
        return $user->unreadNotifications()->limit(10)->get()
            ->map(fn ($n) => ['id' => $n->id, 'text' => __("teaching.{$n->data['key']}", $n->data['params'] ?? []), 'url' => $n->data['url'], 'at' => $n->created_at->format('d M, h:i A')])->all();
    }

    protected function flash(): array
    {
        return session()->only(['success', 'error']);
    }

    /** @return array<int,array{id:int,name:string}> active teachers */
    protected function teachers(): array
    {
        return User::query()->toBase()->where('role', User::ROLE_TEACHER)->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->all();
    }
}
