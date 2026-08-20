import React, { useEffect, useState } from 'react';
import {
  fetchUserPermissionBreakdown,
  setUserPermissionOverride,
  deleteUserPermissionOverride,
} from '../../api/userPermissions';
import type { UserPermissionBreakdown, UserPermissionItem } from '../../types';
import {
  Shield,
  ShieldCheck,
  ShieldAlert,
  ShieldBan,
  X,
  RotateCcw,
  CheckCircle2,
  AlertCircle,
  Loader2,
} from 'lucide-react';

interface Props {
  userId: number;
  userName: string;
  onClose: () => void;
}

export function UserDirectPermissionsModal({ userId, userName, onClose }: Props) {
  const [data, setData] = useState<UserPermissionBreakdown | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [updatingPermId, setUpdatingPermId] = useState<number | null>(null);

  useEffect(() => {
    loadPermissions();
  }, [userId]);

  async function loadPermissions() {
    try {
      setLoading(true);
      setError(null);
      const res = await fetchUserPermissionBreakdown(userId);
      setData(res);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'فشل تحميل الصلاحيات.');
    } finally {
      setLoading(false);
    }
  }

  async function handleSetOverride(permissionId: number, effect: 'grant' | 'deny') {
    try {
      setUpdatingPermId(permissionId);
      setError(null);
      await setUserPermissionOverride(userId, permissionId, effect);
      // Reload fresh breakdown
      const res = await fetchUserPermissionBreakdown(userId);
      setData(res);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'فشل تحديث الصلاحية.');
    } finally {
      setUpdatingPermId(null);
    }
  }

  async function handleRemoveOverride(permissionId: number) {
    try {
      setUpdatingPermId(permissionId);
      setError(null);
      await deleteUserPermissionOverride(userId, permissionId);
      const res = await fetchUserPermissionBreakdown(userId);
      setData(res);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'فشل حذف الاستثناء.');
    } finally {
      setUpdatingPermId(null);
    }
  }

  // Group permissions by group name
  const groupedPermissions: Record<string, UserPermissionItem[]> = {};
  if (data?.permissions) {
    for (const p of data.permissions) {
      const g = p.group || 'عام';
      if (!groupedPermissions[g]) groupedPermissions[g] = [];
      groupedPermissions[g].push(p);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" dir="rtl">
      <div className="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden">
        
        {/* Header */}
        <div className="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center">
              <Shield size={22} />
            </div>
            <div>
              <h2 className="text-lg font-bold text-slate-800">
                الصلاحيات المباشرة — {data?.user.first_name || userName} {data?.user.last_name || ''}
              </h2>
              <p className="text-xs text-slate-500 mt-0.5">
                الدور الأساسي: <span className="font-semibold text-slate-700">{data?.user.role?.display_name || 'غير محدد'}</span> | اسم المستخدم: <span className="font-mono text-slate-600">{data?.user.username}</span>
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
            title="إغلاق"
          >
            <X size={20} />
          </button>
        </div>

        {/* Info Banner */}
        <div className="px-6 py-2.5 bg-amber-50/70 border-b border-amber-100 text-xs text-amber-800 flex items-center gap-2">
          <AlertCircle size={15} className="shrink-0 text-amber-600" />
          <span>
            <strong>قاعدة الأولوية:</strong> المنع اليدوي (Deny) يغلب صلاحية الدور والمنح اليدوي (Grant). إزالة الاستثناء تُعيد القرار تلقائياً لصلاحية الدور.
          </span>
        </div>

        {/* Error alert */}
        {error && (
          <div className="mx-6 mt-4 p-3 rounded-xl bg-red-50 border border-red-200 text-xs text-red-700 flex items-center gap-2">
            <AlertCircle size={16} />
            <span>{error}</span>
          </div>
        )}

        {/* Body content */}
        <div className="p-6 overflow-y-auto flex-1 space-y-6">
          {loading ? (
            <div className="py-16 flex flex-col items-center justify-center gap-3 text-slate-400">
              <Loader2 size={32} className="animate-spin text-emerald-700" />
              <span className="text-sm">جاري تحميل الصلاحيات...</span>
            </div>
          ) : (
            Object.entries(groupedPermissions).map(([groupName, perms]) => (
              <div key={groupName} className="space-y-2">
                <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider px-1">
                  مجموعة: {groupName}
                </h3>
                <div className="divide-y divide-slate-100 border border-slate-200 rounded-xl overflow-hidden bg-white">
                  {perms.map((p) => {
                    const isUpdating = updatingPermId === p.permission_id;

                    return (
                      <div
                        key={p.permission_id}
                        className="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/60 transition"
                      >
                        <div className="space-y-0.5">
                          <div className="flex items-center gap-2">
                            <span className="text-sm font-semibold text-slate-800">
                              {p.display_name}
                            </span>
                            <span className="text-[11px] font-mono text-slate-400">
                              ({p.name})
                            </span>
                          </div>
                          <div className="flex items-center gap-2 text-xs">
                            <span className="text-slate-500">صلاحية الدور:</span>
                            {p.in_role ? (
                              <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-md">
                                <CheckCircle2 size={11} /> مشمولة بالدور
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-1 text-[11px] font-medium text-slate-500 bg-slate-100 px-2 py-0.5 rounded-md">
                                غير مشمولة
                              </span>
                            )}
                          </div>
                        </div>

                        {/* Action buttons & Effective Result */}
                        <div className="flex items-center gap-3 self-end sm:self-center">
                          {/* Status buttons */}
                          <div className="flex items-center bg-slate-100 p-1 rounded-xl gap-1 border border-slate-200">
                            {/* Grant */}
                            <button
                              type="button"
                              disabled={isUpdating}
                              onClick={() => handleSetOverride(p.permission_id, 'grant')}
                              className={`px-3 py-1 text-xs font-semibold rounded-lg transition flex items-center gap-1 ${
                                p.override_effect === 'grant'
                                  ? 'bg-emerald-600 text-white shadow-sm'
                                  : 'text-slate-600 hover:text-emerald-700 hover:bg-white'
                              }`}
                              title="منح يدوي مباشر للمستخدم"
                            >
                              <ShieldCheck size={13} />
                              منح
                            </button>

                            {/* Deny */}
                            <button
                              type="button"
                              disabled={isUpdating}
                              onClick={() => handleSetOverride(p.permission_id, 'deny')}
                              className={`px-3 py-1 text-xs font-semibold rounded-lg transition flex items-center gap-1 ${
                                p.override_effect === 'deny'
                                  ? 'bg-rose-600 text-white shadow-sm'
                                  : 'text-slate-600 hover:text-rose-700 hover:bg-white'
                              }`}
                              title="منع يدوي مباشر للمستخدم"
                            >
                              <ShieldBan size={13} />
                              منع
                            </button>

                            {/* Reset / Default */}
                            {p.override_effect && (
                              <button
                                type="button"
                                disabled={isUpdating}
                                onClick={() => handleRemoveOverride(p.permission_id)}
                                className="px-2 py-1 text-xs text-slate-500 hover:text-slate-800 hover:bg-white rounded-lg transition flex items-center gap-1"
                                title="إزالة الاستثناء والعودة لقرار الدور"
                              >
                                <RotateCcw size={12} />
                                إعادة للدور
                              </button>
                            )}
                          </div>

                          {/* Effective badge */}
                          <div className="min-w-[70px] text-center">
                            {p.is_effective ? (
                              <span className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-800 bg-emerald-100 border border-emerald-300 px-2.5 py-1 rounded-lg">
                                فعّالة
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-1 text-[11px] font-bold text-slate-600 bg-slate-100 border border-slate-300 px-2.5 py-1 rounded-lg">
                                معطّلة
                              </span>
                            )}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            ))
          )}
        </div>

        {/* Footer */}
        <div className="p-4 border-t border-slate-100 bg-slate-50/50 flex justify-end">
          <button
            type="button"
            onClick={onClose}
            className="px-6 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-slate-200 transition"
          >
            إغلاق
          </button>
        </div>

      </div>
    </div>
  );
}
