import axios from 'axios';
import type {
  Payment,
  StorePaymentPayload,
  StudentFeesEnrollment,
  PaginatedResponse,
} from '../types';

export interface PaymentFilters {
  student_id?:    number;
  enrollment_id?: number;
  method?:        string;
  date_from?:     string;
  date_to?:       string;
  per_page?:      number;
  page?:          number;
}

export const paymentsApi = {
  index: (filters?: PaymentFilters) =>
    axios
      .get<PaginatedResponse<Payment>>('/api/payments', { params: filters })
      .then((r) => r.data),

  store: (data: StorePaymentPayload) =>
    axios.post<Payment>('/api/payments', data).then((r) => r.data),

  show: (id: number) =>
    axios.get<Payment>(`/api/payments/${id}`).then((r) => r.data),

  destroy: (id: number) =>
    axios.delete(`/api/payments/${id}`).then((r) => r.data),
};

export const studentFeesApi = {
  balance: (studentId: number) =>
    axios
      .get<{ student_id: number; balance: number }>(`/api/students/${studentId}/balance`)
      .then((r) => r.data),

  fees: (studentId: number, enrollmentId?: number) =>
    axios
      .get<StudentFeesEnrollment[]>(`/api/students/${studentId}/fees`, {
        params: enrollmentId ? { enrollment_id: enrollmentId } : undefined,
      })
      .then((r) => r.data),
};
