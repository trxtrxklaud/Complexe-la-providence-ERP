import { getHeaders } from './http';

export async function fetchStudentPhotoUrl(studentId: number): Promise<string | null> {
  const res = await fetch(`/api/students/${studentId}/photo`, { headers: getHeaders() });
  if (res.ok === false) return null;
  return URL.createObjectURL(await res.blob());
}
