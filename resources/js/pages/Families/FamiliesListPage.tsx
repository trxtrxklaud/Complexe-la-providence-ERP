import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Users, Search, Eye, RefreshCw, AlertCircle, Loader2 } from 'lucide-react';
import { fetchFamilies, type FamilySummary } from '../../api/families';
import { EmptyState } from '../../components/EmptyState';
import { EnterpriseHeader } from '../../components/ui/EnterpriseHeader';
import { EnterpriseBadge } from '../../components/ui/EnterpriseBadge';

const C = { forest: '#3B4A36', sage: '#E3EBDB', ink: '#1F261C', muted: '#7C8677', line: '#EDF1E8' };

function money(v: number): string {
  return (v || 0).toFixed(2);
}

export function FamiliesListPage() {
  const navigate = useNavigate();
  const [families, setFamilies] = useState<FamilySummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const loadData = useCallback(async (p = 1, s = search) => {
    setLoading(true);
    setError(null);
    try {
      const res = await fetchFamilies({ search: s, page: p, per_page: 25 });
      setFamilies(res.data);
      setPage(res.current_page);
      setLastPage(res.last_page);
      setTotal(res.total);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'تعذّر تحميل قائمة العائلات');
    } finally {
      setLoading(false);
    }
  }, [search]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadData(1, search);
    }, 300);
    return () => clearTimeout(timer);
  }, [search, loadData]);

  // الاستماع لتحديث العائلات من العمليات الأخرى + عند استعادة التركيز
  useEffect(() => {
    const handleFamiliesUpdated = () => {
      console.log('🔄 Families updated - refreshing list...');
      loadData(page, search);
    };

    window.addEventListener('families:updated', handleFamiliesUpdated);

    // تحديث عند العودة للصفحة
    const handleFocus = () => {
      console.log('🔄 Page focused - refreshing list...');
      loadData(page, search);
    };

    window.addEventListener('focus', handleFocus);

    return () => {
      window.removeEventListener('families:updated', handleFamiliesUpdated);
      window.removeEventListener('focus', handleFocus);
    };
  }, [loadData, page, search]);

  return (
    <div dir="rtl" className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Header Bar */}
      <EnterpriseHeader
        title="إدارة العائلات"
        subtitle="تجميع الأبناء تحت ملف الولي وإجراء الاستخلاص والتحصيل المالي الموحد"
        icon={Users}
        badge={
          <EnterpriseBadge variant="brand">
            {total} عائلة مسجلة
          </EnterpriseBadge>
        }
        actions={
          <button
            type="button"
            onClick={() => loadData(page, search)}
            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold bg-white border border-slate-200/90 text-slate-700 shadow-2xs transition hover:bg-slate-50 hover:border-slate-300"
          >
            <RefreshCw size={14} className={loading ? 'animate-spin' : ''} />
            <span>تحديث البيانات</span>
          </button>
        }
      />

      {error && (
        <div className="p-4 rounded-2xl bg-rose-50 text-rose-700 text-sm font-semibold flex items-center gap-2.5 border border-rose-200">
          <AlertCircle size={18} /> {error}
        </div>
      )}

      {/* Filter / Search Bar */}
      <div className="enterprise-card p-4 flex items-center gap-3">
        <Search size={20} className="text-slate-400 shrink-0" />
        <input
          type="text"
          placeholder="ابحث باسم الولي، أو رقم الهاتف، أو اسم التلميذ..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full text-sm font-semibold text-slate-800 outline-none bg-transparent placeholder:text-slate-400"
        />
      </div>

      {/* Families Table */}
      <div className="enterprise-card overflow-hidden">
        {loading ? (
          <div className="flex items-center justify-center py-20 text-slate-400">
            <Loader2 className="animate-spin text-[#2E3B2A]" size={32} />
          </div>
        ) : families.length === 0 ? (
          <EmptyState title="لا توجد عائلات مطابقة للبحث." icon={Users} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-right">
              <thead>
                <tr className="border-b border-slate-200/80 bg-slate-50/90 text-slate-700 text-xs font-bold uppercase tracking-wider">
                  <th className="px-5 py-4">الولي / العائلة</th>
                  <th className="px-5 py-4">رقم الهاتف</th>
                  <th className="px-5 py-4">عدد الأبناء</th>
                  <th className="px-5 py-4">الأبناء المسجلين</th>
                  <th className="px-5 py-4">المتبقي بالذمة</th>
                  <th className="px-5 py-4 text-center">الإجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {families.map((f) => {
                  const hasDebt = f.family_remaining_debt > 0;

                  return (
                    <tr key={f.id} className="hover:bg-slate-50/75 transition-colors">
                      <td className="px-5 py-4 font-bold text-slate-900 text-[15px]">
                        {f.guardian_name}
                      </td>
                      <td className="px-5 py-4 font-mono text-xs font-bold text-slate-600" dir="ltr">
                        {f.phone || '—'}
                      </td>
                      <td className="px-5 py-4">
                        <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200/60">
                          {f.students_count} أبناء
                        </span>
                      </td>
                      <td className="px-5 py-4 text-xs font-semibold text-slate-600 max-w-xs truncate">
                        {f.students.map((s) => s.name).join('، ')}
                      </td>
                      <td className="px-5 py-4 font-bold font-mono">
                        {hasDebt ? (
                          <EnterpriseBadge variant="danger">
                            {money(f.family_remaining_debt)} د.ت
                          </EnterpriseBadge>
                        ) : (
                          <EnterpriseBadge variant="success">
                            0.00 د.ت (مستوفى)
                          </EnterpriseBadge>
                        )}
                      </td>
                      <td className="px-5 py-4 text-center">
                        <button
                          type="button"
                          onClick={() => navigate(`/families/${f.id}`)}
                          className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all bg-slate-100 hover:bg-[#2E3B2A] hover:text-white text-slate-800 shadow-2xs active:scale-95"
                        >
                          <Eye size={14} />
                          <span>استعراض وتنزيل</span>
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination Bar */}
        {lastPage > 1 && (
          <div className="flex items-center justify-between px-5 py-3 border-t" style={{ borderColor: C.line }}>
            <button
              type="button"
              onClick={() => loadData(Math.max(1, page - 1))}
              disabled={page <= 1 || loading}
              className="px-3.5 py-1.5 rounded-xl text-xs font-semibold border disabled:opacity-40"
              style={{ borderColor: C.line, color: C.forest }}
            >
              السابق
            </button>
            <span className="text-xs text-slate-500">
              صفحة {page} من {lastPage}
            </span>
            <button
              type="button"
              onClick={() => loadData(Math.min(lastPage, page + 1))}
              disabled={page >= lastPage || loading}
              className="px-3.5 py-1.5 rounded-xl text-xs font-semibold border disabled:opacity-40"
              style={{ borderColor: C.line, color: C.forest }}
            >
              التالي
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

export default FamiliesListPage;
