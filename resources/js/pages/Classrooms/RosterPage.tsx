import { useEffect, useMemo, useRef, useState } from 'react';
import { ClipboardList, Printer, Plus, Trash2, X, Upload, Pencil, Save, User, Filter } from 'lucide-react';
import { fetchLevels, type Level } from '../../api/classrooms';
import {
  fetchYears,
  fetchRoster,
  bulkEnroll,
  updateStudent,
  removeFromRoster,
  type AcademicYear,
  type RosterResponse,
  type RosterStudent,
  type StudentEntry,
} from '../../api/roster';
import { PageDataSkeleton } from '../../components/DataSkeleton';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  bg: '#F4F6F1',
  error: '#A03434',
  errorBg: '#FDECEC',
};

const SCHOOL_NAME = 'مركب العناية للتعليم الخاص';
const SCHOOL_PHONE = '95 420 350';

type NewRow = StudentEntry & { _key: number };

type ColumnKey = 'father_name' | 'mother_name' | 'father_phone' | 'mother_phone';

type ContactFields = {
  father_name?: string | null;
  mother_name?: string | null;
  father_phone?: string | null;
  mother_phone?: string | null;
};

const COLUMNS: Array<{ key: ColumnKey; label: string; kind: 'name' | 'phone' }> = [
  { key: 'father_name', label: 'اسم الأب', kind: 'name' },
  { key: 'mother_name', label: 'اسم الأم', kind: 'name' },
  { key: 'father_phone', label: 'هاتف الأب', kind: 'phone' },
  { key: 'mother_phone', label: 'هاتف الأم', kind: 'phone' },
];

const STORAGE_KEY = 'roster_visible_columns';

const DEFAULT_VISIBILITY: Record<ColumnKey, boolean> = {
  father_name: true,
  mother_name: true,
  father_phone: true,
  mother_phone: true,
};

function loadVisibility(): Record<ColumnKey, boolean> {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return { ...DEFAULT_VISIBILITY };
    return { ...DEFAULT_VISIBILITY, ...(JSON.parse(raw) as Partial<Record<ColumnKey, boolean>>) };
  } catch {
    return { ...DEFAULT_VISIBILITY };
  }
}

function readField(source: ContactFields, key: ColumnKey): string {
  if (key === 'father_name') return source.father_name ?? '';
  if (key === 'mother_name') return source.mother_name ?? '';
  if (key === 'father_phone') return source.father_phone ?? '';
  return source.mother_phone ?? '';
}

function withField(draft: StudentEntry, key: ColumnKey, value: string): StudentEntry {
  if (key === 'father_name') return { ...draft, father_name: value };
  if (key === 'mother_name') return { ...draft, mother_name: value };
  if (key === 'father_phone') return { ...draft, father_phone: value };
  return { ...draft, mother_phone: value };
}

function formatPhone(value?: string | null): string {
  if (!value) return '—';

  let digits = value.replace(/\D+/g, '');
  if (digits.startsWith('00')) digits = digits.slice(2);
  if (digits.length === 11 && digits.startsWith('216')) digits = digits.slice(3);

  if (digits.length === 8) {
    return digits.slice(0, 2) + ' ' + digits.slice(2, 5) + ' ' + digits.slice(5);
  }

  return digits === '' ? '—' : digits;
}

function cellText(source: ContactFields, key: ColumnKey, kind: 'name' | 'phone'): string {
  if (kind === 'phone') return formatPhone(readField(source, key));
  return readField(source, key) || '—';
}

function errorMessage(err: unknown): string {
  if (err && typeof err === 'object') {
    const anyErr = err as { firstError?: string; message?: string };
    if (anyErr.firstError) return anyErr.firstError;
    if (anyErr.message) return anyErr.message;
  }
  return 'حدث خطأ غير متوقّع';
}

