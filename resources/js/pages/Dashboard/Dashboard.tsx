import { useEffect, useState } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import {
  AlertCircle,
  ArrowDownCircle,
  GraduationCap,
  Landmark,
  TrendingDown,
  TrendingUp,
  UserRound,
  Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { fetchDashboard, type DashboardData } from '../../api/dashboard';
import { errorMessage } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  rose: '#F1E4E2',
  beige: '#EFEAE0',
  blush: '#EFE0E4',
  ink: '#1F261C',
  muted: '#7C8677',
  error: '#A03434',
  errorBg: '#FDECEC',
};

/** الدينار بثلاث خانات، كما في الكشوف المطبوعة. */
function dinar(value: number | null | undefined): string {
  return `${Number(value ?? 0).toFixed(3)} د`;
}

function AnalogClock({ size = 150 }: { size?: number }) {
  const [now, setNow] = useState(new Date());

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);

  const ms = now.getMilliseconds();
  const s = now.getSeconds() + ms / 1000;
  const m = now.getMinutes() + s / 60;
  const h = (now.getHours() % 12) + m / 60;

  const secDeg = s * 6;
  const minDeg = m * 6;
  const hrDeg = h * 30;
  const cx = 100;
  const cy = 100;

  return (
    <svg width={size} height={size} viewBox="0 0 200 200">
      <circle cx={cx} cy={cy} r={98} fill={C.deep} />
      <circle cx={cx} cy={cy} r={92} fill="#FDFDFB" />
      {Array.from({ length: 60 }).map((_, i) => {
        const major = i % 5 === 0;
        return (
          <line
            key={i}
            x1={cx}
            y1={major ? 16 : 12}
            x2={cx}
            y2={major ? 28 : 18}
            stroke={major ? C.deep : '#B9BFB2'}
            strokeWidth={major ? 4 : 1.6}
            strokeLinecap="round"
            transform={`rotate(${i * 6} ${cx} ${cy})`}
          />
        );
      })}
      <line x1={cx} y1={cy + 14} x2={cx} y2={cy - 46} stroke={C.deep} strokeWidth={7} strokeLinecap="round" transform={`rotate(${hrDeg} ${cx} ${cy})`} />
      <line x1={cx} y1={cy + 18} x2={cx} y2={cy - 70} stroke={C.deep} strokeWidth={4.5} strokeLinecap="round" transform={`rotate(${minDeg} ${cx} ${cy})`} />
      <g transform={`rotate(${secDeg} ${cx} ${cy})`}>
        <line x1={cx} y1={cy + 24} x2={cx} y2={cy - 78} stroke="#B5493F" strokeWidth={1.8} strokeLinecap="round" />
        <circle cx={cx} cy={cy + 24} r={4} fill="#B5493F" />
      </g>
      <circle cx={cx} cy={cy} r={6.5} fill={C.deep} />
      <circle cx={cx} cy={cy} r={2.4} fill="#FDFDFB" />
    </svg>
  );
}

function KpiCard({
  label,
  value,
  icon: Icon,
  tint,
  iconColor,
  hint,
}: {
  label: string;
  value: string | number;
  icon: LucideIcon;
  tint: string;
  iconColor: string;
  hint?: string;
}) {
  return (
    <div className="rounded-[22px] p-5" style={{ backgroundColor: tint }}>
      <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/70" style={{ color: iconColor }}>
        <Icon size={20} />
      </div>
      <p className="mt-4 text-[26px] font-extrabold" style={{ color: C.ink }}>
        {value}
      </p>
      <p className="mt-1 text-sm" style={{ color: C.muted }}>
        {label}
      </p>
      {hint && (
        <p className="mt-1 text-xs" style={{ color: C.muted }}>
          {hint}
        </p>
      )}
    </div>
  );
}

/**
 * لوحة صاحبة المدرسة.
 *
 * كروت الصندوق تُقرأ من الدفتر النقدي المركزي لا من جداول الدفعات، فهي نفس
 * الأرقام التي تظهر في الخزينة والدخل الصافي حرفيّاً. وهي مستقلّة عن السنة الدراسية:
 * المدرسة تستخلص في كل الأشهر، وما يُدفع في أوت عن متخلَّد جوان هو دخل يوم أوت.
 *
 * أمّا كروت التلاميذ فتخصّ السنة الدراسية النشطة، واسمها معروض تحتها صراحة
 * حتّى لا تُقرأ أرقام سنة على أنّها أرقام سنة أخرى.
 */
