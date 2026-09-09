'use client';

import { staffFetch as fetch, signOutStaff } from '@/lib/staff-fetch';

import { FormEvent, useEffect, useMemo, useState, useSyncExternalStore } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';
import DateRangeFilter, { matchesDateRange } from '@/components/date-range-filter';

type User = { name: string; roles: string[]; department_id?: number | null; department_name?: string | null };
type Department = { department_id: number; department_name: string };
type Vacancy = {
  vacancy_id: number; department_id: number; title: string; description: string; vacancy_type: string;
  vacancy_grade: 'A' | 'B' | 'C'; audience: 'Internal' | 'External' | 'Both'; opening_date: string;
  closing_date: string; status: string; rejection_reason?: string | null; department?: Department;
};
type ApiMessage = { message?: string };
type VacancyCategory = 'ongoing' | 'published' | 'finished';

const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';
const emptyForm = {
  title: '', description: '', vacancy_type: 'Permanent', vacancy_grade: 'C' as Vacancy['vacancy_grade'],
  audience: 'External' as Vacancy['audience'], department_id: '', opening_date: '', closing_date: '',
};

function subscribeToSessionStorage(onStoreChange: () => void) {
  window.addEventListener('storage', onStoreChange);
  return () => window.removeEventListener('storage', onStoreChange);
}
function getStoredUser() { return sessionStorage.getItem('rms_user'); }
const HYDRATING_SESSION = '__rms_session_hydrating__';

