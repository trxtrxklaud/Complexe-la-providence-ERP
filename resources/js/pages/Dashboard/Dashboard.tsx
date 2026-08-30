import { type ReactNode, useEffect, useRef, useState } from 'react';
import { motion, useReducedMotion, type Variants } from 'motion/react';
import { useAuth } from '../../contexts/AuthContext';
import {
  AlertCircle,
  ArrowDownCircle,
  Award,
  Coffee,
  GraduationCap,
  History,
  Landmark,
  Moon,
  TrendingDown,
  TrendingUp,
  UserRound,
  Users,
  X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { fetchDashboard, type DashboardData, type PriorDebtSummary } from '../../api/dashboard';
import { errorMessage } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';
import schoolgirlAvatar from '../../assets/schoolgirl.jpg';
import schoolboyAvatar from '../../assets/schoolboy.jpg';

/**
 * لوحة الألوان: أخضر غامق أساسيّ، أخضر فاتح للإيجابي، وردي/عنبري خفيف للسالب،
 * وذهب عتيق **محدود** (قوس الترسيم ورقاقة الخزينة والنسبة) لا لونًا منتشرًا.
 */
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
  gold: '#C89B3C',
  goldDeep: '#8A6A1E',
  goldSoft: '#F4EAD0',
  collected: '#15803D',
  collectedSoft: '#E3EFE4',
  remaining: '#B45309',
  remainingSoft: '#FBEFE0',
  expense: '#9E5A52',
  hair: '#EAEFE4', // حدّ خفيف جدًّا — يحلّ محلّ الخلفيات الملوّنة
  soft: '#F7F9F4', // سطح ثانويّ مسطّح (هرمية بلا ألوان إضافية)
  track: '#EDF1E8', // مضمار الدوائر
};

/** أرقام مصطفّة عموديّاً — تُطبَّق على كل قيمة رقمية في الصفحة. */
const NUM = { fontVariantNumeric: 'tabular-nums' } as const;

