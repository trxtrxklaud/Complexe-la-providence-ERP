import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { login as apiLogin } from '../api/auth';
import { LogIn, Lock, Mail, AlertCircle, Eye, EyeOff } from 'lucide-react';

/** ألوان الشعار — تُستعمل في الشريط الثلاثي الصغير فقط، ربطًا بصريًا بالهوية. */
const LOGO_COLORS = ['#35A8DE', '#D51F7F', '#8CC63F'];

/** شعار المدرسة داخل لوحة عاجية مرتفعة. يتراجع لمونوغرام «ع» إن غاب ملف الشعار. */
function Brandmark({ className = '', imgClass = 'h-28' }: { className?: string; imgClass?: string }) {
  const [ok, setOk] = useState(true);
  return (
    <div
      className={`inline-flex items-center justify-center rounded-3xl bg-white p-5 shadow-2xl ring-1 ring-brass/30 ${className}`}
    >
      {ok ? (
        <img
          src="/image/logo.jpg"
          alt="مدرسة العناية — Complexe La Providence"
          className={`${imgClass} w-auto object-contain`}
          onError={() => setOk(false)}
        />
      ) : (
        <div className="px-6 py-2 text-center">
          <span className="font-display text-6xl leading-none text-brand">ع</span>
          <p className="mt-1 text-xs font-medium tracking-wide text-brand/70">مدرسة العناية</p>
        </div>
      )}
    </div>
  );
}

function TriAccent({ className = '' }: { className?: string }) {
  return (
    <div className={`flex items-center gap-1.5 ${className}`} aria-hidden="true">
      {LOGO_COLORS.map((c) => (
        <span key={c} className="h-1 w-8 rounded-full" style={{ backgroundColor: c }} />
      ))}
    </div>
  );
}

/** إليستريشن مسطّح حديث (نمط flat): شخص يعمل على حاسوب عند مكتب، مع ساعة حائط ونبتة —
 *  inline SVG (يعمل دون إنترنت، بلا صور خارجية). قميص أصفر خردليّ ولمسات برتقالية
 *  (إطار الساعة، أصيص النبتة، السهم)، مع أخضر الهوية والنحاس ربطًا بمنصّة La Providence.
 *  للبطاقة خلفية فاتحة مدمجة كي تظهر على الأخضر (سطح المكتب) والعاجي (الجوال). زخرفة بحتة. */
