import React from "react";
import { Link } from "react-router-dom";
import { UserPlus, UserCheck, ArrowRight } from "lucide-react";

const C = {
  forest: "#3B4A36",
  sage: "#E3EBDB",
  ink: "#1F261C",
  muted: "#7C8677",
  line: "#EDF1E8",
  beige: "#EFEAE0",
};

export function EnrollWizard() {
  return (
    <div className="p-6 md:p-8" dir="rtl">
      <div className="flex items-center gap-4 mb-10">
        <Link
          to="/students"
          className="flex h-10 w-10 items-center justify-center rounded-full bg-white border transition hover:bg-gray-50"
          style={{ borderColor: C.line, color: C.muted }}
        >
          <ArrowRight size={18} />
        </Link>
        <div>
          <h1 className="text-2xl font-bold" style={{ color: C.ink }}>
            ترسيم التلاميذ
          </h1>
          <p className="mt-1 text-sm" style={{ color: C.muted }}>
            اختر نوع الترسيم لمتابعة العملية
          </p>
        </div>
      </div>

      <div className="max-w-3xl mx-auto">
        <h2 className="text-center text-xl font-bold mb-8" style={{ color: C.ink }}>
          ما هو نوع الترسيم الذي ترغب بالقيام به؟
        </h2>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <Link
            to="/students/enroll/new"
            className="group bg-white rounded-[24px] p-8 text-center border transition-all hover:-translate-y-1 hover:shadow-lg"
            style={{ borderColor: C.line }}
          >
            <div
              className="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full"
              style={{ backgroundColor: C.sage, color: C.forest }}
            >
              <UserPlus size={36} strokeWidth={1.5} />
            </div>
            <h3 className="text-xl font-bold mb-2" style={{ color: C.ink }}>
              تلميذ جديد
            </h3>
            <p className="text-sm leading-relaxed" style={{ color: C.muted }}>
              إضافة تلميذ جديد إلى المنظومة وإدخال كافة بياناته الشخصية والدراسية لأول مرة.
            </p>
          </Link>

          <Link
            to="/students/enroll/old"
            className="group bg-white rounded-[24px] p-8 text-center border transition-all hover:-translate-y-1 hover:shadow-lg"
            style={{ borderColor: C.line }}
          >
            <div
              className="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full"
              style={{ backgroundColor: C.beige, color: "#8A7C57" }}
            >
              <UserCheck size={36} strokeWidth={1.5} />
            </div>
            <h3 className="text-xl font-bold mb-2" style={{ color: C.ink }}>
              تلميذ قديم
            </h3>
            <p className="text-sm leading-relaxed" style={{ color: C.muted }}>
              تجديد تسجيل تلميذ موجود مسبقاً في المنظومة وتحديث بياناته للسنة الدراسية الجديدة.
            </p>
          </Link>
        </div>
      </div>
    </div>
  );
}
