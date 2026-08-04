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
          <label className={LABEL_CLASS} htmlFor="guardian_first_name">
            الاسم الأول للولي <span className="text-red-500">*</span>
          </label>
          <input
            type="text"
            id="guardian_first_name"
            name="guardian_first_name"
            autoComplete="off"
            value={data.guardian_first_name}
            onChange={onChange}
            className={FIELD_CLASS}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="guardian_last_name">
            لقب الولي <span className="text-red-500">*</span>
          </label>
          <input
            type="text"
            id="guardian_last_name"
            name="guardian_last_name"
            autoComplete="off"
            value={data.guardian_last_name}
            onChange={onChange}
            className={FIELD_CLASS}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="guardian_phone">
            رقم هاتف الولي <span className="text-red-500">*</span>
          </label>
          <input
            type="tel"
            dir="ltr"
            id="guardian_phone"
            name="guardian_phone"
            autoComplete="tel"
            inputMode="tel"
            value={data.guardian_phone}
            onChange={onChange}
            className={`${FIELD_CLASS} text-right`}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="guardian_email">البريد الإلكتروني للولي</label>
          <input
            type="email"
            dir="ltr"
            id="guardian_email"
            name="guardian_email"
            autoComplete="email"
            value={data.guardian_email}
            onChange={onChange}
            className={`${FIELD_CLASS} text-right`}
          />
        </div>

        <div className="md:col-span-2 space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="address">
            العنوان <span className="text-red-500">*</span>
          </label>
          <input
            type="text"
            id="address"
            name="address"
            autoComplete="street-address"
            value={data.address}
            onChange={onChange}
            className={FIELD_CLASS}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="mother_phone">رقم هاتف الأم</label>
          <input
            type="tel"
            dir="ltr"
            id="mother_phone"
            name="mother_phone"
            autoComplete="off"
            inputMode="tel"
            value={data.mother_phone}
            onChange={onChange}
            className={`${FIELD_CLASS} text-right`}
          />
        </div>

        <div className="space-y-2.5">
          <label className={LABEL_CLASS} htmlFor="mother_email">البريد الإلكتروني للأم</label>
          <input
            type="email"
            dir="ltr"
            id="mother_email"
            name="mother_email"
            autoComplete="off"
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
