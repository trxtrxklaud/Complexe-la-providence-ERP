type PageDataSkeletonProps = {
  cards?: number;
  rows?: number;
};

export function PageDataSkeleton({ cards = 4, rows = 5 }: PageDataSkeletonProps) {
  return (
    <div className="animate-pulse space-y-4" role="status" aria-label="تحميل البيانات">
      <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))' }}>
        {Array.from({ length: cards }).map((_, index) => (
          <div key={index} className="h-24 rounded-2xl border border-[#EDF1E8] bg-white/80 p-4">
            <div className="h-3 w-24 rounded bg-slate-200" />
            <div className="mt-4 h-6 w-32 rounded bg-slate-300/70" />
          </div>
        ))}
      </div>
      <div className="overflow-hidden rounded-2xl border border-[#EDF1E8] bg-white/80">
        <div className="h-12 border-b border-[#EDF1E8] bg-[#E3EBDB]/70" />
        <div className="divide-y divide-[#EDF1E8] px-4">
          {Array.from({ length: rows }).map((_, index) => (
            <div key={index} className="flex items-center gap-6 py-4">
              <div className="h-3 w-1/4 rounded bg-slate-200" />
              <div className="h-3 w-1/3 rounded bg-slate-200" />
              <div className="h-3 w-20 rounded bg-slate-200" />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export function TableRowsSkeleton({ columns, rows = 5 }: { columns: number; rows?: number }) {
  return (
    <>
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <tr key={rowIndex} className="animate-pulse" aria-hidden="true">
          {Array.from({ length: columns }).map((_, columnIndex) => (
            <td key={columnIndex} className="px-6 py-4">
              <div className={`h-3 rounded bg-slate-200 ${columnIndex % 3 === 0 ? 'w-16' : 'w-28'}`} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}

export function ListSkeleton({ rows = 6 }: { rows?: number }) {
  return (
    <div className="divide-y divide-[#EDF1E8] animate-pulse" role="status" aria-label="تحميل القائمة">
      {Array.from({ length: rows }).map((_, index) => (
        <div key={index} className="flex items-center gap-4 p-5">
          <div className="h-11 w-11 shrink-0 rounded-2xl bg-[#E3EBDB]" />
          <div className="flex-1 space-y-2">
            <div className="h-4 w-40 rounded bg-slate-300/70" />
            <div className="h-3 w-28 rounded bg-slate-200" />
          </div>
          <div className="h-9 w-24 rounded-xl bg-slate-200" />
        </div>
      ))}
    </div>
  );
}
