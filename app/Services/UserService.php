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
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $user->update($data);
        return $user->fresh('role');
    }
    public function deleteUser(User $user): void
    {
        $user->delete();
    }
}
