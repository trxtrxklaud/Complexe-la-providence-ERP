<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetUserPermissionOverrideRequest;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserPermissionOverrideController extends Controller
{
    /**
     * Display a full breakdown of permissions for a given user:
     * - Role permission status
     * - Direct override (grant / deny / none)
     * - Final effective status
     */
    public function index(User $user): JsonResponse
    {
        $user->loadMissing(['role.permissions', 'permissionOverrides.permission']);

        $allPermissions = Permission::orderBy('group')->orderBy('id')->get();
        $rolePermissionIds = $user->role ? $user->role->permissions->pluck('id')->all() : [];
        $overrides = $user->permissionOverrides->keyBy('permission_id');

        $result = $allPermissions->map(function (Permission $permission) use ($user, $rolePermissionIds, $overrides) {
            $inRole = in_array($permission->id, $rolePermissionIds, true);
            $override = $overrides->get($permission->id);
            $overrideEffect = $override?->effect; // 'grant', 'deny', or null

            $isEffective = $user->hasPermissionTo($permission->name);

            return [
                'permission_id'   => $permission->id,
                'name'            => $permission->name,
                'display_name'    => $permission->display_name,
                'group'           => $permission->group,
                'in_role'         => $inRole,
                'override_effect' => $overrideEffect,
                'is_effective'    => $isEffective,
            ];
        });

        return response()->json([
            'user' => [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'username'   => $user->username,
                'email'      => $user->email,
                'role'       => $user->role ? [
                    'id'           => $user->role->id,
                    'name'         => $user->role->name,
                    'display_name' => $user->role->display_name,
                ] : null,
            ],
            'permissions' => $result,
        ]);
    }

    /**
     * Set or update a direct permission override using POST.
     */
    public function store(SetUserPermissionOverrideRequest $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'لا يمكنك تعديل صلاحيات حسابك الشخصي.',
            ], 422);
        }

        $validated = $request->validated();

        $override = UserPermissionOverride::firstOrNew([
            'user_id'       => $user->id,
            'permission_id' => $validated['permission_id'],
        ]);

        if (! $override->exists) {
            $override->created_by = $request->user()->id;
        }

        $override->effect = $validated['effect'];
        $override->save();

        $override->load('permission');

        return response()->json([
            'message'               => 'تم تحديث الصلاحية المباشرة بنجاح.',
            'override'              => $override,
            'effective_permissions' => $user->fresh()->getEffectivePermissionNames(),
        ], 200);
    }

    /**
     * Set or update a direct permission override using PUT /users/{user}/permission-overrides/{permission}.
     */
    public function update(Request $request, User $user, Permission $permission): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'لا يمكنك تعديل صلاحيات حسابك الشخصي.',
            ], 422);
        }

        $request->validate([
            'effect' => [
                'required',
                'string',
                Rule::in(UserPermissionOverride::VALID_EFFECTS),
            ],
        ], [
            'effect.required' => 'حقل نوع الاستثناء مطلوب.',
            'effect.in'       => 'نوع الاستثناء يجب أن يكون grant أو deny.',
        ]);

        $override = UserPermissionOverride::firstOrNew([
            'user_id'       => $user->id,
            'permission_id' => $permission->id,
        ]);

        if (! $override->exists) {
            $override->created_by = $request->user()->id;
        }

        $override->effect = $request->input('effect');
        $override->save();

        $override->load('permission');

        return response()->json([
            'message'               => 'تم تحديث الصلاحية المباشرة بنجاح.',
            'override'              => $override,
            'effective_permissions' => $user->fresh()->getEffectivePermissionNames(),
        ], 200);
    }

    /**
     * Remove direct permission override (fallback to role).
     */
    public function destroy(Request $request, User $user, Permission $permission): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'لا يمكنك تعديل صلاحيات حسابك الشخصي.',
            ], 422);
        }

        UserPermissionOverride::where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->delete();

        return response()->json([
            'message'               => 'تمت إزالة الاستثناء المباشر والعودة لصلاحية الدور.',
            'effective_permissions' => $user->fresh()->getEffectivePermissionNames(),
        ]);
    }

    /**
     * Get effective permission names for a given user.
     */
    public function effectivePermissions(User $user): JsonResponse
    {
        return response()->json([
            'user_id'               => $user->id,
            'effective_permissions' => $user->getEffectivePermissionNames(),
        ]);
    }
}
