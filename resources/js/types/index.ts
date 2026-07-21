export interface Permission {
    id: number;
    name: string;
    display_name: string;
}
export interface Role {
    id: number;
    name: string;
    display_name: string;
    permissions?: Permission[];
}
export interface User {
    id: number;
    first_name: string;
    last_name: string;
    username: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    role_id: number;
    role?: Role;
}

// ─── Pagination ───────────────────────────────────────────────────────────────
export interface PaginatedResponse<T> {
  data:         T[];
  current_page: number;
  last_page:    number;
  per_page:     number;
  total:        number;
  from:         number;
  to:           number;
}

// ─── Reference models ────────────────────────────────────────────────────────
export interface AcademicYear {
  id:         number;
  name:       string;
  start_date: string;
  end_date:   string;
  is_active:  boolean;
}

export interface Level {
  id:    number;
  name:  string;
  code:  string;
  order: number;
}

export interface Section {
  id:        number;
  level_id:  number;
  name:      string;
  code:      string;
  capacity:  number;
}

// ─── Enrollment ───────────────────────────────────────────────────────────────
export type EnrollmentStatus =
  | 'active' | 'inactive' | 'transferred' | 'graduated' | 'expelled';

export interface Enrollment {
  id:                     number;
  student_id:             number;
  academic_year_id:       number;
  level_id:               number;
  section_id:             number | null;
  enrollment_date:        string;
  status:                 EnrollmentStatus;
  previous_enrollment_id: number | null;
  notes:                  string | null;
  deleted_at:             string | null;
  created_at:             string;
  updated_at:             string;
  student?:               Student;
  academic_year?:         AcademicYear;
  level?:                 Level;
}

// ─── Fees ────────────────────────────────────────────────────────────────────
export type FeeStatus = 'pending' | 'partial' | 'paid' | 'overdue' | 'cancelled';

export interface StudentFee {
  id:            number;
  enrollment_id: number;
  fee_plan_id:   number;
  description:   string;
  amount_due:    number;
  due_date:      string;
  status:        FeeStatus;
  created_at:    string;
  updated_at:    string;
  allocated?:    number;
  remaining?:    number;
}

export interface StudentFeesEnrollment {
  enrollment_id: number;
  academic_year: Pick<AcademicYear, 'id' | 'name'>;
  level:         Pick<Level, 'id' | 'name'>;
  status:        EnrollmentStatus;
  fees:          Array<StudentFee & { allocated: number; remaining: number }>;
}

// ─── Payments ─────────────────────────────────────────────────────────────────
export type PaymentMethod = 'cash' | 'bank_transfer' | 'check' | 'card';

export interface PaymentAllocation {
  id:               number;
  payment_id:       number;
  student_fee_id:   number;
  amount_allocated: number;
  student_fee?:     StudentFee;
}

export interface Payment {
  id:                   number;
  student_id:           number;
  enrollment_id:        number | null;
  amount:               number;
  payment_date:         string;
  method:               PaymentMethod;
  reference:            string | null;
  notes:                string | null;
  created_by:           number | null;
  created_at:           string;
  updated_at:           string;
  student?:             Pick<Student, 'id' | 'first_name' | 'last_name' | 'student_code'>;
  enrollment?:          Enrollment;
  payment_allocations?: PaymentAllocation[];
}

export interface StorePaymentPayload {
  student_id:     number;
  enrollment_id?: number;
  amount:         number;
  payment_date:   string;
  method:         PaymentMethod;
  reference?:     string;
  notes?:         string;
  allocations?: Array<{
    student_fee_id: number;
    amount:         number;
  }>;
}
