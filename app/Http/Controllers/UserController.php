<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    /**
     * Display a listing of users with pagination and filtering.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // ✅ Search
        if ($request->filled('search')) {
            $search = trim($request->search);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
        }

        // ✅ Status filter
        if ($request->filled('status') && $request->status !== '' && Schema::hasColumn('users', 'status')) {
            $query->where('status', $request->status);
        }

        // ✅ Role filter
        if ($request->filled('role') && $request->role !== '' && Schema::hasColumn('users', 'role')) {
            $query->where('role', $request->role);
        }

        // ✅ Sorting
        $sortField = $request->input('sortField', 'created_at');
        $sortDirection = strtolower($request->input('sortDirection', 'desc'));

        $allowedSortFields = ['id', 'name', 'email', 'role', 'status', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'created_at';
        }
        if (!in_array($sortDirection, ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        $query->orderBy($sortField, $sortDirection);

        // ✅ Pagination (backend)
        $users = $query->paginate(10)->withQueryString();

        // ✅ Page out of range hole (jemon last page-er shob user delete korle) last valid page-e jao
        if ($users->isEmpty() && $users->currentPage() > 1) {
            return redirect()->to(
                $request->fullUrlWithQuery(['page' => $users->lastPage()])
            );
        }

        // ✅ Stats (filter/pagination-er upor depend kore na)
        $stats = [
            'total'    => User::count(),
            'admins'   => User::where('role', 'admin')->count(),
            'teachers' => User::where('role', 'teacher')->count(),
        ];

        return inertia('users', [
            'users' => $users,
            'stats' => $stats,
            'filters' => [
                'search'        => $request->input('search', ''),
                'status'        => $request->input('status', ''),
                'role'          => $request->input('role', ''),
                'sortField'     => $sortField,
                'sortDirection' => $sortDirection,
            ],
            'flash' => session()->only(['success', 'error', 'warning']),
        ]);
    }

    /**
     * Store a new user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => 'required|string|in:admin,teacher,user',
            'status'   => 'required|string|in:active,inactive,pending,suspended',
        ]);

        User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => $validated['role'],
            'status'   => $validated['status'],
        ]);

        return redirect()
            ->to('/users?' . http_build_query($request->only(['search', 'status', 'role', 'sortField', 'sortDirection', 'page'])))
            ->with('success', 'User created successfully!');
    }

    /**
     * Update an existing user.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email,' . $id,
            'role'     => 'required|string|in:admin,teacher,user',
            'status'   => 'required|string|in:active,inactive,pending,suspended',
            'password' => 'nullable|string|min:8',
        ]);

        // ✅ নিজের admin role পরিবর্তনে বাধা (optional safety)
        // if ($user->id === auth()->id() && $validated['role'] !== User::ROLE_ADMIN) {
        //     return redirect()->back()->with('error', 'You cannot change your own admin role.');
        // }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];
        $user->status = $validated['status'];

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return redirect()
            ->to('/users?' . http_build_query($request->only(['search', 'status', 'role', 'sortField', 'sortDirection', 'page'])))
            ->with('success', 'User updated successfully!');
    }

    /**
     * Delete a user.
     */
    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // ✅ নিজেকে delete করা থেকে বিরত
        if ($user->id === auth()->id()) {
            return redirect()->back()->with('error', 'You cannot delete your own account.');
        }

        // ✅ শেষ admin delete করা থেকে বিরত
        if ($user->isAdmin() && User::where('role', User::ROLE_ADMIN)->count() <= 1) {
            return redirect()->back()->with('error', 'Cannot delete the last admin user.');
        }

        $user->delete();

        return redirect()
            ->to('/users?' . http_build_query($request->only(['search', 'status', 'role', 'sortField', 'sortDirection', 'page'])))
            ->with('success', 'User deleted successfully!');
    }
}