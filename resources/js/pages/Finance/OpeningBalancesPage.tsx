import { useEffect, useRef, useState } from 'react';
import { Ban, BookOpenCheck, Coins, Edit2, Layers, Plus, Save, Users, Wallet, X } from 'lucide-react';
import { PageShell } from '../../components/PageShell';
import { CancelReasonModal } from '../../components/CancelReasonModal';
import { fetchYears, type AcademicYear } from '../../api/roster';
import { getStudents, type Student } from '../../api/students';
import { getEmployees, type Employee } from '../../api/employees';
import {
  DEBT_STATUS_LABELS,
  DEBT_TYPE_LABELS,
  bulkCreateDebts,
  cancelManualDebt,
  createManualDebt,
  fetchBulkOptions,
  fetchManualDebts,
  fetchSectionStudents,
  type BulkOptions,
  type ManualDebt,
  type SectionStudentRow,
} from '../../api/manualDebts';
import {
  EMPLOYEE_DEBT_STATUS_LABELS,
  EMPLOYEE_DEBT_TYPE_LABELS,
  cancelOldEmployeeDebt,
  collectOldEmployeeDebt,
  createOldEmployeeDebt,
  fetchOldEmployeeDebts,
  updateOldEmployeeDebt,
  type OldEmployeeDebt,
} from '../../api/oldEmployeeDebts';
import { errorMessage, money, personName } from '../../lib/format';
import { ListSkeleton } from '../../components/DataSkeleton';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  ink: '#1F261C',
  muted: '#7C8677',
  line: '#EDF1E8',
  error: '#A03434',
  errorBg: '#FDECEC',
};

const statusColor: Record<string, { bg: string; fg: string }> = {
  pending: { bg: '#FEF3C7', fg: '#92400E' },
  partial: { bg: '#DBEAFE', fg: '#1E40AF' },
  paid: { bg: '#D1FAE5', fg: '#065F46' },
  cancelled: { bg: '#F3F4F6', fg: '#6B7280' },
};

const METHOD_OPTIONS = [
  { value: 'cash', label: 'نقداً' },
  { value: 'bank_transfer', label: 'تحويل بنكي' },
  { value: 'check', label: 'شيك' },
  { value: 'card', label: 'بطاقة بنكية' },
];

type Tab = 'debts' | 'bulk' | 'employee_debts';

