<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        return response()->json($this->userService->getAllUsers($perPage));
    }

    public function store(StoreUserRequest $request)
    {
        return response()->json($this->userService->createUser($request->validated()), 201);
    }

    public function show(User $user)
    {
        return response()->json($this->userService->getUser($user));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        if ($this->wouldDisableLastSuperUser($user, $request->validated())) {
            return response()->json([
                'message' => 'لا يمكن حذف أو تعطيل آخر حساب مدير في النظام.',
            ], 422);
        }

        return response()->json($this->userService->updateUser($user, $request->validated()));
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'لا يمكنك حذف حسابك الخاص.'], 422);
        }

        if ($this->wouldDisableLastSuperUser($user, ['is_active' => false])) {
            return response()->json([
                'message' => 'لا يمكن حذف أو تعطيل آخر حساب مدير في النظام.',
            ], 422);
        }

        $this->userService->deleteUser($user);

        return response()->json(null, 204);
    }

    public function roles()
    {
        return response()->json(Role::all());
    }

    private function wouldDisableLastSuperUser(User $user, array $changes): bool
    {
        if (($changes['is_active'] ?? true) !== false || ! $user->is_active) {
            return false;
        }

        $superRoles = config('permissions.super_roles', []);

        if (! in_array($user->role?->name, $superRoles, true)) {
            return false;
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('name', $superRoles))
            ->count() <= 1;
    }
}
