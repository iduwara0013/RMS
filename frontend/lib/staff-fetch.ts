const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

export function staffFetch(input: RequestInfo | URL, init: RequestInit = {}) {
  const headers = new Headers(init.headers);
  headers.set('Authorization', `Bearer ${sessionStorage.getItem('rms_staff_token') ?? ''}`);
  if (!headers.has('Accept')) headers.set('Accept', 'application/json');
  return fetch(input, { ...init, headers });
}

export async function signOutStaff() {
  try { await staffFetch(`${API}/logout`, { method: 'POST' }); }
  finally {
    sessionStorage.removeItem('rms_user');
    sessionStorage.removeItem('rms_staff_token');
    window.location.assign('/');
  }
}
