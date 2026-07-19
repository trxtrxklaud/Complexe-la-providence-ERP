<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function getAllUsers()
    {
        return User::with('role')->latest()->get();
    }

    public function createUser(array $data)
    {
        return User::create($data)->load('role');
    }

    public function getUser(User $user)
    {
        return $user->load('role');
    }

    public function updateUser(User $user, array $data)
    {
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);
        return $user->load('role');
    }

    public function deleteUser(User $user)
    {
        $user->delete();
    }
}