function parseCSV(text: string): string[][] {
  const lines = text.split(/\r?\n/).filter((l) => l.trim().length > 0);
  return lines.map((line) => {
    if (line.includes('\t')) return line.split('\t').map((c) => c.trim());
    // تقسيم CSV بسيط (أسماء عربية بلا فواصل داخل الخانة)
    return line.split(',').map((c) => c.trim());
  });
}

function rowsToEntries(rows: string[][]): NewRow[] {
  return rows.map((cells, i) => ({
    _key: Date.now() + i,
    first_name: cells[0] || '',
    last_name: cells[1] || '',
    father_name: cells[2] || '',
    mother_name: cells[3] || '',
    father_phone: cells[4] || '',
    mother_phone: cells[5] || '',
  }));
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

  // إظهار/حجب أعمدة الوليّين — تسري على الشاشة وعلى الطباعة معًا
  const [visible, setVisible] = useState<Record<ColumnKey, boolean>>(loadVisibility);
  const shownColumns = useMemo(() => COLUMNS.filter((column) => visible[column.key]), [visible]);

  // نافذة الإضافة
  const [addOpen, setAddOpen] = useState(false);
  const [newRows, setNewRows] = useState<NewRow[]>([]);
  const [pasteText, setPasteText] = useState('');
  const [showPaste, setShowPaste] = useState(false);
  const [saving, setSaving] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  // تعديل داخل الجدول
  const [editId, setEditId] = useState<number | null>(null);
  const [editDraft, setEditDraft] = useState<StudentEntry | null>(null);
  const [editSaving, setEditSaving] = useState(false);

  // طباعة بطاقة فردية
  const [printStudent, setPrintStudent] = useState<RosterStudent | null>(null);

  const rowKey = useRef(1);

  const toggleColumn = (key: ColumnKey) => {
    setVisible((current) => {
      const next = { ...current, [key]: !current[key] };
      try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
      } catch {
        // حفظ التفضيل رفاهية؛ فشله لا يعطّل الشاشة.
      }
      return next;
    });
  };

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

  // --- نافذة الإضافة ---
  const openAdd = () => {
    setNewRows([{ _key: rowKey.current++, first_name: '', last_name: '', father_name: '', mother_name: '', father_phone: '', mother_phone: '' }]);
    setPasteText('');
    setShowPaste(false);
    setAddOpen(true);
  };

  const addRow = () => {
    setNewRows([...newRows, { _key: rowKey.current++, first_name: '', last_name: '', father_name: '', mother_name: '', father_phone: '', mother_phone: '' }]);
  };

  const removeNewRow = (key: number) => {
    setNewRows(newRows.filter((r) => r._key !== key));
  };

  const updateNewRow = (key: number, field: keyof StudentEntry, value: string) => {
    setNewRows(newRows.map((r) => (r._key === key ? { ...r, [field]: value } : r)));
  };

  const handleFile = async (file: File) => {
    const text = await file.text();
    const parsed = parseCSV(text);
    if (parsed.length > 0) {
      const entries = rowsToEntries(parsed);
      setNewRows([...newRows, ...entries]);
      flash('تم استيراد ' + entries.length + ' سطر');
    }
  };

  const handlePaste = () => {
    const parsed = parseCSV(pasteText);
    if (parsed.length > 0) {
      const entries = rowsToEntries(parsed);
      setNewRows([...newRows, ...entries]);
      setPasteText('');
      setShowPaste(false);
      flash('تم لصق ' + entries.length + ' سطر');
    }
  };

  const validRows = useMemo(() => newRows.filter((r) => r.first_name.trim().length >= 2 && r.last_name.trim().length >= 2), [newRows]);

  const saveAll = async () => {
    if (typeof yearId !== 'number' || typeof sectionId !== 'number' || validRows.length === 0) return;
    setSaving(true);
    setError('');
    try {
      const result = await bulkEnroll({
        academic_year_id: yearId,
        section_id: sectionId,
        students: validRows.map((r) => ({
          first_name: r.first_name.trim(),
          last_name: r.last_name.trim(),
          father_name: r.father_name?.trim() || null,
          mother_name: r.mother_name?.trim() || null,
          father_phone: r.father_phone?.trim() || null,
          mother_phone: r.mother_phone?.trim() || null,
        })),
      });
      setAddOpen(false);
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

  // --- التعديل داخل الجدول ---
  const startEdit = (student: RosterStudent) => {
    setEditId(student.enrollment_id);
    setEditDraft({
      first_name: student.first_name,
      last_name: student.last_name,
      father_name: student.father_name,
      mother_name: student.mother_name,
      father_phone: student.father_phone,
      mother_phone: student.mother_phone,
    });
  };

  const cancelEdit = () => {
    setEditId(null);
    setEditDraft(null);
  };

  const saveEdit = async () => {
    if (editId === null || !editDraft || typeof yearId !== 'number' || typeof sectionId !== 'number') return;
    setEditSaving(true);
    setError('');
    try {
      await updateStudent(editId, editDraft);
      flash('تم تحديث بيانات التلميذ');
      cancelEdit();
      await load(yearId, sectionId);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setEditSaving(false);
    }
  };

  // --- الحذف ---
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

  // --- طباعة القسم ---
  const printClass = () => {
    setPrintStudent(null);
    window.setTimeout(() => window.print(), 100);
  };

  // --- طباعة بطاقة فردية ---
  const printOne = (student: RosterStudent) => {
    setPrintStudent(student);
    window.setTimeout(() => window.print(), 100);
  };

  const printDate = new Date().toLocaleDateString('ar-TN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

  const selectStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };
  const inputCls = 'w-full px-2 py-1.5 rounded-lg text-sm';
  const inputStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };
  const thPrint: React.CSSProperties = { padding: '10px 10px', border: '1px solid #bbb', fontWeight: 700, fontSize: 14, background: '#2E3B2A', color: '#fff' };
  const tdPrint: React.CSSProperties = { padding: '10px 10px', border: '1px solid #ccc', fontSize: 14, fontWeight: 600 };

  return (
    <div className="p-6" dir="rtl" style={{ backgroundColor: C.bg, minHeight: '100vh' }}>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800&display=swap');
        .print-only { display: none; }
        @media print {
          @page { size: A4 portrait; margin: 8mm; }
          body * { visibility: hidden; }
          #print-area, #print-area * { visibility: visible; }
          #print-area {
            display: block !important;
            position: static !important;
            width: 100% !important;
            min-height: 88vh !important;
            height: auto !important;
            margin: 0 !important;
            padding: 8mm !important;
            box-sizing: border-box !important;
            background: #fff !important;
            font-family: 'Cairo', sans-serif !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            border: 1px solid #2a9d8f !important;
            border-radius: 0 !important;
          }
          #print-area > div {
            display: flex !important;
            flex-direction: column !important;
            min-height: 80vh !important;
          }
          #print-area table {
            width: 100% !important;
            border-collapse: collapse !important;
            height: auto !important;
          }
          #print-area thead { display: table-header-group; }
          #print-area tr { page-break-inside: avoid; break-inside: avoid; }
          #print-area tbody tr {
            height: calc((88vh - 220px) / var(--print-rows, 20)) !important;
            min-height: 28px !important;
            max-height: 48px !important;
          }
          .no-print { display: none !important; }
          * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
      `}</style>

      {/* ===== منطقة الطباعة (مخفية على الشاشة) ===== */}
      <div id="print-area" className="print-only" dir="rtl">
        {printStudent ? (
          <div style={{ fontFamily: "'Cairo', sans-serif", color: '#222', display: 'flex', flexDirection: 'column', minHeight: '82vh' }}>
            <div style={{ textAlign: 'center', borderBottom: '2px solid #2a9d8f', paddingBottom: 8, marginBottom: 12 }}>
              <div style={{ fontWeight: 800, fontSize: 15, color: '#c8a96e' }}>Complexe La Providence — {SCHOOL_NAME}</div>
              <div style={{ fontSize: 9, fontWeight: 600, color: '#7d93a8', marginTop: 2 }}>{printDate} — هاتف <span style={{ direction: 'ltr', display: 'inline-block' }}>{SCHOOL_PHONE}</span></div>
              <div style={{ fontWeight: 700, fontSize: 13, color: '#1a3a5c', marginTop: 6, borderTop: '1px solid #E3EBDB', paddingTop: 6 }}>بطاقة تلميذ</div>
            </div>
            <div style={{ fontSize: 15, lineHeight: 2.2 }}>
              <p style={{ margin: '6px 0' }}><strong>الاسم واللقب: </strong>{printStudent.first_name} {printStudent.last_name}</p>
              {printStudent.student_code && <p style={{ margin: '6px 0' }}><strong>الرقم المدرسي: </strong>{printStudent.student_code}</p>}
              {roster && <p style={{ margin: '6px 0' }}><strong>القسم: </strong>{roster.level} — {roster.section}</p>}
              {roster && <p style={{ margin: '6px 0' }}><strong>السنة الدراسية: </strong>{roster.year}</p>}
              {shownColumns.map((column) => (
                <p key={column.key} style={{ margin: '6px 0' }}>
                  <strong>{column.label}: </strong>
                  {column.kind === 'phone' ? (
                    <span style={{ direction: 'ltr', display: 'inline-block' }}>{cellText(printStudent, column.key, column.kind)}</span>
                  ) : (
                    cellText(printStudent, column.key, column.kind)
                  )}
                </p>
              ))}
            </div>
            <div style={{ marginTop: 'auto', paddingTop: 10, borderTop: '1px solid #2a9d8f', display: 'flex', justifyContent: 'space-between', fontSize: 9, fontWeight: 600, color: '#7d93a8' }}>
              <span>توقيع الولي: ______________</span>
              <span>توقيع الإدارة: ______________</span>
              <span>{printDate}</span>
            </div>
          </div>
        ) : roster ? (
          <div style={{ fontFamily: "'Cairo', sans-serif", color: '#222', display: 'flex', flexDirection: 'column', minHeight: '82vh' }}>
            <div style={{ textAlign: 'center', borderBottom: '2px solid #2a9d8f', paddingBottom: 8, marginBottom: 10 }}>
              <div style={{ fontWeight: 800, fontSize: 15, color: '#c8a96e', letterSpacing: 0.5 }}>Complexe La Providence — {SCHOOL_NAME}</div>
              <div style={{ fontSize: 9, fontWeight: 600, color: '#7d93a8', marginTop: 2 }}>{printDate} — هاتف <span style={{ direction: 'ltr', display: 'inline-block' }}>{SCHOOL_PHONE}</span></div>
              <div style={{ fontWeight: 700, fontSize: 13, color: '#1a3a5c', marginTop: 6 }}>{roster.level} — {roster.section} <span style={{ fontWeight: 400, color: '#7d93a8', fontSize: 10 }}>({roster.year} · {roster.students.length} تلميذ)</span></div>
            </div>
            <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse', ['--print-rows' as any]: Math.max(roster.students.length, 1) } as React.CSSProperties}>
              <thead>
                <tr>
                  <th style={thPrint}>#</th>
                  <th style={thPrint}>الإسم الكامل</th>
                  {shownColumns.map((column) => <th key={column.key} style={thPrint}>{column.label}</th>)}
                </tr>
              </thead>
              <tbody>
                {roster.students.map((s, i) => (
                  <tr key={s.enrollment_id}>
                    <td style={{ ...tdPrint, textAlign: 'center' }}>{i + 1}</td>
                    <td style={tdPrint}>{s.first_name} {s.last_name}</td>
                    {shownColumns.map((column) => (
                      <td
                        key={column.key}
                        style={column.kind === 'phone' ? { ...tdPrint, direction: 'ltr', textAlign: 'right', letterSpacing: '0.5px' } : tdPrint}
                      >
                        {cellText(s, column.key, column.kind)}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
              </table>
            <div style={{ marginTop: 'auto', paddingTop: 10, borderTop: '1px solid #2a9d8f', display: 'flex', justifyContent: 'space-between', fontSize: 9, fontWeight: 600, color: '#7d93a8' }}>
              <span>توقيع الإدارة: ______________</span>
              <span>تاريخ الطباعة: {printDate}</span>
              <span>عدد التلاميذ: {roster.students.length}</span>
            </div>
          </div>
        ) : null}
      </div>

      {/* ===== محتوى الشاشة ===== */}
      <div className="no-print">
        <div className="flex items-center justify-between mb-6 flex-wrap gap-3">
          <div className="flex items-center gap-3">
            <div className="p-3 rounded-2xl" style={{ backgroundColor: C.sage }}>
              <ClipboardList size={22} color={C.forest} />
            </div>
            <div>
              <h1 className="text-2xl font-bold" style={{ color: C.ink }}>قوائم الأقسام</h1>
              <p className="text-sm" style={{ color: C.muted }}>تسجيل التلاميذ، تعديل البيانات، طباعة القوائم</p>
            </div>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <button
              onClick={openAdd}
              disabled={typeof sectionId !== 'number' || typeof yearId !== 'number'}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-40"
              style={{ backgroundColor: C.forest }}
            >
              <Plus size={18} />
              <span>إضافة تلاميذ</span>
            </button>
            <button
              onClick={printClass}
              disabled={!roster || roster.students.length === 0}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium disabled:opacity-40"
              style={{ border: '1px solid ' + C.forest, color: C.forest, backgroundColor: '#fff' }}
            >
              <Printer size={18} />
              <span>طباعة القسم</span>
            </button>
          </div>
        </div>

        {/* المرشّحات */}
        <div className="bg-white rounded-2xl p-4 mb-5" style={{ border: '1px solid ' + C.line }}>
          <div className="grid sm:grid-cols-3 gap-3">
            <div>
              <label htmlFor="roster_year_id" className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الدراسية</label>
              <select id="roster_year_id" name="roster_year_id" value={yearId} onChange={(e) => setYearId(e.target.value ? Number(e.target.value) : '')} className="w-full px-3 py-2.5 rounded-xl text-sm" style={selectStyle}>
                <option value="">اختر السنة</option>
                {years.map((y) => <option key={y.id} value={y.id}>{y.name}{y.is_active ? ' — حالية' : ''}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor="roster_level_id" className="block text-xs mb-1.5" style={{ color: C.muted }}>المستوى</label>
              <select id="roster_level_id" name="roster_level_id" value={levelId} onChange={(e) => { setLevelId(e.target.value ? Number(e.target.value) : ''); setSectionId(''); }} className="w-full px-3 py-2.5 rounded-xl text-sm" style={selectStyle}>
                <option value="">اختر المستوى</option>
                {levels.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor="roster_section_id" className="block text-xs mb-1.5" style={{ color: C.muted }}>القسم</label>
              <select id="roster_section_id" name="roster_section_id" value={sectionId} onChange={(e) => setSectionId(e.target.value ? Number(e.target.value) : '')} disabled={sections.length === 0} className="w-full px-3 py-2.5 rounded-xl text-sm disabled:opacity-50" style={selectStyle}>
                <option value="">اختر القسم</option>
                {sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
          </div>

          <div className="mt-4 pt-4" style={{ borderTop: '1px solid ' + C.line }}>
            <div className="flex items-center gap-2 mb-3 text-sm font-semibold" style={{ color: C.ink }}>
              <Filter size={16} color={C.forest} />
              <span>الأعمدة الظاهرة — في الجدول وفي الطباعة</span>
            </div>
            <div className="flex flex-wrap gap-2">
              {COLUMNS.map((column) => {
                const active = visible[column.key];
                return (
                  <label
                    key={column.key}
                    htmlFor={'roster_col_' + column.key}
                    className="inline-flex cursor-pointer select-none items-center gap-2 px-3 py-2 rounded-xl text-sm font-medium"
                    style={{
                      backgroundColor: active ? C.sage : '#fff',
                      color: active ? C.forest : C.muted,
                      border: '1px solid ' + (active ? C.forest : C.line),
                    }}
                  >
                    <input
                      id={'roster_col_' + column.key}
                      name={'roster_col_' + column.key}
                      type="checkbox"
                      className="h-4 w-4"
                      checked={active}
                      onChange={() => toggleColumn(column.key)}
                    />
                    <span>{column.label}</span>
                  </label>
                );
              })}
            </div>
            <p className="text-xs mt-3" style={{ color: C.muted }}>العمود المحجوب يختفي من الجدول ومن الورقة المطبوعة معًا، ويُحفظ اختيارك للمرة القادمة.</p>
          </div>
        </div>

        {error && <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>{error}</div>}
        {notice && <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.sage, color: C.deep }}>{notice}</div>}

        {/* جدول القسم */}
        {loading ? (
          <PageDataSkeleton cards={2} />
        ) : !roster ? (
          <div className="bg-white rounded-2xl p-10 text-center" style={{ border: '1px solid ' + C.line }}>
            <p style={{ color: C.muted }}>اختر السنة والمستوى والقسم لعرض القائمة.</p>
          </div>
        ) : (
          <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
            <div className="px-5 py-4 flex items-center justify-between flex-wrap gap-2" style={{ backgroundColor: C.sage }}>
              <div>
                <h2 className="font-bold text-lg" style={{ color: C.deep }}>{roster.level} — {roster.section}</h2>
                <p className="text-xs mt-0.5" style={{ color: C.muted }}>السنة {roster.year} · {roster.students.length}/{roster.capacity} تلميذ</p>
              </div>
            </div>

            {roster.students.length === 0 ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>لا تلاميذ في هذا القسم بعد. استعمل «إضافة تلاميذ».</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                      <th className="text-right px-3 py-3 font-medium" style={{ width: '2.5rem' }}>#</th>
                      <th className="text-right px-3 py-3 font-medium">الاسم</th>
                      <th className="text-right px-3 py-3 font-medium">اللقب</th>
                      {shownColumns.map((column) => (
                        <th key={column.key} className="text-right px-3 py-3 font-medium">{column.label}</th>
                      ))}
                      <th className="px-3 py-3" style={{ width: '7rem' }} />
                    </tr>
                  </thead>
                  <tbody>
                    {roster.students.map((s, i) => (
                      <tr key={s.enrollment_id} style={{ borderBottom: '1px solid ' + C.line }}>
                        {editId === s.enrollment_id && editDraft ? (
                          <>
                            <td className="px-3 py-2" style={{ color: C.muted }}>{i + 1}</td>
                            <td className="px-2 py-1.5"><input value={editDraft.first_name} onChange={(e) => setEditDraft({ ...editDraft, first_name: e.target.value })} className={inputCls} style={inputStyle} /></td>
                            <td className="px-2 py-1.5"><input value={editDraft.last_name} onChange={(e) => setEditDraft({ ...editDraft, last_name: e.target.value })} className={inputCls} style={inputStyle} /></td>
                            {shownColumns.map((column) => (
                              <td key={column.key} className="px-2 py-1.5">
                                <input
                                  value={readField(editDraft, column.key)}
                                  onChange={(e) => setEditDraft(withField(editDraft, column.key, e.target.value))}
                                  className={inputCls}
                                  style={column.kind === 'phone' ? { ...inputStyle, direction: 'ltr' } : inputStyle}
                                />
                              </td>
                            ))}
                            <td className="px-3 py-2 flex gap-1">
                              <button onClick={() => void saveEdit()} disabled={editSaving} title="حفظ" className="p-1.5 rounded-lg" style={{ backgroundColor: C.forest }}>
                                <Save size={14} color="#fff" />
                              </button>
                              <button onClick={cancelEdit} title="إلغاء" className="p-1.5 rounded-lg bg-gray-100">
                                <X size={14} color={C.muted} />
                              </button>
                            </td>
                          </>
                        ) : (
                          <>
                            <td className="px-3 py-2.5" style={{ color: C.muted }}>{i + 1}</td>
                            <td className="px-3 py-2.5" style={{ color: C.ink }}>{s.first_name}</td>
                            <td className="px-3 py-2.5" style={{ color: C.ink }}>{s.last_name}</td>
                            {shownColumns.map((column) => (
                              <td
                                key={column.key}
                                className={column.kind === 'phone' ? 'px-3 py-2.5 tabular-nums' : 'px-3 py-2.5'}
                                style={column.kind === 'phone' ? { color: C.ink, direction: 'ltr', textAlign: 'right', letterSpacing: '0.5px' } : { color: C.ink }}
                              >
                                {cellText(s, column.key, column.kind)}
                              </td>
                            ))}
                            <td className="px-3 py-2.5 flex gap-1">
                              <button onClick={() => startEdit(s)} title="تعديل" className="p-1.5 rounded-lg bg-gray-50">
                                <Pencil size={14} color={C.forest} />
                              </button>
                              <button onClick={() => printOne(s)} title="طباعة بطاقة" className="p-1.5 rounded-lg bg-gray-50">
                                <User size={14} color={C.muted} />
                              </button>
                              <button onClick={() => void removeRow(s.enrollment_id, s.first_name + ' ' + s.last_name)} title="حذف" className="p-1.5 rounded-lg bg-gray-50">
                                <Trash2 size={14} color={C.error} />
                              </button>
                            </td>
                          </>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </div>

      {/* ===== نافذة الإضافة ===== */}
      {addOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 no-print" style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}>
          <div className="bg-white rounded-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto p-6" dir="rtl">
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-bold text-lg" style={{ color: C.ink }}>إضافة تلاميذ جدد</h3>
              <button onClick={() => setAddOpen(false)}><X size={20} color={C.muted} /></button>
            </div>

            <div className="flex items-center gap-2 mb-4 flex-wrap">
              <button onClick={addRow} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm" style={{ border: '1px solid ' + C.line, color: C.forest }}>
                <Plus size={16} /><span>سطر جديد</span>
              </button>
              <button onClick={() => fileRef.current?.click()} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm" style={{ border: '1px solid ' + C.line, color: C.forest }}>
                <Upload size={16} /><span>استيراد CSV</span>
              </button>
              <input ref={fileRef} id="roster_csv_file" name="roster_csv_file" type="file" accept=".csv,text/csv" style={{ display: 'none' }} onChange={(e) => { const f = e.target.files?.[0]; if (f) void handleFile(f); }} />
              <button onClick={() => setShowPaste(!showPaste)} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm" style={{ border: '1px solid ' + C.line, color: C.forest }}>
                <ClipboardList size={16} /><span>لصق من Excel</span>
              </button>
            </div>

            {showPaste && (
              <div className="mb-4">
                <label htmlFor="roster_paste" className="text-xs mb-2 block" style={{ color: C.muted }}>الصق من Excel (كل سطر = تلميذ، الأعمدة: الاسم، اللقب، الأب، الأم، هاتف الأب، هاتف الأم)</label>
                <textarea id="roster_paste" name="roster_paste" value={pasteText} onChange={(e) => setPasteText(e.target.value)} rows={6} className="w-full px-3 py-2.5 rounded-xl text-sm" style={{ border: '1px solid ' + C.line, color: C.ink }} placeholder={'أحمد\tخطاب\tمحمد\tفاطمة\t12345678\t87654321'} />
                <button onClick={handlePaste} disabled={!pasteText.trim()} className="mt-2 px-4 py-2 rounded-lg text-white text-sm disabled:opacity-50" style={{ backgroundColor: C.forest }}>إضافة السطور</button>
              </div>
            )}

            <div className="overflow-x-auto" style={{ maxHeight: '40vh' }}>
              <table className="w-full text-sm">
                <thead>
                  <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                    <th className="text-right px-2 py-2 font-medium" style={{ width: '2rem' }}>#</th>
                    <th className="text-right px-2 py-2 font-medium">الاسم *</th>
                    <th className="text-right px-2 py-2 font-medium">اللقب *</th>
                    <th className="text-right px-2 py-2 font-medium">اسم الأب</th>
                    <th className="text-right px-2 py-2 font-medium">اسم الأم</th>
                    <th className="text-right px-2 py-2 font-medium">هاتف الأب</th>
                    <th className="text-right px-2 py-2 font-medium">هاتف الأم</th>
                    <th className="px-2 py-2" style={{ width: '2.5rem' }} />
                  </tr>
                </thead>
                <tbody>
                  {newRows.map((row, i) => (
                    <tr key={row._key} style={{ borderBottom: '1px solid ' + C.line }}>
                      <td className="px-2 py-1.5" style={{ color: C.muted }}>{i + 1}</td>
                      <td className="px-1.5 py-1"><input value={row.first_name} onChange={(e) => updateNewRow(row._key, 'first_name', e.target.value)} className={inputCls} style={inputStyle} placeholder="الاسم" /></td>
                      <td className="px-1.5 py-1"><input value={row.last_name} onChange={(e) => updateNewRow(row._key, 'last_name', e.target.value)} className={inputCls} style={inputStyle} placeholder="اللقب" /></td>
                      <td className="px-1.5 py-1"><input value={row.father_name || ''} onChange={(e) => updateNewRow(row._key, 'father_name', e.target.value)} className={inputCls} style={inputStyle} /></td>
                      <td className="px-1.5 py-1"><input value={row.mother_name || ''} onChange={(e) => updateNewRow(row._key, 'mother_name', e.target.value)} className={inputCls} style={inputStyle} /></td>
                      <td className="px-1.5 py-1"><input value={row.father_phone || ''} onChange={(e) => updateNewRow(row._key, 'father_phone', e.target.value)} className={inputCls} style={{ ...inputStyle, direction: 'ltr' }} /></td>
                      <td className="px-1.5 py-1"><input value={row.mother_phone || ''} onChange={(e) => updateNewRow(row._key, 'mother_phone', e.target.value)} className={inputCls} style={{ ...inputStyle, direction: 'ltr' }} /></td>
                      <td className="px-2 py-1.5"><button onClick={() => removeNewRow(row._key)} className="p-1 rounded-lg"><Trash2 size={14} color={C.error} /></button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <p className="text-xs mt-3" style={{ color: C.muted }}>{validRows.length} تلميذ جاهز للتسجيل (الاسم واللقب إجباريان)</p>

            <div className="flex gap-3 mt-5">
              <button onClick={() => void saveAll()} disabled={saving || validRows.length === 0} className="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50" style={{ backgroundColor: C.forest }}>
                {saving ? 'جارٍ الحفظ…' : 'تسجيل ' + validRows.length + ' تلميذ'}
              </button>
              <button onClick={() => setAddOpen(false)} className="px-5 py-2.5 rounded-xl text-sm" style={{ border: '1px solid ' + C.line, color: C.muted }}>إلغاء</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default RosterPage;
