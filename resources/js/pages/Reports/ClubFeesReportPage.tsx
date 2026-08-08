import React, { useState, useEffect, useRef } from 'react';
import {
  fetchClubFeesReport,
  generateClubMonthFees,
  collectClubFeePayment,
  excludeStudentFromClub,
  restoreStudentToClub,
  fetchClubs,
  ClubReportData,
  ClubReportRecord,
  ClubItem,
} from '../../api/clubs';
import { fetchAcademicYears } from '../../api/years';
import { fetchLevels, fetchSections } from '../../api/classrooms';
import { AcademicYear, Level, Section } from '../../types';
import { Printer, RefreshCw, Search, CheckCircle, AlertCircle, Clock, UserX, RotateCcw } from 'lucide-react';

export default function ClubFeesReportPage() {
  const [reportData, setReportData] = useState<ClubReportData | null>(null);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  const activeRequestId = useRef(0);

  // Filter options
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [clubs, setClubs] = useState<ClubItem[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [sections, setSections] = useState<Section[]>([]);

  // Selected filters
  const [selectedYearId, setSelectedYearId] = useState<number | ''>('');
  const [selectedMonth, setSelectedMonth] = useState<string>(new Date().toISOString().slice(0, 7));
  const [selectedClubId, setSelectedClubId] = useState<number | ''>('');
  const [selectedLevelId, setSelectedLevelId] = useState<number | ''>('');
  const [selectedSectionId, setSelectedSectionId] = useState<number | ''>('');
  const [selectedStatus, setSelectedStatus] = useState<string>('');
  const [search, setSearch] = useState<string>('');

  // Collect Payment Modal state
  const [collectModalRecord, setCollectModalRecord] = useState<ClubReportRecord | null>(null);
  const [payAmount, setPayAmount] = useState<number | ''>('');
  const [payDate, setPayDate] = useState<string>(new Date().toISOString().slice(0, 10));
  const [payMethod, setPayMethod] = useState<'cash' | 'bank_transfer' | 'check' | 'card'>('cash');
  const [payNotes, setPayNotes] = useState<string>('');
  const [submittingPayment, setSubmittingPayment] = useState(false);

  useEffect(() => {
    loadOptions();
  }, []);

  useEffect(() => {
    loadReport();
  }, [selectedYearId, selectedMonth, selectedClubId, selectedLevelId, selectedSectionId, selectedStatus, search]);

  const loadOptions = async () => {
    try {
      const [yList, cList, lList, sList] = await Promise.all([
        fetchAcademicYears(),
        fetchClubs(),
        fetchLevels(),
        fetchSections(),
      ]);
      setYears(yList);
      setClubs(cList);
      setLevels(lList);
      setSections(sList);

      const activeYear = yList.find((y) => y.is_active);
      if (activeYear) {
        setSelectedYearId(activeYear.id);
      } else if (yList.length > 0) {
        setSelectedYearId(yList[0].id);
      }
    } catch (err: any) {
      console.error(err);
    }
  };

  const loadReport = async () => {
    const requestId = ++activeRequestId.current;
    setLoading(true);
    setReportData(null); // Clear previous section/filter reportData immediately to avoid displaying stale names/totals
    setError(null);
    try {
      const data = await fetchClubFeesReport({
        academic_year_id: selectedYearId ? Number(selectedYearId) : undefined,
        month: selectedMonth || undefined,
        club_id: selectedClubId ? Number(selectedClubId) : undefined,
        level_id: selectedLevelId ? Number(selectedLevelId) : undefined,
        section_id: selectedSectionId ? Number(selectedSectionId) : undefined,
        status: selectedStatus || undefined,
        search: search.trim() || undefined,
      });
      if (requestId === activeRequestId.current) {
        setReportData(data);
      }
    } catch (err: any) {
      if (requestId === activeRequestId.current) {
        setError(err.message || 'حدث خطأ أثناء تحميل التقرير');
      }
    } finally {
      if (requestId === activeRequestId.current) {
        setLoading(false);
      }
    }
  };

  const handleClubChange = (clubIdVal: number | '') => {
    setSelectedClubId(clubIdVal);
    if (clubIdVal) {
      const selectedClubObj = clubs.find((c) => c.id === Number(clubIdVal));
      const allowedLevelIds = (selectedClubObj?.levels && selectedClubObj.levels.length > 0)
        ? selectedClubObj.levels.map((l) => l.id)
        : [];
      if (allowedLevelIds.length > 0) {
        if (selectedLevelId && !allowedLevelIds.includes(Number(selectedLevelId))) {
          setSelectedLevelId('');
        }
        if (selectedSectionId) {
          const matchingSec = sections.find((s) => s.id === Number(selectedSectionId));
          if (matchingSec && !allowedLevelIds.includes(matchingSec.level_id)) {
            setSelectedSectionId('');
          }
        }
      }
    }
  };

  const handleLevelChange = (levelIdVal: number | '') => {
    setSelectedLevelId(levelIdVal);
    if (levelIdVal && selectedSectionId) {
      const matchingSec = sections.find((s) => s.id === Number(selectedSectionId));
      if (matchingSec && matchingSec.level_id !== Number(levelIdVal)) {
        setSelectedSectionId('');
      }
    }
  };

  const selectedClubObj = selectedClubId ? clubs.find((c) => c.id === Number(selectedClubId)) : null;
  const clubAllowedLevelIds = (selectedClubObj?.levels && selectedClubObj.levels.length > 0)
    ? selectedClubObj.levels.map((l) => l.id)
    : [];

  const availableLevels = clubAllowedLevelIds.length > 0
    ? levels.filter((l) => clubAllowedLevelIds.includes(l.id))
    : levels;

  const availableSections = sections.filter((s) => {
    if (clubAllowedLevelIds.length > 0 && !clubAllowedLevelIds.includes(s.level_id)) {
      return false;
    }
    if (selectedLevelId && s.level_id !== Number(selectedLevelId)) {
      return false;
    }
    return true;
  });

  const handleGenerateMonth = async () => {
    if (!selectedYearId || !selectedMonth) {
      setError('اختر السنة الدراسية والشهر أولاً');
      return;
    }
    setGenerating(true);
    setError(null);
    setSuccessMsg(null);
    try {
      const res = await generateClubMonthFees({
        academic_year_id: Number(selectedYearId),
        month: selectedMonth,
        club_id: selectedClubId ? Number(selectedClubId) : undefined,
        section_id: selectedSectionId ? Number(selectedSectionId) : undefined,
      });
      setSuccessMsg(res.message);
      await loadReport();
    } catch (err: any) {
      setError(err.message || 'فشل توليد سجلات الشهر');
    } finally {
      setGenerating(false);
    }
  };

  const openCollectModal = (record: ClubReportRecord) => {
    setCollectModalRecord(record);
    setPayAmount(record.remaining > 0 ? record.remaining : record.amount_due);
    setPayDate(new Date().toISOString().slice(0, 10));
    setPayMethod('cash');
    setPayNotes('');
  };

  const handleCollectSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!collectModalRecord || !payAmount || Number(payAmount) <= 0) return;

    setSubmittingPayment(true);
    setError(null);
    try {
      await collectClubFeePayment(collectModalRecord.id, {
        amount_paid: Number(payAmount),
        paid_at: payDate,
        method: payMethod,
        notes: payNotes || undefined,
      });
      setCollectModalRecord(null);
      setSuccessMsg('تم تسجيل استخلاص معلوم النادي بنجاح');
      await loadReport();
    } catch (err: any) {
      setError(err.message || 'فشل تسجيل الدفع');
    } finally {
      setSubmittingPayment(false);
    }
  };

  const handleExclude = async (subId: number) => {
    if (!window.confirm('هل تأكد من استبعاد هذا التلميذ من متابعة معلوم النادي بعد سبتمبر؟ لا يحذف التلميذ ولا مدفوعاته القديمة.')) return;
    try {
      await excludeStudentFromClub(subId, 'استبعاد من متابعة معلوم النادي');
      setSuccessMsg('تم استبعاد التلميذ بنجاح دون حذف بياناته القديمة');
      await loadReport();
    } catch (err: any) {
      setError(err.message || 'فشل استبعاد التلميذ');
    }
  };

  const handleRestore = async (subId: number) => {
    try {
      await restoreStudentToClub(subId);
      setSuccessMsg('تمت إعادة التلميذ لمتابعة معلوم النادي بنجاح');
      await loadReport();
    } catch (err: any) {
      setError(err.message || 'فشل إعادة التلميذ');
    }
  };

  const handlePrint = () => {
    window.print();
  };

  const summary = reportData?.summary;
  const records = reportData?.records || [];

  return (
    <div className="space-y-6 dir-rtl">
      {/* Printable CSS style */}
      <style>{`
        @media print {
          @page { size: A4 portrait; margin: 10mm 8mm; }
          body * {
            visibility: hidden;
          }
          .printable-area, .printable-area * {
            visibility: visible;
          }
          .printable-area {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            padding: 0;
          }
          .no-print, button, input, select {
            display: none !important;
            visibility: hidden !important;
          }
          thead {
            display: table-header-group !important;
          }
          tr {
            break-inside: avoid !important;
          }
        }
      `}</style>

      {/* Header & Controls */}
      <div className="flex flex-wrap items-center justify-between gap-4 no-print">
        <div>
          <h1 className="text-2xl font-bold text-gray-800">تقارير معلوم النوادي المدرسية</h1>
          <p className="text-sm text-gray-500">متابعة تحصيل واستخلاص معاليم النوادي شهرياً</p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={handleGenerateMonth}
            disabled={generating || !selectedYearId || !selectedMonth}
            className="flex items-center gap-2 px-4 py-2 bg-[#3B4A36] text-white rounded-lg hover:bg-[#2E3B2A] transition disabled:opacity-50"
          >
            <RefreshCw className={`w-4 h-4 ${generating ? 'animate-spin' : ''}`} />
            توليد سجلات الشهر
          </button>
          <button
            onClick={handlePrint}
            disabled={loading || !reportData || records.length === 0}
            className="flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition disabled:opacity-50"
          >
            <Printer className="w-4 h-4" />
            طباعة التقرير (A4)
          </button>
        </div>
      </div>

      {/* Feedback Messages */}
      {error && (
        <div className="p-4 bg-red-50 text-red-700 rounded-lg border border-red-200 flex items-center justify-between no-print">
          <span>{error}</span>
          <button onClick={() => setError(null)} className="text-sm underline">إغلاق</button>
        </div>
      )}
      {successMsg && (
        <div className="p-4 bg-green-50 text-green-700 rounded-lg border border-green-200 flex items-center justify-between no-print">
          <span>{successMsg}</span>
          <button onClick={() => setSuccessMsg(null)} className="text-sm underline">إغلاق</button>
        </div>
      )}

      {/* Filter Bar */}
      <div className="p-4 bg-white rounded-xl shadow-sm border border-gray-100 space-y-4 no-print">
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">السنة الدراسية</label>
            <select
              value={selectedYearId}
              onChange={(e) => setSelectedYearId(e.target.value ? Number(e.target.value) : '')}
              className="w-full text-sm border-gray-300 rounded-lg p-2 bg-gray-50"
            >
              {years.map((y) => (
                <option key={y.id} value={y.id}>{y.name} {y.is_active ? '(النشطة)' : ''}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">الشهر</label>
            <input
              type="month"
              value={selectedMonth}
              onChange={(e) => setSelectedMonth(e.target.value)}
              className="w-full text-sm border-gray-300 rounded-lg p-2 bg-gray-50"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">النادي</label>
            <select
              value={selectedClubId}
              onChange={(e) => handleClubChange(e.target.value ? Number(e.target.value) : '')}
              className="w-full text-sm border-gray-300 rounded-lg p-2 bg-gray-50"
            >
              <option value="">كل النوادي</option>
              {clubs.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">المستوى</label>
            <select
              value={selectedLevelId}
              onChange={(e) => handleLevelChange(e.target.value ? Number(e.target.value) : '')}
              className="w-full text-sm border-gray-300 rounded-lg p-2 bg-gray-50"
            >
              <option value="">كل المستويات</option>
              {availableLevels.map((l) => (
                <option key={l.id} value={l.id}>{l.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">القسم</label>
            <select
              value={selectedSectionId}
              onChange={(e) => setSelectedSectionId(e.target.value ? Number(e.target.value) : '')}
              className="w-full text-sm border-gray-300 rounded-lg p-2 bg-gray-50"
            >
              <option value="">كل الأقسام</option>
              {availableSections.map((s) => (
                <option key={s.id} value={s.id}>
                  {selectedLevelId
                    ? s.name
                    : `${levels.find((l) => l.id === s.level_id)?.name || ''} - ${s.name}`}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-gray-600 mb-1">حالة الدفع</label>
            <select
              value={selectedStatus}
              onChange={(e) => setSelectedStatus(e.target.value)}
              className="w-full text-sm border-gray-300 rounded-lg p-2 bg-gray-50"
            >
              <option value="">الكل</option>
              <option value="paid">خلاص كامل (أخضر)</option>
              <option value="pending">في انتظار الدفع (برتقالي)</option>
            </select>
          </div>
        </div>

        <div className="relative">
          <Search className="w-4 h-4 absolute right-3 top-3 text-gray-400" />
          <input
            type="text"
            placeholder="البحث باسم التلميذ أو الرمز..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full text-sm pr-9 border-gray-300 rounded-lg p-2 bg-gray-50"
          />
        </div>
      </div>

      {/* Report Summary Cards */}
      {loading ? (
        <div className="p-4 bg-gray-50 rounded-xl border border-gray-200 text-center text-sm text-gray-500 animate-pulse no-print">
          جاري تحميل بيانات التقرير للقسم المحدد...
        </div>
      ) : summary ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 no-print">
          <div className="p-3 bg-white rounded-xl border border-gray-100 text-center">
            <span className="block text-xs text-gray-500">عدد التلاميذ بالمجموعة</span>
            <span className="text-xl font-bold text-gray-800">{summary.enrolled_count}</span>
          </div>

          <div className="p-3 bg-green-50 border border-green-100 rounded-xl text-center">
            <span className="block text-xs text-green-700">خلاص كامل</span>
            <span className="text-xl font-bold text-green-800">{summary.paid_count}</span>
          </div>

          <div className="p-3 bg-orange-50 border border-orange-100 rounded-xl text-center">
            <span className="block text-xs text-orange-700">في انتظار الدفع</span>
            <span className="text-xl font-bold text-orange-800">{summary.pending_count ?? summary.unpaid_count}</span>
          </div>

          <div className="p-3 bg-white rounded-xl border border-gray-100 text-center">
            <span className="block text-xs text-gray-500">إجمالي المطلوب</span>
            <span className="text-xl font-bold text-gray-800">{summary.total_due.toFixed(2)} د.ت</span>
          </div>

          <div className="p-3 bg-green-50 border border-green-100 rounded-xl text-center">
            <span className="block text-xs text-green-700">إجمالي المقبوض</span>
            <span className="text-xl font-bold text-green-800">{summary.total_paid.toFixed(2)} د.ت</span>
          </div>

          <div className="p-3 bg-orange-50 border border-orange-100 rounded-xl text-center">
            <span className="block text-xs text-orange-700">إجمالي المتبقي</span>
            <span className="text-xl font-bold text-orange-800">{summary.total_remaining.toFixed(2)} د.ت</span>
          </div>
        </div>
      ) : null}

      {/* Main Printable Content Container */}
      <div className="printable-area bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-6">
        {/* Printable School Header */}
        <div className="hidden print:block border-b border-gray-200 pb-4 text-center">
          <h2 className="text-xl font-bold text-gray-900">مركب العناية للتعليم الخاص</h2>
          <p className="text-sm text-gray-600">كشف متابعة معاليم النوادي المدرسية</p>
          <div className="flex justify-between items-center text-xs text-gray-500 mt-2">
            <span>الشهر: {selectedMonth}</span>
            <span>القسم: {selectedSectionId ? sections.find(s => s.id === Number(selectedSectionId))?.name : 'كل الأقسام'}</span>
            <span>تاريخ الاستخراج: {new Date().toLocaleDateString('ar-TN')}</span>
          </div>
        </div>

        {/* Records Table */}
        {loading ? (
          <div className="py-12 text-center text-gray-500">جاري تحميل البيانات...</div>
        ) : records.length === 0 ? (
          <div className="py-12 text-center text-gray-500 space-y-2">
            <AlertCircle className="w-8 h-8 mx-auto text-gray-400" />
            <p>لا توجد سجلات مطابقة للشروط المختارة.</p>
            <p className="text-xs text-gray-400">انقر على "توليد سجلات الشهر" لإنشاء السجلات للتلاميذ المسجلين في النوادي.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-right border-collapse text-sm">
              <thead>
                <tr className="bg-gray-50 border-b border-gray-200 text-gray-600 font-semibold">
                  <th className="p-3">رمز التلميذ</th>
                  <th className="p-3">اسم التلميذ</th>
                  <th className="p-3">المستوى والقسم</th>
                  <th className="p-3">النادي</th>
                  <th className="p-3 text-left">المطلوب (د.ت)</th>
                  <th className="p-3 text-left">المدفوع (د.ت)</th>
                  <th className="p-3 text-left">المتبقي (د.ت)</th>
                  <th className="p-3 text-center">الحالة</th>
                  <th className="p-3 no-print text-center">الإجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {records.map((r: any) => (
                  <tr key={r.id} className="hover:bg-gray-50">
                    <td className="p-3 text-gray-500 font-mono">{r.student_code}</td>
                    <td className="p-3 font-semibold text-gray-800">
                      {r.student_name}
                      {r.is_excluded && <span className="mr-2 text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">(مستبعد)</span>}
                    </td>
                    <td className="p-3 text-gray-600">{r.level_name} - {r.section_name}</td>
                    <td className="p-3 font-medium text-gray-700">{r.club_name}</td>
                    <td className="p-3 text-left font-mono font-medium">{r.amount_due.toFixed(2)}</td>
                    <td className="p-3 text-left font-mono text-green-700">{r.amount_paid.toFixed(2)}</td>
                    <td className="p-3 text-left font-mono text-orange-600">{r.remaining.toFixed(2)}</td>
                    <td className="p-3 text-center">
                      <span
                        className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold ${
                          r.status === 'paid'
                            ? 'bg-green-100 text-green-800'
                            : 'bg-orange-100 text-orange-800'
                        }`}
                      >
                        {r.status === 'paid' ? <CheckCircle className="w-3.5 h-3.5" /> : <Clock className="w-3.5 h-3.5" />}
                        {r.status === 'paid' ? 'خلاص كامل' : 'في انتظار الدفع'}
                      </span>
                    </td>
                    <td className="p-3 no-print text-center flex items-center justify-center gap-2">
                      {r.status !== 'paid' && (
                        <button
                          onClick={() => openCollectModal(r)}
                          className="px-3 py-1 bg-[#3B4A36] text-white text-xs rounded hover:bg-[#2E3B2A] transition"
                        >
                          تسجيل الدفع
                        </button>
                      )}
                      {r.subscription_id && (
                        r.is_excluded ? (
                          <button
                            onClick={() => handleRestore(r.subscription_id)}
                            title="إعادة التلميذ للمتابعة"
                            className="p-1 text-gray-500 hover:text-green-600 rounded"
                          >
                            <RotateCcw className="w-4 h-4" />
                          </button>
                        ) : (
                          <button
                            onClick={() => handleExclude(r.subscription_id)}
                            title="استبعاد من متابعة النادي"
                            className="p-1 text-gray-400 hover:text-red-600 rounded"
                          >
                            <UserX className="w-4 h-4" />
                          </button>
                        )
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Printable Summary Footer */}
        {summary && (
          <div className="border-t border-gray-200 pt-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs font-medium text-gray-700">
            <div>إجمالي التلاميذ: <span className="font-bold">{summary.enrolled_count}</span></div>
            <div>إجمالي المطلوب: <span className="font-bold">{summary.total_due.toFixed(2)} د.ت</span></div>
            <div>إجمالي المقبوض: <span className="font-bold text-green-700">{summary.total_paid.toFixed(2)} د.ت</span></div>
            <div>إجمالي المتبقي: <span className="font-bold text-orange-600">{summary.total_remaining.toFixed(2)} د.ت</span></div>
          </div>
        )}
      </div>

      {/* Collect Payment Modal */}
      {collectModalRecord && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50 no-print">
          <div className="bg-white rounded-xl shadow-xl max-w-md w-full p-6 space-y-4 text-right">
            <h3 className="text-lg font-bold text-gray-800">تسجيل استخلاص معلوم نادي</h3>
            <p className="text-xs text-gray-500">
              التلميذ: <span className="font-semibold text-gray-700">{collectModalRecord.student_name}</span> ({collectModalRecord.club_name} - {collectModalRecord.month})
            </p>

            <form onSubmit={handleCollectSubmit} className="space-y-4 text-sm">
              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">المبلغ المقبوض (د.ت)</label>
                <input
                  type="number"
                  step="0.001"
                  min="0.01"
                  max={collectModalRecord.remaining > 0 ? collectModalRecord.remaining : collectModalRecord.amount_due}
                  value={payAmount}
                  onChange={(e) => setPayAmount(e.target.value ? Number(e.target.value) : '')}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                  required
                />
                <span className="text-xs text-gray-400 mt-1 block">
                  المتبقي: {collectModalRecord.remaining.toFixed(2)} د.ت
                </span>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">تاريخ القبض</label>
                <input
                  type="date"
                  value={payDate}
                  onChange={(e) => setPayDate(e.target.value)}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                  required
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">طريقة الدفع</label>
                <select
                  value={payMethod}
                  onChange={(e) => setPayMethod(e.target.value as any)}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                >
                  <option value="cash">نقداً (Cash)</option>
                  <option value="bank_transfer">تحويل بنكي</option>
                  <option value="check">شيك</option>
                  <option value="card">بطاقة بانكية</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-600 mb-1">ملاحظات</label>
                <textarea
                  value={payNotes}
                  onChange={(e) => setPayNotes(e.target.value)}
                  className="w-full border-gray-300 rounded-lg p-2 bg-gray-50"
                  rows={2}
                />
              </div>

              <div className="flex justify-between items-center pt-2">
                <button
                  type="button"
                  onClick={() => setCollectModalRecord(null)}
                  className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={submittingPayment}
                  className="px-4 py-2 bg-[#3B4A36] text-white rounded-lg hover:bg-[#2E3B2A] transition disabled:opacity-50"
                >
                  {submittingPayment ? 'جاري التسجيل...' : 'حفظ الاستخلاص'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
