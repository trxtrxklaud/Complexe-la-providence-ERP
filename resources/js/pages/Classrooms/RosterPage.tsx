import { useEffect, useMemo, useRef, useState } from 'react';
import { ClipboardList, Printer, Plus, Trash2, X, Upload, Pencil, Save, User } from 'lucide-react';
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

type NewRow = StudentEntry & { _key: number };

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
    // simple CSV split (no quoted-comma handling needed for Arabic names)
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

  // column toggles for print
  const [showFather, setShowFather] = useState(true);
  const [showMother, setShowMother] = useState(true);
  const [showPhones, setShowPhones] = useState(true);

  // add modal
  const [addOpen, setAddOpen] = useState(false);
  const [newRows, setNewRows] = useState<NewRow[]>([]);
  const [pasteText, setPasteText] = useState('');
  const [showPaste, setShowPaste] = useState(false);
  const [saving, setSaving] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  // inline edit
  const [editId, setEditId] = useState<number | null>(null);
  const [editDraft, setEditDraft] = useState<StudentEntry | null>(null);
  const [editSaving, setEditSaving] = useState(false);

  // individual print
  const [printStudent, setPrintStudent] = useState<RosterStudent | null>(null);

  let rowKey = useRef(1);

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

  // --- Add modal helpers ---
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

  // --- Inline edit helpers ---
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

  // --- Delete ---
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

  // --- Print class ---
  const printClass = () => {
    setPrintStudent(null);
    window.setTimeout(() => window.print(), 100);
  };

  // --- Print individual ---
  const printOne = (student: RosterStudent) => {
    setPrintStudent(student);
    window.setTimeout(() => window.print(), 100);
  };

  const selectStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };
  const inputCls = 'w-full px-2 py-1.5 rounded-lg text-sm';
  const inputStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };

  return (
    <div className="p-6" dir="rtl" style={{ backgroundColor: C.bg, minHeight: '100vh' }}>
      <style>{`
        @media print {
          body * { visibility: hidden; }
          #print-area, #print-area * { visibility: visible; }
          #print-area { position: absolute; inset: 0; width: 100%; padding: 12mm; }
          .no-print { display: none !important; }
          @page { size: A4 portrait; margin: 0; }
        }
      `}</style>

      {/* ===== Print area (hidden on screen) ===== */}
      <div id="print-area" style={{ display: 'none' }}>
        {printStudent ? (
          <div style={{ padding: '20mm 15mm', fontFamily: 'sans-serif', color: '#222' }}>
            <h2 style={{ textAlign: 'center', fontSize: 18, marginBottom: 20 }}>بطاقة تلميذ</h2>
            <table style={{ width: '100%', fontSize: 14, borderCollapse: 'collapse' }}>
              <tbody>
                <tr><td style={{ padding: '8px 12px', border: '1px solid #ccc', fontWeight: 'bold' }}>الاسم واللقب</td><td style={{ padding: '8px 12px', border: '1px solid #ccc' }}>{printStudent.first_name} {printStudent.last_name}</td></tr>
              {printStudent.student_code && (
                <tr><td style={{ padding: '8px 12px', border: '1px solid #ccc', fontWeight: 'bold' }}>الرقم المدرسي</td><td style={{ padding: '8px 12px', border: '1px solid #ccc' }}>{printStudent.student_code}</td></tr>
              )}
              {roster && (
                <tr><td style={{ padding: '8px 12px', border: '1px solid #ccc', fontWeight: 'bold' }}>القسم</td><td style={{ padding: '8px 12px', border: '1px solid #ccc' }}>{roster.level} — {roster.section}</td></tr>
              )}
              {roster && (
                <tr><td style={{ padding: '8px 12px', border: '1px solid #ccc', fontWeight: 'bold' }}>السنة الدراسية</td><td style={{ padding: '8px 12px', border: '1px solid #ccc' }}>{roster.year}</td></tr>
              )}
              {printStudent.father_name && (
                <tr><td style={{ padding: '8px 12px', border: '1px solid #ccc', fontWeight: 'bold' }}>اسم الأب</td><td style={{ padding: '8px 12px', border: '1px solid #ccc' }}>{printStudent.father_name}</td></tr>
              )}
              {printStudent.mother_name && (
                <tr><td style={{ padding: '8px 12px', border: '1px solid #ccc', fontWeight: 'bold' }}>اسم الأم</td><td style={{ padding: '8px 12px', border: '1px solid #ccc' }}>{printStudent.mother_name}</td></tr>
              )}
              {printStudent.father_phone && (
                <tr><td style={{ padding: '8px 12px', border: '1px solid #ccc', fontWeight: 'bold' }}>هاتف الأب</td><td style={{ padding: '8px 12px', border: '1px solid #ccc', direction: 'ltr', textAlign: 'right' }}>{printStudent.father_phone}</td></tr>
              )}
              {printStudent.mother_phone && (
                <tr><td style={{ padding: '8px 12px', border: '1px solid #ccc', fontWeight: 'bold' }}>هاتف الأم</td><td style={{ padding: '8px 12px', border: '1px solid #ccc', direction: 'ltr', textAlign: 'right' }}>{printStudent.mother_phone}</td></tr>
              )}
              </tbody>
            </table>
          </div>
        ) : roster ? (
          <div style={{ padding: '12mm', fontFamily: 'sans-serif', color: '#222' }}>
            <h2 style={{ textAlign: 'center', fontSize: 18, marginBottom: 4 }}>
              {roster.level} — {roster.section}
            </h2>
            <p style={{ textAlign: 'center', fontSize: 13, color: '#666', marginBottom: 16 }}>
              السنة الدراسية {roster.year} · {roster.students.length} تلميذ
            </p>
            <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
              <thead>
                <tr>
                  <th style={{ padding: '6px 8px', border: '1px solid #ccc', fontWeight: 'bold' }}>#</th>
                  <th style={{ padding: '6px 8px', border: '1px solid #ccc', fontWeight: 'bold' }}>الإسم الكامل</th>
                  {showFather && <th style={{ padding: '6px 8px', border: '1px solid #ccc', fontWeight: 'bold' }}>اسم الأب</th>}
                  {showMother && <th style={{ padding: '6px 8px', border: '1px solid #ccc', fontWeight: 'bold' }}>اسم الأم</th>}
                  {showPhones && <th style={{ padding: '6px 8px', border: '1px solid #ccc', fontWeight: 'bold' }}>الهاتف</th>}
                </tr>
              </thead>
              <tbody>
                {roster.students.map((s, i) => (
                  <tr key={s.enrollment_id}>
                    <td style={{ padding: '6px 8px', border: '1px solid #ccc', textAlign: 'center' }}>{i + 1}</td>
                    <td style={{ padding: '6px 8px', border: '1px solid #ccc' }}>{s.first_name} {s.last_name}</td>
                    {showFather && <td style={{ padding: '6px 8px', border: '1px solid #ccc' }}>{s.father_name || '—'}</td>}
                    {showMother && <td style={{ padding: '6px 8px', border: '1px solid #ccc' }}>{s.mother_name || '—'}</td>}
                    {showPhones && (
                      <td style={{ padding: '6px 8px', border: '1px solid #ccc', direction: 'ltr', textAlign: 'right' }}>
                        {s.father_phone || '—'}{s.mother_phone ? ' / ' + s.mother_phone : ''}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </div>

      {/* ===== Screen content ===== */}
      <div className="no-print">
        {/* Header */}
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

        {/* Selectors */}
        <div className="bg-white rounded-2xl p-4 mb-5" style={{ border: '1px solid ' + C.line }}>
          <div className="grid sm:grid-cols-3 gap-3">
            <div>
              <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الدراسية</label>
              <select value={yearId} onChange={(e) => setYearId(e.target.value ? Number(e.target.value) : '')} className="w-full px-3 py-2.5 rounded-xl text-sm" style={selectStyle}>
                <option value="">اختر السنة</option>
                {years.map((y) => <option key={y.id} value={y.id}>{y.name}{y.is_active ? ' — حالية' : ''}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المستوى</label>
              <select value={levelId} onChange={(e) => { setLevelId(e.target.value ? Number(e.target.value) : ''); setSectionId(''); }} className="w-full px-3 py-2.5 rounded-xl text-sm" style={selectStyle}>
                <option value="">اختر المستوى</option>
                {levels.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1.5" style={{ color: C.muted }}>القسم</label>
              <select value={sectionId} onChange={(e) => setSectionId(e.target.value ? Number(e.target.value) : '')} disabled={sections.length === 0} className="w-full px-3 py-2.5 rounded-xl text-sm disabled:opacity-50" style={selectStyle}>
                <option value="">اختر القسم</option>
                {sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
          </div>

          <div className="flex items-center gap-4 mt-4 flex-wrap text-sm" style={{ color: C.ink }}>
            <span style={{ color: C.muted }}>أعمدة الطباعة:</span>
            <label className="flex items-center gap-1.5"><input type="checkbox" checked={showFather} onChange={(e) => setShowFather(e.target.checked)} /><span>اسم الأب</span></label>
            <label className="flex items-center gap-1.5"><input type="checkbox" checked={showMother} onChange={(e) => setShowMother(e.target.checked)} /><span>اسم الأم</span></label>
            <label className="flex items-center gap-1.5"><input type="checkbox" checked={showPhones} onChange={(e) => setShowPhones(e.target.checked)} /><span>الهواتف</span></label>
          </div>
        </div>

        {error && <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>{error}</div>}
        {notice && <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.sage, color: C.deep }}>{notice}</div>}

        {/* Roster table */}
        {loading ? (
          <p className="text-sm" style={{ color: C.muted }}>جارٍ التحميل…</p>
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
                      <th className="text-right px-3 py-3 font-medium">اسم الأب</th>
                      <th className="text-right px-3 py-3 font-medium">اسم الأم</th>
                      <th className="text-right px-3 py-3 font-medium">هاتف الأب</th>
                      <th className="text-right px-3 py-3 font-medium">هاتف الأم</th>
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
                            <td className="px-2 py-1.5"><input value={editDraft.father_name || ''} onChange={(e) => setEditDraft({ ...editDraft, father_name: e.target.value })} className={inputCls} style={inputStyle} /></td>
                            <td className="px-2 py-1.5"><input value={editDraft.mother_name || ''} onChange={(e) => setEditDraft({ ...editDraft, mother_name: e.target.value })} className={inputCls} style={inputStyle} /></td>
                            <td className="px-2 py-1.5"><input value={editDraft.father_phone || ''} onChange={(e) => setEditDraft({ ...editDraft, father_phone: e.target.value })} className={inputCls} style={{ ...inputStyle, direction: 'ltr' }} /></td>
                            <td className="px-2 py-1.5"><input value={editDraft.mother_phone || ''} onChange={(e) => setEditDraft({ ...editDraft, mother_phone: e.target.value })} className={inputCls} style={{ ...inputStyle, direction: 'ltr' }} /></td>
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
                            <td className="px-3 py-2.5" style={{ color: C.ink }}>{s.father_name || '—'}</td>
                            <td className="px-3 py-2.5" style={{ color: C.ink }}>{s.mother_name || '—'}</td>
                            <td className="px-3 py-2.5 text-xs" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{s.father_phone || '—'}</td>
                            <td className="px-3 py-2.5 text-xs" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{s.mother_phone || '—'}</td>
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

      {/* ===== Add modal ===== */}
      {addOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 no-print" style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}>
          <div className="bg-white rounded-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto p-6" dir="rtl">
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-bold text-lg" style={{ color: C.ink }}>إضافة تلاميذ جدد</h3>
              <button onClick={() => setAddOpen(false)}><X size={20} color={C.muted} /></button>
            </div>

            {/* Toolbar */}
            <div className="flex items-center gap-2 mb-4 flex-wrap">
              <button onClick={addRow} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm" style={{ border: '1px solid ' + C.line, color: C.forest }}>
                <Plus size={16} /><span>سطر جديد</span>
              </button>
              <button onClick={() => fileRef.current?.click()} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm" style={{ border: '1px solid ' + C.line, color: C.forest }}>
                <Upload size={16} /><span>استيراد CSV</span>
              </button>
              <input ref={fileRef} type="file" accept=".csv,text/csv" style={{ display: 'none' }} onChange={(e) => { const f = e.target.files?.[0]; if (f) void handleFile(f); }} />
              <button onClick={() => setShowPaste(!showPaste)} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm" style={{ border: '1px solid ' + C.line, color: C.forest }}>
                <ClipboardList size={16} /><span>لصق من Excel</span>
              </button>
            </div>

            {/* Paste area */}
            {showPaste && (
              <div className="mb-4">
                <p className="text-xs mb-2" style={{ color: C.muted }}>الصق من Excel (كل سطر = تلميذ، الأعمدة: الاسم، اللقب، الأب، الأم، هاتف الأب، هاتف الأم)</p>
                <textarea value={pasteText} onChange={(e) => setPasteText(e.target.value)} rows={6} className="w-full px-3 py-2.5 rounded-xl text-sm" style={{ border: '1px solid ' + C.line, color: C.ink }} placeholder={'أحمد\tخطاب\tمحمد\tفاطمة\t12345678\t87654321'} />
                <button onClick={handlePaste} disabled={!pasteText.trim()} className="mt-2 px-4 py-2 rounded-lg text-white text-sm disabled:opacity-50" style={{ backgroundColor: C.forest }}>إضافة السطور</button>
              </div>
            )}

            {/* Editable table */}
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