function WorkspaceScene({ className = '', uid = 'x' }: { className?: string; uid?: string }) {
  const skin = `url(#ws-skin-${uid})`;
  const hair = `url(#ws-hair-${uid})`;
  const shirt = `url(#ws-shirt-${uid})`;
  return (
    <svg viewBox="0 0 440 240" className={className} aria-hidden="true">
      <defs>
        {/* بشرة: إضاءة أعلى-يسار → ظلّ دافئ */}
        <radialGradient id={`ws-skin-${uid}`} cx="0.4" cy="0.34" r="0.78">
          <stop offset="0" stopColor="#F7C8A4" />
          <stop offset="0.6" stopColor="#EDB489" />
          <stop offset="1" stopColor="#DB9B72" />
        </radialGradient>
        {/* شعر: بُنّي داكن بعُمق قُطريّ */}
        <linearGradient id={`ws-hair-${uid}`} x1="0.1" y1="0" x2="0.9" y2="1">
          <stop offset="0" stopColor="#4A3B2F" />
          <stop offset="0.55" stopColor="#37291F" />
          <stop offset="1" stopColor="#271B13" />
        </linearGradient>
        {/* قميص: أصفر خردليّ بتدرّج خفيف للحجم */}
        <linearGradient id={`ws-shirt-${uid}`} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#F8BE33" />
          <stop offset="1" stopColor="#E9A11C" />
        </linearGradient>
      </defs>

      {/* خلفية البطاقة الفاتحة + دائرة عمق ناعمة + ظل أرضي */}
      <rect width="440" height="240" fill="#EDEBE4" />
      <circle cx="248" cy="120" r="88" fill="#E4E1D9" />
      <ellipse cx="234" cy="221" rx="156" ry="8" fill="#D9D6CE" />

      {/* ساعة حائط (لكنة برتقالية) */}
      <circle cx="350" cy="64" r="23" fill="#F7F5EF" stroke="#F5541C" strokeWidth={4} />
      <g stroke="#3A3936" strokeWidth={2} strokeLinecap="round">
        <line x1="350" y1="45" x2="350" y2="48" />
        <line x1="369" y1="64" x2="366" y2="64" />
        <line x1="350" y1="83" x2="350" y2="80" />
        <line x1="331" y1="64" x2="334" y2="64" />
      </g>
      <path d="M350 64 V51 M350 64 L360 69" stroke="#3A3936" strokeWidth={2.5} strokeLinecap="round" />
      <circle cx="350" cy="64" r="2.4" fill="#C2A24E" />

      {/* نبتة — أوراق بأخضر الهوية وأصيص برتقالي */}
      <path d="M74 152 C58 122 72 102 80 98 C84 118 84 136 80 154 Z" fill="#5FA968" />
      <path d="M80 152 C98 124 94 106 88 100 C84 120 82 138 84 154 Z" fill="#81C784" />
      <path d="M76 154 C62 142 52 140 46 143 C58 152 68 156 76 158 Z" fill="#5FA968" />
      <path d="M62 152 H92 L88 180 H66 Z" fill="#F5541C" />
      <rect x="59" y="148" width="36" height="7" rx="3" fill="#FF7A3D" />

      {/* مكتب */}
      <rect x="86" y="170" width="290" height="9" rx="2" fill="#3A3936" />
      <rect x="104" y="179" width="7" height="40" fill="#2F2E2B" />
      <rect x="352" y="179" width="7" height="40" fill="#2F2E2B" />

      {/* ===== الشخص ===== */}
      {/* شعر خلفي (كتلة) */}
      <ellipse cx="269" cy="93" rx="23" ry="24" fill={hair} />
      {/* رقبة (بتدرّج البشرة) */}
      <path d="M263 108 L275 108 L277 131 L261 131 Z" fill={skin} />
      {/* الجذع (قميص) + ظلّ الياقة */}
      <path d="M236 170 Q236 130 269 130 Q302 130 302 170 Z" fill={shirt} />
      <path d="M263 131 L269 142 L275 131 Z" fill="#D9950F" />
      {/* ظلّ أسفل الذقن على الرقبة */}
      <ellipse cx="269" cy="114" rx="9" ry="3.1" fill="#CE9066" opacity={0.32} />

      {/* الوجه (بيضاويّ بتدرّج ناعم) */}
      <path d="M269 78 C258 78 250 85 250 96 C250 106 258 115 269 115 C280 115 288 106 288 96 C288 85 280 78 269 78 Z" fill={skin} />
      {/* أذنان */}
      <ellipse cx="250" cy="99" rx="2.4" ry="3.4" fill={skin} />
      <ellipse cx="288" cy="99" rx="2.4" ry="3.4" fill={skin} />
      <path d="M250 97 Q248.6 99 250 101" stroke="#CE9066" strokeWidth={0.7} fill="none" strokeLinecap="round" />

      {/* خدّان ورديّان خفيفان */}
      <ellipse cx="260" cy="103" rx="2.6" ry="1.7" fill="#E67E5C" opacity={0.22} />
      <ellipse cx="278" cy="103" rx="2.6" ry="1.7" fill="#E67E5C" opacity={0.22} />

      {/* حاجبان */}
      <path d="M259 92.4 Q263 90.3 267 92.1" stroke="#33261D" strokeWidth={1.5} fill="none" strokeLinecap="round" />
      <path d="M271 92.1 Q275 90.3 279 92.4" stroke="#33261D" strokeWidth={1.5} fill="none" strokeLinecap="round" />

      {/* عينان: بياض + قزحية + بؤبؤ + بريق (نظرة نحو الحاسوب) */}
      <ellipse cx="263" cy="97" rx="3.1" ry="2.3" fill="#FCF8F3" />
      <ellipse cx="275" cy="97" rx="3.1" ry="2.3" fill="#FCF8F3" />
      <circle cx="262.4" cy="97.3" r="1.9" fill="#5B3E29" />
      <circle cx="274.4" cy="97.3" r="1.9" fill="#5B3E29" />
      <circle cx="262.4" cy="97.3" r="0.95" fill="#241611" />
      <circle cx="274.4" cy="97.3" r="0.95" fill="#241611" />
      <circle cx="261.8" cy="96.6" r="0.5" fill="#FFFFFF" />
      <circle cx="273.8" cy="96.6" r="0.5" fill="#FFFFFF" />
      {/* خطّ الجفن العلويّ */}
      <path d="M260 95.3 Q263 94 266 95.3" stroke="#6B4A32" strokeWidth={0.7} fill="none" strokeLinecap="round" />
      <path d="M272 95.3 Q275 94 278 95.3" stroke="#6B4A32" strokeWidth={0.7} fill="none" strokeLinecap="round" />

      {/* أنف */}
      <path d="M268.4 98 Q266.6 101.6 268.8 103.4" stroke="#D2915F" strokeWidth={1.2} fill="none" strokeLinecap="round" />

      {/* شفتان مع ابتسامة خفيفة */}
      <path d="M264 107 Q269 105.2 274 107 Q269 110.8 264 107 Z" fill="#C56E58" />
      <path d="M264.4 107 Q269 108.5 273.6 107" stroke="#9C4C3B" strokeWidth={0.8} fill="none" strokeLinecap="round" />
      <path d="M266 108.5 Q269 109.7 272 108.5" stroke="#E29A82" strokeWidth={0.7} fill="none" opacity={0.7} strokeLinecap="round" />

      {/* غُرّة أماميّة (خصلة الشعر فوق الجبين) */}
      <path d="M250 96 C250 82 259 75 269 75 C279 75 289 82 289 96 C283 87 276 85 269 85 C262 85 256 87 250 96 Z" fill={hair} />
      {/* كعكة علويّة + ربطة نحاسية + بريق */}
      <ellipse cx="269" cy="70" rx="7.5" ry="7" fill={hair} />
      <ellipse cx="269" cy="77.5" rx="4.2" ry="1.7" fill="#C2A24E" />
      <path d="M265 67 Q269 64.5 273 67" stroke="#5C4838" strokeWidth={1.1} fill="none" opacity={0.55} strokeLinecap="round" />
      <path d="M256 88 Q260 79 269 77" stroke="#5C4838" strokeWidth={1.4} fill="none" opacity={0.5} strokeLinecap="round" />

      {/* ذراع تمتدّ إلى الحاسوب + يد */}
      <path d="M240 150 Q218 150 206 159" stroke={shirt} strokeWidth={11} fill="none" strokeLinecap="round" />
      <circle cx="204" cy="160" r="5.2" fill={skin} />

      {/* حاسوب محمول — شاشة بلمسات أخضر الهوية والنحاس */}
      <rect x="172" y="139" width="42" height="27" rx="2" fill="#3A3936" />
      <rect x="176" y="143" width="34" height="19" rx="1" fill="#E9EEE9" />
      <rect x="180" y="147" width="13" height="3" rx="1.5" fill="#5FA968" />
      <rect x="180" y="153" width="22" height="2.6" rx="1.3" fill="#C2A24E" />
      <rect x="180" y="158" width="17" height="2.6" rx="1.3" fill="#B9C2B4" />
      <path d="M166 166 H220 L224 172 H162 Z" fill="#2F2E2B" />

      {/* سهم برتقالي مرسوم يدويًّا (لمسة المرجع) */}
      <path d="M40 210 C70 197 94 201 106 212" stroke="#F5541C" strokeWidth={4} fill="none" strokeLinecap="round" />
      <path d="M106 212 l-10 -1 M106 212 l-3 -9" stroke="#F5541C" strokeWidth={4} fill="none" strokeLinecap="round" />
    </svg>
  );
}

