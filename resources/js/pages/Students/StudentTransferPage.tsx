import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { ArrowRightLeft, CheckCircle2, Users } from 'lucide-react';
import {
  getStudentSearchOptions,
  getTransferStudents,
  transferStudents,
  type StudentSearchOptions,
  type TransferStudent,
} from '../../api/students';
import { TableRowsSkeleton } from '../../components/DataSkeleton';
import { errorMessage } from '../../lib/format';

const emptyOptions: StudentSearchOptions = { levels: [], years: [] };
const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition focus:border-[#3B4A36] focus:ring-2 focus:ring-[#3B4A36]/15';

export function StudentTransferPage() {
  const [options, setOptions] = useState<StudentSearchOptions>(emptyOptions);
  const [yearId, setYearId] = useState('');
  const [sourceSectionId, setSourceSectionId] = useState('');
  const [destinationSectionId, setDestinationSectionId] = useState('');
  const [students, setStudents] = useState<TransferStudent[]>([]);
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [loadingOptions, setLoadingOptions] = useState(true);
  const [loadingStudents, setLoadingStudents] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [warning, setWarning] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    const controller = new AbortController();

    getStudentSearchOptions(controller.signal)
      .then(setOptions)
      .catch((requestError) => {
        if (!controller.signal.aborted) setError(errorMessage(requestError));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoadingOptions(false);
      });

    return () => controller.abort();
  }, []);

  useEffect(() => {
    setSelectedIds(new Set());
    setWarning('');

    if (!yearId || !sourceSectionId) {
      setStudents([]);
      return;
    }

    const controller = new AbortController();
    setLoadingStudents(true);
    setError('');

    getTransferStudents(Number(yearId), Number(sourceSectionId), controller.signal)
      .then((response) => setStudents(response.students))
      .catch((requestError) => {
        if (!controller.signal.aborted) {
          setStudents([]);
          setError(errorMessage(requestError));
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoadingStudents(false);
      });

    return () => controller.abort();
  }, [yearId, sourceSectionId, reloadKey]);

  const destinationSections = useMemo(
    () => options.levels.filter((section) => String(section.id) !== sourceSectionId),
    [options.levels, sourceSectionId],
  );

  const allSelected = students.length > 0 && students.every((student) => selectedIds.has(student.id));

  function toggleStudent(studentId: number) {
    setSelectedIds((current) => {
      const next = new Set(current);
      if (next.has(studentId)) next.delete(studentId);
      else next.add(studentId);
      return next;
    });
    setWarning('');
    setSuccess('');
  }

  function toggleAll() {
    setSelectedIds(allSelected ? new Set() : new Set(students.map((student) => student.id)));
    setWarning('');
    setSuccess('');
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    setSuccess('');

    if (!yearId || !sourceSectionId || !destinationSectionId) {
      setWarning('حدّد السنة الدراسية والقسم المصدر والقسم الوجهة.');
      return;
    }

    if (selectedIds.size === 0) {
      setWarning('اختر تلميذًا واحدًا على الأقل!');
      return;
    }

    setWarning('');
    setSubmitting(true);

    try {
      const result = await transferStudents({
        academic_year_id: Number(yearId),
        source_section_id: Number(sourceSectionId),
        destination_section_id: Number(destinationSectionId),
        student_ids: Array.from(selectedIds),
      });
      setSuccess(result.message);
      setSelectedIds(new Set());
      setReloadKey((value) => value + 1);
    } catch (requestError) {
      setError(errorMessage(requestError));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="mx-auto max-w-7xl p-6 md:p-8" dir="rtl">
      <div className="mb-6 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-orange-100 text-orange-700">
          <ArrowRightLeft size={22} />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-slate-800">نقل التلاميذ من قسم إلى قسم</h1>
          <p className="mt-1 text-sm text-slate-500">اختر السنة والقسم المصدر ثم حدّد التلاميذ المراد نقلهم.</p>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="mb-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
          <label className="space-y-1.5 text-sm font-medium text-slate-700">
            <span>السنة الدراسية (*)</span>
            <select
              value={yearId}
              onChange={(event) => {
                setYearId(event.target.value);
                setSuccess('');
              }}
              className={inputClass}
              disabled={loadingOptions}
            >
              <option value="">اختر السنة الدراسية</option>
              {options.years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}
            </select>
          </label>

          <label className="space-y-1.5 text-sm font-medium text-slate-700">
            <span>من القسم</span>
            <select
              value={sourceSectionId}
              onChange={(event) => {
                setSourceSectionId(event.target.value);
                if (event.target.value === destinationSectionId) setDestinationSectionId('');
                setSuccess('');
              }}
              className={inputClass}
              disabled={loadingOptions}
            >
              <option value="">حدّد القسم</option>
              {options.levels.map((section) => <option key={section.id} value={section.id}>{section.label}</option>)}
            </select>
          </label>

          <label className="space-y-1.5 text-sm font-medium text-slate-700">
            <span>إلى القسم</span>
            <select
              value={destinationSectionId}
              onChange={(event) => {
                setDestinationSectionId(event.target.value);
                setSuccess('');
              }}
              className={inputClass}
              disabled={loadingOptions}
            >
              <option value="">حدّد القسم</option>
              {destinationSections.map((section) => <option key={section.id} value={section.id}>{section.label}</option>)}
            </select>
          </label>

          <div className="flex items-end">
            <button type="submit" disabled={submitting || loadingStudents} className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#3B4A36] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#2E3B2A] disabled:cursor-not-allowed disabled:opacity-60">
              <CheckCircle2 size={17} />
              {submitting ? 'جارٍ النقل...' : `نقل${selectedIds.size ? ` (${selectedIds.size})` : ''}`}
            </button>
          </div>
        </div>
      </form>

      {warning && <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-800">{warning}</div>}
      {error && <div className="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}</div>}
      {success && <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-700">{success}</div>}

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
          <div className="flex items-center gap-2">
            <Users size={18} className="text-[#3B4A36]" />
            <h2 className="font-bold text-slate-800">تلاميذ القسم المصدر</h2>
          </div>
          {!loadingStudents && <span className="text-xs text-slate-500">{students.length} تلميذ</span>}
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-right text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr>
                <th className="w-16 px-5 py-3 text-center">
                  <input type="checkbox" checked={allSelected} onChange={toggleAll} disabled={students.length === 0} aria-label="اختيار كل التلاميذ" />
                </th>
                <th className="px-5 py-3 font-semibold">CNTE</th>
                <th className="px-5 py-3 font-semibold">الاسم الكامل</th>
                <th className="px-5 py-3 font-semibold">تاريخ الولادة</th>
                <th className="px-5 py-3 font-semibold">اسم الأب / الولي</th>
                <th className="px-5 py-3 font-semibold">اسم الأم</th>
                <th className="px-5 py-3 font-semibold">رقم الجوال</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loadingStudents ? (
                <TableRowsSkeleton columns={7} />
              ) : !yearId || !sourceSectionId ? (
                <tr><td colSpan={7} className="px-5 py-10 text-center text-slate-500">اختر السنة الدراسية والقسم المصدر لعرض التلاميذ.</td></tr>
              ) : students.length === 0 ? (
                <tr><td colSpan={7} className="px-5 py-10 text-center text-slate-500">لا يوجد تلاميذ نشطون في هذا القسم خلال السنة المختارة.</td></tr>
              ) : students.map((student) => (
                <tr key={student.id} className={selectedIds.has(student.id) ? 'bg-orange-50/60' : 'hover:bg-slate-50/70'}>
                  <td className="px-5 py-3 text-center">
                    <input type="checkbox" checked={selectedIds.has(student.id)} onChange={() => toggleStudent(student.id)} aria-label={`اختيار ${student.first_name} ${student.last_name}`} />
                  </td>
                  <td className="px-5 py-3 font-medium text-slate-700" dir="ltr">{student.student_code || '—'}</td>
                  <td className="px-5 py-3 font-semibold text-slate-800">{student.first_name} {student.last_name}</td>
                  <td className="px-5 py-3 text-slate-600">{student.dob ? new Date(student.dob).toLocaleDateString('ar-TN') : '—'}</td>
                  <td className="px-5 py-3 text-slate-600">{student.guardian_name || '—'}</td>
                  <td className="px-5 py-3 text-slate-600">{student.mother_name || '—'}</td>
                  <td className="px-5 py-3 text-slate-600" dir="ltr">{student.phone || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
