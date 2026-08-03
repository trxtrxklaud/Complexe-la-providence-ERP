import { Users } from 'lucide-react';
import { FIELD_CLASS, LABEL_CLASS, type ChangeHandler, type WizardFormData } from './types';

type Props = {
  data: WizardFormData;
  onChange: ChangeHandler;
};

export function GuardianStep({ data, onChange }: Props) {
  return (
    <div className="space-y-8">
      <div className="flex items-center gap-3 pb-6 border-b border-slate-100">
        <div className="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center text-blue-600">
          <Users size={20} />
        </div>
        <div>
          <h2 className="text-xl font-bold text-slate-800">بيانات الولي</h2>
          <p className="text-sm text-slate-500 mt-1">وسيلة الاتصال المعتمدة في الاستخلاص والإشعارات</p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
        <div className="space-y-2.5">
          <label className={LABEL_CLASS}>
            الاسم الأول للولي <span className="text-red-500">*</span>
          </label>
          <input
            type="text"
            name="guardian_first_name"
            value={data.guardian_first_name}
            onChange={onChange}
            className={FIELD_CLASS}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS}>
            لقب الولي <span className="text-red-500">*</span>
          </label>
          <input
            type="text"
            name="guardian_last_name"
            value={data.guardian_last_name}
            onChange={onChange}
            className={FIELD_CLASS}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS}>
            رقم هاتف الولي <span className="text-red-500">*</span>
          </label>
          <input
            type="tel"
            dir="ltr"
            name="guardian_phone"
            value={data.guardian_phone}
            onChange={onChange}
            className={`${FIELD_CLASS} text-right`}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS}>البريد الإلكتروني للولي</label>
          <input
            type="email"
            dir="ltr"
            name="guardian_email"
            value={data.guardian_email}
            onChange={onChange}
            className={`${FIELD_CLASS} text-right`}
          />
        </div>

        <div className="md:col-span-2 space-y-2.5">
          <label className={LABEL_CLASS}>
            العنوان <span className="text-red-500">*</span>
          </label>
          <input
            type="text"
            name="address"
            value={data.address}
            onChange={onChange}
            className={FIELD_CLASS}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS}>رقم هاتف الأم</label>
          <input
            type="tel"
            dir="ltr"
            name="mother_phone"
            value={data.mother_phone}
            onChange={onChange}
            className={`${FIELD_CLASS} text-right`}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS}>البريد الإلكتروني للأم</label>
          <input
            type="email"
            dir="ltr"
            name="mother_email"
            value={data.mother_email}
            onChange={onChange}
            className={`${FIELD_CLASS} text-right`}
          />
        </div>
      </div>
    </div>
  );
}

export default GuardianStep;
