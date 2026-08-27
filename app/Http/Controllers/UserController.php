<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
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
        $user = $this->userService->createUser($request->validated());

        AuditService::log('user.create', 'إنشاء مستخدم: '.trim($user->first_name.' '.$user->last_name), $user);

        return response()->json($user, 201);
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

        $updated = $this->userService->updateUser($user, $request->validated());

        AuditService::log('user.update', 'تعديل مستخدم: '.trim($updated->first_name.' '.$updated->last_name), $updated, ['fields' => array_keys($request->validated())]);

        return response()->json($updated);
    }

    /**
     * تغيير كلمة مرور مستخدم مباشرةً من قِبل المشرف — دون كلمة المرور القديمة.
     */
    public function changePassword(ChangePasswordRequest $request, User $user)
    {
        $this->userService->changePassword($user, $request->validated()['password']);

        AuditService::log('user.password_changed', 'تغيير كلمة مرور المستخدم: '.trim($user->first_name.' '.$user->last_name), $user);

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح']);
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
