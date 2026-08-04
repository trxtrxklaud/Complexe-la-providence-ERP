import type { ChangeEvent } from 'react';

export type WizardFormData = {
  first_name: string;
  last_name: string;
  dob: string;
  gender: string;
  section_id: string;
  notes: string;
  guardian_first_name: string;
  guardian_last_name: string;
  guardian_phone: string;
  guardian_email: string;
  address: string;
  mother_phone: string;
  mother_email: string;
  payment_method: string;
  registration_amount: string;
  payment_date: string;
  payment_notes: string;
};

export const EMPTY_FORM: WizardFormData = {
  first_name: '',
  last_name: '',
  dob: '',
  gender: '',
  section_id: '',
  notes: '',
  guardian_first_name: '',
  guardian_last_name: '',
  guardian_phone: '',
  guardian_email: '',
  address: '',
  mother_phone: '',
  mother_email: '',
  payment_method: '',
  registration_amount: '',
  payment_date: '',
  payment_notes: '',
};

export type ChangeHandler = (
  e: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>,
) => void;

export const FIELD_CLASS =
  'block w-full px-4 py-3 bg-slate-50/50 hover:bg-white border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200 text-slate-700 placeholder:text-slate-400';

export const LABEL_CLASS = 'block text-sm font-semibold text-slate-700';
