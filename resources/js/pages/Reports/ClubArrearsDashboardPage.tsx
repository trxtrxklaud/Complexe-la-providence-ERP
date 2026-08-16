import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, Printer, RefreshCw, Search, Users, Building2, WalletCards, BadgeDollarSign } from 'lucide-react';
import { fetchClubArrearsDashboard, fetchClubs, fetchClubSections, ClubArrearsDashboardData, ClubItem } from '../../api/clubs';
import { fetchAcademicYears } from '../../api/years';
import { fetchLevels } from '../../api/classrooms';
import { AcademicYear, Level, Section } from '../../types';

const money = (value: number) => `${Number(value || 0).toFixed(2)} Ø¯.Øª`;

export default function ClubArrearsDashboardPage() {
  const [data, setData] = useState<ClubArrearsDashboardData | null>(null);
  const [years, setYears] = useState<AcademicYear[]>([]);
  const [clubs, setClubs] = useState<ClubItem[]>([]);
  const [levels, setLevels] = useState<Level[]>([]);
  const [sections, setSections] = useState<any[]>([]);
  const [yearId, setYearId] = useState<number | ''>('');
  const [clubId, setClubId] = useState<number | ''>('');
  const [levelId, setLevelId] = useState<number | ''>('');
  const [sectionId, setSectionId] = useState<number | ''>('');
  const [fromMonth, setFromMonth] = useState('');
  const [toMonth, setToMonth] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([fetchAcademicYears(), fetchClubs(), fetchLevels(), fetchClubSections()])
      .then(([yearList, clubList, levelList, sectionList]) => {
        setYears(yearList);
        setClubs(clubList);
        setLevels(levelList);
        setSections(sectionList);
        const active = yearList.find((year) => year.is_active) || yearList[0];
        if (active) setYearId(active.id);
      })
      .catch((err: any) => setError(err.message || 'ØªØ¹Ø°Ø± ØªØ­Ù?Ù?Ù? Ø®Ù?Ø§Ø±Ø§Øª Ø§Ù?ØªÙ?Ø±Ù?Ø±'));
  }, []);

  useEffect(() => {
    if (!yearId) return;
    setLoading(true);
    setError(null);
    fetchClubArrearsDashboard({
      academic_year_id: Number(yearId),
      club_id: clubId ? Number(clubId) : undefined,
      level_id: levelId ? Number(levelId) : undefined,
      section_id: sectionId ? Number(sectionId) : undefined,
      search: search.trim() || undefined,
      from_month: fromMonth || undefined,
      to_month: toMonth || undefined,
    })
      .then(setData)
      .catch((err: any) => setError(err.message || 'ØªØ¹Ø°Ø± ØªØ­Ù?Ù?Ù? Dashboard Ø§Ù?Ù?ØªØ®Ù?Ø¯Ø§Øª'))
      .finally(() => setLoading(false));
  }, [yearId, clubId, levelId, sectionId, search, fromMonth, toMonth]);

  const filteredSections = useMemo(() => {
    return sections.filter((section) => !levelId || section.level_id === Number(levelId));
  }, [sections, levelId]);

  const detailRows = useMemo(() => {
    return (data?.students || []).flatMap((student) => student.details.map((detail) => ({ student, detail })));
  }, [data]);

  const handlePrint = () => window.print();

  return (
    <div className="space-y-5 dir-rtl text-slate-800">
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 8mm; }
          body * { visibility: hidden; }
          .club-arrears-print, .club-arrears-print * { visibility: visible; }
          .club-arrears-print { position: absolute; inset: 0; width: 100%; }
          .no-print, button, input, select { display: none !important; }
          .print-only { display: block !important; }
          tr { break-inside: avoid !important; }
        }
        .print-only { display: none; }
      `}</style>

      <div className="flex flex-wrap items-center justify-between gap-3 no-print">
        <div>
          <h1 className="text-2xl font-bold text-[#26352B]">Dashboard Ù?ØªØ®Ù?Ø¯Ø§Øª Ø§Ù?Ù?Ù?Ø§Ø¯Ù?</h1>
          <p className="text-sm text-slate-500 mt-1">Ø¥Ø¬Ù?Ø§Ù?Ù? Ø§Ù?Ù?ØªØ®Ù?Ø¯Ø§Øª Ù?Ø¬Ù?Ù?Ø¹Ù?Ø§ Ø­Ø³Ø¨ Ø§Ù?Ø£Ù?Ø³Ø§Ù? Ù?Ø§Ù?ØªÙ?Ø§Ù?Ù?Ø° Ù?Ø§Ù?Ù?Ù?Ø§Ø¯Ù?</p>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setData(null)} className="inline-flex items-center gap-2 rounded-xl border px-4 py-2 text-sm hover:bg-slate-50">
            <RefreshCw size={16} /> ØªØ­Ø¯Ù?Ø«
          </button>
          <button onClick={handlePrint} disabled={!data || loading} className="inline-flex items-center gap-2 rounded-xl bg-[#3B4A36] px-4 py-2 text-sm text-white disabled:opacity-50">
            <Printer size={16} /> Ø·Ø¨Ø§Ø¹Ø© Ø§Ù?ØªÙ?Ø±Ù?Ø±
          </button>
        </div>
      </div>

      {error && <div className="no-print flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700"><AlertCircle size={17} /> {error}</div>}

      <div className="no-print flex flex-wrap gap-4 rounded-2xl border bg-white p-4 *:flex-1 *:min-w-[150px]">
        <label className="text-sm font-semibold">Ø§Ù?Ø³Ù?Ø© Ø§Ù?Ø¯Ø±Ø§Ø³Ù?Ø©
          <select value={yearId} onChange={(e) => setYearId(e.target.value ? Number(e.target.value) : '')} className="mt-1 w-full rounded-xl border px-3 py-2 font-normal">
            <option value="">Ø§Ø®ØªØ± Ø§Ù?Ø³Ù?Ø©</option>
            {years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}
          </select>
        </label>
        <label className="text-sm font-semibold">Ø§Ù?Ù?Ø§Ø¯Ù?
          <select value={clubId} onChange={(e) => setClubId(e.target.value ? Number(e.target.value) : '')} className="mt-1 w-full rounded-xl border px-3 py-2 font-normal">
            <option value="">Ù?Ù? Ø§Ù?Ù?Ù?Ø§Ø¯Ù?</option>
            {clubs.map((club) => <option key={club.id} value={club.id}>{club.name}</option>)}
          </select>
        </label>
        <label className="text-sm font-semibold">Ø§Ù?Ù?Ø³ØªÙ?Ù?
          <select value={levelId} onChange={(e) => { setLevelId(e.target.value ? Number(e.target.value) : ''); setSectionId(''); }} className="mt-1 w-full rounded-xl border px-3 py-2 font-normal">
            <option value="">Ù?Ù? Ø§Ù?Ù?Ø³ØªÙ?Ù?Ø§Øª</option>
            {levels.map((level) => <option key={level.id} value={level.id}>{level.name}</option>)}
          </select>
        </label>
        <label className="text-sm font-semibold">Ø§Ù?Ù?Ø³Ù?
          <select value={sectionId} onChange={(e) => setSectionId(e.target.value ? Number(e.target.value) : '')} className="mt-1 w-full rounded-xl border px-3 py-2 font-normal">
            <option value="">Ù?Ù? Ø§Ù?Ø£Ù?Ø³Ø§Ù?</option>
            {filteredSections.map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}
          </select>
        </label>
        <label className="text-sm font-semibold">Ù?Ù? Ø´Ù?Ø±
          <input type="month" value={fromMonth} onChange={(e) => setFromMonth(e.target.value)} className="mt-1 w-full rounded-xl border px-3 py-2 font-normal" />
        </label>
        <label className="text-sm font-semibold">Ø¥Ù?Ù? Ø´Ù?Ø±
          <input type="month" value={toMonth} onChange={(e) => setToMonth(e.target.value)} className="mt-1 w-full rounded-xl border px-3 py-2 font-normal" />
        </label>
        <label className="text-sm font-semibold">Ø¨Ø­Ø« Ø¹Ù? ØªÙ?Ù?Ù?Ø°
          <div className="relative mt-1"><Search size={16} className="absolute right-3 top-3 text-slate-400" /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ø§Ù?Ø§Ø³Ù? Ø£Ù? Ø±Ù?Ù? Ø§Ù?ØªÙ?Ù?Ù?Ø°" className="w-full rounded-xl border py-2 pr-9 pl-3 font-normal" /></div>
        </label>
      </div>

      <div className="club-arrears-print space-y-5">
        <div className="print-only mb-4 text-center">
          <h1 className="text-xl font-bold">ØªÙ?Ø±Ù?Ø± Ù?ØªØ®Ù?Ø¯Ø§Øª Ø§Ù?Ù?Ù?Ø§Ø¯Ù? Ø­Ø³Ø¨ Ø§Ù?Ø£Ù?Ø³Ø§Ù? Ù?Ø§Ù?ØªÙ?Ø§Ù?Ù?Ø°</h1>
          <p className="text-sm">Ø§Ù?Ø³Ù?Ø© Ø§Ù?Ø¯Ø±Ø§Ø³Ù?Ø©: {years.find((year) => year.id === Number(yearId))?.name || 'â??'} â?? ØªØ§Ø±Ù?Ø® Ø§Ù?Ø·Ø¨Ø§Ø¹Ø©: {new Date().toLocaleDateString('ar-TN')}</p>
        </div>

        {loading ? <div className="rounded-2xl border bg-white p-10 text-center text-slate-500">Ø¬Ø§Ø±Ù? ØªØ­Ù?Ù?Ù? Ø§Ù?Ø¥Ø­ØµØ§Ø¦Ù?Ø§Øª...</div> : data && (
          <>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
              {[
                { label: 'Ø¥Ø¬Ù?Ø§Ù?Ù? Ø§Ù?Ù?ØªØ®Ù?Ø¯Ø§Øª', value: money(data.summary.total_remaining), icon: <BadgeDollarSign size={21} />, tone: 'bg-amber-50 text-amber-800' },
                { label: 'Ø¹Ø¯Ø¯ Ø§Ù?ØªÙ?Ø§Ù?Ù?Ø°', value: data.summary.students_count, icon: <Users size={21} />, tone: 'bg-blue-50 text-blue-800' },
                { label: 'Ø¹Ø¯Ø¯ Ø§Ù?Ø£Ù?Ø³Ø§Ù?', value: data.summary.sections_count, icon: <Building2 size={21} />, tone: 'bg-emerald-50 text-emerald-800' },
                { label: 'Ø¹Ø¯Ø¯ Ø±Ø³Ù?Ù? Ø§Ù?Ù?Ù?Ø§Ø¯Ù?', value: data.summary.fees_count, icon: <WalletCards size={21} />, tone: 'bg-violet-50 text-violet-800' },
              ].map((card) => <div key={card.label} className={`rounded-2xl border p-4 ${card.tone}`}><div className="flex items-center justify-between"><span className="text-sm font-semibold">{card.label}</span>{card.icon}</div><div className="mt-2 text-2xl font-extrabold">{card.value}</div></div>)}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              {data.sections.map((section) => {
                const max = Math.max(...data.sections.map((item) => item.total_remaining), 1);
                return <div key={`${section.section_id}-${section.section_name}`} className="rounded-2xl border bg-white p-4">
                  <div className="flex items-center justify-between gap-3"><div><h2 className="font-bold">{section.section_name}</h2><p className="text-xs text-slate-500">{section.students_count} ØªÙ?Ù?Ù?Ø°Ù?Ø§ Â· {section.fees_count} Ù?Ø¹Ù?Ù?Ù?Ù?Ø§</p></div><strong className="text-lg text-[#3B4A36]">{money(section.total_remaining)}</strong></div>
                  <div className="mt-3 h-3 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-amber-500" style={{ width: `${Math.max(4, (section.total_remaining / max) * 100)}%` }} /></div>
                  <div className="mt-3 space-y-2">{section.students.slice(0, 5).map((student) => <div key={`${data.academic_year_id}-${student.student_id}`} className="flex items-center justify-between border-b pb-2 text-sm last:border-0"><span>{student.student_name}<small className="mr-2 text-slate-400">{student.student_code}</small></span><strong>{money(student.total_remaining)}</strong></div>)}</div>
                  {section.students.length > 5 && <p className="mt-2 text-xs text-slate-500">Ù?Ø¸Ù?Ø± ÙÙ? Ø§Ù?Ø¬Ø¯Ù?Ù? Ø£Ø¯Ù?Ø§Ù? Ù?Ø§Ù?Ù? ØªÙ?Ø§Ù?Ù?Ø° Ø§Ù?Ù?Ø³Ù?.</p>}
                </div>;
              })}
            </div>

            <div className="overflow-x-auto rounded-2xl border bg-white">
              <div className="flex items-center justify-between border-b p-4"><div><h2 className="font-bold">Ø§Ù?ØªÙØµÙ?Ù? Ø­Ø³Ø¨ Ø§Ù?ØªÙ?Ù?Ù?Ø° Ù?Ø§Ù?Ù?Ø§Ø¯Ù?</h2><p className="text-xs text-slate-500">Ù?Ù? Ø³Ø·Ø± Ù?Ù?Ø«Ù? Ù?Ø¹Ù?Ù?Ù? Ù?Ø§Ø¯Ù? ØºÙ?Ø± Ù?Ø³Ø¯Ø¯ Ø£Ù? Ù?Ø³Ø¯Ø¯ Ø¬Ø²Ø¦Ù?Ù?Ø§.</p></div><strong className="text-[#3B4A36]">{money(data.summary.total_remaining)}</strong></div>
              <table className="w-full min-w-[900px] text-sm"><thead className="bg-slate-50 text-right"><tr><th className="p-3">Ø§Ù?Ù?Ø³Ù?</th><th className="p-3">Ø§Ù?ØªÙ?Ù?Ù?Ø°</th><th className="p-3">Ø§Ù?Ù?Ø§Ø¯Ù?</th><th className="p-3">Ø§Ù?Ø´Ù?Ø±</th><th className="p-3">Ø§Ù?Ù?Ø³ØªØ­Ù?</th><th className="p-3">Ø§Ù?Ù?Ø¯ÙÙ?Ø¹</th><th className="p-3">Ø§Ù?Ù?ØªØ®Ù?Ø¯</th></tr></thead><tbody>{detailRows.map(({ student, detail }) => <tr key={`${data.academic_year_id}-${detail.id}`} className="border-t"><td className="p-3">{detail.section_name}</td><td className="p-3 font-semibold">{student.student_name}<div className="text-xs font-normal text-slate-400">{student.student_code}</div></td><td className="p-3">{detail.club_name}</td><td className="p-3">{detail.month}</td><td className="p-3">{money(detail.amount_due)}</td><td className="p-3 text-emerald-700">{money(detail.amount_paid)}</td><td className="p-3 font-bold text-amber-700">{money(detail.remaining)}</td></tr>)}</tbody><tfoot><tr className="border-t bg-slate-50 font-bold"><td colSpan={6} className="p-3">Ø§Ù?Ø¥Ø¬Ù?Ø§Ù?Ù? Ø§Ù?Ø¹Ø§Ù?</td><td className="p-3 text-amber-700">{money(data.summary.total_remaining)}</td></tr></tfoot></table>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

