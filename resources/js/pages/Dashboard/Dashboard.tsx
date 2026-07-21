import React, { useEffect, useRef, useState } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { Users, GraduationCap, UserRound, CalendarCheck } from 'lucide-react';

const C = {
  forest: '#3B4A36',
  deep: '#2E3B2A',
  sage: '#E3EBDB',
  rose: '#F1E4E2',
  beige: '#EFEAE0',
  blush: '#EFE0E4',
  ink: '#1F261C',
  muted: '#7C8677',
};

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

function KpiCard({ label, value, icon: Icon, tint, iconColor }: any) {
  return (
    <div className="rounded-[22px] p-5" style={{ backgroundColor: tint }}>
      <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/70" style={{ color: iconColor }}>
        <Icon size={20} />
      </div>
      <p className="mt-4 text-[32px] font-extrabold" style={{ color: C.ink }}>{value}</p>
      <p className="mt-1 text-sm" style={{ color: C.muted }}>{label}</p>
    </div>
  );
}

export default function Dashboard() {
  const { user } = useAuth();

  return (
    <div className="p-6 md:p-8" dir="rtl">
      <div className="mb-8">
        <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
          مرحباً، {user?.first_name || 'مدير'}
        </h1>
        <p className="mt-1 text-sm" style={{ color: C.muted }}>
          هذه نظرة عامة على مدرستك اليوم
        </p>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <KpiCard label="إجمالي التلاميذ" value={0} icon={GraduationCap} tint={C.sage} iconColor={C.forest} />
        <KpiCard label="الإناث" value={0} icon={UserRound} tint={C.rose} iconColor="#A46E67" />
        <KpiCard label="الذكور" value={0} icon={Users} tint={C.beige} iconColor="#8A7C57" />
        <KpiCard label="حاضرون اليوم" value={0} icon={CalendarCheck} tint={C.blush} iconColor="#9A6B7E" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="lg:col-span-2 bg-white rounded-[22px] p-6 border border-[#EDF1E8]">
          <h2 className="font-bold text-lg mb-2" style={{ color: C.ink }}>ملخص سريع</h2>
          <p style={{ color: C.muted }}>
            مرحباً بك في نظام إدارة مدرسة العناية.
          </p>
        </div>

        <div className="rounded-[22px] p-6 flex flex-col items-center justify-center" style={{ background: `linear-gradient(165deg, ${C.forest}, ${C.deep})` }}>
          <p className="text-white/70 text-sm mb-3">توقيت المؤسسة</p>
          <AnalogClock size={140} />
          <p className="mt-3 text-white font-semibold text-sm">مدرسة العناية</p>
        </div>
      </div>
    </div>
  );
}