/** حلقة تركيز ظاهرة للعناصر التفاعلية (لا اعتماد على hover وحده). */
const FOCUS = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-[#3B4A36]';

/** الدينار بثلاث خانات، كما في الكشوف المطبوعة. */
function dinar(value: number | null | undefined): string {
  return `${Number(value ?? 0).toFixed(3)} د`;
}

/** قيمة نقدية معزولة الاتجاه: إشارة السالب تلتصق بالرقم يساراً دائماً، والقيمة السالبة تُحمرّ. */
function Money({ value }: { value: number | null | undefined }) {
  const negative = Number(value ?? 0) < 0;
  return (
    <bdi dir='ltr' className={negative ? 'text-[#A03434]' : undefined} style={NUM}>
      {dinar(value)}
    </bdi>
  );
}

/**
 * عدّاد تصاعدي لطيف من الصفر إلى القيمة النهائية — تجميل بصري بحت لا يغيّر أيّ رقم:
 * القيمة النهائية هي عينها المُرسَلة من الخادم. يُحترم تفضيل تقليل الحركة فتظهر القيمة فوراً.
 */
function useCountUp(target: number): number {
  const reduce = useReducedMotion();
  const [val, setVal] = useState(reduce ? target : 0);
  const rafRef = useRef(0);

  useEffect(() => {
    if (reduce) {
      setVal(target);
      return;
    }
    const start = performance.now();
    const duration = 900;
    const tick = (t: number) => {
      const p = Math.min(1, (t - start) / duration);
      const eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
      setVal(target * eased);
      if (p < 1) rafRef.current = requestAnimationFrame(tick);
    };
    rafRef.current = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(rafRef.current);
  }, [target, reduce]);

  return val;
}

/** رقم صحيح بعدّ تصاعدي، أرقام مصطفّة عموديّاً. */
function AnimatedInt({ value }: { value: number }) {
  const v = useCountUp(value);
  return <span style={NUM}>{Math.round(v)}</span>;
}

/** قيمة نقدية بعدّ تصاعدي — إشارة السالب ثابتة أثناء العدّ (تُقرأ من الهدف لا من الرقم المتحرّك). */
function AnimatedMoney({ value }: { value: number | null | undefined }) {
  const target = Number(value ?? 0);
  const v = useCountUp(target);
  const negative = target < 0;
  return (
    <bdi dir='ltr' className={negative ? 'text-[#A03434]' : undefined} style={NUM}>
      {dinar(v)}
    </bdi>
  );
}

/**
 * دائرة نِسبة: مضمار كامل + قوس مُتحرّك يُرسَم بـstroke-dashoffset. البدء من الأعلى
 * (rotate -90). تتقلّص مع عرض الحاوية (aspect-ratio) فلا تتكسّر في الشاشات الضيّقة ولا في RTL.
 * زخرفة تعرض نسبة قائمة من أرقام الخادم — لا رقم جديد يُختلق. الـSVG نفسه مخفيّ عن
 * قارئ الشاشة، والمعنى يُقرأ من aria-label الحاوية.
 */
function RatioDonut({
  size,
  stroke,
  progress,
  track,
  color,
  label,
  children,
  delay = 0,
}: {
  size: number;
  stroke: number;
  progress: number; // 0..1
  track: string;
  color: string;
  label: string;
  children?: ReactNode;
  delay?: number;
}) {
  const reduce = useReducedMotion();
  const p = Math.max(0, Math.min(1, Number.isFinite(progress) ? progress : 0));
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const cx = size / 2;
  const cy = size / 2;
  const target = c * (1 - p);

  return (
    <div
      role='img'
      aria-label={label}
      className='relative inline-flex items-center justify-center transition-transform duration-300 hover:scale-[1.02]'
      style={{ width: size, maxWidth: '100%', aspectRatio: '1 / 1' }}
    >
      <svg viewBox={`0 0 ${size} ${size}`} width='100%' height='100%' className='block' aria-hidden='true' focusable='false'>
        <circle cx={cx} cy={cy} r={r} fill='none' stroke={track} strokeWidth={stroke} opacity={0.85} />
        <motion.circle
          cx={cx}
          cy={cy}
          r={r}
          fill='none'
          stroke={color}
          strokeWidth={stroke}
          strokeLinecap='round'
          strokeDasharray={c}
          transform={`rotate(-90 ${cx} ${cy})`}
          initial={{ strokeDashoffset: reduce ? target : c }}
          animate={{ strokeDashoffset: target }}
          transition={{ duration: reduce ? 0 : 1.1, ease: [0.16, 1, 0.3, 1], delay: reduce ? 0 : delay }}
          style={{ filter: 'drop-shadow(0 2px 5px rgba(0,0,0,0.10))' }}
        />
      </svg>
      <div className='absolute inset-0 flex flex-col items-center justify-center px-3 text-center pointer-events-none'>{children}</div>
    </div>
  );
}

/** نقطة وسم صغيرة ملوّنة للأساطير. */
function LegendDot({ color }: { color: string }) {
  return <span className='inline-block h-2.5 w-2.5 rounded-full' style={{ backgroundColor: color }} aria-hidden='true' />;
}

/** عنوان قسم هادئ: شريط رفيع + عنوان صغير + تلميح على اليسار. يرتّب القراءة دون ازدحام. */
function SectionLabel({ title, hint }: { title: string; hint?: string }) {
  return (
    <div className='mb-4 flex flex-wrap items-baseline justify-between gap-2 pb-2.5 border-b' style={{ borderColor: C.hair }}>
      <h2 className='inline-flex items-center gap-2.5 text-[16px] font-bold tracking-tight' style={{ color: C.ink }}>
        <span className='inline-block h-4.5 w-1.5 rounded-full' style={{ backgroundColor: C.forest }} aria-hidden='true' />
        {title}
      </h2>
      {hint && (
        <span className='text-xs font-semibold' style={{ color: C.muted }}>
          {hint}
        </span>
      )}
    </div>
  );
}

/** نافذة تفصيل تحصيل الديون السابقة: جدول ديون التلاميذ. */
function PriorDebtDetailModal({
  summary,
  onClose,
}: {
  summary: PriorDebtSummary;
  onClose: () => void;
}) {
  const students = summary.student_details.filter((row) => row.original_amount > 0);

  return (
    <motion.div
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      transition={{ duration: 0.18 }}
      className='fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm'
      style={{ backgroundColor: 'rgba(31,38,28,0.45)' }}
      dir='rtl'
    >
      <motion.div
        initial={{ opacity: 0, scale: 0.96, y: 10 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        transition={{ duration: 0.22, ease: [0.16, 1, 0.3, 1] }}
        role='dialog'
        aria-modal='true'
        aria-labelledby='prior-debt-detail-title'
        className='max-h-[85vh] w-full max-w-3xl overflow-y-auto rounded-3xl bg-white p-6 md:p-8'
      >
        <div className='mb-6 flex items-center justify-between gap-3'>
          <h3 id='prior-debt-detail-title' className='text-lg font-bold' style={{ color: C.ink }}>
            تحصيل الديون السابقة — التفصيل
          </h3>
          <button
            type='button'
            onClick={onClose}
            aria-label='إغلاق نافذة التفصيل'
            className={`inline-flex h-9 w-9 items-center justify-center rounded-xl transition-colors hover:bg-[#F7F9F4] ${FOCUS}`}
          >
            <X size={18} color={C.muted} />
          </button>
        </div>

        {/* ديون التلاميذ */}
        <div className='overflow-x-auto rounded-2xl' style={{ border: `1px solid ${C.hair}` }}>
          <table className='w-full text-sm'>
            <thead>
              <tr style={{ backgroundColor: C.sage, color: C.deep }}>
                <th className='px-3 py-2.5 text-right font-medium'>الاسم</th>
                <th className='px-3 py-2.5 text-right font-medium'>المبلغ الأصلي</th>
                <th className='px-3 py-2.5 text-right font-medium'>المحصّل</th>
                <th className='px-3 py-2.5 text-right font-medium'>المتبقي</th>
              </tr>
            </thead>
            <tbody>
              {students.length === 0 ? (
                <tr>
                  <td colSpan={4} className='px-3 py-6 text-center' style={{ color: C.muted }}>
                    لا توجد سجلات
                  </td>
                </tr>
              ) : (
                students.map((row) => (
                  <tr key={row.id} style={{ borderTop: `1px solid ${C.hair}` }}>
                    <td className='px-3 py-2.5' style={{ color: C.ink }}>{row.student_name}</td>
                    <td className='px-3 py-2.5' style={{ color: C.ink }}><bdi dir='ltr' style={NUM}>{dinar(row.original_amount)}</bdi></td>
                    <td className='px-3 py-2.5' style={{ color: C.collected }}><bdi dir='ltr' style={NUM}>{dinar(row.paid_amount)}</bdi></td>
                    <td className='px-3 py-2.5 font-medium' style={{ color: C.remaining }}><bdi dir='ltr' style={NUM}>{dinar(row.outstanding_amount)}</bdi></td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </motion.div>
    </motion.div>
  );
}

/** ساعة المؤسسة — زخرفة مخفيّة عن قارئ الشاشة (الوقت متاح للنظام أصلاً). */
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
    <svg width={size} height={size} viewBox='0 0 200 200' aria-hidden='true' focusable='false'>
      <circle cx={cx} cy={cy} r={98} fill={C.deep} />
      <circle cx={cx} cy={cy} r={92} fill='#FDFDFB' />
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
            strokeLinecap='round'
            transform={`rotate(${i * 6} ${cx} ${cy})`}
          />
        );
      })}
      <line x1={cx} y1={cy + 14} x2={cx} y2={cy - 46} stroke={C.deep} strokeWidth={7} strokeLinecap='round' transform={`rotate(${hrDeg} ${cx} ${cy})`} />
      <line x1={cx} y1={cy + 18} x2={cx} y2={cy - 70} stroke={C.deep} strokeWidth={4.5} strokeLinecap='round' transform={`rotate(${minDeg} ${cx} ${cy})`} />
      <g transform={`rotate(${secDeg} ${cx} ${cy})`}>
        <line x1={cx} y1={cy + 24} x2={cx} y2={cy - 78} stroke='#B5493F' strokeWidth={1.8} strokeLinecap='round' />
        <circle cx={cx} cy={cy + 24} r={4} fill='#B5493F' />
      </g>
      <circle cx={cx} cy={cy} r={6.5} fill={C.deep} />
      <circle cx={cx} cy={cy} r={2.4} fill='#FDFDFB' />
    </svg>
  );
}

/**
 * دخول متدرّج لطيف لكروت المؤشّرات. تجميل بصري بحت — لا يمسّ أيّ قيمة أو تسمية،
 * ويُحترم تفضيل تقليل الحركة عبر MotionConfig reducedMotion="user" في App.
 */
const gridStagger: Variants = {
  hidden: {},
  show: { transition: { staggerChildren: 0.06 } },
};
const cardRise: Variants = {
  hidden: { opacity: 0, y: 12 },
  show: { opacity: 1, y: 0, transition: { duration: 0.3, ease: [0.16, 1, 0.3, 1] } },
};

/**
 * بطاقة مؤشّر بخلفية متدرجة غنية — سطح ملوّن بدل الأبيض، كلّ بطاقة بلونها الدلاليّ.
 */
function StatCard({
  label,
  value,
  icon: Icon,
  chipBg,
  chipColor,
  hint,
  gradFrom,
  gradTo,
}: {
  label: string;
  value: ReactNode;
  icon: LucideIcon;
  chipBg: string;
  chipColor: string;
  valueColor?: string;
  hint?: string;
  gradFrom?: string;
  gradTo?: string;
}) {
  const from = gradFrom ?? chipColor;
  const to   = gradTo   ?? chipBg;
  return (
    <motion.div
      variants={cardRise}
      className='card-interactive group relative flex flex-col justify-between rounded-3xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl overflow-hidden'
      style={{
        background: `linear-gradient(135deg, ${from} 0%, ${to} 100%)`,
        boxShadow: `0 8px 32px -8px ${from}66`,
      }}
    >
      {/* طبقة زجاجية علوية */}
      <div
        className='pointer-events-none absolute inset-0'
        style={{ background: 'linear-gradient(180deg,rgba(255,255,255,0.14) 0%,rgba(255,255,255,0.02) 60%)' }}
      />
      {/* وهج دائري خلفي */}
      <div
        className='pointer-events-none absolute -top-10 -right-10 h-36 w-36 rounded-full'
        style={{ background: 'rgba(255,255,255,0.12)', filter: 'blur(28px)' }}
      />
      <div className='relative'>
        <div className='flex items-center justify-between gap-3'>
          <p className='text-[13px] font-bold tracking-tight text-white/85'>
            {label}
          </p>
          <span
            className='inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl shadow-md transition-transform duration-300 group-hover:scale-110'
            style={{ backgroundColor: 'rgba(255,255,255,0.22)', color: '#fff' }}
            aria-hidden='true'
          >
            <Icon size={19} />
          </span>
        </div>
        <p
          className='mt-4 text-[32px] md:text-[34px] leading-none font-extrabold tracking-tight text-white'
          style={{ fontFamily: 'var(--font-display)', ...NUM }}
        >
          {value}
        </p>
      </div>
      {hint && (
        <p className='relative mt-4 text-xs font-medium leading-relaxed text-white/60'>
          {hint}
        </p>
      )}
    </motion.div>
  );
}


/** بطاقة ثانوية: سطح رماديّ ناعم مسطّح — أقلّ وزناً بصريّاً من البطاقات الأساسية. */
function MiniStat({
  label,
  value,
  icon: Icon,
  hint,
}: {
  label: string;
  value: ReactNode;
  icon: LucideIcon;
  hint?: string;
}) {
  return (
    <motion.div variants={cardRise} className='rounded-3xl border p-6' style={{ backgroundColor: C.soft, borderColor: C.hair }}>
      <div className='flex items-center gap-2'>
        <Icon size={16} style={{ color: C.muted }} aria-hidden='true' />
        <p className='text-[13px] font-bold' style={{ color: C.muted }}>
          {label}
        </p>
      </div>
      <p className='mt-2.5 text-[24px] leading-none font-extrabold' style={{ color: C.ink, fontFamily: 'var(--font-display)', ...NUM }}>
        {value}
      </p>
      {hint && (
        <p className='mt-2 text-xs' style={{ color: C.muted }}>
          {hint}
        </p>
      )}
    </motion.div>
  );
}

/**
 * بطاقة البطل: نسبة الترسيم من مجموع التلاميذ في دائرة.
 * القوس الذهبي = المُرسَّمون الجدد هذا العام، والوسط = إجمالي التلاميذ ثمّ النسبة.
 * كلّها أرقام الخادم عينها (total_active_students / new_students_this_year)،
 * وعند مقام صفر تُعرض 0.0٪ بأمان دون أي قسمة.
 */
function EnrollmentDonutCard({
  total,
  paid,
  unpaid,
  yearName,
}: {
  total: number;
  paid: number;
  unpaid: number;
  yearName: string;
}) {
  const safePaid = Math.max(0, Math.min(paid, total));
  const safeUnpaid = Math.max(0, total - safePaid);
  const progress = total > 0 ? safePaid / total : 0;
  const pct = total > 0 ? (safePaid / total) * 100 : 0;

  return (
    <motion.div
      variants={cardRise}
      className='card-interactive relative flex h-full flex-col justify-between rounded-3xl p-6 md:p-8 transition-all duration-300 hover:shadow-2xl overflow-hidden'
      style={{
        background: 'linear-gradient(135deg, #1B4332 0%, #065F46 60%, #047857 100%)',
        boxShadow: '0 12px 40px -8px rgba(27,67,50,0.55)',
      }}
    >
      {/* طبقة زجاجية علوية */}
      <div className='pointer-events-none absolute inset-0'
        style={{ background: 'linear-gradient(180deg,rgba(255,255,255,0.12) 0%,rgba(255,255,255,0.01) 50%)' }} />
      {/* وهج دائري خلفي */}
      <div className='pointer-events-none absolute -top-12 -left-12 h-48 w-48 rounded-full'
        style={{ background: 'rgba(253,230,138,0.1)', filter: 'blur(40px)' }} />

      <div className='relative flex items-center justify-between gap-3'>
        <div className='flex items-center gap-3'>
          <span
            className='inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl shadow-md'
            style={{ backgroundColor: 'rgba(253,230,138,0.25)', color: '#FDE68A' }}
            aria-hidden='true'
          >
            <GraduationCap size={20} />
          </span>
          <div>
            <h2 className='text-[16px] font-bold tracking-tight text-white'>
              نسبة الترسيم
            </h2>
            <p className='mt-0.5 text-xs font-semibold text-white/60'>
              السنة النشطة: {yearName}
            </p>
          </div>
        </div>
        <span className='inline-flex items-center px-3 py-1 rounded-full text-xs font-bold'
          style={{ backgroundColor: 'rgba(253,230,138,0.22)', color: '#FDE68A' }}>
          {pct.toFixed(1)}٪
        </span>
      </div>

      <div className='relative my-6 flex flex-1 items-center justify-center'>
        <RatioDonut
          size={210}
          stroke={18}
          progress={progress}
          track='rgba(255,255,255,0.12)'
          color='#F59E0B'
          label={`نسبة التلاميذ الذين دفعوا الترسيم ${pct.toFixed(1)} بالمئة من إجمالي ${total} تلميذاً`}
        >
          <span
            className='text-[38px] leading-none font-extrabold tracking-tight text-white'
            style={{ fontFamily: 'var(--font-display)', ...NUM }}
          >
            <AnimatedInt value={total} />
          </span>
          <span className='mt-2 text-xs font-semibold text-white/60'>
            إجمالي التلاميذ
          </span>
          <span className='mt-2 text-xs font-extrabold px-2.5 py-0.5 rounded-full'
            style={{ backgroundColor: 'rgba(253,230,138,0.22)', color: '#FDE68A', ...NUM }}>
            {pct.toFixed(1)}٪ تم الترسيم
          </span>
        </RatioDonut>
      </div>

      <div className='relative grid grid-cols-2 gap-3.5'>
        <div className='rounded-2xl p-4 transition-transform hover:scale-[1.02]'
          style={{ backgroundColor: 'rgba(253,230,138,0.15)', border: '1px solid rgba(253,230,138,0.25)' }}>
          <p className='flex items-center gap-2 text-xs font-bold' style={{ color: '#FDE68A' }}>
            <LegendDot color='#F59E0B' /> دَفعوا الترسيم
          </p>
          <p className='mt-2 text-[22px] leading-none font-extrabold text-white' style={{ ...NUM }}>
            {safePaid}
          </p>
        </div>
        <div className='rounded-2xl p-4 transition-transform hover:scale-[1.02]'
          style={{ backgroundColor: 'rgba(255,255,255,0.08)', border: '1px solid rgba(255,255,255,0.15)' }}>
          <p className='flex items-center gap-2 text-xs font-bold text-white/70'>
            <LegendDot color='rgba(255,255,255,0.5)' /> لم يدفعوا بعد
          </p>
          <p className='mt-2 text-[22px] leading-none font-extrabold text-white/85' style={{ ...NUM }}>
            {safeUnpaid}
          </p>
        </div>
      </div>
    </motion.div>
  );
}

/**
 * لوحة تحصيل الديون السابقة: حلقة نسبة التحصيل (محصّل مقابل متبقّي)، وأمامها المجموع
 * والمبلغان وزرّ التفصيل. أرقام الخادم عينها (total_collected / total_remaining)،
 * والتفصيل يبقى مفصولاً في جدولين: ديون التلاميذ وديون الإطارات — لا خلط بينهما.
 */
function PriorDebtPanel({
  summary,
  onOpenDetail,
}: {
  summary: PriorDebtSummary;
  onOpenDetail: () => void;
}) {
  const collected = Number(summary.total_collected ?? 0);
  const remaining = Number(summary.total_remaining ?? 0);
  const total = collected + remaining;
  const progress = total > 0 ? collected / total : 0;
  const pct = total > 0 ? (collected / total) * 100 : 0;

  return (
    <section
      className='relative rounded-3xl p-6 md:p-8 overflow-hidden shadow-xl border'
      style={{
        background: 'linear-gradient(135deg, #FAF8F5 0%, #F5EFE4 50%, #ECE4D0 100%)',
        borderColor: '#E6DCB8',
        boxShadow: '0 8px 32px -8px rgba(138, 106, 30, 0.12)',
      }}
      aria-labelledby='prior-debt-title'
    >
      <div
        className='pointer-events-none absolute -top-10 -right-10 h-40 w-40 rounded-full'
        style={{ background: 'rgba(200, 155, 60, 0.12)', filter: 'blur(30px)' }}
      />
      <div className='relative mb-6 flex items-center gap-3'>
        <span
          className='inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl shadow-sm'
          style={{ backgroundColor: '#E3EFE4', color: '#15803D' }}
          aria-hidden='true'
        >
          <History size={19} />
        </span>
        <div>
          <h2 id='prior-debt-title' className='text-[16px] font-bold tracking-tight' style={{ color: C.ink }}>
            تحصيل الديون السابقة
          </h2>
          <p className='text-xs font-semibold' style={{ color: C.muted }}>
            سجل متابعة المستحقات القديمة
          </p>
        </div>
      </div>

      <div className='relative grid grid-cols-1 items-center gap-6 md:grid-cols-[auto_1fr] md:gap-8'>
        {/* حلقة النسبة */}
        <div className='flex justify-center'>
          <RatioDonut
            size={140}
            stroke={13}
            progress={progress}
            track='rgba(21, 128, 61, 0.15)'
            color='#15803D'
            delay={0.1}
            label={`نسبة تحصيل الديون السابقة ${pct.toFixed(1)} بالمئة`}
          >
            <span className='text-[26px] leading-none font-extrabold text-[#15803D]' style={{ fontFamily: 'var(--font-display)', ...NUM }}>
              {pct.toFixed(1)}٪
            </span>
            <span className='mt-1.5 text-xs font-bold text-[#15803D]/80'>
              نسبة التحصيل
            </span>
          </RatioDonut>
        </div>

        {/* المجموع والمبلغان والتفصيل */}
        <div>
          <p className='text-[13px] font-medium' style={{ color: C.muted }}>
            مجموع الديون السابقة
          </p>
          <p className='mt-2 text-[28px] leading-none font-extrabold' style={{ color: C.ink, fontFamily: 'var(--font-display)', ...NUM }}>
            <bdi dir='ltr'>{dinar(total)}</bdi>
          </p>

          <div className='mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2'>
            <div className='rounded-2xl p-4 border transition-transform hover:scale-[1.02]' style={{ background: 'linear-gradient(135deg, #EFFBF2 0%, #DCFCE7 100%)', borderColor: '#BBF7D0' }}>
              <p className='text-xs font-bold' style={{ color: '#166534' }}>
                المحصّل
              </p>
              <p className='mt-1.5 text-xl font-extrabold' style={{ color: '#15803D' }}>
                <bdi dir='ltr' style={NUM}>{dinar(collected)}</bdi>
              </p>
            </div>
            <div className='rounded-2xl p-4 border transition-transform hover:scale-[1.02]' style={{ background: 'linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%)', borderColor: '#FDE68A' }}>
              <p className='text-xs font-bold' style={{ color: '#92400E' }}>
                المتبقّي
              </p>
              <p className='mt-1.5 text-xl font-extrabold' style={{ color: '#B45309' }}>
                <bdi dir='ltr' style={NUM}>{dinar(remaining)}</bdi>
              </p>
            </div>
          </div>

          <button
            type='button'
            onClick={onOpenDetail}
            aria-label='عرض تفصيل تحصيل الديون السابقة'
            className={`mt-6 rounded-2xl px-5 py-2.5 text-sm font-bold text-white shadow-md transition-all hover:shadow-lg hover:brightness-105 active:scale-95 ${FOCUS}`}
            style={{ background: 'linear-gradient(135deg, #1B4332 0%, #2D6A4F 100%)' }}
          >
            عرض التفصيل
          </button>

          <p className='mt-4 text-xs leading-relaxed' style={{ color: C.muted }}>
            التفصيل مفصول في جدولين: ديون التلاميذ وديون الإطارات، كلٌّ في جدوله.
          </p>
        </div>
      </div>
    </section>
  );
}

/**
 * لوحة صاحبة المدرسة.
 *
 * كروت الصندوق تُقرأ من الدفتر النقدي المركزي لا من جداول الدفعات، فهي نفس
 * الأرقام التي تظهر في الخزينة والدخل الصافي حرفيّاً. وهي مستقلّة عن السنة الدراسية:
 * المدرسة تستخلص في كل الأشهر، وما يُدفع في أوت عن متخلَّد جوان هو دخل يوم أوت.
 *
 * أمّا كروت التلاميذ فتخصّ السنة الدراسية النشطة، واسمها معروض تحتها صراحة
 * حتّى لا تُقرأ أرقام سنة على أنّها أرقام سنة أخرى.
 */
export default function Dashboard() {
  const { user } = useAuth();
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [priorDebtDetailOpen, setPriorDebtDetailOpen] = useState(false);

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

  const totalActive = data ? (data.total_active_students ?? data.total_students ?? 0) : 0;
  const paidReg = data ? (Number((data as any).paid_registration_count) || 0) : 0;
  const unpaidReg = data ? (Number((data as any).unpaid_registration_count) || Math.max(0, totalActive - paidReg)) : 0;
  const newcomers = data ? (data.new_students_this_year ?? 0) : 0;
  const males = data ? (data.male_students_count ?? data.total_males ?? 0) : 0;
  const females = data ? (data.female_students_count ?? data.total_females ?? 0) : 0;
  const unspecified = data ? (data.unknown_gender_count ?? data.total_unspecified_gender ?? 0) : 0;

  const malePct = totalActive > 0 && males > 0 ? `(${((males / totalActive) * 100).toFixed(1)}%)` : '';
  const femalePct = totalActive > 0 && females > 0 ? `(${((females / totalActive) * 100).toFixed(1)}%)` : '';
  const unspecifiedPct = totalActive > 0 && unspecified > 0 ? `(${((unspecified / totalActive) * 100).toFixed(1)}%)` : '';
  const femalePctNum = totalActive > 0 ? (females / totalActive) * 100 : 0;
  const malePctNum = totalActive > 0 ? (males / totalActive) * 100 : 0;
  const femaleProgress = totalActive > 0 ? females / totalActive : 0;
  const maleProgress = totalActive > 0 ? males / totalActive : 0;

  // لون الدخل الصافي يتبع القيمة الفعليّة المُرسَلة (سالب/موجب/صفر) — لا قيمة مُختلقة.
  const netToday = Number(today?.net_income ?? 0);
  const netColor = netToday < 0 ? C.error : netToday > 0 ? C.collected : C.ink;

  // حسابات المقارنة الدائرية للشهر الجاري (مداخيل بالأزرق ومصاريف بالأحمر)
  const monthIncome = Number(month?.income ?? 0);
  const monthExpenses = Number(month?.expenses ?? 0);
  const totalFlow = monthIncome + monthExpenses;
  const incomePct = totalFlow > 0 ? (monthIncome / totalFlow) * 100 : 0;
  const expensePct = totalFlow > 0 ? (monthExpenses / totalFlow) * 100 : 0;
  const netMonth = Number(month?.net_income ?? 0);
  const netMonthColor = netMonth < 0 ? C.error : netMonth > 0 ? C.collected : C.ink;
  const netMonthColorBg = netMonth < 0 ? C.errorBg : netMonth > 0 ? C.collectedSoft : C.soft;

  const hour = new Date().getHours();
  const isMorning = hour < 12;
  const greetName = user?.first_name && !['مدير', 'النظام', 'Admin', 'admin'].includes(user.first_name) ? `، ${user.first_name}` : '';

  return (
    <div className='p-6 md:p-8' dir='rtl'>
      {/* الرأس: تحية واضحة + ساعة المؤسسة بقياسها الكامل والأصلي في أعلى اليسار */}
      <header className='mb-8 flex flex-wrap items-center justify-between gap-6'>
        <div className='flex items-center gap-4'>
          <span
            className='inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-3xl shadow-sm'
            style={{ backgroundColor: isMorning ? C.sage : C.beige, color: isMorning ? C.forest : C.deep }}
            aria-hidden='true'
          >
            {isMorning ? <Coffee size={26} /> : <Moon size={26} />}
          </span>
          <div className='min-w-0'>
            <h1 className='truncate text-2xl md:text-3xl leading-tight font-extrabold tracking-tight' style={{ color: C.ink, fontFamily: 'var(--font-display)' }}>
              {isMorning ? 'صباح الخير' : 'مساء الخير'}
              {greetName}
            </h1>
            <p className='mt-1 text-sm font-semibold' style={{ color: C.muted }}>
              جرد اليوم {data?.current_date ?? ''}
            </p>
          </div>
        </div>

        {/* ساعة المؤسسة — أعلى اليسار بالقياس الكامل الأصلي */}
        <div
          className='flex items-center gap-4 px-5 py-3 rounded-3xl border shadow-sm transition-all hover:shadow-md'
          style={{
            background: 'linear-gradient(135deg, #FAFBF9 0%, #EFF4EC 100%)',
            borderColor: '#DCE4D6',
          }}
        >
          <div className='text-right'>
            <p className='text-xs font-bold leading-tight' style={{ color: C.ink }}>
              توقيت المؤسسة
            </p>
            <p className='mt-0.5 text-[11px] font-semibold' style={{ color: C.muted }}>
              مدرسة العناية
            </p>
          </div>
          <AnalogClock size={132} />
        </div>
      </header>

      {error && (
        <div
          role='alert'
          className='mb-6 flex items-start gap-2 rounded-2xl p-4 text-sm'
          style={{ backgroundColor: C.errorBg, color: C.error }}
        >
          <AlertCircle size={18} className='mt-0.5 shrink-0' aria-hidden='true' />
          <span>{error}</span>
        </div>
      )}

      {loading && <PageDataSkeleton cards={4} rows={4} />}

      {!loading && data && (
        <div className='space-y-8'>
          {/* صف البطل: دائرة نسبة الترسيم (بيانات التلاميذ ليست محجوبة، فتظهر للجميع)
              + الكروت المالية. الكتلة المالية تُعرض فقط حين يُرجع الخادم مفتاح cash
              (manage_treasury/view_reports)؛ القابض لا يستلمه فتُخفى بلا أصفار مضلّلة. */}
          <motion.section
            variants={gridStagger}
            initial='hidden'
            animate='show'
            className={`grid grid-cols-1 gap-6 ${data.cash ? 'lg:grid-cols-3' : ''}`}
          >
            <div className={data.cash ? '' : 'max-w-md'}>
              <EnrollmentDonutCard total={totalActive} paid={paidReg} unpaid={unpaidReg} yearName={yearName} />
            </div>

            {data.cash && (
              <div className='lg:col-span-2'>
                <SectionLabel title='المؤشّرات المالية' hint='أرقام الدفتر النقدي المركزي' />
                <div className='grid grid-cols-1 gap-4 sm:grid-cols-2'>
                  <StatCard
                    label='مداخيل اليوم'
                    value={<AnimatedMoney value={today?.income} />}
                    icon={TrendingUp}
                    chipBg={C.sage}
                    chipColor={C.forest}
                    gradFrom='#064E3B'
                    gradTo='#059669'
                    hint='ما قُبض فعليّاً اليوم'
                  />
                  <StatCard
                    label='مصاريف اليوم'
                    value={<AnimatedMoney value={today?.expenses} />}
                    icon={TrendingDown}
                    chipBg={C.rose}
                    chipColor={C.expense}
                    gradFrom='#7F1D1D'
                    gradTo='#DC2626'
                    hint='ما خرج من الصندوق اليوم'
                  />
                  <StatCard
                    label='الدخل الصافي اليوم'
                    value={<AnimatedMoney value={today?.net_income} />}
                    icon={ArrowDownCircle}
                    chipBg={C.soft}
                    chipColor={C.forest}
                    gradFrom='#1E1B4B'
                    gradTo='#4F46E5'
                    hint='المداخيل ناقص المصاريف'
                  />
                  <StatCard
                    label='رصيد الخزينة'
                    value={<AnimatedMoney value={data.treasury_balance} />}
                    icon={Landmark}
                    chipBg={C.goldSoft}
                    chipColor={C.goldDeep}
                    gradFrom='#78350F'
                    gradTo='#D97706'
                    hint='من بداية السجلّ بعد السحوبات'
                  />
                </div>
              </div>
            )}
          </motion.section>

          {/* التلاميذ: الإجمالي والمتخلَّد (ومداخيل النوادي إن وُجدت) بوزن أساسيّ،
              وتوزيع الجنس بوزن ثانويّ. */}
          <section>
            <SectionLabel title='التلاميذ' hint={`السنة النشطة: ${yearName}`} />

            <motion.div
              variants={gridStagger}
              initial='hidden'
              animate='show'
              className={`grid grid-cols-1 gap-4 sm:grid-cols-2 ${data.club_revenue ? 'lg:grid-cols-3' : 'lg:grid-cols-2'}`}
            >
              <StatCard
                label='إجمالي التلاميذ'
                value={<AnimatedInt value={totalActive} />}
                icon={GraduationCap}
                chipBg={C.sage}
                chipColor={C.forest}
                gradFrom='#1B4332'
                gradTo='#2D6A4F'
                hint={`السنة النشطة: ${yearName}`}
              />
              <StatCard
                label='المتخلَّد'
                value={<AnimatedMoney value={data.outstanding_balance} />}
                icon={AlertCircle}
                chipBg={C.remainingSoft}
                chipColor={C.remaining}
                gradFrom='#7C2D12'
                gradTo='#C2410C'
                hint={`معاليم غير مدفوعة — السنة النشطة: ${yearName}`}
              />
              {data.club_revenue && (
                <StatCard
                  label='مداخيل النوادي هذا الشهر'
                  value={<AnimatedMoney value={data.club_revenue.collected_amount} />}
                  icon={Award}
                  chipBg={C.sage}
                  chipColor={C.forest}
                  gradFrom='#134E4A'
                  gradTo='#0F766E'
                  hint={`خلاص كامل: ${data.club_revenue.paid_students_count} | في انتظار الدفع: ${data.club_revenue.pending_students_count}`}
                />
              )}
            </motion.div>

            {/* بطاقة التوزيع الديمغرافي للجنس — تصميم متدرج فاخر */}
            <motion.div
              variants={cardRise}
              className='mt-6 rounded-3xl border p-6 md:p-8 shadow-card overflow-hidden'
              style={{
                background: 'linear-gradient(135deg, #FAFBF8 0%, #F3F6EF 100%)',
                borderColor: '#DEE6D8',
              }}
            >
              <div className='flex flex-wrap items-center justify-between gap-4 mb-6 pb-3 border-b' style={{ borderColor: '#E2EADF' }}>
                <div className='flex items-center gap-3'>
                  <span
                    className='inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl shadow-sm'
                    style={{ backgroundColor: C.sage, color: C.forest }}
                    aria-hidden='true'
                  >
                    <Users size={19} />
                  </span>
                  <div>
                    <h3 className='text-sm font-bold' style={{ color: C.ink }}>
                      توزيع التلاميذ حسب الجنس
                    </h3>
                    <p className='text-xs font-semibold' style={{ color: C.muted }}>
                      إجمالي {totalActive} تلميذاً مسجلاً
                    </p>
                  </div>
                </div>

                {/* شريط المقارنة التناسبي المصغر */}
                <div className='flex items-center gap-3 min-w-[200px] flex-1 max-w-xs'>
                  <div className='w-full h-3 rounded-full bg-white flex overflow-hidden p-0.5 border border-slate-200 shadow-inner'>
                    <div
                      className='h-full rounded-full transition-all duration-700'
                      style={{ width: `${femalePctNum}%`, backgroundColor: '#E11D48' }}
                      title={`إناث: ${femalePctNum.toFixed(1)}%`}
                    />
                    <div
                      className='h-full rounded-full transition-all duration-700 ml-0.5'
                      style={{ width: `${malePctNum}%`, backgroundColor: '#2563EB' }}
                      title={`ذكور: ${malePctNum.toFixed(1)}%`}
                    />
                  </div>
                </div>
              </div>

              <div className={`grid grid-cols-1 gap-6 ${unspecified > 0 ? 'md:grid-cols-3' : 'md:grid-cols-2'}`}>
                {/* بطاقة الإناث — مع صورة التلميذة بالميدعة المدرسية */}
                <div
                  className='flex flex-col sm:flex-row items-center justify-between gap-6 p-6 rounded-3xl border transition-all duration-300 hover:shadow-lg hover:-translate-y-0.5'
                  style={{
                    background: 'linear-gradient(135deg, #FFF1F2 0%, #FFE4E6 50%, #FECDD3 100%)',
                    borderColor: '#FDA4AF',
                    boxShadow: '0 4px 20px -4px rgba(225,29,72,0.12)',
                  }}
                >
                  <div className='flex items-center gap-4'>
                    <div className='relative shrink-0'>
                      <img
                        src={schoolgirlAvatar}
                        alt='تلميذة بميدعة مدرسية'
                        className='w-20 h-20 rounded-2xl object-cover shadow-md border-2 border-white ring-2 ring-[#E11D48]/30'
                      />
                      <span className='absolute -bottom-1.5 -right-1.5 w-6 h-6 rounded-full bg-[#E11D48] text-white flex items-center justify-center text-xs font-black shadow-sm'>
                        ♀
                      </span>
                    </div>
                    <div>
                      <p className='text-xs font-bold' style={{ color: '#9F1239' }}>
                        عدد الإناث
                      </p>
                      <p className='mt-1 text-3xl font-extrabold tracking-tight' style={{ color: '#E11D48', ...NUM }}>
                        <AnimatedInt value={females} /> <span className='text-xs font-semibold'>تلميذة</span>
                      </p>
                    </div>
                  </div>

                  <RatioDonut
                    size={100}
                    stroke={10}
                    progress={femaleProgress}
                    track='rgba(225,29,72,0.15)'
                    color='#E11D48'
                    label={`نسبة الإناث ${femalePctNum.toFixed(1)}%`}
                  >
                    <span className='text-lg font-black' style={{ color: '#E11D48', ...NUM }}>
                      {femalePctNum.toFixed(1)}٪
                    </span>
                  </RatioDonut>
                </div>

                {/* بطاقة الذكور — مع صورة التلميذ بالميدعة المدرسية */}
                <div
                  className='flex flex-col sm:flex-row items-center justify-between gap-6 p-6 rounded-3xl border transition-all duration-300 hover:shadow-lg hover:-translate-y-0.5'
                  style={{
                    background: 'linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 50%, #BFDBFE 100%)',
                    borderColor: '#93C5FD',
                    boxShadow: '0 4px 20px -4px rgba(37,99,235,0.12)',
                  }}
                >
                  <div className='flex items-center gap-4'>
                    <div className='relative shrink-0'>
                      <img
                        src={schoolboyAvatar}
                        alt='تلميذ بميدعة مدرسية'
                        className='w-20 h-20 rounded-2xl object-cover shadow-md border-2 border-white ring-2 ring-[#2563EB]/30'
                      />
                      <span className='absolute -bottom-1.5 -right-1.5 w-6 h-6 rounded-full bg-[#2563EB] text-white flex items-center justify-center text-xs font-black shadow-sm'>
                        ♂
                      </span>
                    </div>
                    <div>
                      <p className='text-xs font-bold' style={{ color: '#1E40AF' }}>
                        عدد الذكور
                      </p>
                      <p className='mt-1 text-3xl font-extrabold tracking-tight' style={{ color: '#2563EB', ...NUM }}>
                        <AnimatedInt value={males} /> <span className='text-xs font-semibold'>تلميذ</span>
                      </p>
                    </div>
                  </div>

                  <RatioDonut
                    size={100}
                    stroke={10}
                    progress={maleProgress}
                    track='rgba(37,99,235,0.15)'
                    color='#2563EB'
                    label={`نسبة الذكور ${malePctNum.toFixed(1)}%`}
                  >
                    <span className='text-lg font-black' style={{ color: '#2563EB', ...NUM }}>
                      {malePctNum.toFixed(1)}٪
                    </span>
                  </RatioDonut>
                </div>

                {/* غير محدد (إذا وُجد) */}
                {unspecified > 0 && (
                  <div
                    className='flex flex-col items-center justify-center p-6 rounded-2xl border'
                    style={{
                      background: 'linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%)',
                      borderColor: '#FDE68A',
                    }}
                  >
                    <span className='inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-100 text-amber-800 border border-amber-300'>
                      <UserRound size={22} />
                    </span>
                    <p className='mt-4 text-xs font-bold' style={{ color: C.ink }}>
                      غير محدّد الجنس
                    </p>
                    <p className='mt-1 text-2xl font-extrabold' style={{ color: C.remaining, ...NUM }}>
                      <AnimatedInt value={unspecified} /> <span className='text-xs font-semibold'>تلميذ</span>
                    </p>
                    <p className='mt-2 text-xs font-medium text-center' style={{ color: C.muted }}>
                      {unspecifiedPct} لم يُسجَّل جنسهم بعد
                    </p>
                  </div>
                )}
              </div>
            </motion.div>
          </section>

          {/* تحصيل الديون السابقة — تظهر لمن يملك رؤية الماليّة فقط */}
          {data.prior_debt_summary && (
            <PriorDebtPanel
              summary={data.prior_debt_summary}
              onOpenDetail={() => setPriorDebtDetailOpen(true)}
            />
          )}

          {/* متابعة الشهر الجاري — مقارنة دائرية بين المداخيل (بالأزرق) والمصاريف (بالأحمر) مع الأرقام تحتهما */}
          {data.cash && (
            <section>
              <SectionLabel title='متابعة الشهر' hint='مقارنة المداخيل والمصاريف للشهر الجاري' />
              <div
                className='rounded-3xl border p-6 md:p-8 shadow-card'
                style={{
                  background: 'linear-gradient(135deg, #FAFBF8 0%, #F4F7F0 100%)',
                  borderColor: '#DEE6D8',
                }}
              >
                <div className='grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 items-center'>
                  {/* دائرة المداخيل — أزرق */}
                  <div
                    className='flex flex-col items-center justify-center p-5 rounded-2xl border transition-all duration-300 hover:shadow-md hover:-translate-y-0.5'
                    style={{
                      background: 'linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%)',
                      borderColor: '#BFDBFE',
                      boxShadow: '0 4px 16px -4px rgba(37,99,235,0.12)',
                    }}
                  >
                    <RatioDonut
                      size={120}
                      stroke={11}
                      progress={totalFlow > 0 ? monthIncome / totalFlow : 0}
                      track='rgba(37,99,235,0.15)'
                      color='#2563EB'
                      label={`نسبة المداخيل ${incomePct.toFixed(1)}%`}
                    >
                      <span className='text-lg font-black' style={{ color: '#2563EB', ...NUM }}>
                        {incomePct.toFixed(1)}٪
                      </span>
                    </RatioDonut>
                    <p className='mt-3 text-xs font-bold' style={{ color: '#1E40AF' }}>
                      مجموع المداخيل
                    </p>
                    <p className='mt-1 text-xl font-extrabold' style={{ color: '#2563EB', ...NUM }}>
                      <AnimatedMoney value={month?.income} />
                    </p>
                  </div>

                  {/* دائرة المصاريف — أحمر */}
                  <div
                    className='flex flex-col items-center justify-center p-5 rounded-2xl border transition-all duration-300 hover:shadow-md hover:-translate-y-0.5'
                    style={{
                      background: 'linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%)',
                      borderColor: '#FECACA',
                      boxShadow: '0 4px 16px -4px rgba(220,38,38,0.12)',
                    }}
                  >
                    <RatioDonut
                      size={120}
                      stroke={11}
                      progress={totalFlow > 0 ? monthExpenses / totalFlow : 0}
                      track='rgba(220,38,38,0.15)'
                      color='#DC2626'
                      label={`نسبة المصاريف ${expensePct.toFixed(1)}%`}
                    >
                      <span className='text-lg font-black' style={{ color: '#DC2626', ...NUM }}>
                        {expensePct.toFixed(1)}٪
                      </span>
                    </RatioDonut>
                    <p className='mt-3 text-xs font-bold' style={{ color: '#991B1B' }}>
                      مجموع المصاريف
                    </p>
                    <p className='mt-1 text-xl font-extrabold' style={{ color: '#DC2626', ...NUM }}>
                      <AnimatedMoney value={month?.expenses} />
                    </p>
                  </div>

                  {/* الدخل الصافي */}
                  <div
                    className='flex flex-col items-center justify-center p-5 rounded-2xl border h-full transition-all duration-300 hover:shadow-md hover:-translate-y-0.5'
                    style={{
                      background: netMonth >= 0
                        ? 'linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%)'
                        : 'linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%)',
                      borderColor: netMonth >= 0 ? '#BBF7D0' : '#FECACA',
                      boxShadow: netMonth >= 0
                        ? '0 4px 16px -4px rgba(22,101,52,0.12)'
                        : '0 4px 16px -4px rgba(220,38,38,0.12)',
                    }}
                  >
                    <span
                      className='inline-flex h-11 w-11 items-center justify-center rounded-2xl shadow-sm'
                      style={{ backgroundColor: 'rgba(255,255,255,0.7)', color: netMonthColor }}
                    >
                      <TrendingUp size={22} />
                    </span>
                    <p className='mt-3 text-xs font-bold' style={{ color: netMonth >= 0 ? '#166534' : '#991B1B' }}>
                      الدخل الصافي
                    </p>
                    <p className='mt-1 text-xl font-extrabold' style={{ color: netMonthColor, ...NUM }}>
                      <AnimatedMoney value={month?.net_income} />
                    </p>
                    <span className='mt-2 text-[11px] font-semibold' style={{ color: netMonth >= 0 ? '#166534' : '#991B1B' }}>
                      المداخيل − المصاريف
                    </span>
                  </div>

                  {/* السحوبات */}
                  <div
                    className='flex flex-col items-center justify-center p-5 rounded-2xl border h-full transition-all duration-300 hover:shadow-md hover:-translate-y-0.5'
                    style={{
                      background: 'linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%)',
                      borderColor: '#FDE68A',
                      boxShadow: '0 4px 16px -4px rgba(180,83,9,0.12)',
                    }}
                  >
                    <span
                      className='inline-flex h-11 w-11 items-center justify-center rounded-2xl shadow-sm'
                      style={{ backgroundColor: 'rgba(255,255,255,0.7)', color: '#B45309' }}
                    >
                      <Landmark size={22} />
                    </span>
                    <p className='mt-3 text-xs font-bold' style={{ color: '#92400E' }}>
                      السحوبات
                    </p>
                    <p className='mt-1 text-xl font-extrabold' style={{ color: '#B45309', ...NUM }}>
                      <AnimatedMoney value={month?.withdrawals} />
                    </p>
                    <span className='mt-2 text-[11px] font-semibold' style={{ color: '#92400E' }}>
                      سحوبات الشهر الجاري
                    </span>
                  </div>
                </div>

                <p className='mt-6 text-xs leading-relaxed text-center' style={{ color: C.muted }}>
                  أرقام الصندوق تتبع تاريخ القبض الفعلي، لا الشهر المُستخلَص عنه.
                </p>
              </div>
            </section>
          )}

          {data.prior_debt_summary && priorDebtDetailOpen && (
            <PriorDebtDetailModal
              summary={data.prior_debt_summary}
              onClose={() => setPriorDebtDetailOpen(false)}
            />
          )}
        </div>
      )}
    </div>
  );
}