/** عنصرا الإشهار: الاسم بالعربية ثم الترحيب بالفرنسية — يتعاقبان عبر الشريط. */
const AD_ITEMS = [
  {
    text: 'مركب العناية للتعليم الخاص',
    dir: 'rtl' as const,
    style: { fontFamily: "'Reem Kufi', 'Cairo', sans-serif", fontWeight: 700, letterSpacing: '0.02em' },
  },
  {
    text: 'BIENVENUE AU COMPLEXE LA PROVIDENCE',
    dir: 'ltr' as const,
    style: { fontFamily: "'Montserrat', 'Poppins', 'Inter', system-ui, sans-serif", fontWeight: 800, letterSpacing: '0.16em' },
  },
];

/** شريط إشهاريّ متواصل بأعلى الصفحة: اسم المركب بالعربية والفرنسية يتعاقبان بحركة
 *  أفقية دائمة، يفصلهما معينٌ نحاسيّ. المسار نسختان متطابقتان كي يكون الالتفاف بلا
 *  قطع (قواعد الحركة في app.css: توقّف عند المرور بالمؤشّر، وتعطيل كامل عند تفضيل
 *  تقليل الحركة). النصّ زخرفيّ متكرّر، فيُخفى عن قارئ الشاشة ويُستبدل بتسمية واحدة. */
