<?php

namespace App\Services\Mobile;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\FamilyService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PhoneAuthService
{
    /**
     * تسجيل الدخول الموحد برقم الهاتف لجميع الأدوار (إدارة، معلم، ولي أمر).
     *
     * @param string $rawPhone
     * @return array{token: string, access_token: string, token_type: string, role: string, user: array, profile: array}
     * @throws AuthenticationException
     */
    public function loginByPhone(string $rawPhone): array
    {
        $normalizedPhone = FamilyService::normalizePhone($rawPhone);

        if (! $normalizedPhone) {
            throw new AuthenticationException('رقم الهاتف غير صالح.');
        }

        // 1. فحص الإدارة (Admin User)
        $adminResult = $this->attemptAdminLogin($normalizedPhone);
        if ($adminResult) {
            return $adminResult;
        }

        // 2. فحص المعلمين (Teacher Employee)
        $teacherResult = $this->attemptTeacherLogin($normalizedPhone);
        if ($teacherResult) {
            return $teacherResult;
        }

        // 3. فحص أولياء الأمور (Parent Student)
        $parentResult = $this->attemptParentLogin($normalizedPhone);
        if ($parentResult) {
            return $parentResult;
        }

        throw new AuthenticationException('رقم الهاتف غير مسجل في النظام.');
    }

    /**
     * محاولة تسجيل الدخول كمسؤول إدارة.
     */
    protected function attemptAdminLogin(string $normalizedPhone): ?array
    {
        $superRoles = config('permissions.super_roles', ['admin']);

        $user = User::query()
            ->with(['role.permissions', 'permissionOverrides.permission'])
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->get()
            ->first(function (User $u) use ($normalizedPhone, $superRoles) {
                $roleName = $u->role?->name ?? '';
                $isSuperOrAdmin = in_array($roleName, $superRoles, true) || $roleName === 'admin';
                return $isSuperOrAdmin && FamilyService::normalizePhone($u->phone) === $normalizedPhone;
            });

        if (! $user) {
            return null;
        }

        $effectivePermissions = $user->getEffectivePermissionNames();
        $token = $user->createToken('mobile_admin', $effectivePermissions)->plainTextToken;

        $userArray = $user->toArray();
        $userArray['effective_permissions'] = $effectivePermissions;

        $profile = [
            'id' => $user->id,
            'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'phone' => $user->phone,
            'role' => 'admin',
            'email' => $user->email,
        ];

        return [
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'role' => 'admin',
            'user' => $userArray,
            'profile' => $profile,
        ];
    }

    /**
     * محاولة تسجيل الدخول كمعلم.
     */
    protected function attemptTeacherLogin(string $normalizedPhone): ?array
    {
        $employee = Employee::query()
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('staff_type', 'like', '%teacher%')
            ->get()
            ->first(function (Employee $emp) use ($normalizedPhone) {
                return FamilyService::normalizePhone($emp->phone) === $normalizedPhone;
            });

        if (! $employee) {
            return null;
        }

        $teacherRole = Role::firstOrCreate(
            ['name' => 'teacher'],
            ['display_name' => 'معلّم']
        );

        $teacherUser = User::query()
            ->where('role_id', $teacherRole->id)
            ->where('phone', $normalizedPhone)
            ->first();

        if (! $teacherUser) {
            $teacherUser = User::create([
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'username' => 'teacher_'.$normalizedPhone,
                'email' => 'teacher_'.$normalizedPhone.'@providence.local',
                'phone' => $normalizedPhone,
                'password' => Hash::make(Str::random(40)),
                'role_id' => $teacherRole->id,
                'is_active' => true,
            ]);
        }

        if (Schema::hasColumn('employees', 'user_id') && ! $employee->user_id) {
            $employee->update(['user_id' => $teacherUser->id]);
        }

        $effectivePermissions = $teacherUser->getEffectivePermissionNames();
        $token = $teacherUser->createToken('mobile_teacher', $effectivePermissions)->plainTextToken;

        $userArray = $teacherUser->toArray();
        $userArray['effective_permissions'] = $effectivePermissions;

        $profile = [
            'id' => $employee->id,
            'user_id' => $teacherUser->id,
            'name' => trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
            'phone' => $employee->phone,
            'staff_type' => $employee->staff_type,
            'role' => 'teacher',
        ];

        return [
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'role' => 'teacher',
            'user' => $userArray,
            'profile' => $profile,
        ];
    }

    /**
     * محاولة تسجيل الدخول كولي أمر.
     */
    protected function attemptParentLogin(string $normalizedPhone): ?array
    {
        $matchingStudents = Student::query()
            ->select('id', 'first_name', 'last_name', 'guardian_first_name', 'guardian_last_name', 'guardian_phone', 'mother_phone', 'status')
            ->where(function ($q) {
                $q->whereNotNull('guardian_phone')->orWhereNotNull('mother_phone');
            })
            ->get()
            ->filter(function (Student $st) use ($normalizedPhone) {
                return FamilyService::normalizePhone($st->guardian_phone) === $normalizedPhone
                    || FamilyService::normalizePhone($st->mother_phone) === $normalizedPhone;
            });

        if ($matchingStudents->isEmpty()) {
            return null;
        }

        $parentRole = Role::firstOrCreate(
            ['name' => 'parent'],
            ['display_name' => 'وليّ']
        );

        $parentUser = User::query()
            ->where('role_id', $parentRole->id)
            ->where('phone', $normalizedPhone)
            ->first();

        $firstStudent = $matchingStudents->first();

        if (! $parentUser) {
            $guardianFirstName = $firstStudent->guardian_first_name ?: 'وليّ';
            $guardianLastName = $firstStudent->guardian_last_name ?: $normalizedPhone;

            $parentUser = User::create([
                'first_name' => $guardianFirstName,
                'last_name' => $guardianLastName,
                'username' => 'parent_'.$normalizedPhone,
                'email' => 'parent_'.$normalizedPhone.'@parent.local',
                'phone' => $normalizedPhone,
                'password' => Hash::make(Str::random(40)),
                'role_id' => $parentRole->id,
                'is_active' => true,
            ]);
        }

        $effectivePermissions = $parentUser->getEffectivePermissionNames();
        $token = $parentUser->createToken('mobile_parent', $effectivePermissions)->plainTextToken;

        $userArray = $parentUser->toArray();
        $userArray['effective_permissions'] = $effectivePermissions;

        $profile = [
            'id' => $parentUser->id,
            'name' => trim(($parentUser->first_name ?? '').' '.($parentUser->last_name ?? '')),
            'phone' => $normalizedPhone,
            'children_count' => $matchingStudents->count(),
            'children_ids' => $matchingStudents->pluck('id')->values()->all(),
            'role' => 'parent',
        ];

        return [
            'token' => $token,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'role' => 'parent',
            'user' => $userArray,
            'profile' => $profile,
        ];
    }
}
