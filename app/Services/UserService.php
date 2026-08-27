<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function getAllUsers(int $perPage = 20)
    {
        return User::with('role')->latest()->paginate($perPage);
    }

    public function createUser(array $data): User
    {
        $data['password'] = Hash::make($data['password']);

        return User::create($data)->load('role');
    }

    public function getUser(User $user): User
    {
        return $user->load('role');
    }

    public function updateUser(User $user, array $data): User
    {
        $passwordChanged = false;
        $deactivated = array_key_exists('is_active', $data) && ! (bool) $data['is_active'];

        if (! empty($data['password'])) {
            if (Hash::check($data['password'], $user->password)) {
                unset($data['password']);
            } else {
                $data['password'] = Hash::make($data['password']);
                $passwordChanged = true;
            }
        } else {
            unset($data['password']);
        }

        $user->update($data);

        if ($passwordChanged || $deactivated) {
            $user->tokens()->delete();
        }

        return $user->fresh('role');
    }

    public function deleteUser(User $user): void
    {
        $user->tokens()->delete();
        $user->delete();
    }

    /**
     * تعيين كلمة مرور جديدة مباشرةً (تغيير المشرف). يبطل كل الجلسات القائمة
     * للمستخدم فيُلزَم بإعادة الدخول — نفس سلوك تغيير كلمة المرور في updateUser.
     */
    public function changePassword(User $user, string $password): void
    {
        $user->update(['password' => Hash::make($password)]);
        $user->tokens()->delete();
    }
}
