import React from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';

interface ProtectedRouteProps {
  children: React.ReactNode;
  permission?: string;
}

export function ProtectedRoute({ children, permission }: ProtectedRouteProps) {
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

  if (permission && !hasPermission(permission)) {
    return (
      <div className="p-8">
        <div className="bg-red-50 text-red-700 p-6 rounded-xl border border-red-200 shadow-sm text-center">
          <h2 className="text-xl font-bold mb-2">عذراً، لا تملك صلاحية للوصول</h2>
          <p>هذه الصفحة تتطلب صلاحية <code>{permission}</code> غير متوفرة في حسابك.</p>
        </div>
      </div>
    );
  }

  return <>{children}</>;
}
