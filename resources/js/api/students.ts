export const API_BASE = '/api';

export async function getStudents() {
  const token = localStorage.getItem('token');

  // Enhanced mock for demo mode (works in Google AI Studio)
  if (token === 'mock-token') {
    return [
      {
        id: 1,
        first_name: 'أحمد',
        last_name: 'بن علي',
        student_code: 'PRV-2026-ABC123',
        gender: 'male',
        dob: '2015-03-12',
        guardians: [{ first_name: 'محمد', last_name: 'بن علي', phone: '0612345678' }],
        enrollments: [{
          id: 1,
          level: { name: 'السنة الأولى' },
          section: { name: 'أ' },
          academicYear: { name: '2025-2026' }
        }]
      },
      {
        id: 2,
        first_name: 'فاطمة',
        last_name: 'الزهراء',
        student_code: 'PRV-2026-DEF456',
        gender: 'female',
        dob: '2014-07-22',
        guardians: [{ first_name: 'خديجة', last_name: 'الزهراء', phone: '0698765432' }],
        enrollments: [{
          id: 2,
          level: { name: 'السنة الثانية' },
          section: { name: 'ب' },
          academicYear: { name: '2025-2026' }
        }]
      }
    ];
  }

  const res = await fetch(`${API_BASE}/students`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  if (!res.ok) {
    throw new Error('حدث خطأ أثناء جلب قائمة التلاميذ');
  }
  const data = await res.json();
  return data.data || data;
}

export async function enrollStudent(formData: FormData) {
  const token = localStorage.getItem('token');
  const res = await fetch(`${API_BASE}/students/enroll`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    },
    body: formData // sending FormData directly for file upload
  });

  if (!res.ok) {
    const error = await res.json();
    throw new Error(error.message || 'حدث خطأ أثناء التسجيل');
  }

  return res.json();
}
