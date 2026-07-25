import { useEffect, useMemo, useState } from 'react';
import { ClipboardList, Printer, Plus, Trash2, X } from 'lucide-react';
import { fetchLevels, type Level } from '../../api/classrooms';
import {
  fetchYears,
  fetchRoster,
  bulkEnroll,
  removeFromRoster,
  type AcademicYear,
  type RosterResponse,
} from '../../api/roster';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  bg: '#F4F6F1',
};

function errorMessage(err: unknown): string {
  if (err && typeof err === 'object') {
    const anyErr = err as { firstError?: string; message?: string };
    if (anyErr.firstError) return anyErr.firstError;
    if (anyErr.message) return anyErr.message;
  }
  return 'حدث خطأ غير متوقّع';
}

export function RosterPage() {
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [yearId, setYearId] = useState<number | ''>('');
  const [levelId, setLevelId] = useState<number | ''>('');
  const [sectionId, setSectionId] = useState<number | ''>('');

  const [roster, setRoster] = useState<RosterResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [showFather, setShowFather] = useState(false);
  const [showMother, setShowMother] = useState(false);
  const [showPhones, setShowPhones] = useState(false);

  const [bulkOpen, setBulkOpen] = useState(false);
  const [bulkText, setBulkText] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        const [y, l] = await Promise.all([fetchYears(), fetchLevels()]);
        setYears(y);
        setLevels(l);
        const active = y.find((item) => item.is_active) ?? y[0];
        if (active) setYearId(active.id);
      } catch (err) {
        setError(errorMessage(err));
      }
    })();
  }, []);

  const sections = useMemo(() => {
    const level = levels.find((item) => item.id === levelId);
    return level ? level.sections : [];
  }, [levels, levelId]);

  const load = async (year: number, section: number) => {
    setLoading(true);
    setError('');
    try {
      setRoster(await fetchRoster(year, section));
    } catch (err) {
      setError(errorMessage(err));
      setRoster(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (typeof yearId === 'number' && typeof sectionId === 'number') {
      void load(yearId, sectionId);
    } else {
      setRoster(null);
    }
  }, [yearId, sectionId]);

  const flash = (message: string) => {
    setNotice(message);
    window.setTimeout(() => setNotice(''), 4000);
  };

  const parsedNames = useMemo(
    () =>
      bulkText
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line.length > 1),
    [bulkText],
  );

  const saveBulk = async () => {
    if (typeof yearId !== 'number' || typeof sectionId !== 'number' || parsedNames.length === 0) return;
    setSaving(true);
    setError('');
    try {
      const result = await bulkEnroll({ academic_year_id: yearId, section_id: sectionId, names: parsedNames });
      setBulkOpen(false);
      setBulkText('');
      flash(
        result.skipped.length > 0
          ? 'تمّ تسجيل ' + result.created + ' تلميذ، وتُجاهل ' + result.skipped.length + ' (مسجّلون من قبل): ' + result.skipped.join('، ')
          : 'تمّ تسجيل ' + result.created + ' تلميذ',
      );
      await load(yearId, sectionId);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const removeRow = async (enrollmentId: number, name: string) => {
    if (!window.confirm('حذف «' + name + '» من هذا القسم؟')) return;
    try {
      await removeFromRoster(enrollmentId);
      flash('تم الحذف');
      if (typeof yearId === 'number' && typeof sectionId === 'number') await load(yearId, sectionId);
    } catch (err) {
      setError(errorMessage(err));
    }
  };

  const selectStyle = {
    border: '1px solid ' + C.line,
    backgroundColor: '#fff',
    color: C.ink,
  };

  const colCount = 2 + (showFather ? 1 : 0) + (showMother ? 1 : 0) + (showPhones ? 1 : 0);

  return (
    <div className="p-6" dir="rtl" style={{ backgroundColor: C.bg, minHeight: '100vh' }}>
      <style>{`
        @media print {
          body * { visibility: hidden; }
          #roster-print, #roster-print * { visibility: visible; }
          #roster-print { position: absolute; inset: 0; width: 100%; padding: 12mm; }
          .no-print { display: none !important; }
          @page { size: A4 portrait; margin: 0; }
        }
      `}</style>

      <div className="no-print">
        <div className="flex items-center justify-between mb-6 flex-wrap gap-3">
          <div className="flex items-center gap-3">
            <div className="p-3 rounded-2xl" style={{ backgroundColor: C.sage }}>
              <ClipboardList size={22} color={C.forest} />
            </div>
            <div>
              <h1 className="text-2xl font-bold" style={{ color: C.ink }}>قوائم الأقسام</h1>
              <p className="text-sm" style={{ color: C.muted }}>تسجيل التلاميذ دفعة واحدة ثمّ طباعة القائمة</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={() => setBulkOpen(true)}
              disabled={typeof sectionId !== 'number' || typeof yearId !== 'number'}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-40"
              style={{ backgroundColor: C.forest }}
            >
              <Plus size={18} />
              <span>إضافة دفعة</span>
            </button>
            <button
              onClick={() => window.print()}
              disabled={!roster || roster.students.length === 0}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium disabled:opacity-40"
              style={{ border: '1px solid ' + C.forest, color: C.forest, backgroundColor: '#fff' }}
            >
              <Printer size={18} />
              <span>طباعة</span>
            </button>
          </div>
        </div>

        <div className="bg-white rounded-2xl p-4 mb-5" style={{ border: '1px solid ' + C.line }}>
          <div className="grid sm:grid-cols-3 gap-3">
            <div>
              <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الدراسية</label>
              <select
                value={yearId}
                onChange={(e) => setYearId(e.target.value ? Number(e.target.value) : '')}
                className="w-full px-3 py-2.5 rounded-xl text-sm"
                style={selectStyle}
              >
                <option value="">اختر السنة</option>
                {years.map((year) => (
                  <option key={year.id} value={year.id}>{year.name}{year.is_active ? ' — حالية' : ''}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المستوى</label>
              <select
                value={levelId}
                onChange={(e) => {
                  setLevelId(e.target.value ? Number(e.target.value) : '');
                  setSectionId('');
                }}
                className="w-full px-3 py-2.5 rounded-xl text-sm"
                style={selectStyle}
              >
                <option value="">اختر المستوى</option>
                {levels.map((level) => (
                  <option key={level.id} value={level.id}>{level.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs mb-1.5" style={{ color: C.muted }}>القسم</label>
              <select
                value={sectionId}
                onChange={(e) => setSectionId(e.target.value ? Number(e.target.value) : '')}
                disabled={sections.length === 0}
                className="w-full px-3 py-2.5 rounded-xl text-sm disabled:opacity-50"
                style={selectStyle}
              >
                <option value="">اختر القسم</option>
                {sections.map((section) => (
                  <option key={section.id} value={section.id}>{section.name}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex items-center gap-4 mt-4 flex-wrap text-sm" style={{ color: C.ink }}>
            <span style={{ color: C.muted }}>أعمدة إضافية:</span>
            <label className="flex items-center gap-1.5">
              <input type="checkbox" checked={showFather} onChange={(e) => setShowFather(e.target.checked)} />
              <span>اسم الأب</span>
            </label>
            <label className="flex items-center gap-1.5">
              <input type="checkbox" checked={showMother} onChange={(e) => setShowMother(e.target.checked)} />
              <span>اسم الأم</span>
            </label>
            <label className="flex items-center gap-1.5">
              <input type="checkbox" checked={showPhones} onChange={(e) => setShowPhones(e.target.checked)} />
              <span>أرقام الجوال</span>
            </label>
          </div>
        </div>

        {error && (
          <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: '#FDECEC', color: '#A03434' }}>{error}</div>
        )}
        {notice && (
          <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.sage, color: C.deep }}>{notice}</div>
        )}
      </div>

      {loading ? (
        <p className="text-sm no-print" style={{ color: C.muted }}>جارٍ التحميل…</p>
      ) : !roster ? (
        <div className="bg-white rounded-2xl p-10 text-center no-print" style={{ border: '1px solid ' + C.line }}>
          <p style={{ color: C.muted }}>اختر السنة والمستوى والقسم لعرض القائمة.</p>
        </div>
      ) : (
        <div id="roster-print" className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
          <div className="px-5 py-4" style={{ backgroundColor: C.sage }}>
            <h2 className="font-bold text-lg" style={{ color: C.deep }}>
              {roster.level} — {roster.section}
            </h2>
            <p className="text-xs mt-0.5" style={{ color: C.muted }}>
              السنة الدراسية {roster.year} · {roster.students.length} تلميذ من أصل {roster.capacity}
            </p>
          </div>

          {roster.students.length === 0 ? (
            <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>
              لا تلاميذ في هذا القسم بعد. استعمل «إضافة دفعة».
            </p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                  <th className="text-right px-4 py-3 font-medium" style={{ width: '3rem' }}>#</th>
                  <th className="text-right px-4 py-3 font-medium">الإسم الكامل</th>
                  {showFather && <th className="text-right px-4 py-3 font-medium">اسم الأب</th>}
                  {showMother && <th className="text-right px-4 py-3 font-medium">اسم الأم</th>}
                  {showPhones && <th className="text-right px-4 py-3 font-medium">رقم الجوال</th>}
                  <th className="px-4 py-3 no-print" style={{ width: '3rem' }} />
                </tr>
              </thead>
              <tbody>
                {roster.students.map((student, index) => (
                  <tr key={student.enrollment_id} style={{ borderBottom: '1px solid ' + C.line }}>
                    <td className="px-4 py-2.5" style={{ color: C.muted }}>{index + 1}</td>
                    <td className="px-4 py-2.5" style={{ color: C.ink }}>
                      {student.first_name} {student.last_name}
                    </td>
                    {showFather && <td className="px-4 py-2.5" style={{ color: C.ink }}>{student.father_name ?? '—'}</td>}
                    {showMother && <td className="px-4 py-2.5" style={{ color: C.ink }}>{student.mother_name ?? '—'}</td>}
                    {showPhones && (
                      <td className="px-4 py-2.5 text-xs" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>
                        {student.father_phone ?? '—'} / {student.mother_phone ?? '—'}
                      </td>
                    )}
                    <td className="px-4 py-2.5 no-print">
                      <button
                        onClick={() => void removeRow(student.enrollment_id, student.first_name + ' ' + student.last_name)}
                        title="حذف من القسم"
                      >
                        <Trash2 size={15} color="#A03434" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          <p className="px-4 py-3 text-xs" style={{ color: C.muted }}>عدد الأعمدة المعروضة: {colCount}</p>
        </div>
      )}

      {bulkOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 no-print" style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}>
          <div className="bg-white rounded-2xl w-full max-w-lg p-6" dir="rtl">
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-bold text-lg" style={{ color: C.ink }}>إضافة دفعة تلاميذ</h3>
              <button onClick={() => setBulkOpen(false)}><X size={20} color={C.muted} /></button>
            </div>

            <p className="text-xs mb-3" style={{ color: C.muted }}>
              اكتب اسماً واحداً في كلّ سطر. الكلمة الأولى هي الاسم والباقي اللقب.
            </p>

            <textarea
              value={bulkText}
              onChange={(e) => setBulkText(e.target.value)}
              rows={10}
              className="w-full px-3 py-2.5 rounded-xl text-sm"
              style={{ border: '1px solid ' + C.line, color: C.ink }}
              placeholder={'أواب حامدي\nحنين عامري'}
            />

            <p className="text-xs mt-2" style={{ color: C.muted }}>{parsedNames.length} اسم جاهز للتسجيل</p>

            <div className="flex gap-3 mt-5">
              <button
                onClick={() => void saveBulk()}
                disabled={saving || parsedNames.length === 0}
                className="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: C.forest }}
              >
                {saving ? 'جارٍ الحفظ…' : 'تسجيل ' + parsedNames.length + ' تلميذ'}
              </button>
              <button
                onClick={() => setBulkOpen(false)}
                className="px-5 py-2.5 rounded-xl text-sm"
                style={{ border: '1px solid ' + C.line, color: C.muted }}
              >
                إلغاء
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default RosterPage;
