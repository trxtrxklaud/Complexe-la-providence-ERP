import { useEffect, useRef, useState } from 'react';
import { Ban, BookOpenCheck, Briefcase, Layers, Plus, Printer, Save, Wallet } from 'lucide-react';
import { PageShell } from '../../components/PageShell';
import { CancelReasonModal } from '../../components/CancelReasonModal';
import { fetchYears, type AcademicYear } from '../../api/roster';
import { getStudents, type Student } from '../../api/students';
import { getEmployees, type Employee } from '../../api/employees';
import {
  DEBT_STATUS_LABELS,
  DEBT_TYPE_LABELS,
  LIABILITY_TYPE_LABELS,
  bulkCreateDebts,
  bulkCreateLiabilities,
  cancelEmployeeLiability,
  cancelManualDebt,
  createEmployeeLiability,
  createManualDebt,
  fetchBulkOptions,
  fetchEmployeeLiabilities,
  fetchManualDebts,
  fetchSectionStudents,
  liabilityTypesForStaff,
  payEmployeeLiability,
  type BulkOptions,
  type EmployeeLiability,
  type ManualDebt,
  type SectionStudentRow,
} from '../../api/manualDebts';
import { errorMessage, money, personName, today } from '../../lib/format';
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

type Tab = 'debts' | 'liabilities' | 'bulk';

/** تسمية نوع الاستحقاق من الخريطة المشتركة المطابقة للخادم. */
function liabilityTypeLabel(type: string): string {
  return LIABILITY_TYPE_LABELS[type] ?? type;
}

