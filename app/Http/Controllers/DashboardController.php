<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function index(Request $request): JsonResponse
    {
        // اللوحة مفتوحة لكل مسجَّل (auth+active بلا permission)، لكنّ الجرد النقدي
        // — الخزينة والمصاريف والسحوبات والدخل الصافي — حِكرٌ على من يملك
        // manage_treasury أو view_reports. نُطابق منطق CheckPermission حرفاً.
        $canViewFinancials = $this->userCan($request, ['manage_treasury', 'view_reports']);

        $data = $this->dashboardService->getDashboardData($canViewFinancials);

        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully',
            'data'    => $data
        ]);
    }

    /**
     * هل يملك المستخدم أياً من الصلاحيات المطلوبة؟ نفس دلالة CheckPermission:
     * صلاحية ممنوحة صراحةً، أو دورٌ ضمن PERMISSION_SUPER_ROLES.
     *
     * @param  array<int,string>  $permissions
     */
    private function userCan(Request $request, array $permissions): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if (! $user->relationLoaded('role') || ! $user->role?->relationLoaded('permissions')) {
            $user->load('role.permissions');
        }

        $role = $user->role;

        if (! $role) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($role->permissions->contains('name', $permission)) {
                return true;
            }
        }

        $superRoles = (array) config('permissions.super_roles', []);

        return in_array($role->name, $superRoles, true);
    }
}