export default function ManageVacanciesPage() {
  const router = useRouter();
  const storedUser = useSyncExternalStore(subscribeToSessionStorage, getStoredUser, () => HYDRATING_SESSION);
  const user = useMemo<User | null | undefined>(() => {
    if (storedUser === HYDRATING_SESSION) return undefined;
    if (!storedUser) return null;
    try { return JSON.parse(storedUser) as User; } catch { return null; }
  }, [storedUser]);
  const [vacancies, setVacancies] = useState<Vacancy[]>([]);
  const [departments, setDepartments] = useState<Department[]>([]);
  const [message, setMessage] = useState('');
  const [form, setForm] = useState(emptyForm);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [category, setCategory] = useState<VacancyCategory>('ongoing');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const role = user?.roles[0] ?? '';
  const roles = user?.roles ?? [];
  const categorizedVacancies = useMemo<Record<VacancyCategory, Vacancy[]>>(() => ({
    ongoing: vacancies.filter((vacancy) => !['Published', 'Closed', 'Cancelled'].includes(vacancy.status)),
    published: vacancies.filter((vacancy) => vacancy.status === 'Published'),
    finished: vacancies.filter((vacancy) => ['Closed', 'Cancelled'].includes(vacancy.status)),
  }), [vacancies]);
  const visibleVacancies = useMemo(() => categorizedVacancies[category].filter((vacancy) => matchesDateRange(vacancy.opening_date, fromDate, toDate)), [categorizedVacancies, category, fromDate, toDate]);
  const emptyCategoryMessage: Record<VacancyCategory, string> = {
    ongoing: 'No vacancies are currently moving through the approval workflow.',
    published: 'No vacancies are currently published.',
    finished: 'No recruitment vacancies have been completed yet.',
  };

  async function refreshVacancies(activeUser: User) {
    const response = await fetch(`${API}/vacancies/all`, { headers: {
      'X-User-Role': activeUser.roles[0] ?? '',
      'X-User-Department-Id': String(activeUser.department_id ?? ''),
    } });
    const payload = await response.json().catch(() => ({})) as { vacancies?: Vacancy[]; message?: string };
    if (response.ok) setVacancies(payload.vacancies ?? []);
    else setMessage(payload.message ?? 'Unable to load vacancies.');
  }

  useEffect(() => {
    if (user === undefined) return;
    if (user === null) { router.replace('/'); return; }
    const controller = new AbortController();
    fetch(`${API}/vacancies/all`, {
      headers: {
        'X-User-Role': user.roles[0] ?? '',
        'X-User-Department-Id': String(user.department_id ?? ''),
      },
      signal: controller.signal,
    })
      .then(async (response) => ({ response, payload: await response.json() as { vacancies?: Vacancy[]; message?: string } }))
      .then(({ response, payload }) => {
        if (response.ok) setVacancies(payload.vacancies ?? []);
        else setMessage(payload.message ?? 'Unable to load vacancies.');
      })
      .catch((error: unknown) => {
        if (!(error instanceof DOMException && error.name === 'AbortError')) setMessage('Unable to load vacancies.');
      });
    if (user.roles.includes('HR Manager')) {
      fetch(`${API}/departments`, { signal: controller.signal })
        .then(async (response) => ({ response, payload: await response.json() as { departments?: Department[] } }))
        .then(({ response, payload }) => { if (response.ok) setDepartments(payload.departments ?? []); })
        .catch((error: unknown) => {
          if (!(error instanceof DOMException && error.name === 'AbortError')) setMessage('Unable to load departments.');
        });
    }
    return () => controller.abort();
  }, [router, user]);

  async function action(id: number, operation: string, body: Record<string, string> = {}) {
    if (!user) return;
    const response = await fetch(`${API}/vacancies/${id}/${operation}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-User-Role': role, 'X-User-Department-Id': String(user.department_id ?? '') },
      body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({})) as ApiMessage;
    setMessage(response.ok ? (payload.message ?? 'Action completed.') : (payload.message ?? 'Action could not be completed.'));
    if (response.ok) await refreshVacancies(user);
  }

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!user) return;
    const response = await fetch(editingId ? `${API}/vacancies/${editingId}` : `${API}/vacancies`, {
      method: editingId ? 'PATCH' : 'POST', headers: { 'Content-Type': 'application/json', 'X-User-Role': role },
      body: JSON.stringify({ ...form, department_id: Number(form.department_id) }),
    });
    const payload = await response.json().catch(() => ({})) as ApiMessage;
    setMessage(response.ok ? (editingId ? 'Vacancy updated. Submit for approval again.' : 'Draft vacancy created.') : (payload.message ?? 'Unable to create vacancy.'));
    if (response.ok) { setForm(emptyForm); setEditingId(null); await refreshVacancies(user); }
  }

  if (!user) return <main className="dashboard-loading">Loading vacancies…</main>;

  return <main className="dashboard-page"><div className="app-wrapper rms-adminlte">
    <DashboardNavigation roles={roles} />
    <section className="app-main"><header className="app-header"><span className="app-header__title">Vacancy approval workflow</span><span className="app-header__meta">{user.name}<button className="dashboard-signout" type="button" onClick={() => { void signOutStaff(); }}>Sign out</button></span></header><div className="content-wrapper">
      <div className="content-header"><div><h2>Vacancies</h2><p>Approval steps are selected automatically from the vacancy grade.</p></div><div className="role-badge"><span className="role-badge__dot" />{role}{user.department_name ? ` · ${user.department_name}` : ''}</div></div>{message && <p className="form-message form-message--success">{message}</p>}
      {role === 'HR Manager' && <form className="workflow-create-form" onSubmit={create}><h3>{editingId ? 'Edit vacancy draft' : 'Create vacancy draft'}</h3>{editingId && <button type="button" onClick={() => { setEditingId(null); setForm(emptyForm); }}>Cancel editing</button>}<div className="workflow-form-grid">
        <input required placeholder="Vacancy title" value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} />
        <select required aria-label="Department" value={form.department_id} onChange={(event) => setForm({ ...form, department_id: event.target.value })}><option value="">Select department</option>{departments.map((department) => <option key={department.department_id} value={department.department_id}>{department.department_name}</option>)}</select>
        <select aria-label="Vacancy grade" value={form.vacancy_grade} onChange={(event) => setForm({ ...form, vacancy_grade: event.target.value as 'A' | 'B' | 'C' })}><option value="A">Grade A — HR, HOD and MD approval</option><option value="B">Grade B — HR and HOD approval</option><option value="C">Grade C — HR and HOD approval</option></select>
        <select aria-label="Candidate audience" value={form.audience} onChange={(event) => setForm({ ...form, audience: event.target.value as 'Internal' | 'External' | 'Both' })}><option value="Internal">Internal candidates only</option><option value="External">External candidates only</option><option value="Both">Internal and external candidates</option></select>
        <input required placeholder="Vacancy type" value={form.vacancy_type} onChange={(event) => setForm({ ...form, vacancy_type: event.target.value })} /><input required type="date" aria-label="Opening date" value={form.opening_date} onChange={(event) => setForm({ ...form, opening_date: event.target.value })} /><input required type="date" aria-label="Closing date" value={form.closing_date} onChange={(event) => setForm({ ...form, closing_date: event.target.value })} /><textarea required placeholder="Description" value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} />
      </div><button className="submit-button workflow-submit" type="submit"><span>{editingId ? 'Save changes' : 'Save draft'}</span><span>→</span></button></form>}
      <div className="workflow-category-tabs" role="tablist" aria-label="Vacancy categories">
        {([
          ['ongoing', 'Ongoing'],
          ['published', 'Published'],
          ['finished', 'Finished'],
        ] as const).map(([value, label]) => <button
          key={value}
          type="button"
          role="tab"
          aria-selected={category === value}
          className={`workflow-category-tab${category === value ? ' active' : ''}`}
          onClick={() => setCategory(value)}
        ><span>{label}</span><strong>{categorizedVacancies[value].length}</strong></button>)}
      </div>
      <DateRangeFilter fromDate={fromDate} toDate={toDate} dateLabel="opening date" onFromDateChange={setFromDate} onToDateChange={setToDate} />
      <div className="workflow-list" role="tabpanel">{visibleVacancies.map((vacancy) => <article className="workflow-row" key={vacancy.vacancy_id}><div><span className={`workflow-status workflow-status--${vacancy.status.toLowerCase().replaceAll(' ', '-')}`}>{vacancy.status}</span><h3>{vacancy.title}</h3><p>{vacancy.description}</p><small>{vacancy.department?.department_name ?? 'Unassigned department'} · Grade {vacancy.vacancy_grade} · {vacancy.audience} · {vacancy.vacancy_grade === 'A' ? 'HR → HOD → MD' : 'HR → HOD'} · Open {vacancy.opening_date} · Close {vacancy.closing_date}</small>{vacancy.rejection_reason && <p className="workflow-rejection">Returned: {vacancy.rejection_reason}</p>}</div><div className="workflow-actions">
        {role === 'HR Manager' && ['Draft', 'Rejected'].includes(vacancy.status) && <button type="button" onClick={() => { setEditingId(vacancy.vacancy_id); setForm({ title: vacancy.title, description: vacancy.description, vacancy_type: vacancy.vacancy_type, vacancy_grade: vacancy.vacancy_grade, audience: vacancy.audience, department_id: String(vacancy.department_id), opening_date: vacancy.opening_date.slice(0, 10), closing_date: vacancy.closing_date.slice(0, 10) }); window.scrollTo({ top: 0, behavior: 'smooth' }); }}>Edit vacancy</button>}
        {role === 'HR Manager' && ['Draft', 'Rejected'].includes(vacancy.status) && <button type="button" onClick={() => action(vacancy.vacancy_id, 'submit')}>HR approve &amp; send to HOD</button>}
        {role === 'Head of Department' && vacancy.status === 'Pending HOD Approval' && <><button type="button" onClick={() => action(vacancy.vacancy_id, 'hod-approve')}>{vacancy.vacancy_grade === 'A' ? 'Approve & send to MD' : 'Approve'}</button><button type="button" onClick={() => action(vacancy.vacancy_id, 'reject', { rejection_reason: 'Please revise the vacancy details.' })}>Send back</button></>}
        {role === 'Managing Director' && vacancy.vacancy_grade === 'A' && vacancy.status === 'Pending MD Approval' && <><button type="button" onClick={() => action(vacancy.vacancy_id, 'md-approve')}>Final approve</button><button type="button" onClick={() => action(vacancy.vacancy_id, 'reject', { rejection_reason: 'Please revise the vacancy details.' })}>Reject</button></>}
        {role === 'HR Manager' && vacancy.status === 'Approved' && <button type="button" onClick={() => action(vacancy.vacancy_id, 'publish')}>Publish</button>}
        {role === 'HR Manager' && ['Draft', 'Rejected', 'Pending HOD Approval', 'Pending MD Approval', 'Approved'].includes(vacancy.status) && <button className="workflow-actions__secondary" type="button" onClick={() => { if (window.confirm(`Cancel ${vacancy.title}? This vacancy will move to Finished.`)) void action(vacancy.vacancy_id, 'cancel'); }}>Cancel vacancy</button>}
        {role === 'HR Manager' && vacancy.status === 'Published' && <button className="workflow-actions__secondary" type="button" onClick={() => { if (window.confirm(`Close ${vacancy.title}? It will stop accepting applications and move to Finished.`)) void action(vacancy.vacancy_id, 'close'); }}>Close vacancy</button>}
      </div></article>)}{visibleVacancies.length === 0 && <div className="vacancy-empty">{fromDate || toDate ? `No ${category} vacancies match the selected opening-date range.` : emptyCategoryMessage[category]}</div>}</div>
    </div></section>
  </div></main>;
}