function escapeHtml(value: unknown): string {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function personLabel(p: { first_name: string; last_name: string; job_title?: string | null } | null | undefined): string {
  if (!p) return '—';
  const name = [p.first_name, p.last_name].filter(Boolean).join(' ');
  return p.job_title ? name + ' (' + p.job_title + ')' : name;
}

export function OpeningBalancesPage() {
  const [tab, setTab] = useState<Tab>('debts');
  const [years, setYears] = useState<AcademicYear[]>([]);

  // ===== قائمة الديون =====
  const [debts, setDebts] = useState<ManualDebt[]>([]);
  const [debtStatusFilter, setDebtStatusFilter] = useState('');
  const [loadingDebts, setLoadingDebts] = useState(false);

  // ===== قائمة ديون الإطارات =====
  const [liabilities, setLiabilities] = useState<EmployeeLiability[]>([]);
  const [liabilityStatusFilter, setLiabilityStatusFilter] = useState('');
  const [loadingLiabilities, setLoadingLiabilities] = useState(false);

  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [cancelDebtTarget, setCancelDebtTarget] = useState<ManualDebt | null>(null);
  const [cancelLiabilityTarget, setCancelLiabilityTarget] = useState<EmployeeLiability | null>(null);
  const [payTarget, setPayTarget] = useState<EmployeeLiability | null>(null);
  const [cancelBusy, setCancelBusy] = useState(false);
  const [payBusy, setPayBusy] = useState(false);

  // ===== نموذج إدخال دَين =====
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

  // ===== نموذج إدخال دَين إطار =====
  const [liabilityYearId, setLiabilityYearId] = useState<number | ''>('');
  const [liabilityType, setLiabilityType] = useState('debt');
  const [liabilityYearLabel, setLiabilityYearLabel] = useState('');
  const [liabilityDescription, setLiabilityDescription] = useState('');
  const [liabilityAmount, setLiabilityAmount] = useState('');
  const [liabilityNotes, setLiabilityNotes] = useState('');
  const [employeeId, setEmployeeId] = useState<number | ''>('');
  const [employees, setEmployees] = useState<Employee[]>([]);

  const selectedEmployee = employees.find((employee) => employee.id === employeeId);
  // نوع الاستحقاق يتبع تصنيف الإطار المختار — نفس خريطة الخادم (manualDebts.ts).
  const availableLiabilityTypes = liabilityTypesForStaff(selectedEmployee?.staff_type);

  // ===== نموذج الخلاص =====
  const [payAmount, setPayAmount] = useState('');
  const [payDate, setPayDate] = useState(today());
  const [payMethod, setPayMethod] = useState('cash');
  const [payNotes, setPayNotes] = useState('');

  const [savingDebt, setSavingDebt] = useState(false);
  const [savingLiability, setSavingLiability] = useState(false);

  // ===== الإدخال الجماعي =====
  const [bulkYearLabel, setBulkYearLabel] = useState('');
  const [bulkLevelId, setBulkLevelId] = useState<number | ''>('');
  const [bulkSectionId, setBulkSectionId] = useState<number | ''>('');
  const [bulkOptions, setBulkOptions] = useState<BulkOptions | null>(null);
  const [bulkStudents, setBulkStudents] = useState<SectionStudentRow[]>([]);
  const [bulkStudentRows, setBulkStudentRows] = useState<Record<number, { checked: boolean; debtType: string; amount: string; notes: string }>>({});
  const [bulkEmployees, setBulkEmployees] = useState<BulkOptions['employees']>([]);
  const [bulkEmployeeRows, setBulkEmployeeRows] = useState<Record<number, { checked: boolean; liabilityType: string; amount: string; notes: string }>>({});
  const [bulkExistingLiabilities, setBulkExistingLiabilities] = useState<Map<number, BulkOptions['existing_liabilities'][number]>>(new Map());
  const [bulkLoading, setBulkLoading] = useState(false);
  const [bulkStudentsLoading, setBulkStudentsLoading] = useState(false);
  const [bulkSavingStudents, setBulkSavingStudents] = useState(false);
  const [bulkSavingEmployees, setBulkSavingEmployees] = useState(false);

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

  const reloadLiabilities = async () => {
    setLoadingLiabilities(true);
    try {
      const page = await fetchEmployeeLiabilities({
        status: liabilityStatusFilter || null,
        per_page: 100,
      });
      setLiabilities(page.data);
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setLoadingLiabilities(false);
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
          setLiabilityYearId(active.id);
        }
      } catch (err) {
        setError(errorMessage(err));
      }
      try {
        const list = await getEmployees();
        setEmployees(list);
      } catch {
        /* قائمة الإطارات تخصّ صلاحية HR — لا نُوقف الصفحة إن غابت */
      }
    })();
  }, []);

  useEffect(() => {
    void reloadDebts();
  }, [debtStatusFilter]);

  useEffect(() => {
    void reloadLiabilities();
  }, [liabilityStatusFilter]);

  useEffect(() => {
    if (!availableLiabilityTypes.includes(liabilityType)) {
      setLiabilityType(availableLiabilityTypes[0] ?? '');
    }
  }, [employeeId, employees, liabilityType]);

  // تحميل خيارات الإدخال الجماعي عند فتح التبويب
  useEffect(() => {
    if (tab !== 'bulk' || bulkOptions) return;
    (async () => {
      setBulkLoading(true);
      try {
        const opts = await fetchBulkOptions();
        setBulkOptions(opts);
        setBulkEmployees(opts.employees);
        const map = new Map<number, BulkOptions['existing_liabilities'][number]>();
        opts.existing_liabilities.forEach((l) => map.set(l.employee_id, l));
        setBulkExistingLiabilities(map);
        // تهيئة صفوف الإطارات
        const rows: Record<number, { checked: boolean; liabilityType: string; amount: string; notes: string }> = {};
        opts.employees.forEach((emp) => {
          const existing = map.get(emp.id);
          const allowed = liabilityTypesForStaff(emp.staff_type);
          rows[emp.id] = {
            checked: false,
            liabilityType: existing ? existing.liability_type : allowed[0] ?? 'debt',
            amount: existing ? String(existing.original_amount) : '',
            notes: existing ? existing.notes ?? '' : '',
          };
        });
        setBulkEmployeeRows(rows);
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

  const submitLiability = async () => {
    if (employeeId === '') {
      setError('اختر الإطار');
      return;
    }
    if (!availableLiabilityTypes.includes(liabilityType)) {
      setError('نوع الالتزام غير متاح لهذا الإطار');
      return;
    }
    const value = Number(liabilityAmount);
    if (!Number.isFinite(value) || value <= 0) {
      setError('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }
    if (liabilityDescription.trim().length === 0) {
      setError('الوصف مطلوب');
      return;
    }
    if (liabilityYearLabel.trim().length === 0) {
      setError('تسمية السنة الأصلية مطلوبة — مثال: 2025/2026');
      return;
    }

    setSavingLiability(true);
    setError('');
    try {
      await createEmployeeLiability({
        employee_id: employeeId as number,
        academic_year_id: liabilityYearId === '' ? null : liabilityYearId,
        original_year_label: liabilityYearLabel.trim(),
        liability_type: liabilityType,
        description: liabilityDescription.trim(),
        original_amount: value,
        notes: liabilityNotes.trim() || null,
      });
      setEmployeeId('');
      setLiabilityDescription('');
      setLiabilityAmount('');
      setLiabilityNotes('');
      flash('تمّ إدخال دَين الإطار — يُخلَّص من زرّ «خلاص» ليمرّ بالخزينة');
      await reloadLiabilities();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSavingLiability(false);
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
        if ((s.existing?.collected_amount ?? 0) > 0) return false;
        const v = Number(row.amount);
        return Number.isFinite(v) && v > 0;
      })
      .map((s) => ({
        student_id: s.id,
        debt_type: bulkStudentRows[s.id].debtType,
        amount: Number(bulkStudentRows[s.id].amount),
        notes: bulkStudentRows[s.id].notes.trim() || null,
      }));
    if (items.length === 0) {
      setError('حدد تلميذاً واحداً على الأقل بمبلغ موجب');
      return;
    }
    setBulkSavingStudents(true);
    setError('');
    try {
      const res = await bulkCreateDebts({ original_year_label: bulkYearLabel.trim(), items });
      flash(res.message);
      // إعادة تحميل تلاميذ القسم + قائمة الديون
      if (bulkSectionId) {
        const refreshed = await fetchSectionStudents(bulkSectionId as number);
        setBulkStudents(refreshed.students);
        const rows: Record<number, { checked: boolean; debtType: string; amount: string; notes: string }> = {};
        refreshed.students.forEach((s) => {
          const ex = s.existing;
          const prev = bulkStudentRows[s.id];
          rows[s.id] = ex
            ? { checked: false, debtType: ex.debt_type, amount: String(ex.original_amount), notes: ex.notes ?? '' }
            : { checked: false, debtType: prev?.debtType ?? 'tuition', amount: '', notes: prev?.notes ?? '' };
        });
        setBulkStudentRows(rows);
      }
      await reloadDebts();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBulkSavingStudents(false);
    }
  };

  const submitBulkEmployees = async () => {
    if (!bulkYearLabel.trim()) {
      setError('السنة الأصلية مطلوبة');
      return;
    }
    if (bulkOptions?.active_year && bulkYearLabel.trim() === bulkOptions.active_year.name) {
      setError('سنة المنشأ لا يمكن أن تساوي السنة الحالية');
      return;
    }
    const items = bulkEmployees
      .filter((emp) => {
        const row = bulkEmployeeRows[emp.id];
        if (!row?.checked) return false;
        const existing = bulkExistingLiabilities.get(emp.id);
        if ((existing as unknown as { paid_amount?: number }) || 0) {
          // paid check عبر الخريطة لا يكفي؛ نعتمد paid_amount إن وجد
        }
        // تعطيل الصفوف المحصلة: نتحقق من الخريطة الأصلية إن كانت paid>0 عبر reload لاحق، حالياً نعتمد تعطيل الواجهة فقط
        const v = Number(row.amount);
        return Number.isFinite(v) && v > 0;
      })
      .map((emp) => ({
        employee_id: emp.id,
        liability_type: bulkEmployeeRows[emp.id].liabilityType,
        amount: Number(bulkEmployeeRows[emp.id].amount),
        notes: bulkEmployeeRows[emp.id].notes.trim() || null,
      }))
      .filter((it) => {
        const existing = bulkExistingLiabilities.get(it.employee_id);
        // استبعاد الصفوف المحصلة جزئياً — لا تُرسل
        if (existing && Number(existing.paid_amount ?? 0) > 0) return false;
        return true;
      });
    if (items.length === 0) {
      setError('حدد إطاراً واحداً على الأقل بمبلغ موجب');
      return;
    }
    setBulkSavingEmployees(true);
    setError('');
    try {
      const res = await bulkCreateLiabilities({ original_year_label: bulkYearLabel.trim(), items });
      flash(res.message);
      const opts = await fetchBulkOptions();
      setBulkOptions(opts);
      const map = new Map<number, BulkOptions['existing_liabilities'][number]>();
      opts.existing_liabilities.forEach((l) => map.set(l.employee_id, l));
      setBulkExistingLiabilities(map);
      const rows: Record<number, { checked: boolean; liabilityType: string; amount: string; notes: string }> = {};
      opts.employees.forEach((emp) => {
        const ex = map.get(emp.id);
        const allowed = liabilityTypesForStaff(emp.staff_type);
        rows[emp.id] = {
          checked: false,
          liabilityType: ex ? ex.liability_type : allowed[0] ?? 'debt',
          amount: ex ? String(ex.original_amount) : '',
          notes: ex ? ex.notes ?? '' : bulkEmployeeRows[emp.id]?.notes ?? '',
        };
      });
      setBulkEmployees(opts.employees);
      setBulkEmployeeRows(rows);
      await reloadLiabilities();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBulkSavingEmployees(false);
    }
  };

  const printEmployeeLiabilities = async () => {
    const printWindow = window.open('', '_blank', 'width=1000,height=800');
    if (!printWindow) {
      setError('تعذّر فتح نافذة الطباعة. تحقّق من السماح بالنوافذ المنبثقة.');
      return;
    }

    printWindow.document.write('<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة تقرير الإطارات</title></head><body><p>جارٍ إعداد التقرير…</p></body></html>');
    printWindow.document.close();

    try {
      const allLiabilities: EmployeeLiability[] = [];
      let pageNumber = 1;
      let lastPage = 1;

      do {
        const page = await fetchEmployeeLiabilities({ per_page: 100, page: pageNumber });
        allLiabilities.push(...page.data);
        lastPage = page.last_page;
        pageNumber += 1;
      } while (pageNumber <= lastPage);

      const grouped = new Map<number, { name: string; rows: EmployeeLiability[] }>();
      allLiabilities.forEach((liability) => {
        const current = grouped.get(liability.employee_id);
        if (current) {
          current.rows.push(liability);
          return;
        }
        grouped.set(liability.employee_id, {
          name: liability.employee ? personLabel(liability.employee) : `إطار رقم ${liability.employee_id}`,
          rows: [liability],
        });
      });

      const sections = [...grouped.values()]
        .sort((a, b) => a.name.localeCompare(b.name, 'ar'))
        .map((group) => `
          <section>
            <h2>${escapeHtml(group.name)}</h2>
            <table>
              <thead><tr><th>النوع</th><th>المبلغ</th><th>السنة الأصلية</th><th>الوصف</th></tr></thead>
              <tbody>
                ${group.rows.map((liability) => `
                  <tr>
                    <td>${escapeHtml(liabilityTypeLabel(liability.liability_type))}</td>
                    <td class="amount">${escapeHtml(money(liability.original_amount))} د.ت</td>
                    <td>${escapeHtml(liability.original_year_label)}</td>
                    <td>${escapeHtml(liability.description)}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </section>
        `).join('');

      const reportBody = sections || '<p class="empty">لا توجد ديون إطارات مدخلة.</p>';
      printWindow.document.open();
      printWindow.document.write(`<!doctype html>
        <html lang="ar" dir="rtl">
          <head>
            <meta charset="utf-8">
            <title>تقرير ديون الإطارات</title>
            <style>
              @page { size: A4; margin: 14mm; }
              * { box-sizing: border-box; }
              body { margin: 0; color: #1F261C; font-family: Tahoma, Arial, sans-serif; font-size: 13px; }
              h1 { margin: 0 0 6px; color: #2E3B2A; font-size: 22px; }
              .meta { margin: 0 0 22px; color: #7C8677; }
              section { margin-bottom: 22px; break-inside: avoid; }
              h2 { margin: 0; padding: 9px 12px; background: #E3EBDB; color: #2E3B2A; font-size: 15px; }
              table { width: 100%; border-collapse: collapse; }
              th, td { border: 1px solid #DDE4D8; padding: 8px 10px; text-align: right; vertical-align: top; }
              th { background: #F6F8F4; color: #3B4A36; font-weight: 700; }
              .amount { direction: ltr; text-align: right; white-space: nowrap; }
              .empty { padding: 30px; text-align: center; color: #7C8677; }
            </style>
          </head>
          <body>
            <h1>تقرير ديون الإطارات</h1>
            <p class="meta">تاريخ الطباعة: ${escapeHtml(new Date().toLocaleDateString('ar-TN'))}</p>
            ${reportBody}
          </body>
        </html>`);
      printWindow.document.close();
      printWindow.focus();
      window.setTimeout(() => printWindow.print(), 150);
    } catch (err) {
      printWindow.close();
      setError(errorMessage(err));
    }
  };

  const confirmCancelDebt = async (reason: string) => {
    if (!cancelDebtTarget) return;
    setCancelBusy(true);
    setError('');
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

  const confirmCancelLiability = async (reason: string) => {
    if (!cancelLiabilityTarget) return;
    setCancelBusy(true);
    setError('');
    try {
      await cancelEmployeeLiability(cancelLiabilityTarget.id, reason);
      setCancelLiabilityTarget(null);
      flash('تمّ إلغاء دَين الإطار');
      await reloadLiabilities();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setCancelBusy(false);
    }
  };

  const openPay = (liability: EmployeeLiability) => {
    setPayTarget(liability);
    setPayAmount(String(liability.outstanding_amount ?? 0));
    setPayDate(today());
    setPayMethod('cash');
    setPayNotes('');
  };

  const submitPay = async () => {
    if (!payTarget) return;
    const value = Number(payAmount);
    if (!Number.isFinite(value) || value <= 0) {
      setError('المبلغ يجب أن يكون أكبر من صفر');
      return;
    }
    setPayBusy(true);
    setError('');
    try {
      await payEmployeeLiability(payTarget.id, {
        amount: value,
        paid_at: payDate,
        method: payMethod,
        notes: payNotes.trim() || null,
      });
      setPayTarget(null);
      flash('تمّ خلاص دَين الإطار — خرج من الخزينة في بند «خلاص ديون إطارات قديمة»');
      await reloadLiabilities();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setPayBusy(false);
    }
  };

  const fieldStyle = { border: '1px solid ' + C.line, backgroundColor: '#fff', color: C.ink };
  const fieldCls = 'w-full px-3 py-2.5 rounded-xl text-sm';
  const btnCls = 'flex items-center gap-2 px-5 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50';
  const statusColor: Record<string, string> = {
    pending: '#B45309',
    partial: '#2563EB',
    paid: '#15803D',
    cancelled: '#9CA3AF',
  };

  return (
    <div className="px-6 pb-10 max-w-6xl mx-auto" dir="rtl">
      <PageShell
        title="الأرصدة الافتتاحية"
        subtitle="الديون القديمة للتلاميذ وديون الإطارات — بيانات خارجية تُدخل يدوياً بلا أثر في الخزينة"
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
          <div className="no-print flex gap-2 mb-6">
            {([
              { key: 'debts', label: 'ديون التلاميذ', icon: BookOpenCheck },
              { key: 'liabilities', label: 'ديون الإطارات', icon: Briefcase },
              { key: 'bulk', label: 'إدخال جماعي', icon: Layers },
            ] as const).map((item) => (
              <button
                key={item.key}
                type="button"
                onClick={() => setTab(item.key)}
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

          {tab === 'debts' ? (
            <>
              {/* نموذج إدخال دَين */}
              <div className="no-print bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
                <div className="flex items-center gap-2 mb-4">
                  <Plus size={18} color={C.deep} />
                  <h3 className="font-bold" style={{ color: C.deep }}>إدخال دَين قديم</h3>
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

              {/* جدول الديون */}
              <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
                <div className="px-5 py-4 flex items-center justify-between" style={{ backgroundColor: C.sage }}>
                  <h3 className="font-bold" style={{ color: C.deep }}>الديون المدخلة</h3>
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
                          <th className="text-right px-3 py-3 font-medium">الأصلي</th>
                          <th className="text-right px-3 py-3 font-medium">المحصّل</th>
                          <th className="text-right px-3 py-3 font-medium">المتبقّي</th>
                          <th className="text-right px-3 py-3 font-medium">الحالة</th>
                          <th className="no-print px-3 py-3" style={{ width: '6rem' }} />
                        </tr>
                      </thead>
                      <tbody>
                        {debts.map((debt) => {
                          const cancelled = Boolean(debt.cancelled_at);
                          return (
                            <tr key={debt.id} style={{ borderBottom: '1px solid ' + C.line, opacity: cancelled ? 0.55 : 1 }}>
                              <td className="px-3 py-2.5 font-medium" style={{ color: C.ink }}>{personName(debt.student)}</td>
                              <td className="px-3 py-2.5" style={{ color: C.muted }}>{DEBT_TYPE_LABELS[debt.debt_type] ?? debt.debt_type}</td>
                              <td className="px-3 py-2.5" style={{ color: C.ink }}>
                                {debt.description}
                                {cancelled && debt.cancellation_reason ? (
                                  <span className="block text-xs mt-0.5" style={{ color: C.error }}>ملغى: {debt.cancellation_reason}</span>
                                ) : null}
                              </td>
                              <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{debt.original_year_label}</td>
                              <td className="px-3 py-2.5" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{money(debt.original_amount)}</td>
                              <td className="px-3 py-2.5" style={{ color: C.muted, direction: 'ltr', textAlign: 'right' }}>{money(debt.collected_amount)}</td>
                              <td className="px-3 py-2.5 font-medium" style={{ color: C.forest, direction: 'ltr', textAlign: 'right' }}>{money(debt.outstanding_amount)}</td>
                              <td className="px-3 py-2.5">
                                <span className="text-xs font-medium px-2.5 py-1 rounded-full" style={{ color: statusColor[debt.status] ?? C.muted, backgroundColor: '#F3F4F6' }}>
                                  {DEBT_STATUS_LABELS[debt.status] ?? debt.status}
                                </span>
                              </td>
                              <td className="no-print px-3 py-2.5">
                                {!cancelled && Number(debt.collected_amount ?? 0) === 0 ? (
                                  <button type="button" onClick={() => setCancelDebtTarget(debt)} title="إلغاء الدَّين" className="p-1.5 rounded-lg bg-gray-50">
                                    <Ban size={14} color={C.error} />
                                  </button>
                                ) : null}
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
          ) : tab === 'liabilities' ? (
            <>
              {/* نموذج إدخال دَين إطار */}
              <div className="no-print bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
                <div className="flex items-center gap-2 mb-4">
                  <Plus size={18} color={C.deep} />
                  <h3 className="font-bold" style={{ color: C.deep }}>إدخال دَين إطار قديم</h3>
                </div>
                <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>الإطار *</label>
                    <select
                      value={employeeId}
                      onChange={(e) => {
                        const id = e.target.value ? Number(e.target.value) : '';
                        setEmployeeId(id);
                        // نوع الاستحقاق يتبع تصنيف الإطار المختار: عاملة دَيناً
                        // فقط، ومعلم/منشط دَيناً أو سلفة غير مسددة.
                        const emp = employees.find((x) => x.id === id) ?? null;
                        const allowed = liabilityTypesForStaff(emp?.staff_type);
                        if (!allowed.includes(liabilityType)) {
                          setLiabilityType(allowed[0] ?? '');
                        }
                      }}
                      className={fieldCls}
                      style={fieldStyle}
                    >
                      <option value="">اختر الإطار…</option>
                      {employees.map((emp) => (
                        <option key={emp.id} value={emp.id}>{emp.first_name} {emp.last_name}{emp.job_title ? ' — ' + emp.job_title : ''}</option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الدراسية (النقل إليها)</label>
                    <select value={liabilityYearId} onChange={(e) => setLiabilityYearId(e.target.value ? Number(e.target.value) : '')} className={fieldCls} style={fieldStyle}>
                      {years.map((y) => <option key={y.id} value={y.id}>{y.name}{y.is_active ? ' — حالية' : ''}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>نوع الالتزام *</label>
                    <select value={liabilityType} onChange={(e) => setLiabilityType(e.target.value)} disabled={!selectedEmployee} className={fieldCls + ' disabled:opacity-60'} style={fieldStyle}>
                      {!selectedEmployee ? <option value="">اختر الإطار أولاً</option> : null}
                      {selectedEmployee
                        ? availableLiabilityTypes.map((value) => (
                            <option key={value} value={value}>{liabilityTypeLabel(value)}</option>
                          ))
                        : null}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الأصلية *</label>
                    <select value={liabilityYearLabel} onChange={(e) => setLiabilityYearLabel(e.target.value)} className={fieldCls} style={fieldStyle}>
                      <option value="">اختر السنة الأصلية…</option>
                      {years.map((year) => <option key={year.id} value={year.name}>{year.name}</option>)}
                    </select>
                  </div>
                  <div className="lg:col-span-2">
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>الوصف *</label>
                    <input value={liabilityDescription} onChange={(e) => setLiabilityDescription(e.target.value)} className={fieldCls} style={fieldStyle} placeholder="مثال: أجور شهر جوان غير مدفوعة" />
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المبلغ (د.ت) *</label>
                    <input value={liabilityAmount} onChange={(e) => setLiabilityAmount(e.target.value)} type="number" step="0.01" min="0" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} placeholder="0.00" />
                  </div>
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: C.muted }}>ملاحظات</label>
                    <input value={liabilityNotes} onChange={(e) => setLiabilityNotes(e.target.value)} className={fieldCls} style={fieldStyle} />
                  </div>
                </div>
                <button type="button" onClick={() => void submitLiability()} disabled={savingLiability} className={btnCls + ' mt-5'} style={{ backgroundColor: C.forest }}>
                  <Save size={18} />
                  <span>{savingLiability ? 'جارٍ الإدخال…' : 'إدخال دَين الإطار'}</span>
                </button>
              </div>

              {/* جدول ديون الإطارات */}
              <div className="bg-white rounded-2xl overflow-hidden" style={{ border: '1px solid ' + C.line }}>
                <div className="px-5 py-4 flex flex-wrap items-center justify-between gap-3" style={{ backgroundColor: C.sage }}>
                  <h3 className="font-bold" style={{ color: C.deep }}>ديون الإطارات المدخلة</h3>
                  <div className="no-print flex flex-wrap items-center gap-2">
                    <button type="button" onClick={() => void printEmployeeLiabilities()} className="flex items-center gap-2 rounded-xl px-3 py-1.5 text-xs font-medium text-white" style={{ backgroundColor: C.deep }}>
                      <Printer size={15} />
                      <span>طباعة تقرير الإطارات</span>
                    </button>
                    <select value={liabilityStatusFilter} onChange={(e) => setLiabilityStatusFilter(e.target.value)} className="text-xs rounded-xl px-3 py-1.5" style={fieldStyle}>
                      <option value="">كل الحالات</option>
                      {Object.entries(DEBT_STATUS_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                  </div>
                </div>
                {loadingLiabilities ? (
                  <ListSkeleton rows={4} />
                ) : liabilities.length === 0 ? (
                  <p className="px-5 py-8 text-sm text-center" style={{ color: C.muted }}>لا ديون إطارات مدخلة بعد.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr style={{ borderBottom: '1px solid ' + C.line, color: C.muted }}>
                          <th className="text-right px-3 py-3 font-medium">الإطار</th>
                          <th className="text-right px-3 py-3 font-medium">النوع</th>
                          <th className="text-right px-3 py-3 font-medium">الوصف</th>
                          <th className="text-right px-3 py-3 font-medium">السنة الأصلية</th>
                          <th className="text-right px-3 py-3 font-medium">الأصلي</th>
                          <th className="text-right px-3 py-3 font-medium">المدفوع</th>
                          <th className="text-right px-3 py-3 font-medium">المتبقّي</th>
                          <th className="text-right px-3 py-3 font-medium">الحالة</th>
                          <th className="no-print px-3 py-3" style={{ width: '9rem' }} />
                        </tr>
                      </thead>
                      <tbody>
                        {liabilities.map((liability) => {
                          const cancelled = Boolean(liability.cancelled_at);
                          const outstanding = Number(liability.outstanding_amount ?? 0);
                          return (
                            <tr key={liability.id} style={{ borderBottom: '1px solid ' + C.line, opacity: cancelled ? 0.55 : 1 }}>
                              <td className="px-3 py-2.5 font-medium" style={{ color: C.ink }}>{personLabel(liability.employee)}</td>
                              <td className="px-3 py-2.5" style={{ color: C.muted }}>{liabilityTypeLabel(liability.liability_type)}</td>
                              <td className="px-3 py-2.5" style={{ color: C.ink }}>
                                {liability.description}
                                {cancelled && liability.cancellation_reason ? (
                                  <span className="block text-xs mt-0.5" style={{ color: C.error }}>ملغى: {liability.cancellation_reason}</span>
                                ) : null}
                              </td>
                              <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{liability.original_year_label}</td>
                              <td className="px-3 py-2.5" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{money(liability.original_amount)}</td>
                              <td className="px-3 py-2.5" style={{ color: C.muted, direction: 'ltr', textAlign: 'right' }}>{money(liability.paid_amount)}</td>
                              <td className="px-3 py-2.5 font-medium" style={{ color: C.forest, direction: 'ltr', textAlign: 'right' }}>{money(outstanding)}</td>
                              <td className="px-3 py-2.5">
                                <span className="text-xs font-medium px-2.5 py-1 rounded-full" style={{ color: statusColor[liability.status] ?? C.muted, backgroundColor: '#F3F4F6' }}>
                                  {DEBT_STATUS_LABELS[liability.status] ?? liability.status}
                                </span>
                              </td>
                              <td className="no-print px-3 py-2.5">
                                <div className="flex items-center gap-1.5">
                                  {!cancelled && outstanding > 0 ? (
                                    <button type="button" onClick={() => openPay(liability)} title="خلاص دَين الإطار" className="px-2.5 py-1.5 rounded-lg text-xs font-medium text-white" style={{ backgroundColor: C.forest }}>
                                      خلاص
                                    </button>
                                  ) : null}
                                  {!cancelled && Number(liability.paid_amount ?? 0) === 0 ? (
                                    <button type="button" onClick={() => setCancelLiabilityTarget(liability)} title="إلغاء دَين الإطار" className="p-1.5 rounded-lg bg-gray-50">
                                      <Ban size={14} color={C.error} />
                                    </button>
                                  ) : null}
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
          ) : (
            <>
              {/* ===== الإدخال الجماعي ===== */}
              <div className="bg-white rounded-2xl p-5 mb-6" style={{ border: '1px solid ' + C.line }}>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>السنة الأصلية (مشتركة) *</label>
                <select value={bulkYearLabel} onChange={(e) => setBulkYearLabel(e.target.value)} className={fieldCls} style={fieldStyle}>
                  <option value="">اختر السنة الأصلية…</option>
                  {years.filter((y) => y.name !== bulkOptions?.active_year?.name).map((y) => <option key={y.id} value={y.name}>{y.name}</option>)}
                  {years.filter((y) => y.name === bulkOptions?.active_year?.name).length === 0 && bulkOptions?.active_year ? null : null}
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

              {/* قسم الإطارات */}
              <div className="bg-white rounded-2xl p-5" style={{ border: '1px solid ' + C.line }}>
                <h3 className="font-bold mb-4" style={{ color: C.deep }}>ديون الإطارات والعاملين — إدخال جماعي</h3>
                {bulkLoading ? <ListSkeleton rows={4} /> : bulkEmployees.length === 0 ? <p className="text-sm text-center py-6" style={{ color: C.muted }}>لا إطارات نشطة</p> : (
                  <>
                    <div className="overflow-x-auto rounded-xl" style={{ border: '1px solid ' + C.line }}>
                      <table className="w-full text-sm">
                        <thead>
                          <tr style={{ backgroundColor: C.sage, color: C.deep }}>
                            <th className="px-3 py-2.5">☐</th>
                            <th className="text-right px-3 py-2.5 font-medium">الاسم الكامل</th>
                            <th className="text-right px-3 py-2.5 font-medium">الوظيفة</th>
                            <th className="text-right px-3 py-2.5 font-medium">نوع الالتزام</th>
                            <th className="text-right px-3 py-2.5 font-medium">المبلغ</th>
                            <th className="text-right px-3 py-2.5 font-medium">الملاحظات</th>
                            <th className="text-right px-3 py-2.5 font-medium">الأصلي</th>
                            <th className="text-right px-3 py-2.5 font-medium">المحصل</th>
                            <th className="text-right px-3 py-2.5 font-medium">المتبقي</th>
                            <th className="text-right px-3 py-2.5 font-medium">الحالة</th>
                          </tr>
                        </thead>
                        <tbody>
                          {bulkEmployees.map((emp) => {
                            const existing = bulkExistingLiabilities.get(emp.id) ?? null;
                            const row = bulkEmployeeRows[emp.id] ?? { checked: false, liabilityType: liabilityTypesForStaff(emp.staff_type)[0] ?? 'debt', amount: '', notes: '' };
                            const paidVal = Number(existing?.paid_amount ?? 0);
                            const disabled = paidVal > 0;
                            const outstanding = existing ? Math.max(0, Number(existing.original_amount) - paidVal) : 0;
                            const allowed = liabilityTypesForStaff(emp.staff_type);
                            return (
                              <tr key={emp.id} style={{ borderTop: '1px solid ' + C.line, opacity: disabled ? 0.55 : 1 }}>
                                <td className="px-3 py-2.5"><input type="checkbox" checked={row.checked} disabled={disabled} onChange={(e) => setBulkEmployeeRows((prev) => ({ ...prev, [emp.id]: { ...row, checked: e.target.checked } }))} /></td>
                                <td className="px-3 py-2.5" style={{ color: C.ink }}>{emp.first_name} {emp.last_name}</td>
                                <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{emp.job_title ?? '—'}</td>
                                <td className="px-3 py-2.5">
                                  <select value={row.liabilityType} disabled={disabled} onChange={(e) => setBulkEmployeeRows((prev) => ({ ...prev, [emp.id]: { ...row, liabilityType: e.target.value } }))} className="text-xs rounded-lg px-2 py-1.5" style={{ ...fieldStyle, opacity: disabled ? 0.6 : 1 }}>
                                    {allowed.map((v) => <option key={v} value={v}>{LIABILITY_TYPE_LABELS[v] ?? v}</option>)}
                                  </select>
                                </td>
                                <td className="px-3 py-2.5"><input type="number" min="0" step="0.01" value={row.amount} disabled={disabled || !row.checked} onChange={(e) => setBulkEmployeeRows((prev) => ({ ...prev, [emp.id]: { ...row, amount: e.target.value } }))} className="w-24 px-2 py-1.5 rounded-lg text-sm" style={{ ...fieldStyle, direction: 'ltr', opacity: disabled || !row.checked ? 0.6 : 1 }} placeholder="0.00" /></td>
                                <td className="px-3 py-2.5"><input value={row.notes} disabled={disabled} onChange={(e) => setBulkEmployeeRows((prev) => ({ ...prev, [emp.id]: { ...row, notes: e.target.value } }))} className="w-28 px-2 py-1.5 rounded-lg text-xs" style={{ ...fieldStyle, opacity: disabled ? 0.6 : 1 }} /></td>
                                <td className="px-3 py-2.5" style={{ color: C.ink, direction: 'ltr', textAlign: 'right' }}>{existing ? money(existing.original_amount) : '—'}</td>
                                <td className="px-3 py-2.5" style={{ color: C.muted, direction: 'ltr', textAlign: 'right' }}>{existing ? money(paidVal) : '—'}</td>
                                <td className="px-3 py-2.5" style={{ color: C.forest, direction: 'ltr', textAlign: 'right' }}>{existing ? money(outstanding) : '—'}</td>
                                <td className="px-3 py-2.5 text-xs" style={{ color: C.muted }}>{existing ? (paidVal > 0 ? (outstanding === 0 ? 'مدفوع' : 'جزئي') : 'قائم') : '—'}</td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                    <button type="button" onClick={() => void submitBulkEmployees()} disabled={bulkSavingEmployees} className="mt-4 px-5 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50" style={{ backgroundColor: C.forest }}>
                      {bulkSavingEmployees ? 'جارٍ الحفظ…' : 'حفظ جماعي (إطارات)'}
                    </button>
                  </>
                )}
              </div>
            </>
          )}
        </div>
      </PageShell>

      {cancelDebtTarget ? (
        <CancelReasonModal
          title="إلغاء دَين قديم"
          description={'سيُلغى الدَّين بمبلغ ' + money(cancelDebtTarget.original_amount) + ' الخاص بـ ' + personName(cancelDebtTarget.student) + '.'}
          busy={cancelBusy}
          onConfirm={(reason) => void confirmCancelDebt(reason)}
          onClose={() => setCancelDebtTarget(null)}
        />
      ) : null}

      {cancelLiabilityTarget ? (
        <CancelReasonModal
          title="إلغاء دَين إطار قديم"
          description={'سيُلغى دَين الإطار بمبلغ ' + money(cancelLiabilityTarget.original_amount) + ' الخاص بـ ' + personLabel(cancelLiabilityTarget.employee) + '.'}
          busy={cancelBusy}
          onConfirm={(reason) => void confirmCancelLiability(reason)}
          onClose={() => setCancelLiabilityTarget(null)}
        />
      ) : null}

      {payTarget ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}>
          <div className="bg-white rounded-2xl w-full max-w-md p-6" dir="rtl">
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                <Wallet size={18} color={C.forest} />
                <h3 className="font-bold" style={{ color: C.ink }}>خلاص دَين إطار قديم</h3>
              </div>
              <button onClick={() => setPayTarget(null)} type="button" className="text-sm" style={{ color: C.muted }}>إغلاق</button>
            </div>
            <p className="text-sm mb-4" style={{ color: C.muted }}>
              {personLabel(payTarget.employee)} — {payTarget.description}
              <span className="block mt-1 font-medium" style={{ color: C.ink }}>المتبقّي: {money(payTarget.outstanding_amount)} د.ت</span>
            </p>
            <div className="space-y-3">
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>المبلغ (د.ت) *</label>
                <input value={payAmount} onChange={(e) => setPayAmount(e.target.value)} type="number" step="0.01" min="0" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>تاريخ الدفع</label>
                <input value={payDate} onChange={(e) => setPayDate(e.target.value)} type="date" className={fieldCls} style={{ ...fieldStyle, direction: 'ltr' }} />
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>طريقة الدفع</label>
                <select value={payMethod} onChange={(e) => setPayMethod(e.target.value)} className={fieldCls} style={fieldStyle}>
                  <option value="cash">نقداً</option>
                  <option value="bank">بنكياً</option>
                  <option value="check">صكّ</option>
                  <option value="other">أخرى</option>
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1.5" style={{ color: C.muted }}>ملاحظات</label>
                <input value={payNotes} onChange={(e) => setPayNotes(e.target.value)} className={fieldCls} style={fieldStyle} />
              </div>
            </div>
            <p className="text-xs mt-3" style={{ color: C.muted }}>يخرج المبلغ من الخزينة في بند «خلاص ديون إطارات قديمة» — لا يُحتسب أجراً للسنة الحالية.</p>
            <div className="flex gap-3 mt-4">
              <button
                type="button"
                onClick={() => void submitPay()}
                disabled={payBusy}
                className="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: C.forest }}
              >
                {payBusy ? 'جارٍ الخلاص…' : 'تأكيد الخلاص'}
              </button>
              <button type="button" onClick={() => setPayTarget(null)} className="px-5 py-2.5 rounded-xl text-sm" style={{ border: '1px solid ' + C.line, color: C.muted }}>
                رجوع
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}

export default OpeningBalancesPage;
