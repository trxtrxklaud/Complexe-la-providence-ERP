import { type ReactNode, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { motion, useReducedMotion, type Variants } from 'motion/react';
import { useAuth } from '../../contexts/AuthContext';
import {
  AlertCircle,
  ArrowDownCircle,
  Award,
  Calendar,
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
  Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { fetchDashboard, type DashboardData, type PriorDebtSummary } from '../../api/dashboard';
import { errorMessage } from '../../lib/format';
import { PageDataSkeleton } from '../../components/DataSkeleton';
import schoolgirlAvatar from '../../assets/schoolgirl.jpg';
import schoolboyAvatar from '../../assets/schoolboy.jpg';
import morningCoffeeImg from '../../assets/morning_coffee.jpg';

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
    <bdi dir='ltr' className={negative ? 'text-red-300' : undefined} style={NUM}>
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
    <bdi dir='ltr' className={negative ? 'text-red-300' : undefined} style={NUM}>
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
  colorEnd,
  label,
  children,
  delay = 0,
}: {
  size: number;
  stroke: number;
  progress: number; // 0..1
  track: string;
  color: string;
  colorEnd?: string;
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
  const gradId = useRef(`donut-grad-${Math.random().toString(36).substring(2, 9)}`).current;
  const endColor = colorEnd || color;

  return (
    <div
      role='img'
      aria-label={label}
      className='relative inline-flex items-center justify-center transition-transform duration-300 hover:scale-[1.02]'
      style={{ width: size, maxWidth: '100%', aspectRatio: '1 / 1' }}
    >
      <svg viewBox={`0 0 ${size} ${size}`} width='100%' height='100%' className='block' aria-hidden='true' focusable='false'>
        <defs>
          <linearGradient id={gradId} x1='0%' y1='0%' x2='100%' y2='100%'>
            <stop offset='0%' stopColor={color} />
            <stop offset='100%' stopColor={endColor} />
          </linearGradient>
        </defs>
        {/* مضمار الخلفية الدائري */}
        <circle cx={cx} cy={cy} r={r} fill='none' stroke={track} strokeWidth={stroke} opacity={0.85} />
        {/* حلقة توجيهية داخلية رقيقة تعطي عمق المقياس الاحترافي */}
        <circle cx={cx} cy={cy} r={Math.max(1, r - stroke / 2 - 3)} fill='none' stroke={track} strokeWidth={1} strokeDasharray='3 4' opacity={0.4} />
        {/* قوس التقدم المتحرك */}
        <motion.circle
          cx={cx}
          cy={cy}
          r={r}
          fill='none'
          stroke={`url(#${gradId})`}
          strokeWidth={stroke}
          strokeLinecap='round'
          strokeDasharray={c}
          transform={`rotate(-90 ${cx} ${cy})`}
          initial={{ strokeDashoffset: reduce ? target : c }}
          animate={{ strokeDashoffset: target }}
          transition={{ duration: reduce ? 0 : 1.1, ease: [0.16, 1, 0.3, 1], delay: reduce ? 0 : delay }}
          style={{ filter: 'drop-shadow(0 2px 6px rgba(0,0,0,0.18))' }}
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

/** عنوان قسم فخم: أيقونة في كبسولة أنيقة + عنوان واضح + شارة تلميح مميزة. */
function SectionLabel({ title, hint, icon: Icon, extra }: { title: string; hint?: string; icon?: LucideIcon; extra?: ReactNode }) {
  return (
    <div className='mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/80 pb-4'>
      <div className='flex items-center gap-3'>
        <span
          className='inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#1B4332] to-[#2D6A4F] text-white shadow-xs'
        >
          {Icon ? <Icon size={18} /> : <span className='h-2 w-2 rounded-full bg-[#C2A24E]' />}
        </span>
        <h2 className='text-lg md:text-xl font-black tracking-tight text-slate-900' style={{ fontFamily: 'var(--font-display)' }}>
          {title}
        </h2>
      </div>

      <div className='flex items-center gap-3 flex-wrap'>
        {extra}
        {hint && (
          <span className='inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-bold text-slate-600 shadow-2xs'>
            <span className='h-1.5 w-1.5 rounded-full bg-emerald-500' />
            {hint}
          </span>
        )}
      </div>
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
      style={{ backgroundColor: 'rgba(15,23,42,0.45)' }}
      dir='rtl'
    >
      <motion.div
        initial={{ opacity: 0, scale: 0.96, y: 10 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        transition={{ duration: 0.22, ease: [0.16, 1, 0.3, 1] }}
        role='dialog'
        aria-modal='true'
        aria-labelledby='prior-debt-detail-title'
        className='max-h-[85vh] w-full max-w-3xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl md:p-8'
      >
        <div className='mb-6 flex items-center justify-between gap-3 border-b border-slate-100 pb-4'>
          <h3 id='prior-debt-detail-title' className='text-lg font-black text-slate-900'>
            تحصيل الديون السابقة — التفصيل
          </h3>
          <button
            type='button'
            onClick={onClose}
            aria-label='إغلاق نافذة التفصيل'
            className={`inline-flex h-9 w-9 items-center justify-center rounded-xl text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 ${FOCUS}`}
          >
            <X size={18} />
          </button>
        </div>

        {/* ديون التلاميذ */}
        <div className='overflow-x-auto rounded-xl border border-slate-200'>
          <table className='w-full text-sm'>
            <thead>
              <tr className='bg-slate-50 text-slate-700 border-b border-slate-200'>
                <th className='px-3.5 py-3 text-right font-black'>الاسم</th>
                <th className='px-3.5 py-3 text-right font-black'>المبلغ الأصلي</th>
                <th className='px-3.5 py-3 text-right font-black'>المحصّل</th>
                <th className='px-3.5 py-3 text-right font-black'>المتبقي</th>
              </tr>
            </thead>
            <tbody className='divide-y divide-slate-100'>
              {students.length === 0 ? (
                <tr>
                  <td colSpan={4} className='px-3 py-6 text-center text-slate-400'>
                    لا توجد سجلات
                  </td>
                </tr>
              ) : (
                students.map((row) => (
                  <tr key={row.id} className='hover:bg-slate-50/80 transition-colors'>
                    <td className='px-3.5 py-3 font-semibold text-slate-800'>{row.student_name}</td>
                    <td className='px-3.5 py-3 font-mono text-slate-600'><bdi dir='ltr' style={NUM}>{dinar(row.original_amount)}</bdi></td>
                    <td className='px-3.5 py-3 font-mono font-bold text-emerald-700'><bdi dir='ltr' style={NUM}>{dinar(row.paid_amount)}</bdi></td>
                    <td className='px-3.5 py-3 font-mono font-bold text-amber-700'><bdi dir='ltr' style={NUM}>{dinar(row.outstanding_amount)}</bdi></td>
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

/** ساعة المؤسسة — زخرفة بطابع مدرسي كلاسيكي (أخضر وذهب وأرقام واضحة). */
function AnalogClock({ size = 135 }: { size?: number }) {
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

  // أرقام الساعة المدرسية الكلاسيكية 1 إلى 12
  const numerals = [
    { num: '12', x: 100, y: 35 },
    { num: '1', x: 133, y: 44 },
    { num: '2', x: 157, y: 68 },
    { num: '3', x: 166, y: 100 },
    { num: '4', x: 157, y: 132 },
    { num: '5', x: 133, y: 156 },
    { num: '6', x: 100, y: 165 },
    { num: '7', x: 67, y: 156 },
    { num: '8', x: 43, y: 132 },
    { num: '9', x: 34, y: 100 },
    { num: '10', x: 43, y: 68 },
    { num: '11', x: 67, y: 44 },
  ];

  return (
    <svg width={size} height={size} viewBox='0 0 200 200' aria-hidden='true' focusable='false' className='drop-shadow-md select-none'>
      <defs>
        {/* تدرج إطار المدرسة الأخضر الملكي */}
        <linearGradient id='clockBezel' x1='0%' y1='0%' x2='100%' y2='100%'>
          <stop offset='0%' stopColor='#1B4332' />
          <stop offset='50%' stopColor='#2D6A4F' />
          <stop offset='100%' stopColor='#0F281E' />
        </linearGradient>
        {/* تدرج الطوق الذهبي الأكاديمي المصقول */}
        <linearGradient id='clockGold' x1='0%' y1='0%' x2='100%' y2='100%'>
          <stop offset='0%' stopColor='#E6CA65' />
          <stop offset='50%' stopColor='#C89B3C' />
          <stop offset='100%' stopColor='#8A6A1E' />
        </linearGradient>
      </defs>

      {/* الهيكل الخارجي المدرسي */}
      <circle cx={cx} cy={cy} r={98} fill='url(#clockBezel)' stroke='url(#clockGold)' strokeWidth={3} />
      {/* الطوق الداخلي الذهبي */}
      <circle cx={cx} cy={cy} r={88} fill='none' stroke='url(#clockGold)' strokeWidth={2} opacity={0.8} />
      {/* القرص العاجي الدافئ للساعة المدرسية */}
      <circle cx={cx} cy={cy} r={85} fill='#FFFDF8' />

      {/* علامات الدقائق الكلاسيكية */}
      {Array.from({ length: 60 }).map((_, i) => {
        const major = i % 5 === 0;
        return (
          <line
            key={i}
            x1={cx}
            y1={major ? 17 : 17}
            x2={cx}
            y2={major ? 23 : 20}
            stroke={major ? '#1B4332' : '#CBD5E1'}
            strokeWidth={major ? 2.5 : 1}
            strokeLinecap='round'
            transform={`rotate(${i * 6} ${cx} ${cy})`}
          />
        );
      })}

      {/* الأرقام المدرسية الواضحة 1 إلى 12 */}
      {numerals.map(({ num, x, y }) => (
        <text
          key={num}
          x={x}
          y={y}
          textAnchor='middle'
          dominantBaseline='central'
          fill={['12', '3', '6', '9'].includes(num) ? '#1B4332' : '#334155'}
          fontSize={['12', '3', '6', '9'].includes(num) ? '15' : '12'}
          fontWeight='900'
          fontFamily='var(--font-display), sans-serif'
        >
          {num}
        </text>
      ))}

      {/* اسم المدرسة في قرص الساعة */}
      <text
        x={cx}
        y={cy - 22}
        textAnchor='middle'
        dominantBaseline='central'
        fill='#8A6A1E'
        fontSize='7.5'
        fontWeight='800'
        letterSpacing='0.12em'
      >
        LA PROVIDENCE
      </text>

      {/* عقرب الساعات الكلاسيكي */}
      <line
        x1={cx}
        y1={cy + 10}
        x2={cx}
        y2={cy - 44}
        stroke='#1B4332'
        strokeWidth={5.5}
        strokeLinecap='round'
        transform={`rotate(${hrDeg} ${cx} ${cy})`}
      />
      {/* عقرب الدقائق */}
      <line
        x1={cx}
        y1={cy + 14}
        x2={cx}
        y2={cy - 64}
        stroke='#1B4332'
        strokeWidth={3.5}
        strokeLinecap='round'
        transform={`rotate(${minDeg} ${cx} ${cy})`}
      />
      {/* عقرب الثواني — أحمر مدرسي أنيق */}
      <g transform={`rotate(${secDeg} ${cx} ${cy})`}>
        <line x1={cx} y1={cy + 18} x2={cx} y2={cy - 72} stroke='#DC2626' strokeWidth={1.6} strokeLinecap='round' />
        <circle cx={cx} cy={cy + 18} r={3.5} fill='#DC2626' />
      </g>
      {/* سرّة المركز الذهبية */}
      <circle cx={cx} cy={cy} r={5} fill='url(#clockGold)' />
      <circle cx={cx} cy={cy} r={2} fill='#1B4332' />
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
 * بطاقة مؤشّر بخلفية متدرجة غنية — ألوان متدرجة حية وخط كبير مقروء بوضوح.
 */
function StatCard({
  label,
  value,
  icon: Icon,
  chipBg,
  chipColor,
  valueColor,
  hint,
  gradFrom,
  gradTo,
  children,
}: {
  label: string;
  value: ReactNode;
  icon: LucideIcon;
  chipBg?: string;
  chipColor?: string;
  valueColor?: string;
  hint?: string;
  gradFrom?: string;
  gradTo?: string;
  children?: ReactNode;
}) {
  const from = gradFrom || chipColor || '#1B4332';
  const to = gradTo || chipBg || '#2D6A4F';

  return (
    <motion.div
      variants={cardRise}
      className='card-interactive group relative flex flex-col justify-between overflow-hidden rounded-3xl p-6 shadow-lg transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl'
      style={{
        background: `linear-gradient(135deg, ${from} 0%, ${to} 100%)`,
        boxShadow: `0 10px 30px -6px ${from}66`,
      }}
    >
      {/* طبقة زجاجية ناعمة علوية */}
      <div
        className='pointer-events-none absolute inset-0'
        style={{ background: 'linear-gradient(180deg, rgba(255,255,255,0.16) 0%, rgba(255,255,255,0.02) 60%)' }}
      />
      {/* وهج دائري خلفي خفيف */}
      <div
        className='pointer-events-none absolute -top-10 -right-10 h-36 w-36 rounded-full'
        style={{ background: 'rgba(255,255,255,0.12)', filter: 'blur(28px)' }}
      />

      <div className='relative'>
        <div className='flex items-center justify-between gap-3'>
          <p className='text-[14px] font-black tracking-tight text-white/95'>
            {label}
          </p>
          <span
            className='inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl shadow-md transition-transform duration-300 group-hover:scale-110'
            style={{ backgroundColor: 'rgba(255,255,255,0.22)', color: '#ffffff' }}
            aria-hidden='true'
          >
            <Icon size={20} />
          </span>
        </div>

        {/* خط الأرقام: كبير جداً، أسود عريض، وواضح ومقروء بامتياز */}
        <div
          className='mt-4 text-[32px] sm:text-[36px] xl:text-[40px] leading-none font-black tracking-tight text-white'
          style={{ ...NUM, fontFamily: 'var(--font-display)', color: valueColor || '#ffffff' }}
        >
          {value}
        </div>

        {children}
      </div>

      {hint && (
        <p className='relative mt-4 text-xs font-medium leading-relaxed text-white/80 flex items-center gap-1.5 pt-3 border-t border-white/15'>
          <span className='h-1.5 w-1.5 rounded-full bg-white/60' />
          {hint}
        </p>
      )}
    </motion.div>
  );
}

/** بطاقة ثانوية: تصميم كارت ناعم وواضح. */
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
    <motion.div
      variants={cardRise}
      className='rounded-2xl border border-slate-200/90 bg-white p-5 shadow-[0_2px_12px_rgba(0,0,0,0.03)] transition-all duration-300 hover:border-slate-300 hover:shadow-md'
    >
      <div className='flex items-center justify-between gap-2'>
        <p className='text-xs font-bold text-slate-600'>{label}</p>
        <span className='inline-flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-800'>
          <Icon size={16} aria-hidden='true' />
        </span>
      </div>
      <div className='mt-2.5 text-[22px] leading-none font-black tracking-tight tabular-nums text-slate-900' style={{ ...NUM }}>
        {value}
      </div>
      {hint && (
        <p className='mt-2 text-[11px] font-medium text-slate-500'>{hint}</p>
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
      className='card-interactive relative flex h-full flex-col justify-between overflow-hidden rounded-3xl p-6 md:p-8 transition-all duration-300 hover:shadow-2xl'
      style={{
        background: 'linear-gradient(135deg, #064E3B 0%, #0F3826 40%, #1B4D36 100%)',
        boxShadow: '0 14px 40px -8px rgba(6, 78, 59, 0.55)',
      }}
    >
      {/* طبقة زجاجية علوية */}
      <div
        className='pointer-events-none absolute inset-0'
        style={{ background: 'linear-gradient(180deg, rgba(255,255,255,0.14) 0%, rgba(255,255,255,0.01) 50%)' }}
      />
      {/* وهج ذهبي خلفي */}
      <div
        className='pointer-events-none absolute -top-12 -left-12 h-48 w-48 rounded-full'
        style={{ background: 'rgba(253, 230, 138, 0.12)', filter: 'blur(40px)' }}
      />

      <div className='relative flex items-center justify-between gap-3'>
        <div className='flex items-center gap-3'>
          <span
            className='inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl shadow-md'
            style={{ backgroundColor: 'rgba(253,230,138,0.25)', color: '#FDE68A' }}
            aria-hidden='true'
          >
            <GraduationCap size={22} />
          </span>
          <div>
            <h2 className='text-[16px] font-black tracking-tight text-white'>
              نسبة الترسيم
            </h2>
            <p className='mt-0.5 text-xs font-bold text-white/70'>
              السنة النشطة: {yearName}
            </p>
          </div>
        </div>
        <span
          className='inline-flex items-center px-3.5 py-1 rounded-full text-xs font-black shadow-xs'
          style={{ backgroundColor: 'rgba(253,230,138,0.25)', color: '#FDE68A', border: '1px solid rgba(253,230,138,0.35)' }}
        >
          {pct.toFixed(1)}٪
        </span>
      </div>

      <div className='relative my-6 flex flex-1 items-center justify-center'>
        <RatioDonut
          size={215}
          stroke={17}
          progress={progress}
          track='rgba(255,255,255,0.12)'
          color='#F59E0B'
          colorEnd='#FDE68A'
          label={`نسبة التلاميذ الذين دفعوا الترسيم ${pct.toFixed(1)} بالمئة من إجمالي ${total} تلميذاً`}
        >
          <span
            className='text-[42px] leading-none font-black tracking-tight text-white'
            style={{ fontFamily: 'var(--font-display)', ...NUM }}
          >
            <AnimatedInt value={total} />
          </span>
          <span className='mt-2 text-xs font-bold text-white/75'>
            إجمالي التلاميذ
          </span>
          <span
            className='mt-2.5 text-xs font-black px-3 py-1 rounded-full shadow-xs'
            style={{
              backgroundColor: 'rgba(253,230,138,0.25)',
              color: '#FDE68A',
              border: '1px solid rgba(253,230,138,0.4)',
              ...NUM,
            }}
          >
            {pct.toFixed(1)}٪ تم الترسيم
          </span>
        </RatioDonut>
      </div>

      <div className='relative grid grid-cols-2 gap-3.5'>
        <div
          className='rounded-2xl p-4 transition-transform hover:scale-[1.02]'
          style={{ backgroundColor: 'rgba(253,230,138,0.18)', border: '1px solid rgba(253,230,138,0.3)' }}
        >
          <p className='flex items-center gap-2 text-xs font-black' style={{ color: '#FDE68A' }}>
            <LegendDot color='#F59E0B' /> دَفعوا الترسيم
          </p>
          <p className='mt-2 text-[26px] leading-none font-black text-white' style={{ ...NUM }}>
            {safePaid}
          </p>
        </div>
        <div
          className='rounded-2xl p-4 transition-transform hover:scale-[1.02]'
          style={{ backgroundColor: 'rgba(255,255,255,0.10)', border: '1px solid rgba(255,255,255,0.18)' }}
        >
          <p className='flex items-center gap-2 text-xs font-black text-white/80'>
            <LegendDot color='rgba(255,255,255,0.6)' /> لم يدفعوا بعد
          </p>
          <p className='mt-2 text-[26px] leading-none font-black text-white/95' style={{ ...NUM }}>
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
        boxShadow: '0 10px 32px -8px rgba(138, 106, 30, 0.15)',
      }}
      aria-labelledby='prior-debt-title'
    >
      <div
        className='pointer-events-none absolute -top-10 -right-10 h-40 w-40 rounded-full'
        style={{ background: 'rgba(200, 155, 60, 0.15)', filter: 'blur(30px)' }}
      />
      <div className='relative mb-6 flex items-center justify-between gap-3'>
        <div className='flex items-center gap-3'>
          <span
            className='inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl shadow-sm'
            style={{ backgroundColor: '#E3EFE4', color: '#15803D' }}
            aria-hidden='true'
          >
            <History size={20} />
          </span>
          <div>
            <h3 id='prior-debt-title' className='text-[18px] font-black tracking-tight text-slate-900'>
              تحصيل الديون السابقة
            </h3>
            <p className='text-xs font-bold text-slate-500'>
              سجل متابعة المستحقات القديمة وسدادها
            </p>
          </div>
        </div>

        <button
          type='button'
          onClick={onOpenDetail}
          aria-label='عرض تفصيل تحصيل الديون السابقة'
          className={`rounded-2xl px-5 py-2.5 text-xs font-black text-white shadow-sm transition-all hover:brightness-110 active:scale-[0.98] ${FOCUS}`}
          style={{ background: 'linear-gradient(135deg, #1B4332 0%, #2D6A4F 100%)' }}
        >
          عرض التفصيل
        </button>
      </div>

      <div className='relative grid grid-cols-1 items-center gap-6 md:grid-cols-[auto_1fr] md:gap-8'>
        {/* حلقة النسبة الاحترافية */}
        <div className='flex justify-center'>
          <RatioDonut
            size={145}
            stroke={13}
            progress={progress}
            track='rgba(21, 128, 61, 0.15)'
            color='#15803D'
            colorEnd='#34D399'
            delay={0.1}
            label={`نسبة تحصيل الديون السابقة ${pct.toFixed(1)} بالمئة`}
          >
            <span className='text-[28px] leading-none font-black text-[#15803D]' style={{ fontFamily: 'var(--font-display)', ...NUM }}>
              {pct.toFixed(1)}٪
            </span>
            <span className='mt-1.5 text-xs font-black text-[#15803D]/85'>
              نسبة التحصيل
            </span>
          </RatioDonut>
        </div>

        {/* المجموع والمبلغان والتفصيل */}
        <div>
          <p className='text-sm font-extrabold text-slate-600'>
            مجموع الديون السابقة
          </p>
          <p className='mt-1 text-[32px] sm:text-[36px] leading-none font-black text-slate-900' style={{ fontFamily: 'var(--font-display)', ...NUM }}>
            <bdi dir='ltr'>{dinar(total)}</bdi>
          </p>

          <div className='mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2'>
            <div
              className='rounded-2xl p-4 border transition-transform hover:scale-[1.02]'
              style={{ background: 'linear-gradient(135deg, #EFFBF2 0%, #DCFCE7 100%)', borderColor: '#BBF7D0' }}
            >
              <p className='text-xs font-black text-[#166534]'>
                المحصّل
              </p>
              <p className='mt-1.5 text-[24px] font-black text-[#15803D] leading-none'>
                <bdi dir='ltr' style={NUM}>{dinar(collected)}</bdi>
              </p>
            </div>
            <div
              className='rounded-2xl p-4 border transition-transform hover:scale-[1.02]'
              style={{ background: 'linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%)', borderColor: '#FDE68A' }}
            >
              <p className='text-xs font-black text-[#92400E]'>
                المتبقّي
              </p>
              <p className='mt-1.5 text-[24px] font-black text-[#B45309] leading-none'>
                <bdi dir='ltr' style={NUM}>{dinar(remaining)}</bdi>
              </p>
            </div>
          </div>

          <p className='mt-4 text-xs font-medium leading-relaxed text-slate-500'>
            التفصيل مفصول في جدولين: ديون التلاميذ وديون الإطارات، كلٌّ في جدوله.
          </p>
        </div>
      </div>
    </section>
  );
}

/**
 * أيقونة الصباح التفاعلية الفاخرة:
 * تتحول بنعومة بين فنجان القهوة الساخنة مع البخار المتصاعد،
 * والوردة المتفتحة مع البتلات الناعمة، مع تأثيرات الضوء والتوهج.
 */
function InteractiveGreetingBadge({ isMorning }: { isMorning: boolean }) {
  const [view, setView] = useState<'coffee' | 'rose'>('coffee');

  useEffect(() => {
    if (!isMorning) return;
    const interval = setInterval(() => {
      setView((prev) => (prev === 'coffee' ? 'rose' : 'coffee'));
    }, 4500);
    return () => clearInterval(interval);
  }, [isMorning]);

  // original state preserved: view toggle + isMorning interval (unchanged logic)
  if (!isMorning) {
    return (
      <div
        className='relative flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl border border-slate-200/90 bg-white shadow-xs md:h-22 md:w-22'
      >
        <Moon size={32} className='text-amber-500' />
      </div>
    );
  }

  return (
    <button
      type='button'
      onClick={() => setView((prev) => (prev === 'coffee' ? 'rose' : 'coffee'))}
      className='group relative flex h-20 w-20 shrink-0 cursor-pointer items-center justify-center rounded-2xl border border-slate-200/90 bg-white p-1 shadow-xs transition-all duration-300 hover:scale-[1.03] hover:border-emerald-500/40 hover:shadow-md active:scale-[0.98] md:h-22 md:w-22'
      title={view === 'coffee' ? 'اضغط للتبديل إلى الوردة الصباحية' : 'اضغط للتبديل إلى فنجان القهوة'}
    >
      {/* المحتوى المتحرك: قهوة ساخنة أو وردة متفتحة */}
      <div className='relative h-full w-full overflow-hidden rounded-xl'>
        {view === 'coffee' ? (
          <motion.div
            key='coffee'
            initial={{ opacity: 0, scale: 0.85, rotate: -6 }}
            animate={{ opacity: 1, scale: 1, rotate: 0 }}
            exit={{ opacity: 0, scale: 0.85, rotate: 6 }}
            transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1] }}
            className='relative h-full w-full'
          >
            <img
              src={morningCoffeeImg}
              alt='فنجان قهوة ساخنة وبخار صاعد'
              className='h-full w-full object-cover rounded-xl'
            />
            {/* بخار متصاعد ناعم */}
            <div className='pointer-events-none absolute inset-x-0 top-1 flex justify-center gap-1 opacity-60'>
              <span className='h-4 w-1 rounded-full bg-white/80 blur-[1px] animate-bounce' style={{ animationDuration: '1.8s' }} />
              <span className='h-5 w-1 rounded-full bg-white/90 blur-[1px] animate-bounce' style={{ animationDuration: '2.2s', animationDelay: '0.4s' }} />
              <span className='h-3.5 w-1 rounded-full bg-white/80 blur-[1px] animate-bounce' style={{ animationDuration: '1.6s', animationDelay: '0.8s' }} />
            </div>
          </motion.div>
        ) : (
          <motion.div
            key='rose'
            initial={{ opacity: 0, scale: 0.8, rotate: 12 }}
            animate={{ opacity: 1, scale: 1, rotate: 0 }}
            exit={{ opacity: 0, scale: 0.8, rotate: -12 }}
            transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1] }}
            className='relative flex h-full w-full items-center justify-center'
            style={{
              background: 'radial-gradient(circle, #FDF2F4 0%, #FEE2E2 70%)',
            }}
          >
            {/* وردة متفتحة بتصميم فيكتور فاخر */}
            <svg viewBox='0 0 100 100' className='h-16 w-16 drop-shadow-sm' aria-hidden='true'>
              <circle cx='50' cy='50' r='42' fill='#FFF1F2' opacity='0.4' />
              {/* أوراق خضراء خلفية */}
              <path d='M25 65 C15 50 35 35 45 48 Z' fill='#059669' opacity='0.85' />
              <path d='M75 65 C85 50 65 35 55 48 Z' fill='#10B981' opacity='0.85' />
              {/* بتلات الوردة */}
              <path d='M50 18 C30 25 30 50 50 65 C70 50 70 25 50 18 Z' fill='#E11D48' />
              <path d='M32 35 C20 48 38 68 50 70 C62 68 80 48 68 35 C58 45 42 45 32 35 Z' fill='#F43F5E' />
              {/* قلب الوردة */}
              <path d='M42 42 C38 52 48 60 50 60 C52 60 62 52 58 42 C54 48 46 48 42 42 Z' fill='#FDA4AF' />
              <circle cx='50' cy='48' r='5' fill='#FFE4E6' />
              {/* قطرات ندى */}
              <circle cx='56' cy='36' r='2' fill='#FFFFFF' opacity='0.9' />
              <circle cx='40' cy='52' r='1.5' fill='#FFFFFF' opacity='0.85' />
            </svg>
          </motion.div>
        )}
      </div>

      {/* مؤشر النقطة الصغيرة المتغيرة أسفل الشارة */}
      <span
        className='absolute -bottom-1.5 rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[9px] font-black tracking-widest text-slate-700 shadow-2xs'
      >
        {view === 'coffee' ? '☕' : '🌹'}
      </span>
    </button>
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
  const [financialPeriod, setFinancialPeriod] = useState<'today' | 'month'>('today');

  useEffect(() => {
    let mounted = true;
    const controller = new AbortController();
    setLoading(true);
    setError('');

    fetchDashboard(controller.signal)
      .then((result) => {
        if (mounted) setData(result);
      })
      .catch((e) => {
        if (!mounted) return;
        const msg = String(e?.message ?? '').toLowerCase();
        if (e?.name === 'AbortError' || msg.includes('abort')) {
          return;
        }
        const err = errorMessage(e);
        if (err) setError(err);
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });

    return () => {
      mounted = false;
      controller.abort();
    };
  }, []);

  const today = data?.cash?.today;
  const month = data?.cash?.month;
  const yearName = data?.academic_year?.name ?? '—';

  // الحسابات المالية للكروت حسب الفترة المختارة (اليوم أو الشهر):
  const currentFigures = financialPeriod === 'today' ? today : month;
  const periodLabel = financialPeriod === 'today' ? 'اليوم' : 'الشهر';
  const periodHint = financialPeriod === 'today' ? 'اليوم' : 'هذا الشهر';

  const periodIncome = Number(currentFigures?.income ?? 0);
  const periodOldDebts = Number(currentFigures?.old_debt_collections ?? currentFigures?.old_debts ?? 0);
  const periodCashIn = Number(currentFigures?.cash_in ?? (periodIncome + periodOldDebts));
  const periodNetIncome = Number(currentFigures?.net_income ?? (periodIncome - Number(currentFigures?.expenses ?? 0)));
  const periodExpenses = Number(currentFigures?.expenses ?? 0);

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
    <div className='relative min-h-full overflow-hidden text-slate-800' dir='rtl'>
      {/* خلفية جمالية ناعمة مع تدرجات رقيقة */}
      <div className='pointer-events-none absolute inset-0' aria-hidden='true'>
        <div className='absolute inset-0 bg-gradient-to-b from-white via-[#F8FAF9] to-[#F1F5F2]' />
        <div className='absolute -top-32 -left-32 h-96 w-96 rounded-full bg-emerald-100/40 blur-3xl' />
        <div className='absolute bottom-0 right-0 h-[28rem] w-[28rem] rounded-full bg-amber-100/30 blur-3xl' />
      </div>

      <div className='relative mx-auto w-full max-w-[1400px] px-6 py-8 md:px-10 md:py-10'>
        {/* الصاري التحريري: تحية ثنائية اللغة + شارة الصباح + ساعة المؤسّسة */}
        <header className='mb-10 grid grid-cols-1 items-center gap-6 lg:grid-cols-[1fr_auto] xl:grid-cols-[1.4fr_auto_auto]'>
          <div className='flex items-center gap-5'>
            <InteractiveGreetingBadge isMorning={isMorning} />
            <div className='min-w-0'>
              <p className='text-xs font-bold uppercase tracking-wider text-emerald-800'>
                Complexe La Providence — {isMorning ? 'Good morning · Bonjour' : 'Good evening · Bonsoir'}
              </p>
              <h1 className='mt-1 truncate text-2xl md:text-[32px] leading-tight font-black tracking-tight text-slate-900' style={{ fontFamily: 'var(--font-display)' }}>
                {isMorning ? 'صباح الخير' : 'مساء الخير'}
                {greetName}
              </h1>

              {/* جرد اليوم: مؤشّر حالة نظيف */}
              <div className='mt-3'>
                <span className='inline-flex items-center gap-2 rounded-xl border border-slate-200/80 bg-white/90 px-3.5 py-1.5 text-xs font-bold text-slate-700 shadow-2xs'>
                  <Calendar size={14} className='text-[#C2A24E]' />
                  جرد اليوم: <span className='font-mono font-black tabular-nums text-slate-900'>{data?.current_date ?? ''}</span>
                </span>
              </div>
            </div>
          </div>

          {/* شارة اسم المؤسسة */}
          <div
            className='hidden lg:flex items-center gap-4 rounded-2xl border border-slate-200/80 bg-white/90 px-5 py-3.5 shadow-xs backdrop-blur-md transition-all duration-300 hover:border-slate-300 lg:col-span-1'
          >
            <div
              className='relative flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200/80 bg-white p-1 shadow-2xs'
            >
              <img
                src='/image/logo.jpg'
                alt='شعار مدرسة العناية'
                className='w-full h-full object-contain'
                onError={(e) => {
                  const el = e.currentTarget.parentElement?.parentElement;
                  if (el) el.style.display = 'none';
                }}
              />
            </div>

            <div className='flex flex-col items-start justify-center'>
              <h2
                className='text-[16px] xl:text-[18px] font-black tracking-wider uppercase text-slate-900'
                style={{ fontFamily: 'var(--font-display)' }}
              >
                COMPLEXE LA PROVIDENCE
              </h2>

              <div className='mt-0.5 flex items-center gap-2'>
                <span className='text-[10.5px] font-bold tracking-widest text-slate-400 uppercase'>
                  Établissement Privé
                </span>
                <span className='h-1 w-1 rounded-full bg-emerald-500' />
                <span className='text-[11.5px] font-bold text-emerald-800'>
                  مجمّع العناية التربوي
                </span>
              </div>
            </div>
          </div>

          {/* ساعة المؤسسة */}
          <div
            className='flex items-center gap-5 rounded-2xl border border-slate-200/80 bg-white/90 px-5 py-3 shadow-xs backdrop-blur-md transition-all duration-300 hover:border-slate-300'
          >
            <div className='flex flex-col items-end gap-1.5 text-right'>
              <span className='inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-900'>
                <span className='h-1.5 w-1.5 rounded-full bg-emerald-700 animate-pulse' />
                توقيت المؤسسة
              </span>
              <span className='inline-flex items-center gap-1.5 text-xs font-bold text-slate-500'>
                مدرسة العناية
              </span>
            </div>
            <AnalogClock size={110} />
          </div>
        </header>

        {error && (
          <div
            role='alert'
            className='mb-6 flex items-start gap-2 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 shadow-2xs'
          >
            <AlertCircle size={18} className='mt-0.5 shrink-0 text-red-600' aria-hidden='true' />
            <span>{error}</span>
          </div>
        )}

        {loading && <PageDataSkeleton cards={4} rows={4} />}

        {!loading && data && (
          <div className='space-y-8'>
            {/* ── شريط الإجراءات السريعة الفاخر (Quick Actions Bar) ── */}
            <div className='grid grid-cols-2 sm:grid-cols-4 gap-3.5'>
              <Link
                to='/collection'
                className='group relative flex items-center gap-3.5 rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs transition-all duration-300 hover:-translate-y-0.5 hover:border-emerald-500/40 hover:shadow-md'
              >
                <span className='flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-800 transition-all duration-300 group-hover:scale-105 group-hover:bg-emerald-100'>
                  <Zap size={20} />
                </span>
                <div>
                  <p className='text-[10px] font-bold uppercase tracking-wider text-slate-400'>استخلاص فردي</p>
                  <p className='text-sm font-black text-slate-900 group-hover:text-[#1B4332]'>قبض قسط شهري</p>
                </div>
              </Link>

              <Link
                to='/families'
                className='group relative flex items-center gap-3.5 rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs transition-all duration-300 hover:-translate-y-0.5 hover:border-amber-500/40 hover:shadow-md'
              >
                <span className='flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-800 transition-all duration-300 group-hover:scale-105 group-hover:bg-amber-100'>
                  <Users size={20} />
                </span>
                <div>
                  <p className='text-[10px] font-bold uppercase tracking-wider text-slate-400'>استخلاص عائلي</p>
                  <p className='text-sm font-black text-slate-900 group-hover:text-amber-900'>سداد العائلات</p>
                </div>
              </Link>

              <Link
                to='/students/enroll'
                className='group relative flex items-center gap-3.5 rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs transition-all duration-300 hover:-translate-y-0.5 hover:border-emerald-500/40 hover:shadow-md'
              >
                <span className='flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-800 transition-all duration-300 group-hover:scale-105 group-hover:bg-emerald-100'>
                  <GraduationCap size={20} />
                </span>
                <div>
                  <p className='text-[10px] font-bold uppercase tracking-wider text-slate-400'>تسجيل جديد</p>
                  <p className='text-sm font-black text-slate-900 group-hover:text-[#1B4332]'>معالج الترسيم</p>
                </div>
              </Link>

              <Link
                to='/expenses/create'
                className='group relative flex items-center gap-3.5 rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs transition-all duration-300 hover:-translate-y-0.5 hover:border-rose-500/40 hover:shadow-md'
              >
                <span className='flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-800 transition-all duration-300 group-hover:scale-105 group-hover:bg-rose-100'>
                  <TrendingDown size={20} />
                </span>
                <div>
                  <p className='text-[10px] font-bold uppercase tracking-wider text-slate-400'>صرف من الخزينة</p>
                  <p className='text-sm font-black text-slate-900 group-hover:text-rose-900'>تسجيل مصروف</p>
                </div>
              </Link>
            </div>

            {/* صف البطل */}
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
                  <SectionLabel
                    title='المؤشّرات المالية'
                    hint='أرقام الدفتر النقدي المركزي'
                    icon={TrendingUp}
                    extra={
                      <div
                        className='inline-flex rounded-xl border border-slate-200/80 bg-slate-100/80 p-1'
                      >
                        <button
                          type='button'
                          onClick={() => setFinancialPeriod('today')}
                          className={`px-3.5 py-1.5 rounded-lg text-xs font-black transition-all ${
                            financialPeriod === 'today'
                              ? 'bg-white text-emerald-950 shadow-xs'
                              : 'text-slate-600 hover:text-slate-900'
                          }`}
                        >
                          جرد اليوم
                        </button>
                        <button
                          type='button'
                          onClick={() => setFinancialPeriod('month')}
                          className={`px-3.5 py-1.5 rounded-lg text-xs font-black transition-all ${
                            financialPeriod === 'month'
                              ? 'bg-white text-emerald-950 shadow-xs'
                              : 'text-slate-600 hover:text-slate-900'
                          }`}
                        >
                          الشهر الجاري
                        </button>
                      </div>
                    }
                  />
                <div className='grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3'>
                  <StatCard
                    label={financialPeriod === 'today' ? 'مقبوضات اليوم (24 ساعة)' : `مداخيل ${periodLabel}`}
                    value={
                      <AnimatedMoney
                        value={
                          financialPeriod === 'today'
                            ? (today?.total_collected_24h ?? periodCashIn)
                            : periodIncome
                        }
                      />
                    }
                    icon={TrendingUp}
                    chipBg={C.sage}
                    chipColor={C.forest}
                    gradFrom='#064E3B'
                    gradTo='#059669'
                    hint={
                      financialPeriod === 'today'
                        ? 'كل ما قُبض خلال 24 ساعة لمطابقة حساب الصندوق'
                        : 'أقساط السنة الدراسية الحالية'
                    }
                  >
                    {financialPeriod === 'today' && (
                      <div className='mt-3.5 flex flex-wrap items-center gap-2 pt-3 border-t border-white/20'>
                        <span className='inline-flex items-center gap-1.5 rounded-xl bg-white/20 px-3 py-1.5 text-xs font-bold text-white border border-white/25'>
                          <span className='text-white/80 font-semibold'>💵 نقداً:</span>
                          <span className='font-black'><Money value={today?.cash_in_hand ?? 0} /></span>
                        </span>
                        <span className='inline-flex items-center gap-1.5 rounded-xl bg-white/20 px-3 py-1.5 text-xs font-bold text-white border border-white/25'>
                          <span className='text-white/80 font-semibold'>🏦 شيكات/بنك:</span>
                          <span className='font-black'><Money value={today?.non_cash ?? 0} /></span>
                        </span>
                        <span className='inline-flex items-center gap-1.5 rounded-xl bg-white/20 px-3 py-1.5 text-xs font-bold text-white border border-white/25'>
                          <span className='text-white/80 font-semibold'>📜 ديون قديمة:</span>
                          <span className='font-black'><Money value={today?.old_debts_today ?? 0} /></span>
                        </span>
                      </div>
                    )}
                  </StatCard>
                  <StatCard
                    label={financialPeriod === 'today' ? 'سداد الديون القديمة اليوم' : 'سداد الديون القديمة (الشهر)'}
                    value={<AnimatedMoney value={periodOldDebts} />}
                    icon={History}
                    chipBg={C.goldSoft}
                    chipColor={C.goldDeep}
                    gradFrom='#92400E'
                    gradTo='#D97706'
                    hint='نقود دخلت الخزينة — لا تدخل في الدخل الصافي'
                  />
                  <StatCard
                    label={`المجموع المقبوض ${periodLabel}`}
                    value={<AnimatedMoney value={periodCashIn} />}
                    icon={Landmark}
                    chipBg={C.soft}
                    chipColor={C.forest}
                    gradFrom='#1E3A8A'
                    gradTo='#2563EB'
                    hint='المداخيل + سداد الديون القديمة'
                  />
                  <StatCard
                    label={`الدخل الصافي ${periodLabel}`}
                    value={<AnimatedMoney value={periodNetIncome} />}
                    icon={ArrowDownCircle}
                    chipBg={C.soft}
                    chipColor={C.forest}
                    gradFrom='#1E1B4B'
                    gradTo='#4F46E5'
                    hint='المداخيل ناقص المصاريف (مستبعد منها الديون القديمة)'
                  />
                  <StatCard
                    label={`مصاريف ${periodLabel}`}
                    value={<AnimatedMoney value={periodExpenses} />}
                    icon={TrendingDown}
                    chipBg={C.rose}
                    chipColor={C.expense}
                    gradFrom='#7F1D1D'
                    gradTo='#DC2626'
                    hint={`ما خرج من الصندوق ${periodHint}`}
                  />
                  <StatCard
                    label='رصيد الخزينة'
                    value={<AnimatedMoney value={data.treasury_balance} />}
                    icon={Landmark}
                    chipBg={C.goldSoft}
                    chipColor={C.goldDeep}
                    gradFrom='#78350F'
                    gradTo='#D97706'
                    hint='يشمل كل المقبوضات (الأقساط + الديون القديمة) بعد السحوبات'
                  />
                </div>
              </div>
            )}
          </motion.section>

          {/* التلاميذ: الإجمالي والمتخلَّد (ومداخيل النوادي إن وُجدت) بوزن أساسيّ،
              وتوزيع الجنس بوزن ثانويّ. */}
          <section>
            <SectionLabel title='التلاميذ' hint={`السنة النشطة: ${yearName}`} icon={GraduationCap} />

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

            {/* بطاقة التوزيع الديمغرافي للجنس — حاوية زجاجية */}
            {/* بطاقة التوزيع الديمغرافي للجنس */}
            <motion.div
              variants={cardRise}
              className='mt-6 overflow-hidden rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs md:p-8'
            >
              <div className='flex flex-wrap items-center justify-between gap-4 mb-6 pb-4 border-b border-slate-100'>
                <div className='flex items-center gap-3'>
                  <span
                    className='inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-800'
                    aria-hidden='true'
                  >
                    <Users size={18} />
                  </span>
                  <div>
                    <p className='text-xs font-bold uppercase tracking-wider text-slate-500'>
                      توزيع التلاميذ حسب الجنس · Gender
                    </p>
                    <p className='mt-0.5 font-mono text-xs font-semibold tabular-nums text-slate-700'>
                      إجمالي {totalActive} تلميذاً مسجلاً
                    </p>
                  </div>
                </div>

                {/* شريط المقارنة التناسبي المصغر */}
                <div className='flex items-center gap-3 min-w-[200px] flex-1 max-w-xs'>
                  <div className='w-full h-2.5 rounded-full bg-slate-100 border border-slate-200/60 flex overflow-hidden'>
                    <div
                      className='h-full rounded-full transition-all duration-700'
                      style={{ width: `${femalePctNum}%`, backgroundColor: '#C2A24E' }}
                      title={`إناث: ${femalePctNum.toFixed(1)}%`}
                    />
                    <div
                      className='h-full rounded-full transition-all duration-700'
                      style={{ width: `${malePctNum}%`, backgroundColor: '#10B981' }}
                      title={`ذكور: ${malePctNum.toFixed(1)}%`}
                    />
                  </div>
                </div>
              </div>

              <div className={`grid grid-cols-1 gap-6 ${unspecified > 0 ? 'md:grid-cols-3' : 'md:grid-cols-2'}`}>
                {/* بطاقة الإناث */}
                <div
                  className='flex flex-col sm:flex-row items-center justify-between gap-6 p-6 rounded-3xl border transition-all duration-300 hover:shadow-lg hover:-translate-y-0.5'
                  style={{
                    background: 'linear-gradient(135deg, #FFF1F2 0%, #FFE4E6 50%, #FECDD3 100%)',
                    borderColor: '#FDA4AF',
                    boxShadow: '0 8px 24px -4px rgba(225, 29, 72, 0.14)',
                  }}
                >
                  <div className='flex items-center gap-4'>
                    <div className='relative shrink-0'>
                      <img
                        src={schoolgirlAvatar}
                        alt='تلميذة بميدعة مدرسية'
                        className='w-20 h-20 rounded-2xl object-cover border-2 border-white shadow-md'
                      />
                      <span className='absolute -bottom-1.5 -right-1.5 w-6 h-6 rounded-full text-white flex items-center justify-center text-xs font-black shadow-sm bg-[#E11D48]'>
                        ♀
                      </span>
                    </div>
                    <div>
                      <p className='text-xs font-black uppercase tracking-wide text-[#9F1239]'>
                        عدد الإناث
                      </p>
                      <p className='mt-1 text-[32px] sm:text-[36px] font-black tracking-tight text-[#E11D48] leading-none' style={{ ...NUM }}>
                        <AnimatedInt value={females} /> <span className='text-xs font-bold text-[#9F1239]'>تلميذة</span>
                      </p>
                    </div>
                  </div>

                  <RatioDonut
                    size={110}
                    stroke={11}
                    progress={femaleProgress}
                    track='rgba(225,29,72,0.15)'
                    color='#E11D48'
                    colorEnd='#FB7185'
                    label={`نسبة الإناث ${femalePctNum.toFixed(1)}%`}
                  >
                    <span className='text-xl font-black text-[#E11D48]' style={{ ...NUM }}>
                      {femalePctNum.toFixed(1)}٪
                    </span>
                  </RatioDonut>
                </div>

                {/* بطاقة الذكور */}
                <div
                  className='flex flex-col sm:flex-row items-center justify-between gap-6 p-6 rounded-3xl border transition-all duration-300 hover:shadow-lg hover:-translate-y-0.5'
                  style={{
                    background: 'linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 50%, #BFDBFE 100%)',
                    borderColor: '#93C5FD',
                    boxShadow: '0 8px 24px -4px rgba(37, 99, 235, 0.14)',
                  }}
                >
                  <div className='flex items-center gap-4'>
                    <div className='relative shrink-0'>
                      <img
                        src={schoolboyAvatar}
                        alt='تلميذ بميدعة مدرسية'
                        className='w-20 h-20 rounded-2xl object-cover border-2 border-white shadow-md'
                      />
                      <span className='absolute -bottom-1.5 -right-1.5 w-6 h-6 rounded-full text-white flex items-center justify-center text-xs font-black shadow-sm bg-[#2563EB]'>
                        ♂
                      </span>
                    </div>
                    <div>
                      <p className='text-xs font-black uppercase tracking-wide text-[#1E40AF]'>
                        عدد الذكور
                      </p>
                      <p className='mt-1 text-[32px] sm:text-[36px] font-black tracking-tight text-[#2563EB] leading-none' style={{ ...NUM }}>
                        <AnimatedInt value={males} /> <span className='text-xs font-bold text-[#1E40AF]'>تلميذ</span>
                      </p>
                    </div>
                  </div>

                  <RatioDonut
                    size={110}
                    stroke={11}
                    progress={maleProgress}
                    track='rgba(37,99,235,0.15)'
                    color='#2563EB'
                    colorEnd='#60A5FA'
                    label={`نسبة الذكور ${malePctNum.toFixed(1)}%`}
                  >
                    <span className='text-xl font-black text-[#2563EB]' style={{ ...NUM }}>
                      {malePctNum.toFixed(1)}٪
                    </span>
                  </RatioDonut>
                </div>

                {/* غير محدد */}
                {unspecified > 0 && (
                  <div
                    className='flex flex-col items-center justify-center p-6 rounded-3xl border border-amber-200/90'
                    style={{ background: 'linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%)' }}
                  >
                    <span className='inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-100 text-amber-900 border border-amber-300 shadow-2xs'>
                      <UserRound size={22} />
                    </span>
                    <p className='mt-4 text-xs font-black uppercase tracking-wide text-amber-900'>
                      غير محدّد الجنس
                    </p>
                    <p className='mt-1 text-3xl font-black text-[#B45309]' style={{ ...NUM }}>
                      <AnimatedInt value={unspecified} /> <span className='text-xs font-bold'>تلميذ</span>
                    </p>
                    <p className='mt-2 text-xs font-medium text-center text-amber-800'>
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

          {/* متابعة الشهر الجاري */}
          {data.cash && (
            <section>
              <SectionLabel title='متابعة الشهر' hint='مقارنة المداخيل والمصاريف للشهر الجاري' icon={History} />
              <div
                className='rounded-3xl border border-slate-200/90 bg-white p-6 shadow-sm md:p-8'
                style={{ background: 'linear-gradient(135deg, #FAFBF8 0%, #F4F7F0 100%)' }}
              >
                <div className='grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 items-center'>
                  {/* دائرة المداخيل */}
                  <div
                    className='flex flex-col items-center justify-center p-6 rounded-3xl border transition-all duration-300 hover:shadow-md hover:-translate-y-0.5'
                    style={{
                      background: 'linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%)',
                      borderColor: '#BFDBFE',
                      boxShadow: '0 4px 16px -4px rgba(37, 99, 235, 0.12)',
                    }}
                  >
                    <RatioDonut
                      size={125}
                      stroke={12}
                      progress={totalFlow > 0 ? monthIncome / totalFlow : 0}
                      track='rgba(37,99,235,0.15)'
                      color='#2563EB'
                      colorEnd='#60A5FA'
                      label={`نسبة المداخيل ${incomePct.toFixed(1)}%`}
                    >
                      <span className='text-xl font-black text-[#2563EB]' style={{ ...NUM }}>
                        {incomePct.toFixed(1)}٪
                      </span>
                    </RatioDonut>
                    <p className='mt-3 text-xs font-black uppercase tracking-wide text-[#1E40AF]'>
                      مجموع المداخيل
                    </p>
                    <p className='mt-1 text-[24px] font-black text-[#2563EB]' style={{ ...NUM }}>
                      <AnimatedMoney value={month?.income} />
                    </p>
                  </div>

                  {/* دائرة المصاريف */}
                  <div
                    className='flex flex-col items-center justify-center p-6 rounded-3xl border transition-all duration-300 hover:shadow-md hover:-translate-y-0.5'
                    style={{
                      background: 'linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%)',
                      borderColor: '#FECACA',
                      boxShadow: '0 4px 16px -4px rgba(220, 38, 38, 0.12)',
                    }}
                  >
                    <RatioDonut
                      size={125}
                      stroke={12}
                      progress={totalFlow > 0 ? monthExpenses / totalFlow : 0}
                      track='rgba(220,38,38,0.15)'
                      color='#DC2626'
                      colorEnd='#F87171'
                      label={`نسبة المصاريف ${expensePct.toFixed(1)}%`}
                    >
                      <span className='text-xl font-black text-[#DC2626]' style={{ ...NUM }}>
                        {expensePct.toFixed(1)}٪
                      </span>
                    </RatioDonut>
                    <p className='mt-3 text-xs font-black uppercase tracking-wide text-[#991B1B]'>
                      مجموع المصاريف
                    </p>
                    <p className='mt-1 text-[24px] font-black text-[#DC2626]' style={{ ...NUM }}>
                      <AnimatedMoney value={month?.expenses} />
                    </p>
                  </div>

                  {/* الدخل الصافي */}
                  <div
                    className='flex flex-col items-center justify-center p-6 rounded-3xl border h-full transition-all duration-300 hover:shadow-md hover:-translate-y-0.5'
                    style={{
                      background: netMonth >= 0
                        ? 'linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%)'
                        : 'linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%)',
                      borderColor: netMonth >= 0 ? '#BBF7D0' : '#FECACA',
                      boxShadow: netMonth >= 0
                        ? '0 4px 16px -4px rgba(22, 101, 52, 0.12)'
                        : '0 4px 16px -4px rgba(220, 38, 38, 0.12)',
                    }}
                  >
                    <span
                      className='inline-flex h-12 w-12 items-center justify-center rounded-2xl shadow-xs'
                      style={{
                        backgroundColor: netMonth >= 0 ? '#DCFCE7' : '#FEE2E2',
                        color: netMonth >= 0 ? '#15803D' : '#DC2626',
                      }}
                    >
                      <TrendingUp size={24} />
                    </span>
                    <p className='mt-3 text-xs font-black uppercase tracking-wide' style={{ color: netMonth >= 0 ? '#166534' : '#991B1B' }}>
                      الدخل الصافي
                    </p>
                    <p className='mt-1 text-[24px] font-black' style={{ color: netMonth < 0 ? '#DC2626' : '#15803D', ...NUM }}>
                      <AnimatedMoney value={month?.net_income} />
                    </p>
                    <span className='mt-2 text-xs font-bold' style={{ color: netMonth >= 0 ? '#166534' : '#991B1B' }}>
                      المداخيل − المصاريف
                    </span>
                  </div>

                  {/* السحوبات */}
                  <div
                    className='flex flex-col items-center justify-center p-6 rounded-3xl border h-full transition-all duration-300 hover:shadow-md hover:-translate-y-0.5'
                    style={{
                      background: 'linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%)',
                      borderColor: '#FDE68A',
                      boxShadow: '0 4px 16px -4px rgba(180, 83, 9, 0.12)',
                    }}
                  >
                    <span
                      className='inline-flex h-12 w-12 items-center justify-center rounded-2xl shadow-xs bg-amber-100 text-amber-900'
                    >
                      <Landmark size={24} />
                    </span>
                    <p className='mt-3 text-xs font-black uppercase tracking-wide text-[#92400E]'>
                      السحوبات
                    </p>
                    <p className='mt-1 text-[24px] font-black text-[#B45309]' style={{ ...NUM }}>
                      <AnimatedMoney value={month?.withdrawals} />
                    </p>
                    <span className='mt-2 text-xs font-bold text-[#92400E]'>
                      سحوبات الشهر الجاري
                    </span>
                  </div>
                </div>

                {/* سداد الديون القديمة للشهر */}
                {Number(month?.old_debt_collections ?? month?.old_debts ?? 0) > 0 && (
                  <div
                    className='mt-6 p-4 rounded-2xl border border-amber-200/80 bg-amber-50/70 flex items-center justify-between gap-4 flex-wrap'
                  >
                    <div className='flex items-center gap-3'>
                      <span className='inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-900'>
                        <History size={18} />
                      </span>
                      <div>
                        <p className='text-xs font-black text-amber-950'>
                          سداد ديون قديمة تم تحصيلها هذا الشهر: <span className='font-mono font-black tabular-nums' style={NUM}>{dinar(month?.old_debt_collections ?? month?.old_debts)}</span>
                        </p>
                        <p className='text-[11px] font-semibold text-amber-800 mt-0.5'>
                          نقود دخلت الخزينة فعلياً — مستبعدة من الدخل الصافي لأنها تتبع سنة دراسية سابقة.
                        </p>
                      </div>
                    </div>
                    <div className='text-left'>
                      <span className='font-mono text-xl font-black tabular-nums text-amber-950' style={NUM}>
                        <AnimatedMoney value={month?.old_debt_collections ?? month?.old_debts} />
                      </span>
                    </div>
                  </div>
                )}

                <p className='mt-6 text-xs leading-relaxed text-center text-slate-400'>
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
    </div>
  );
}