export default function Dashboard() {
  const { user } = useAuth();
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError('');

    fetchDashboard(controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) setData(result);
      })
      .catch((e) => {
        if (!controller.signal.aborted) setError(errorMessage(e));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });

    return () => controller.abort();
  }, []);

  const today = data?.cash?.today;
  const month = data?.cash?.month;
  const yearName = data?.academic_year?.name ?? '—';

  return (
    <div className="p-6 md:p-8" dir="rtl">
      <div className="mb-8">
        <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
          مرحباً، {user?.first_name || 'مدير'}
        </h1>
        <p className="mt-1 text-sm" style={{ color: C.muted }}>
          جرد اليوم {data?.current_date ?? ''}
        </p>
      </div>

      {error && (
        <div
          className="rounded-2xl p-4 mb-6 flex items-start gap-2 text-sm"
          style={{ backgroundColor: C.errorBg, color: C.error }}
        >
          <AlertCircle size={18} />
          <span>{error}</span>
        </div>
      )}

      {loading && (
        <PageDataSkeleton cards={4} rows={4} />
      )}

      {!loading && data && (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
            <KpiCard
              label="مداخيل اليوم"
              value={dinar(today?.income)}
              icon={TrendingUp}
              tint={C.sage}
              iconColor={C.forest}
            />
            <KpiCard
              label="مصاريف اليوم"
              value={dinar(today?.expenses)}
              icon={TrendingDown}
              tint={C.rose}
              iconColor="#A46E67"
            />
            <KpiCard
              label="الدخل الصافي اليوم"
              value={dinar(today?.net_income)}
              icon={ArrowDownCircle}
              tint={C.beige}
              iconColor="#8A7C57"
            />
            <KpiCard
              label="رصيد الخزينة"
              value={dinar(data.treasury_balance)}
              icon={Landmark}
              tint={C.blush}
              iconColor="#9A6B7E"
              hint="من بداية السجلّ بعد السحوبات"
            />
          </div>

          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <KpiCard
              label="إجمالي التلاميذ"
              value={data.total_students}
              icon={GraduationCap}
              tint={C.sage}
              iconColor={C.forest}
              hint={`السنة النشطة: ${yearName}`}
            />
            <KpiCard label="الإناث" value={data.total_females} icon={UserRound} tint={C.rose} iconColor="#A46E67" />
            <KpiCard label="الذكور" value={data.total_males} icon={Users} tint={C.beige} iconColor="#8A7C57" />
            <KpiCard
              label="المتخلَّد"
              value={dinar(data.outstanding_balance)}
              icon={AlertCircle}
              tint={C.blush}
              iconColor="#9A6B7E"
              hint={`السنة النشطة: ${yearName}`}
            />
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
            <div className="lg:col-span-2 bg-white rounded-[22px] p-6 border border-[#EDF1E8]">
              <h2 className="font-bold text-lg mb-4" style={{ color: C.ink }}>
                الشهر الجاري
              </h2>
              <div className="space-y-2 text-sm">
                <div className="flex items-center justify-between">
                  <span style={{ color: C.muted }}>مجموع المداخيل</span>
                  <strong style={{ color: C.forest }}>{dinar(month?.income)}</strong>
                </div>
                <div className="flex items-center justify-between">
                  <span style={{ color: C.muted }}>مجموع المصاريف</span>
                  <strong style={{ color: C.error }}>{dinar(month?.expenses)}</strong>
                </div>
                <div className="flex items-center justify-between">
                  <span style={{ color: C.muted }}>الدخل الصافي</span>
                  <strong style={{ color: C.ink }}>{dinar(month?.net_income)}</strong>
                </div>
                <div className="flex items-center justify-between">
                  <span style={{ color: C.muted }}>السحوبات</span>
                  <strong style={{ color: C.muted }}>{dinar(month?.withdrawals)}</strong>
                </div>
              </div>
              <p className="mt-4 text-xs" style={{ color: C.muted }}>
                أرقام الصندوق تتبع تاريخ القبض الفعلي، لا الشهر المُستخلَص عنه.
              </p>
            </div>

            <div
              className="rounded-[22px] p-6 flex flex-col items-center justify-center"
              style={{ background: `linear-gradient(165deg, ${C.forest}, ${C.deep})` }}
            >
              <p className="text-white/70 text-sm mb-3">توقيت المؤسسة</p>
              <AnalogClock size={140} />
              <p className="mt-3 text-white font-semibold text-sm">مدرسة العناية</p>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