function WelcomeMarquee() {
  const group = (
    <div className="flex shrink-0 items-center">
      {[0, 1, 2, 3, 4, 5].flatMap((rep) =>
        AD_ITEMS.map((item) => (
          <span key={`${rep}-${item.text}`} className="flex shrink-0 items-center">
            <span dir={item.dir} className="whitespace-nowrap text-[13px] text-ivory/90" style={item.style}>
              {item.text}
            </span>
            <span className="mx-6 text-[9px] leading-none text-[#c8a96e]">◆</span>
          </span>
        )),
      )}
    </div>
  );

  return (
    <div
      role="img"
      aria-label="مركب العناية للتعليم الخاص — Bienvenue au Complexe La Providence"
      className="ad-marquee relative flex h-10 shrink-0 select-none items-center overflow-hidden"
      style={{
        background: 'linear-gradient(90deg, #0a1628 0%, #0f1e3a 30%, #1a3a5c 70%, #0f1e3a 100%)',
        borderBottom: '1px solid rgba(200,169,110,0.35)',
      }}
    >
      <div className="ad-marquee__track" aria-hidden="true">
        {group}
        {group}
      </div>
      {/* تلاشٍ عند الحافتين كي لا ينقطع النصّ بحدٍّ حادّ */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0"
        style={{ background: 'linear-gradient(90deg, #0a1628 0%, transparent 7%, transparent 93%, #0f1e3a 100%)' }}
      />
    </div>
  );
}

export function Login() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const navigate = useNavigate();
  const { login } = useAuth();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setIsLoading(true);

    try {
      const response = await apiLogin({ email, password });
      login(response.access_token, response.user);
      navigate('/');
    } catch (err: any) {
      setError(err.message || 'بيانات الدخول غير صحيحة');
    } finally {
      setIsLoading(false);
    }
  };

  const fieldClass =
    'block w-full rounded-xl border border-[#1a3a5c]/15 bg-[#eef2f7] ps-11 pe-4 py-3 text-left text-[#0f1e3a] ' +
    'shadow-sm outline-none transition placeholder:text-[#0f1e3a]/40 ' +
    'focus:border-[#c8a96e] focus:ring-4 focus:ring-[#c8a96e]/20 focus:bg-white';

  return (
    <div
      dir="rtl"
      className="flex min-h-screen flex-col font-arabic text-ink"
    >
      {/* ===== شريط الإشهار المتحرّك (يعلو اللوحتين على كل المقاسات) ===== */}
      <WelcomeMarquee />

      <div className="grid flex-1 lg:grid-cols-[1.1fr_1fr]">
      {/* ===== لوحة الهوية (يمين في RTL) ===== */}
      <aside
        className="relative hidden overflow-hidden text-ivory lg:flex lg:flex-col lg:justify-between"
        style={{ background: `linear-gradient(135deg, rgba(15,30,58,0.97) 0%, rgba(26,58,92,0.95) 55%, rgba(10,22,40,0.98) 100%), url('https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?auto=format&fit=crop&w=1200&q=80') center/cover` }}
      >
        {/* زخرفة هندسية خافتة (خَتَم) */}
        <svg
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 h-full w-full text-brass"
          style={{ opacity: 0.07 }}
        >
          <defs>
            <pattern id="khatam" width="52" height="52" patternUnits="userSpaceOnUse">
              <g fill="none" stroke="currentColor" strokeWidth="1.25">
                <rect x="14" y="14" width="24" height="24" />
                <rect x="14" y="14" width="24" height="24" transform="rotate(45 26 26)" />
              </g>
            </pattern>
          </defs>
          <rect width="100%" height="100%" fill="url(#khatam)" />
        </svg>

        {/* وهج نحاسي ناعم للعمق */}
        <div
          aria-hidden="true"
          className="pointer-events-none absolute -top-24 -start-24 h-96 w-96 rounded-full"
          style={{ background: 'radial-gradient(circle, rgba(194,160,78,0.18) 0%, transparent 70%)' }}
        />

        {/* أعلى: عنوان سينمائي — كوفي يقابله ERP بخط يعجب الكل */}
        <div className="login-rise relative z-10 p-8 md:p-10 flex items-center justify-between gap-4">
          <span className="text-sm md:text-[13px] font-bold tracking-[0.18em] text-ivory/85" style={{ fontFamily: "'Reem Kufi', 'Cairo', sans-serif", fontWeight: 700 }}>
            منصّة الإدارة المدرسية
          </span>
          <span className="text-xs md:text-sm font-extrabold tracking-[0.12em] text-white" style={{ fontFamily: "'Montserrat', 'Poppins', 'Inter', system-ui, sans-serif", fontWeight: 800, letterSpacing: '0.12em', textShadow: '0 2px 10px rgba(0,0,0,0.35)' }}>
            ERP COMPLEXE LA PROVIDENCE
          </span>
        </div>

        {/* الوسط: الشعار + شعار نصّي */}
        <div className="login-rise relative z-10 flex flex-col items-center px-10 text-center" style={{ animationDelay: '0.1s' }}>
          <Brandmark imgClass="h-32" />
          <TriAccent className="mt-8" />
          <p className="mt-6 max-w-sm font-display text-2xl leading-relaxed text-ivory">
            رعايةٌ للعقل، وعنايةٌ بالمستقبل.
          </p>
          <div className="mt-9 w-full max-w-sm overflow-hidden rounded-3xl shadow-2xl ring-1 ring-brass/25">
            <WorkspaceScene className="block w-full" />
          </div>
        </div>

        {/* أسفل: حقوق */}
        <div className="login-rise relative z-10 p-10 text-center" style={{ animationDelay: '0.2s' }}>
          <p className="text-xs tracking-wide text-ivory/45">
            © 2026 Complexe La Providence — PROD BY MNRH GROUPE
          </p>
        </div>
      </aside>

      {/* ===== لوحة النموذج — خلفية تقارير بنكية Wall Street سينمائية ===== */}
      <main className="relative flex flex-col justify-center px-6 py-12 sm:px-10 lg:px-16 overflow-hidden bg-slate-900">
        <div aria-hidden="true" className="absolute inset-0" style={{ background: `url('https://images.unsplash.com/photo-1611974789855-9c2a0a7236a3?auto=format&fit=crop&w=1600&q=80') center/cover no-repeat` }} />
        <div aria-hidden="true" className="absolute inset-0" style={{ background: `linear-gradient(135deg, rgba(10,25,45,0.90) 0%, rgba(26,58,92,0.85) 50%, rgba(200,169,110,0.38) 100%)` }} />
        {/* أوراق تقارير بنكية شفافة في الخلفية */}
        <svg aria-hidden="true" className="absolute inset-0 h-full w-full opacity-[0.14]" viewBox="0 0 800 600" preserveAspectRatio="xMidYMid slice">
          <g fill="white" opacity="0.9">
            <rect x="40" y="40" width="220" height="140" rx="8" />
            <rect x="55" y="60" width="140" height="6" rx="3" fill="#1a3a5c" opacity="0.3" />
            <rect x="55" y="74" width="100" height="4" rx="2" fill="#2a9d8f" opacity="0.25" />
            <rect x="55" y="84" width="120" height="3" rx="1.5" fill="#1a3a5c" opacity="0.15" />
            <rect x="55" y="92" width="90" height="3" rx="1.5" fill="#1a3a5c" opacity="0.15" />
            <rect x="55" y="110" width="190" height="28" rx="4" fill="#E0F0EE" opacity="0.5" />
            <rect x="540" y="80" width="200" height="120" rx="8" />
            <rect x="555" y="100" width="120" height="6" rx="3" fill="#1a3a5c" opacity="0.3" />
            <rect x="555" y="114" width="80" height="4" rx="2" fill="#c8a96e" opacity="0.4" />
            <rect x="30" y="380" width="180" height="110" rx="8" />
            <rect x="45" y="400" width="100" height="5" rx="2.5" fill="#1a3a5c" opacity="0.3" />
          </g>
          {/* خطوط Wall Street */}
          <polyline points="100,520 180,460 240,480 320,420 400,450 480,380 560,400 640,340 720,360" fill="none" stroke="white" strokeWidth="2" opacity="0.18" strokeLinecap="round" strokeLinejoin="round" />
          <polyline points="100,540 200,500 300,520 450,470 600,490 720,430" fill="none" stroke="#c8a96e" strokeWidth="1.5" opacity="0.22" strokeLinecap="round" />
        </svg>
        <div className="login-rise relative mx-auto w-full max-w-md rounded-3xl bg-white p-8 shadow-[0_20px_60px_rgba(0,0,0,0.4)] ring-1 ring-white/20">
          {/* ترويسة الهوية للجوال فقط */}
          <div className="mb-10 flex flex-col items-center lg:hidden">
            <div className="rounded-2xl bg-white p-4 shadow-lg ring-1 ring-brass/20">
              <Brandmark className="!shadow-none !ring-0 !bg-transparent !p-0" imgClass="h-20" />
            </div>
            <TriAccent className="mt-5" />
            <div className="mt-6 w-full max-w-xs overflow-hidden rounded-3xl shadow-lg ring-1 ring-brass/25">
              <WorkspaceScene className="block w-full" />
            </div>
          </div>

          <span className="text-sm font-semibold tracking-widest text-brass-deep">مرحبًا بعودتك</span>
          <h1 className="mt-2 font-display text-4xl font-bold tracking-tight text-brand">
            تسجيل الدخول
          </h1>
          <p className="mt-2 text-sm text-ink/60">
            أدخل بياناتك للوصول إلى لوحة الإدارة.
          </p>

          <form className="mt-8 space-y-5" onSubmit={handleSubmit}>
            {error && (
              <div
                role="alert"
                className="flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-red-700"
              >
                <AlertCircle size={20} className="shrink-0" />
                <p className="text-sm">{error}</p>
              </div>
            )}

            <div>
              <label htmlFor="email" className="mb-2 block text-sm font-medium text-ink/80">
                البريد الإلكتروني
              </label>
              <div className="relative">
                <span className="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3.5 text-ink/35">
                  <Mail size={18} />
                </span>
                <input
                  id="email"
                  type="email"
                  required
                  autoComplete="username"
                  dir="ltr"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className={fieldClass}
                />
              </div>
            </div>

            <div>
              <label htmlFor="password" className="mb-2 block text-sm font-medium text-ink/80">
                كلمة المرور
              </label>
              <div className="relative">
                <span className="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3.5 text-ink/35">
                  <Lock size={18} />
                </span>
                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  required
                  autoComplete="current-password"
                  dir="ltr"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className={`${fieldClass} pe-11`}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
                  className="absolute inset-y-0 end-0 flex items-center pe-3.5 text-ink/40 transition hover:text-brand"
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
            </div>

            <button
              type="submit"
              disabled={isLoading}
              className="flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg transition focus:outline-none focus-visible:ring-4 focus-visible:ring-[#c8a96e]/40 disabled:cursor-not-allowed disabled:opacity-70"
              style={{ background: 'linear-gradient(135deg, #0f1e3a 0%, #1a3a5c 55%, #c8a96e 100%)', boxShadow: '0 8px 24px rgba(15,30,58,0.35)' }}
            >
              {isLoading ? (
                'جاري التحقق…'
              ) : (
                <>
                  <LogIn size={18} />
                  تسجيل الدخول
                </>
              )}
            </button>
          </form>

          <p className="mt-10 text-center text-xs text-ink/40 lg:hidden">
            © 2026 Complexe La Providence — PROD BY MNRH GROUPE
          </p>
        </div>
      </main>
      </div>
    </div>
  );
}
