import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';

interface ProtectedRouteProps {
  children: React.ReactNode;
  /** صلاحية واحدة مطلوبة. */
  permission?: string;
  /**
   * يكفي امتلاك واحدة من هذه الصلاحيات.
   *
   * ضروري للموديولات المختلطة: /income يجمع شاشة الاستخلاص (manage_payments)
   * مع شاشات التقارير (view_reports)، و/treasury تبويبه الافتراضي هو الكشف
   * اليومي وهو مسار تقارير. حصر مثل هذه الموديولات في صلاحية واحدة يمنع
   * مستخدمين شرعيين من صفحات يسمح لهم الـ backend بها فعلاً.
   */
  anyOf?: string[];
}

export function ProtectedRoute({ children, permission, anyOf }: ProtectedRouteProps) {
  const { user, loading, hasPermission } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-slate-50">
        <div className="text-slate-500">جاري التحميل...</div>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  const required = anyOf && anyOf.length > 0 ? anyOf : permission ? [permission] : [];
  const allowed = required.length === 0 || required.some((name) => hasPermission(name));

  if (!allowed) {
    return (
      <div className="p-8">
        <div className="bg-red-50 text-red-700 p-6 rounded-xl border border-red-200 shadow-sm text-center">
          <h2 className="text-xl font-bold mb-2">عذراً، لا تملك صلاحية للوصول</h2>
          <p>
            هذه الصفحة تتطلب {required.length > 1 ? 'إحدى الصلاحيات' : 'صلاحية'}{' '}
            <code>{required.join(' أو ')}</code> وهي غير متوفرة في حسابك.
          </p>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
