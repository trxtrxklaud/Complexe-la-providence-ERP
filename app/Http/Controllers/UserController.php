<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Services\UserService;
use App\Http\Middleware\CheckPermission;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
        $this->middleware(CheckPermission::class.':users.view')->only(['index', 'show', 'roles']);
        $this->middleware(CheckPermission::class.':users.create')->only('store');
        $this->middleware(CheckPermission::class.':users.edit')->only('update');
        $this->middleware(CheckPermission::class.':users.delete')->only('destroy');
    }

    public function index()
    {
        $users = $this->userService->getAllUsers();
        return response()->json($users);
    }

    public function store(StoreUserRequest $request)
    {
        $user = $this->userService->createUser($request->validated());
        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return response()->json($this->userService->getUser($user));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $updatedUser = $this->userService->updateUser($user, $request->validated());
        return response()->json($updatedUser);
    }

    public function destroy(User $user)
    {
        $this->userService->deleteUser($user);
        return response()->json(null, 204);
    }

    public function roles()
    {
        return response()->json(Role::all());
    }
}
