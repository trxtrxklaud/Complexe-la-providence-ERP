import React, { useState } from 'react';
import { changeUserPassword } from '../../api/users';
import { useToast } from '../ui/Toast';
import { KeyRound, X, AlertCircle, Loader2, Eye, EyeOff } from 'lucide-react';

interface Props {
  userId: number;
  userName: string;
  onClose: () => void;
}

/**
 * تعيين كلمة مرور جديدة لمستخدم من قِبل المشرف — بلا كلمة المرور القديمة.
 * الحدّ الأدنى 8 خانات، مع تأكيد مطابق. النجاح يُبلَّغ عبر Toast ثم يُغلَق.
 */
export function ChangePasswordModal({ userId, userName, onClose }: Props) {
  const toast = useToast();
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [show, setShow] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (password.length < 8) {
      setError('كلمة المرور يجب أن تكون 8 خانات على الأقل.');
      return;
    }
    if (password !== confirmation) {
      setError('كلمة المرور وتأكيدها غير متطابقين.');
      return;
    }

    try {
      setSubmitting(true);
      await changeUserPassword(userId, password, confirmation);
      toast.success('تم تغيير كلمة المرور بنجاح');
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'فشل تغيير كلمة المرور.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" dir="rtl">
      <div className="bg-white rounded-2xl max-w-md w-full flex flex-col shadow-2xl border border-slate-200 overflow-hidden">

        {/* Header */}
        <div className="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-800 flex items-center justify-center">
              <KeyRound size={22} />
            </div>
            <div>
              <h2 className="text-lg font-bold text-slate-800">تغيير كلمة المرور</h2>
              <p className="text-xs text-slate-500 mt-0.5">
                المستخدم: <span className="font-semibold text-slate-700">{userName}</span>
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

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          {error && (
            <div className="p-3 rounded-xl bg-red-50 border border-red-200 text-xs text-red-700 flex items-center gap-2">
              <AlertCircle size={16} className="shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="space-y-1.5">
            <label className="block text-sm font-semibold text-slate-700">كلمة المرور الجديدة</label>
            <div className="relative">
              <input
                type={show ? 'text' : 'password'}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="new-password"
                autoFocus
                className="w-full px-4 py-2.5 pl-10 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/40 focus:border-emerald-500 transition"
                dir="ltr"
                placeholder="********"
              />
              <button
                type="button"
                onClick={() => setShow((s) => !s)}
                className="absolute left-2 top-1/2 -translate-y-1/2 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
                title={show ? 'إخفاء' : 'إظهار'}
                tabIndex={-1}
              >
                {show ? <EyeOff size={16} /> : <Eye size={16} />}
              </button>
            </div>
            <p className="text-[11px] text-slate-400">8 خانات على الأقل.</p>
          </div>

          <div className="space-y-1.5">
            <label className="block text-sm font-semibold text-slate-700">تأكيد كلمة المرور</label>
            <input
              type={show ? 'text' : 'password'}
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              autoComplete="new-password"
              className="w-full px-4 py-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/40 focus:border-emerald-500 transition"
              dir="ltr"
              placeholder="********"
            />
          </div>

          <div className="pt-2 flex items-center justify-end gap-3">
            <button
              type="button"
              onClick={onClose}
              className="px-5 py-2 rounded-xl text-sm font-semibold text-slate-700 hover:bg-slate-100 transition"
            >
              إلغاء
            </button>
            <button
              type="submit"
              disabled={submitting}
              className="px-6 py-2 rounded-xl text-sm font-semibold text-white bg-emerald-700 hover:bg-emerald-800 transition flex items-center gap-2 disabled:opacity-60"
            >
              {submitting && <Loader2 size={15} className="animate-spin" />}
              حفظ كلمة المرور
            </button>
          </div>
        </form>

      </div>
    </div>
  );
}
