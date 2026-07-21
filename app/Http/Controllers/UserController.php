<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Models\Role;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
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
        return response()->json($this->userService->updateUser($user, $request->validated()));
    }
    public function destroy(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'لا يمكنك حذف حسابك الخاص.'], 422);
        }
        $this->userService->deleteUser($user);
        return response()->json(null, 204);
    }
    public function roles()
    {
        return response()->json(Role::all());
    }
}