export function OpeningBalancesPage() {
  const [tab, setTab] = useState<Tab>('debts');
  const [years, setYears] = useState<AcademicYear[]>([]);

  // ===== ديون التلاميذ — قائمة الديون =====
  const [debts, setDebts] = useState<ManualDebt[]>([]);
  const [debtStatusFilter, setDebtStatusFilter] = useState('');
  const [loadingDebts, setLoadingDebts] = useState(false);

  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [cancelDebtTarget, setCancelDebtTarget] = useState<ManualDebt | null>(null);
  const [cancelBusy, setCancelBusy] = useState(false);

  // ===== ديون التلاميذ — نموذج إدخال دَين =====
  const [debtYearId, setDebtYearId] = useState<number | ''>('');
  const [debtType, setDebtType] = useState('tuition');
  const [debtYearLabel, setDebtYearLabel] = useState('');
  const [debtDescription, setDebtDescription] = useState('');
  const [debtAmount, setDebtAmount] = useState('');
  const [debtNotes, setDebtNotes] = useState('');
  const [studentQuery, setStudentQuery] = useState('');
  const [studentResults, setStudentResults] = useState<Student[]>([]);
  const [selectedStudent, setSelectedStudent] = useState<Student | null>(null);
  const [studentSearchBusy, setStudentSearchBusy] = useState(false);
  const studentTimer = useRef<number | undefined>(undefined);
  const [savingDebt, setSavingDebt] = useState(false);

  // ===== ديون التلاميذ — الإدخال الجماعي =====
  const [bulkYearLabel, setBulkYearLabel] = useState('');
  const [bulkLevelId, setBulkLevelId] = useState<number | ''>('');
  const [bulkSectionId, setBulkSectionId] = useState<number | ''>('');
  const [bulkOptions, setBulkOptions] = useState<BulkOptions | null>(null);
  const [bulkStudents, setBulkStudents] = useState<SectionStudentRow[]>([]);
  const [bulkStudentRows, setBulkStudentRows] = useState<Record<number, { checked: boolean; debtType: string; amount: string; notes: string }>>({});
  const [bulkLoading, setBulkLoading] = useState(false);
  const [bulkStudentsLoading, setBulkStudentsLoading] = useState(false);
  const [bulkSavingStudents, setBulkSavingStudents] = useState(false);

  // ===== ديون الإطارات القديمة =====
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [loadingEmployees, setLoadingEmployees] = useState(false);
  const [employeeDebts, setEmployeeDebts] = useState<OldEmployeeDebt[]>([]);
  const [empDebtStatusFilter, setEmpDebtStatusFilter] = useState('');
  const [loadingEmpDebts, setLoadingEmpDebts] = useState(false);

  // نموذج إدخال دين إطار
  const [empId, setEmpId] = useState<number | ''>('');
  const [empYearId, setEmpYearId] = useState<number | ''>('');
  const [empYearLabel, setEmpYearLabel] = useState('');
  const [empDebtType, setEmpDebtType] = useState('debt');
  const [empDescription, setEmpDescription] = useState('');
  const [empAmount, setEmpAmount] = useState('');
  const [empNotes, setEmpNotes] = useState('');
  const [savingEmpDebt, setSavingEmpDebt] = useState(false);

  // نوافذ ديون الإطارات
  const [collectTarget, setCollectTarget] = useState<OldEmployeeDebt | null>(null);
  const [collectAmount, setCollectAmount] = useState('');
  const [collectDate, setCollectDate] = useState('');
  const [collectMethod, setCollectMethod] = useState('cash');
  const [collectNotes, setCollectNotes] = useState('');
  const [collecting, setCollecting] = useState(false);
  const [collectError, setCollectError] = useState('');

  const [editTarget, setEditTarget] = useState<OldEmployeeDebt | null>(null);
  const [editYearLabel, setEditYearLabel] = useState('');
  const [editDebtType, setEditDebtType] = useState('debt');
  const [editDescription, setEditDescription] = useState('');
  const [editAmount, setEditAmount] = useState('');
  const [editNotes, setEditNotes] = useState('');
  const [savingEdit, setSavingEdit] = useState(false);
  const [editError, setEditError] = useState('');

  const [cancelEmpTarget, setCancelEmpTarget] = useState<OldEmployeeDebt | null>(null);
  const [cancelEmpBusy, setCancelEmpBusy] = useState(false);

  const flash = (message: string) => {
    setNotice(message);
    window.setTimeout(() => setNotice(''), 4000);
  };

  const reloadDebts = async () => {
    setLoadingDebts(true);
    try {
      const page = await fetchManualDebts({
        status: debtStatusFilter || null,
        per_page: 100,
      });
      setDebts(page.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoadingDebts(false);
    }
  };

  const reloadEmployeeDebts = async () => {
    setLoadingEmpDebts(true);
    try {
      const page = await fetchOldEmployeeDebts({
        status: empDebtStatusFilter || null,
        per_page: 100,
      });
      setEmployeeDebts(page.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoadingEmpDebts(false);
    }
  };

  const loadEmployees = async () => {
    if (employees.length > 0) return;
    setLoadingEmployees(true);
    try {
      const list = await getEmployees();
      setEmployees(list.filter((e) => e.is_active));
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoadingEmployees(false);
    }
  };

  useEffect(() => {
    (async () => {
      try {
        const yrs = await fetchYears();
        setYears(yrs);
        const active = yrs.find((y) => y.is_active) ?? yrs[0];
        if (active) {
          setDebtYearId(active.id);
          setEmpYearId(active.id);
        }
      } catch (err) {
        setError(errorMessage(err));
      }
    })();
  }, []);

  useEffect(() => {
    if (tab === 'debts') {
      void reloadDebts();
    } else if (tab === 'employee_debts') {
      void reloadEmployeeDebts();
      void loadEmployees();
    }
  }, [tab, debtStatusFilter, empDebtStatusFilter]);

  // تحميل خيارات الإدخال الجماعي للتلاميذ عند فتح التبويب
  useEffect(() => {
    if (tab !== 'bulk' || bulkOptions) return;
    (async () => {
      setBulkLoading(true);
      try {
        const opts = await fetchBulkOptions();
        setBulkOptions(opts);
        if (!bulkYearLabel && opts.active_year) {
          const other = years.find((y) => y.name !== opts.active_year!.name) ?? years[0];
          if (other) setBulkYearLabel(other.name);
        }
      } catch (err) {
        setError(errorMessage(err));
      } finally {
        setBulkLoading(false);
      }
    })();
  }, [tab, bulkOptions, years, bulkYearLabel]);

  // تحميل تلاميذ القسم المختار
  useEffect(() => {
    if (tab !== 'bulk' || !bulkSectionId) {
      setBulkStudents([]);
      return;
    }
    (async () => {
      setBulkStudentsLoading(true);
      try {
        const res = await fetchSectionStudents(bulkSectionId as number);
        setBulkStudents(res.students);
        const rows: Record<number, { checked: boolean; debtType: string; amount: string; notes: string }> = {};
        res.students.forEach((s) => {
          const ex = s.existing;
          rows[s.id] = {
            checked: !!ex,
            debtType: ex ? ex.debt_type : 'tuition',
            amount: ex ? String(ex.original_amount) : '',
            notes: ex ? ex.notes ?? '' : '',
          };
        });
        setBulkStudentRows(rows);
      } catch (err) {
        setError(errorMessage(err));
      } finally {
        setBulkStudentsLoading(false);
      }
    })();
  }, [tab, bulkSectionId]);

  // بحث التلاميذ عند الكتابة (debounce).
  useEffect(() => {
    window.clearTimeout(studentTimer.current);
    const q = studentQuery.trim();
    if (q.length < 2) {
      setStudentResults([]);
      return;
    }
    studentTimer.current = window.setTimeout(async () => {
      setStudentSearchBusy(true);
      try {
        const rows = await getStudents({ student_name: q, per_page: 10 });
        setStudentResults(rows);
      } catch {
        setStudentResults([]);
      } finally {
        setStudentSearchBusy(false);
      }
    }, 350);
    return () => window.clearTimeout(studentTimer.current);
  }, [studentQuery]);

  const pickStudent = (student: Student) => {
    setSelectedStudent(student);
    setStudentQuery([student.first_name, student.last_name].filter(Boolean).join(' '));
    setStudentResults([]);
  };

  const submitDebt = async () => {
    if (!selectedStudent) {
      setError('اختر التلميذ');
      return;
    }
    const value = Number(debtAmount);
    if (!Number.isFinite(value) || value <= 0) {
      setError('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }
    if (debtDescription.trim().length === 0) {
      setError('الوصف مطلوب');
      return;
    }
    if (debtYearLabel.trim().length === 0) {
      setError('تسمية السنة الأصلية مطلوبة — مثال: 2025/2026');
      return;
    }

    setSavingDebt(true);
    setError('');
    try {
      await createManualDebt({
        student_id: selectedStudent.id,
        academic_year_id: debtYearId === '' ? null : debtYearId,
        original_year_label: debtYearLabel.trim(),
        debt_type: debtType,
        description: debtDescription.trim(),
        original_amount: value,
        notes: debtNotes.trim() || null,
      });
      setSelectedStudent(null);
      setStudentQuery('');
      setDebtDescription('');
      setDebtAmount('');
      setDebtNotes('');
      flash('تمّ إدخال الدَّين — يظهر في متخلّدات السنوات السابقة عند الاستخلاص');
      await reloadDebts();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSavingDebt(false);
    }
  };

  const submitBulkStudents = async () => {
    if (!bulkYearLabel.trim()) {
      setError('السنة الأصلية مطلوبة');
      return;
    }
    if (bulkOptions?.active_year && bulkYearLabel.trim() === bulkOptions.active_year.name) {
      setError('سنة المنشأ لا يمكن أن تساوي السنة الحالية');
      return;
    }
    const items = bulkStudents
      .filter((s) => {
        const row = bulkStudentRows[s.id];
        if (!row?.checked) return false;
        const val = Number(row.amount);
        return Number.isFinite(val) && val > 0;
      })
      .map((s) => {
        const row = bulkStudentRows[s.id]!;
        return {
          student_id: s.id,
          debt_type: row.debtType,
          original_amount: Number(row.amount),
          notes: row.notes.trim() || null,
        };
      });

    if (items.length === 0) {
      setError('لم يتم تحديد أي تلميذ بمبلغ صحيح (> 0)');
      return;
    }

    setBulkSavingStudents(true);
    setError('');
    try {
      const activeYear = years.find((y) => y.is_active);
      const res = await bulkCreateDebts({
        academic_year_id: activeYear?.id ?? null,
        original_year_label: bulkYearLabel.trim(),
        items,
      });
      flash('تمّ تسجيل ديون جماعية بنجاح: ' + res.created + ' سجل');
      const refreshed = await fetchSectionStudents(bulkSectionId as number);
      setBulkStudents(refreshed.students);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBulkSavingStudents(false);
    }
  };

  const confirmCancelDebt = async (reason: string) => {
    if (!cancelDebtTarget) return;
    setCancelBusy(true);
    try {
      await cancelManualDebt(cancelDebtTarget.id, reason);
      setCancelDebtTarget(null);
      flash('تمّ إلغاء الدَّين');
      await reloadDebts();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setCancelBusy(false);
    }
  };

  // ===== دوال ديون الإطارات =====
  const submitEmployeeDebt = async () => {
    if (!empId) {
      setError('اختر الإطار');
      return;
    }
    const value = Number(empAmount);
    if (!Number.isFinite(value) || value <= 0) {
      setError('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }
    if (empDescription.trim().length === 0) {
      setError('الوصف مطلوب');
      return;
    }
    if (empYearLabel.trim().length === 0) {
      setError('تسمية السنة الأصلية مطلوبة — مثال: 2024/2025');
      return;
    }

    setSavingEmpDebt(true);
    setError('');
    try {
      await createOldEmployeeDebt({
        employee_id: Number(empId),
        academic_year_id: empYearId === '' ? null : Number(empYearId),
        original_year_label: empYearLabel.trim(),
        debt_type: empDebtType,
        description: empDescription.trim(),
        original_amount: value,
        notes: empNotes.trim() || null,
      });
      setEmpId('');
      setEmpDescription('');
      setEmpAmount('');
      setEmpNotes('');
      flash('تمّ تسجيل دَين الإطار القديم بنجاح');
      await reloadEmployeeDebts();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSavingEmpDebt(false);
    }
  };

  const openCollectModal = (debt: OldEmployeeDebt) => {
    const outstanding = debt.outstanding_amount ?? 0;
    setCollectTarget(debt);
    setCollectAmount(outstanding > 0 ? String(outstanding) : '');
    setCollectDate(new Date().toISOString().split('T')[0]);
    setCollectMethod('cash');
    setCollectNotes('');
    setCollectError('');
  };

  const submitCollect = async () => {
    if (!collectTarget) return;
    const value = Number(collectAmount);
    const outstanding = collectTarget.outstanding_amount ?? 0;
    if (!Number.isFinite(value) || value <= 0) {
      setCollectError('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }
    if (value > outstanding) {
      setCollectError(`المبلغ المطلوب تحصيله (${value}) يتجاوز المتبقي (${outstanding})`);
      return;
    }

    setCollecting(true);
    setCollectError('');
    try {
      await collectOldEmployeeDebt(collectTarget.id, {
        amount: value,
        payment_date: collectDate || undefined,
        method: collectMethod,
        notes: collectNotes.trim() || null,
      });
      setCollectTarget(null);
      flash('تمّ تحصيل دفعة دَين الإطار وإسقاطها في الخزينة بنجاح');
      await reloadEmployeeDebts();
    } catch (err) {
      setCollectError(errorMessage(err));
    } finally {
      setCollecting(false);
    }
  };

  const openEditModal = (debt: OldEmployeeDebt) => {
    setEditTarget(debt);
    setEditYearLabel(debt.original_year_label || '');
    setEditDebtType(debt.debt_type || 'debt');
    setEditDescription(debt.description || '');
    setEditAmount(String(debt.original_amount || ''));
    setEditNotes(debt.notes || '');
    setEditError('');
  };

  const submitEdit = async () => {
    if (!editTarget) return;
    const hasPaid = (editTarget.collected_amount ?? 0) > 0;

    setSavingEdit(true);
    setEditError('');
    try {
      if (hasPaid) {
        // بعد التحصيل: تعديل الملاحظات فقط
        await updateOldEmployeeDebt(editTarget.id, {
          notes: editNotes.trim() || null,
        });
      } else {
        const value = Number(editAmount);
        if (!Number.isFinite(value) || value <= 0) {
          setEditError('المبلغ يجب أن يكون أكبر من صفر');
          setSavingEdit(false);
          return;
        }
        if (editDescription.trim().length === 0) {
          setEditError('الوصف مطلوب');
          setSavingEdit(false);
          return;
        }
        if (editYearLabel.trim().length === 0) {
          setEditError('السنة الأصلية مطلوبة');
          setSavingEdit(false);
          return;
        }

        await updateOldEmployeeDebt(editTarget.id, {
          original_year_label: editYearLabel.trim(),
          debt_type: editDebtType,
          description: editDescription.trim(),
          original_amount: value,
          notes: editNotes.trim() || null,
        });
      }

      setEditTarget(null);
      flash('تمّ تحديث بيانات الدَّين بنجاح');
      await reloadEmployeeDebts();
    } catch (err) {
      setEditError(errorMessage(err));
    } finally {
      setSavingEdit(false);
    }
  };

  const openCancelEmpModal = (debt: OldEmployeeDebt) => {
    if ((debt.collected_amount ?? 0) > 0) return;
    setCancelEmpTarget(debt);
  };

  const confirmCancelEmpDebt = async (reason: string) => {
    if (!cancelEmpTarget) return;
    setCancelEmpBusy(true);
    try {
      await cancelOldEmployeeDebt(cancelEmpTarget.id, reason);
      setCancelEmpTarget(null);
      flash('تمّ إلغاء دَين الإطار');
      await reloadEmployeeDebts();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setCancelEmpBusy(false);
    }
  };

  const fieldStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };
  const fieldCls = 'w-full px-3 py-2.5 rounded-xl text-sm transition-colors';
  const btnCls = 'inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-white text-sm font-medium transition-colors disabled:opacity-50';

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <PageShell
        title="الأرصدة الافتتاحية"
        subtitle="الديون القديمة السابقة لتشغيل النظام (تلاميذ وإطارات) كأرصدة افتتاحية مستقلة"
        icon={Wallet}
      >
        <div>
          {error ? (
            <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.errorBg, color: C.error }}>{error}</div>
          ) : null}

          {notice ? (
            <div className="mb-4 px-4 py-3 rounded-xl text-sm" style={{ backgroundColor: C.sage, color: C.deep }}>{notice}</div>
          ) : null}

          {/* التبويبات */}
          <div className="no-print flex flex-wrap gap-2 mb-6">
            {([
              { key: 'debts', label: 'ديون التلاميذ (فردي)', icon: BookOpenCheck },
              { key: 'bulk', label: 'ديون التلاميذ (جماعي)', icon: Layers },
              { key: 'employee_debts', label: 'ديون الإطارات القديمة', icon: Users },
            ] as const).map((item) => (
              <button
                key={item.key}
                type="button"
                onClick={() => { setTab(item.key); setError(''); }}
                className="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium"
                style={
                  tab === item.key
                    ? { backgroundColor: C.deep, color: '#fff' }
                    : { border: '1px solid ' + C.line, color: C.muted, backgroundColor: '#fff' }
                }
              >
                <item.icon size={18} />
                <span>{item.label}</span>
              </button>
            ))}
          </div>

          {/* ══════════ التبويب 1: ديون التلاميذ (فردي) ══════════ */}
          {tab === 'debts' && (
            <>
              {/* نموذج إدخال دَين تلميذ */}
              <div className="no-print bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
                <div className="flex items-center gap-2 mb-4">
                  <Plus size={18} color={C.deep} />
                  <h3 className="font-bold" style={{ color: C.deep }}>إدخال دَين قديم لتلميذ</h3>
                </div>
                <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                  <div className="relative">
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>التلميذ *</label>
                    <input
                      value={studentQuery}
                      onChange={(e) => {
                        setStudentQuery(e.target.value);
                        setSelectedStudent(null);
                      }}
                      className={fieldCls}
                      style={fieldStyle}
                      placeholder="ابحث بالاسم…"
                    />
                    {studentResults.length > 0 ? (
                      <div className="absolute z-20 w-full mt-1 bg-white rounded-xl shadow-lg overflow-hidden" style={{ border: '1px solid ' + C.line }}>
                        {studentResults.map((student) => (
                          <button
                            key={student.id}
                            type="button"
                            onClick={() => pickStudent(student)}
                            className="w-full text-right px-3 py-2.5 text-sm hover:bg-slate-50"
                          >
                            <span style={{ color: C.ink }}>{student.first_name} {student.last_name}</span>
                            <span className="block text-xs" style={{ color: C.muted }}>{student.student_code || ''}</span>
                          </button>
                        ))}
                      </div>
                    ) : null}
                    {studentSearchBusy ? <p className="text-xs mt-1" style={{ color: C.muted }}>جارٍ البحث…</p> : null}
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الدراسية (النقل إليها)</label>
                    <select value={debtYearId} onChange={(e) => setDebtYearId(e.target.value ? Number(e.target.value) : '')} className={fieldCls} style={fieldStyle}>
                      {years.map((y) => <option key={y.id} value={y.id}>{y.name}{y.is_active ? ' — حالية' : ''}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>نوع الدَّين *</label>
                    <select value={debtType} onChange={(e) => setDebtType(e.target.value)} className={fieldCls} style={fieldStyle}>
                      {Object.entries(DEBT_TYPE_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الأصلية *</label>
                    <input value={debtYearLabel} onChange={(e) => setDebtYearLabel(e.target.value)} className={fieldCls} style={fieldStyle} placeholder="مثال: 2025/2026" />
                  </div>
                  <div className="lg:col-span-2">
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>الوصف *</label>
                    <input value={debtDescription} onChange={(e) => setDebtDescription(e.target.value)} className={fieldCls} style={fieldStyle} placeholder="مثال: متخلّدات السنة الدراسية السابقة" />
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المبلغ (د.ت) *</label>
                    <input value={debtAmount} onChange={(e) => setDebtAmount(e.target.value)} type="number" step="0.01" min="0" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} placeholder="0.00" />
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>ملاحظات</label>
                    <input value={debtNotes} onChange={(e) => setDebtNotes(e.target.value)} className={fieldCls} style={fieldStyle} />
                  </div>
                </div>
                <button type="button" onClick={() => void submitDebt()} disabled={savingDebt} className={btnCls + ' mt-5'} style={{ backgroundColor: C.forest }}>
                  <Save size={18} />
                  <span>{savingDebt ? 'جارٍ الإدخال…' : 'إدخال الدَّين'}</span>
                </button>
              </div>

              {/* جدول ديون التلاميذ */}
              <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
                <div className="px-5 py-4 flex items-center justify-between" style={{ backgroundColor: C.sage }}>
                  <h3 className="font-bold" style={{ color: C.deep }}>ديون التلاميذ المدخلة</h3>
                  <select value={debtStatusFilter} onChange={(e) => setDebtStatusFilter(e.target.value)} className="text-xs rounded-xl px-3 py-1.5" style={fieldStyle}>
                    <option value="">كل الحالات</option>
                    {Object.entries(DEBT_STATUS_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                  </select>
                </div>
                {loadingDebts ? (
                  <ListSkeleton rows={4} />
                ) : debts.length === 0 ? (
                  <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>لا ديون مدخلة بعد.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                          <th className="text-right px-3 py-3 font-medium">التلميذ</th>
                          <th className="text-right px-3 py-3 font-medium">النوع</th>
                          <th className="text-right px-3 py-3 font-medium">الوصف</th>
                          <th className="text-right px-3 py-3 font-medium">السنة الأصلية</th>
                          <th className="text-right px-3 py-3 font-medium">المبلغ الأصلي</th>
                          <th className="text-right px-3 py-3 font-medium">المحصّل</th>
                          <th className="text-right px-3 py-3 font-medium">المتبقّي</th>
                          <th className="text-right px-3 py-3 font-medium">الحالة</th>
                          <th className="text-right px-3 py-3 font-medium">الإجراءات</th>
                        </tr>
                      </thead>
                      <tbody>
                        {debts.map((d) => {
                          const outstanding = d.outstanding_amount ?? 0;
                          const sStyle = statusColor[d.status] || { bg: '#F3F4F6', fg: '#6B7280' };
                          return (
                            <tr key={d.id} style={{ borderBottom: '1px solid ' + C.line }}>
                              <td className="px-3 py-3 font-medium" style={{ color: C.ink }}>{personName(d.student)}</td>
                              <td className="px-3 py-3 text-xs" style={{ color: C.muted }}>{DEBT_TYPE_LABELS[d.debt_type] || d.debt_type}</td>
                              <td className="px-3 py-3 text-xs" style={{ color: C.ink }}>{d.description}</td>
                              <td className="px-3 py-3 text-xs" style={{ color: C.muted }}>{d.original_year_label}</td>
                              <td className="px-3 py-3 font-mono text-xs" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{money(d.original_amount)}</td>
                              <td className="px-3 py-3 font-mono text-xs" style={{ color: C.muted, direction: 'ltr', textAlign: 'right' }}>{money(d.collected_amount ?? 0)}</td>
                              <td className="px-3 py-3 font-mono text-xs font-semibold" style={{ color: outstanding > 0 ? C.error : C.forest, direction: 'ltr', textAlign: 'right' }}>{money(outstanding)}</td>
                              <td className="px-3 py-3">
                                <span className="inline-block text-xs px-2.5 py-0.5 rounded-full font-medium" style={{ backgroundColor: sStyle.bg, color: sStyle.fg }}>
                                  {DEBT_STATUS_LABELS[d.status] || d.status}
                                </span>
                              </td>
                              <td className="px-3 py-3">
                                {d.status !== 'cancelled' && (d.collected_amount ?? 0) === 0 ? (
                                  <button
                                    type="button"
                                    onClick={() => setCancelDebtTarget(d)}
                                    className="p-1.5 rounded-lg text-rose-600 hover:bg-rose-50 transition-colors"
                                    title="إلغاء الدَّين"
                                  >
                                    <Ban size={16} />
                                  </button>
                                ) : (
                                  <span className="text-xs" style={{ color: C.muted }}>—</span>
                                )}
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </>
          )}

          {/* ══════════ التبويب 2: الإدخال الجماعي للتلاميذ ══════════ */}
          {tab === 'bulk' && (
            <>
              {/* شريط سنة المنشأ المشتركة */}
              <div className="bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الأصلية (مشتركة) *</label>
                <select value={bulkYearLabel} onChange={(e) => setBulkYearLabel(e.target.value)} className={fieldCls} style={fieldStyle}>
                  <option value="">اختر السنة الأصلية…</option>
                  {years.filter((y) => y.name !== bulkOptions?.active_year?.name).map((y) => <option key={y.id} value={y.name}>{y.name}</option>)}
                </select>
                {bulkOptions?.active_year ? <p className="text-xs mt-1" style={{ color: C.muted }}>السنة النشطة الحالية: {bulkOptions.active_year.name} — لا يمكن اختيارها</p> : null}
              </div>

              {/* قسم التلاميذ */}
              <div className="bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
                <h3 className="font-bold mb-4" style={{ color: C.deep }}>ديون التلاميذ — إدخال جماعي</h3>
                <div className="grid sm:grid-cols-2 gap-4 mb-4">
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المستوى</label>
                    <select value={bulkLevelId} onChange={(e) => { setBulkLevelId(e.target.value ? Number(e.target.value) : ''); setBulkSectionId(''); }} className={fieldCls} style={fieldStyle}>
                      <option value="">اختر المستوى…</option>
                      {bulkOptions?.levels.map((lvl) => <option key={lvl.id} value={lvl.id}>{lvl.name}</option>) ?? null}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>القسم</label>
                    <select value={bulkSectionId} onChange={(e) => setBulkSectionId(e.target.value ? Number(e.target.value) : '')} className={fieldCls} style={fieldStyle}>
                      <option value="">اختر القسم…</option>
                      {(bulkOptions?.sections.filter((s) => !bulkLevelId || s.level_id === bulkLevelId) ?? []).map((sec) => <option key={sec.id} value={sec.id}>{sec.name}</option>)}
                    </select>
                  </div>
                </div>

                {bulkLoading ? <ListSkeleton rows={3} /> : !bulkSectionId ? <p className="text-sm text-center py-6" style={{ color: C.muted }}>اختر المستوى والقسم لعرض التلاميذ</p> : bulkStudentsLoading ? <ListSkeleton rows={4} /> : bulkStudents.length === 0 ? <p className="text-sm text-center py-6" style={{ color: C.muted }}>لا تلاميذ في هذا القسم</p> : (
                  <>
                    <div className="overflow-x-auto rounded-xl" style={{ border: '1px solid ' + C.line }}>
                      <table className="w-full text-sm">
                        <thead>
                          <tr style={{ backgroundColor: C.sage, color: C.deep }}>
                            <th className="px-3 py-2.5">☐</th>
                            <th className="text-right px-3 py-2.5 font-medium">الاسم الكامل</th>
                            <th className="text-right px-3 py-2.5 font-medium">رقم التلميذ</th>
                            <th className="text-right px-3 py-2.5 font-medium">نوع الدين</th>
                            <th className="text-right px-3 py-2.5 font-medium">المبلغ</th>
                            <th className="text-right px-3 py-2.5 font-medium">الملاحظات</th>
                            <th className="text-right px-3 py-2.5 font-medium">الأصلي</th>
                            <th className="text-right px-3 py-2.5 font-medium">المحصل</th>
                            <th className="text-right px-3 py-2.5 font-medium">المتبقي</th>
                            <th className="text-right px-3 py-2.5 font-medium">الحالة</th>
                          </tr>
                        </thead>
                        <tbody>
                          {bulkStudents.map((s) => {
                            const row = bulkStudentRows[s.id] ?? { checked: false, debtType: 'tuition', amount: '', notes: '' };
                            const disabled = (s.existing?.collected_amount ?? 0) > 0;
                            const outstanding = s.existing ? Math.max(0, Number(s.existing.original_amount) - Number(s.existing.collected_amount ?? 0)) : 0;
                            const statusLabel = s.existing ? (s.existing.collected_amount > 0 ? (outstanding === 0 ? 'مدفوع' : 'جزئي') : 'قائم') : '—';
                            return (
                              <tr key={s.id} style={{ borderTop: '1px solid ' + C.line, opacity: disabled ? 0.55 : 1 }}>
                                <td className="px-3 py-2.5"><input type="checkbox" checked={row.checked} disabled={disabled} onChange={(e) => setBulkStudentRows((prev) => ({ ...prev, [s.id]: { ...row, checked: e.target.checked } }))} /></td>
                                <td className="px-3 py-2.5" style={{ color: C.ink }}>{s.full_name}</td>
                                <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{s.student_code ?? '—'}</td>
                                <td className="px-3 py-2.5">
                                  <select value={row.debtType} disabled={disabled} onChange={(e) => setBulkStudentRows((prev) => ({ ...prev, [s.id]: { ...row, debtType: e.target.value } }))} className="text-xs rounded-lg px-2 py-1.5" style={{ ...fieldStyle, opacity: disabled ? 0.6 : 1 }}>
                                    {Object.entries(DEBT_TYPE_LABELS).map(([v,l]) => <option key={v} value={v}>{l}</option>)}
                                  </select>
                                </td>
                                <td className="px-3 py-2.5">
                                  <input type="number" min="0" step="0.01" value={row.amount} disabled={disabled || !row.checked} onChange={(e) => setBulkStudentRows((prev) => ({ ...prev, [s.id]: { ...row, amount: e.target.value } }))} className="w-24 px-2 py-1.5 rounded-lg text-sm" style={{ ...fieldStyle, direction: 'ltr', opacity: disabled || !row.checked ? 0.6 : 1 }} placeholder="0.00" />
                                </td>
                                <td className="px-3 py-2.5"><input value={row.notes} disabled={disabled} onChange={(e) => setBulkStudentRows((prev) => ({ ...prev, [s.id]: { ...row, notes: e.target.value } }))} className="w-28 px-2 py-1.5 rounded-lg text-xs" style={{ ...fieldStyle, opacity: disabled ? 0.6 : 1 }} /></td>
                                <td className="px-3 py-2.5" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{s.existing ? money(s.existing.original_amount) : '—'}</td>
                                <td className="px-3 py-2.5" style={{ color: C.muted, direction: 'ltr', textAlign: 'right' }}>{s.existing ? money(s.existing.collected_amount) : '—'}</td>
                                <td className="px-3 py-2.5" style={{ color: C.forest, direction: 'ltr', textAlign: 'right' }}>{s.existing ? money(outstanding) : '—'}</td>
                                <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{s.existing ? statusLabel : '—'}</td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                    <button type="button" onClick={() => void submitBulkStudents()} disabled={bulkSavingStudents || bulkStudentsLoading} className="mt-4 px-5 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50" style={{ backgroundColor: C.forest }}>
                      {bulkSavingStudents ? 'جارٍ الحفظ…' : 'حفظ جماعي (تلاميذ)'}
                    </button>
                  </>
                )}
              </div>
            </>
          )}

          {/* ══════════ التبويب 3: ديون الإطارات القديمة ══════════ */}
          {tab === 'employee_debts' && (
            <>
              {/* نموذج إدخال دَين إطار */}
              <div className="no-print bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
                <div className="flex items-center gap-2 mb-4">
                  <Plus size={18} color={C.deep} />
                  <h3 className="font-bold" style={{ color: C.deep }}>إدخال دَين قديم لإطار (رصيد افتتاحي)</h3>
                </div>
                <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>الإطار *</label>
                    <select
                      value={empId}
                      onChange={(e) => setEmpId(e.target.value ? Number(e.target.value) : '')}
                      className={fieldCls}
                      style={fieldStyle}
                      disabled={loadingEmployees}
                    >
                      <option value="">اختر الإطار…</option>
                      {employees.map((emp) => (
                        <option key={emp.id} value={emp.id}>
                          {emp.first_name} {emp.last_name} {emp.job_title ? `(${emp.job_title})` : ''}
                        </option>
                      ))}
                    </select>
                    {loadingEmployees ? <p className="text-xs mt-1" style={{ color: C.muted }}>جارٍ تحميل قائمة الإطارات…</p> : null}
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الدراسية (الربط)</label>
                    <select
                      value={empYearId}
                      onChange={(e) => setEmpYearId(e.target.value ? Number(e.target.value) : '')}
                      className={fieldCls}
                      style={fieldStyle}
                    >
                      {years.map((y) => (
                        <option key={y.id} value={y.id}>
                          {y.name}{y.is_active ? ' — حالية' : ''}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>نوع الدَّين *</label>
                    <select
                      value={empDebtType}
                      onChange={(e) => setEmpDebtType(e.target.value)}
                      className={fieldCls}
                      style={fieldStyle}
                    >
                      {Object.entries(EMPLOYEE_DEBT_TYPE_LABELS).map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الأصلية *</label>
                    <input
                      value={empYearLabel}
                      onChange={(e) => setEmpYearLabel(e.target.value)}
                      className={fieldCls}
                      style={fieldStyle}
                      placeholder="مثال: 2024/2025"
                    />
                  </div>
                  <div className="lg:col-span-2">
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>الوصف *</label>
                    <input
                      value={empDescription}
                      onChange={(e) => setEmpDescription(e.target.value)}
                      className={fieldCls}
                      style={fieldStyle}
                      placeholder="مثال: رصيد افتتاحي سابق لتشغيل المنظومة"
                    />
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المبلغ (د.ت) *</label>
                    <input
                      value={empAmount}
                      onChange={(e) => setEmpAmount(e.target.value)}
                      type="number"
                      step="0.01"
                      min="0.01"
                      className={fieldCls}
                      style={{ ...fieldStyle, direction: 'ltr' }}
                      placeholder="0.00"
                    />
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>ملاحظات</label>
                    <input
                      value={empNotes}
                      onChange={(e) => setEmpNotes(e.target.value)}
                      className={fieldCls}
                      style={fieldStyle}
                      placeholder="أي توضيحات إدارية…"
                    />
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => void submitEmployeeDebt()}
                  disabled={savingEmpDebt}
                  className={btnCls + ' mt-5'}
                  style={{ backgroundColor: C.forest }}
                >
                  <Save size={18} />
                  <span>{savingEmpDebt ? 'جارٍ الحفظ…' : 'حفظ دَين الإطار'}</span>
                </button>
              </div>

              {/* جدول ديون الإطارات */}
              <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
                <div className="px-5 py-4 flex items-center justify-between" style={{ backgroundColor: C.sage }}>
                  <h3 className="font-bold" style={{ color: C.deep }}>ديون الإطارات القديمة</h3>
                  <select
                    value={empDebtStatusFilter}
                    onChange={(e) => setEmpDebtStatusFilter(e.target.value)}
                    className="text-xs rounded-xl px-3 py-1.5"
                    style={fieldStyle}
                  >
                    <option value="">كل الحالات</option>
                    {Object.entries(EMPLOYEE_DEBT_STATUS_LABELS).map(([value, label]) => (
                      <option key={value} value={value}>{label}</option>
                    ))}
                  </select>
                </div>
                {loadingEmpDebts ? (
                  <ListSkeleton rows={4} />
                ) : employeeDebts.length === 0 ? (
                  <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>لا توجد ديون إطارات مسجلة.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                          <th className="text-right px-3 py-3 font-medium">الإطار</th>
                          <th className="text-right px-3 py-3 font-medium">الوظيفة</th>
                          <th className="text-right px-3 py-3 font-medium">النوع</th>
                          <th className="text-right px-3 py-3 font-medium">الوصف</th>
                          <th className="text-right px-3 py-3 font-medium">سنة المنشأ</th>
                          <th className="text-right px-3 py-3 font-medium">المبلغ الأصلي</th>
                          <th className="text-right px-3 py-3 font-medium">المحصّل</th>
                          <th className="text-right px-3 py-3 font-medium">المتبقّي</th>
                          <th className="text-right px-3 py-3 font-medium">الحالة</th>
                          <th className="text-right px-3 py-3 font-medium">الإجراءات</th>
                        </tr>
                      </thead>
                      <tbody>
                        {employeeDebts.map((d) => {
                          const outstanding = d.outstanding_amount ?? 0;
                          const collected = d.collected_amount ?? 0;
                          const isCancelled = d.status === 'cancelled';
                          const sStyle = statusColor[d.status] || { bg: '#F3F4F6', fg: '#6B7280' };
                          const empName = d.employee ? `${d.employee.first_name} ${d.employee.last_name}` : `إطار #${d.employee_id}`;

                          return (
                            <tr key={d.id} style={{ borderBottom: '1px solid ' + C.line }}>
                              <td className="px-3 py-3 font-medium" style={{ color: C.ink }}>{empName}</td>
                              <td className="px-3 py-3 text-xs" style={{ color: C.muted }}>{d.employee?.job_title || '—'}</td>
                              <td className="px-3 py-3 text-xs" style={{ color: C.muted }}>{EMPLOYEE_DEBT_TYPE_LABELS[d.debt_type] || d.debt_type}</td>
                              <td className="px-3 py-3 text-xs" style={{ color: C.ink }}>{d.description}</td>
                              <td className="px-3 py-3 text-xs" style={{ color: C.muted }}>{d.original_year_label}</td>
                              <td className="px-3 py-3 font-mono text-xs" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{money(d.original_amount)}</td>
                              <td className="px-3 py-3 font-mono text-xs" style={{ color: C.muted, direction: 'ltr', textAlign: 'right' }}>{money(collected)}</td>
                              <td className="px-3 py-3 font-mono text-xs font-semibold" style={{ color: outstanding > 0 ? C.error : C.forest, direction: 'ltr', textAlign: 'right' }}>{money(outstanding)}</td>
                              <td className="px-3 py-3">
                                <span className="inline-block text-xs px-2.5 py-0.5 rounded-full font-medium" style={{ backgroundColor: sStyle.bg, color: sStyle.fg }}>
                                  {EMPLOYEE_DEBT_STATUS_LABELS[d.status] || d.status}
                                </span>
                              </td>
                              <td className="px-3 py-3">
                                <div className="flex items-center gap-1">
                                  {/* زر التحصيل: يظهر للدين غير الملغى وله متبقٍ > 0 */}
                                  {!isCancelled && outstanding > 0 && (
                                    <button
                                      type="button"
                                      onClick={() => openCollectModal(d)}
                                      className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium text-white transition-colors"
                                      style={{ backgroundColor: C.forest }}
                                      title="تحصيل دفعة"
                                    >
                                      <Coins size={14} />
                                      <span>تحصيل</span>
                                    </button>
                                  )}

                                  {/* زر التعديل: يظهر لكل دين غير ملغى */}
                                  {!isCancelled && (
                                    <button
                                      type="button"
                                      onClick={() => openEditModal(d)}
                                      className="p-1.5 rounded-lg text-slate-600 hover:bg-slate-100 transition-colors"
                                      title={collected > 0 ? 'تعديل الملاحظات (تم التحصيل)' : 'تعديل بيانات الدَّين'}
                                    >
                                      <Edit2 size={15} />
                                    </button>
                                  )}

                                  {/* زر الإلغاء: مفعّل فقط إذا لم يتم تحصيل أي مبلغ */}
                                  {!isCancelled && (
                                    collected === 0 ? (
                                      <button
                                        type="button"
                                        onClick={() => openCancelEmpModal(d)}
                                        className="p-1.5 rounded-lg text-rose-600 hover:bg-rose-50 transition-colors"
                                        title="إلغاء الدَّين"
                                      >
                                        <Ban size={15} />
                                      </button>
                                    ) : (
                                      <button
                                        type="button"
                                        disabled
                                        className="p-1.5 rounded-lg text-slate-300 cursor-not-allowed"
                                        title="لا يمكن إلغاء دين حُصّلت منه مبالغ"
                                      >
                                        <Ban size={15} />
                                      </button>
                                    )
                                  )}

                                  {isCancelled && <span className="text-xs" style={{ color: C.muted }}>ملغى</span>}
                                </div>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </>
          )}
        </div>
      </PageShell>

      {/* ══════════ النافذة المنبثقة 1: تحصيل دين إطار ══════════ */}
      {collectTarget ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}>
          <div className="bg-white rounded-2xl w-full max-w-md p-6 shadow-2xl" dir="rtl">
            <div className="flex items-center justify-between mb-4 border-b pb-3" style={{ borderColor: C.line }}>
              <div className="flex items-center gap-2">
                <Coins size={20} color={C.forest} />
                <h3 className="font-bold text-base" style={{ color: C.deep }}>تحصيل دفعة دَين إطار</h3>
              </div>
              <button onClick={() => setCollectTarget(null)} type="button">
                <X size={18} color={C.muted} />
              </button>
            </div>

            {collectError ? (
              <div className="mb-4 px-3 py-2 rounded-xl text-xs" style={{ backgroundColor: C.errorBg, color: C.error }}>
                {collectError}
              </div>
            ) : null}

            <div className="mb-4 p-3 rounded-xl bg-slate-50 space-y-1 text-xs" style={{ border: '1px solid ' + C.line }}>
              <p><span style={{ color: C.muted }}>الإطار: </span><strong style={{ color: C.ink }}>{collectTarget.employee?.first_name} {collectTarget.employee?.last_name}</strong></p>
              <p><span style={{ color: C.muted }}>المبلغ الأصلي: </span><strong style={{ color: C.ink }}>{money(collectTarget.original_amount)}</strong></p>
              <p><span style={{ color: C.muted }}>المحصّل سابقاً: </span><strong style={{ color: C.muted }}>{money(collectTarget.collected_amount ?? 0)}</strong></p>
              <p><span style={{ color: C.muted }}>المتبقّي للدفع: </span><strong className="text-emerald-700 font-bold">{money(collectTarget.outstanding_amount ?? 0)}</strong></p>
            </div>

            <div className="space-y-3">
              <div>
                <label className="block text-xs mb-1 font-medium" style={{ color: C.ink }}>مبلغ الدفعة (د.ت) *</label>
                <input
                  type="number"
                  step="0.01"
                  min="0.01"
                  max={collectTarget.outstanding_amount ?? undefined}
                  value={collectAmount}
                  onChange={(e) => setCollectAmount(e.target.value)}
                  className={fieldCls}
                  style={{ ...fieldStyle, direction: 'ltr' }}
                  placeholder="0.00"
                  autoFocus
                />
              </div>

              <div>
                <label className="block text-xs mb-1 font-medium" style={{ color: C.ink }}>تاريخ القبض *</label>
                <input
                  type="date"
                  value={collectDate}
                  onChange={(e) => setCollectDate(e.target.value)}
                  className={fieldCls}
                  style={fieldStyle}
                />
              </div>

              <div>
                <label className="block text-xs mb-1 font-medium" style={{ color: C.ink }}>طريقة الدفع *</label>
                <select
                  value={collectMethod}
                  onChange={(e) => setCollectMethod(e.target.value)}
                  className={fieldCls}
                  style={fieldStyle}
                >
                  {METHOD_OPTIONS.map((opt) => (
                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs mb-1 font-medium" style={{ color: C.ink }}>ملاحظات الدفعة</label>
                <input
                  value={collectNotes}
                  onChange={(e) => setCollectNotes(e.target.value)}
                  className={fieldCls}
                  style={fieldStyle}
                  placeholder="مثال: وصل قبض رقم…"
                />
              </div>
            </div>

            <div className="flex gap-3 mt-6">
              <button
                type="button"
                onClick={() => void submitCollect()}
                disabled={collecting}
                className="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: C.forest }}
              >
                {collecting ? 'جارٍ التحصيل…' : 'تأكيد التحصيل'}
              </button>
              <button
                type="button"
                onClick={() => setCollectTarget(null)}
                className="px-5 py-2.5 rounded-xl text-sm"
                style={{ border: '1px solid ' + C.line, color: C.muted }}
              >
                إلغاء
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {/* ══════════ النافذة المنبثقة 2: تعديل دين إطار ══════════ */}
      {editTarget ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}>
          <div className="bg-white rounded-2xl w-full max-w-lg p-6 shadow-2xl" dir="rtl">
            <div className="flex items-center justify-between mb-4 border-b pb-3" style={{ borderColor: C.line }}>
              <div className="flex items-center gap-2">
                <Edit2 size={20} color={C.deep} />
                <h3 className="font-bold text-base" style={{ color: C.deep }}>تعديل دَين الإطار</h3>
              </div>
              <button onClick={() => setEditTarget(null)} type="button">
                <X size={18} color={C.muted} />
              </button>
            </div>

            {editError ? (
              <div className="mb-4 px-3 py-2 rounded-xl text-xs" style={{ backgroundColor: C.errorBg, color: C.error }}>
                {editError}
              </div>
            ) : null}

            {(editTarget.collected_amount ?? 0) > 0 ? (
              <div className="mb-4 px-3 py-2.5 rounded-xl text-xs bg-amber-50 text-amber-900 border border-amber-200">
                بعد التحصيل يمكن تعديل الملاحظات فقط.
              </div>
            ) : null}

            <div className="space-y-3">
              <div className="grid sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs mb-1 font-medium" style={{ color: C.muted }}>السنة الأصلية</label>
                  <input
                    value={editYearLabel}
                    disabled={(editTarget.collected_amount ?? 0) > 0}
                    onChange={(e) => setEditYearLabel(e.target.value)}
                    className={fieldCls}
                    style={{ ...fieldStyle, opacity: (editTarget.collected_amount ?? 0) > 0 ? 0.6 : 1 }}
                  />
                </div>
                <div>
                  <label className="block text-xs mb-1 font-medium" style={{ color: C.muted }}>نوع الدَّين</label>
                  <select
                    value={editDebtType}
                    disabled={(editTarget.collected_amount ?? 0) > 0}
                    onChange={(e) => setEditDebtType(e.target.value)}
                    className={fieldCls}
                    style={{ ...fieldStyle, opacity: (editTarget.collected_amount ?? 0) > 0 ? 0.6 : 1 }}
                  >
                    {Object.entries(EMPLOYEE_DEBT_TYPE_LABELS).map(([v, l]) => (
                      <option key={v} value={v}>{l}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs mb-1 font-medium" style={{ color: C.muted }}>الوصف</label>
                <input
                  value={editDescription}
                  disabled={(editTarget.collected_amount ?? 0) > 0}
                  onChange={(e) => setEditDescription(e.target.value)}
                  className={fieldCls}
                  style={{ ...fieldStyle, opacity: (editTarget.collected_amount ?? 0) > 0 ? 0.6 : 1 }}
                />
              </div>

              <div>
                <label className="block text-xs mb-1 font-medium" style={{ color: C.muted }}>المبلغ الأصلي (د.ت)</label>
                <input
                  type="number"
                  step="0.01"
                  min="0.01"
                  value={editAmount}
                  disabled={(editTarget.collected_amount ?? 0) > 0}
                  onChange={(e) => setEditAmount(e.target.value)}
                  className={fieldCls}
                  style={{ ...fieldStyle, direction: 'ltr', opacity: (editTarget.collected_amount ?? 0) > 0 ? 0.6 : 1 }}
                />
              </div>

              <div>
                <label className="block text-xs mb-1 font-medium" style={{ color: C.ink }}>الملاحظات</label>
                <textarea
                  rows={3}
                  value={editNotes}
                  onChange={(e) => setEditNotes(e.target.value)}
                  className={fieldCls}
                  style={fieldStyle}
                  placeholder="ملاحظات إضافية…"
                />
              </div>
            </div>

            <div className="flex gap-3 mt-6">
              <button
                type="button"
                onClick={() => void submitEdit()}
                disabled={savingEdit}
                className="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: C.forest }}
              >
                {savingEdit ? 'جارٍ الحفظ…' : 'حفظ التعديلات'}
              </button>
              <button
                type="button"
                onClick={() => setEditTarget(null)}
                className="px-5 py-2.5 rounded-xl text-sm"
                style={{ border: '1px solid ' + C.line, color: C.muted }}
              >
                إلغاء
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {/* ══════════ النافذة المنبثقة 3: إلغاء دين تلميذ ══════════ */}
      {cancelDebtTarget ? (
        <CancelReasonModal
          title="إلغاء دَين قديم لتلميذ"
          description={'سيُلغى الدَّين بمبلغ ' + money(cancelDebtTarget.original_amount) + ' الخاص بـ ' + personName(cancelDebtTarget.student) + '.'}
          busy={cancelBusy}
          onConfirm={(reason) => void confirmCancelDebt(reason)}
          onClose={() => setCancelDebtTarget(null)}
        />
      ) : null}

      {/* ══════════ النافذة المنبثقة 4: إلغاء دين إطار ══════════ */}
      {cancelEmpTarget ? (
        <CancelReasonModal
          title="إلغاء دَين إطار قديم"
          description={'سيُلغى الدَّين الافتتاحي بمبلغ ' + money(cancelEmpTarget.original_amount) + ' الخاص بالإطار ' + (cancelEmpTarget.employee ? `${cancelEmpTarget.employee.first_name} ${cancelEmpTarget.employee.last_name}` : '') + '.'}
          busy={cancelEmpBusy}
          onConfirm={(reason) => void confirmCancelEmpDebt(reason)}
          onClose={() => setCancelEmpTarget(null)}
        />
      ) : null}
    </div>
  );
}

export default OpeningBalancesPage;
