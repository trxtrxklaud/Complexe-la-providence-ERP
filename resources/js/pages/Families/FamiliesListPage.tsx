import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Users, Search, Eye, RefreshCw, AlertCircle, Loader2 } from 'lucide-react';
import { fetchFamilies, type FamilySummary } from '../../api/families';
import { EmptyState } from '../../components/EmptyState';

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

  return (
    <div dir="rtl" className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Header Bar */}
      <div className="flex items-center justify-between flex-wrap gap-4">
        <div className="flex items-center gap-3">
          <div className="w-12 h-12 rounded-2xl flex items-center justify-center shadow-sm" style={{ backgroundColor: C.sage }}>
            <Users size={24} style={{ color: C.forest }} />
          </div>
          <div>
            <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
              إدارة العائلات
            </h1>
            <p className="text-xs" style={{ color: C.muted }}>
              تجميع الأبناء تحت ملف الولي والتحصيل الجماعي الموحد — ({total} عائلة)
            </p>
          </div>
        </div>

        <button
          type="button"
          onClick={() => loadData(page, search)}
          className="flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold shadow-sm transition"
          style={{ backgroundColor: C.sage, color: C.forest }}
        >
          <RefreshCw size={15} /> تحديث
        </button>
      </div>

      {error && (
        <div className="p-4 rounded-xl bg-red-50 text-red-700 text-sm flex items-center gap-2 border border-red-200">
          <AlertCircle size={18} /> {error}
        </div>
      )}

      {/* Filter / Search Bar */}
      <div className="bg-white p-4 rounded-2xl border flex items-center gap-3 shadow-sm" style={{ borderColor: C.line }}>
        <Search size={18} className="text-slate-400" />
        <input
          type="text"
          placeholder="ابحث باسم الولي أو رقم الهاتف..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full text-sm outline-none bg-transparent"
        />
      </div>

      {/* Families Table */}
      <div className="bg-white rounded-2xl border shadow-sm overflow-hidden" style={{ borderColor: C.line }}>
        {loading ? (
          <div className="flex items-center justify-center py-20 text-slate-400">
            <Loader2 className="animate-spin" size={28} />
          </div>
        ) : families.length === 0 ? (
          <EmptyState title="لا توجد عائلات مطابقة للبحث." icon={Users} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-right">
              <thead>
                <tr className="border-b text-xs font-semibold" style={{ backgroundColor: C.sage, color: C.forest }}>
                  <th className="px-4 py-3.5">الولي / العائلة</th>
                  <th className="px-4 py-3.5">رقم الهاتف</th>
                  <th className="px-4 py-3.5">عدد الأبناء</th>
                  <th className="px-4 py-3.5">الأبناء المسجلين</th>
                  <th className="px-4 py-3.5">المتبقي بالذمة</th>
                  <th className="px-4 py-3.5 text-center">الإجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {families.map((f) => {
                  const hasDebt = f.family_remaining_debt > 0;

                  return (
                    <tr key={f.id} className="hover:bg-slate-50/80 transition">
                      <td className="px-4 py-3.5 font-bold" style={{ color: C.ink }}>
                        {f.guardian_name}
                      </td>
                      <td className="px-4 py-3.5 font-mono text-xs text-slate-600" dir="ltr">
                        {f.phone || '—'}
                      </td>
                      <td className="px-4 py-3.5">
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-800">
                          {f.students_count} أبناء
                        </span>
                      </td>
                      <td className="px-4 py-3.5 text-xs text-slate-600 max-w-xs truncate">
                        {f.students.map((s) => s.name).join('، ')}
                      </td>
                      <td className="px-4 py-3.5 font-bold font-mono">
                        {hasDebt ? (
                          <span className="text-red-600">{money(f.family_remaining_debt)} د.ت</span>
                        ) : (
                          <span className="text-emerald-600">0.00 د.ت (مستوفى)</span>
                        )}
                      </td>
                      <td className="px-4 py-3.5 text-center">
                        <button
                          type="button"
                          onClick={() => navigate(`/families/${f.id}`)}
                          className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition text-white shadow-sm"
                          style={{ backgroundColor: C.forest }}
                        >
                          <Eye size={14} /> استعراض وتنزيل
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
