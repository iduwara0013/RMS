'use client';

import { useEffect, useMemo, useState, useSyncExternalStore } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';

type User = { name: string; roles: string[]; employee_epf: string; employee_pin: string; department_id?: number | null; department_name?: string | null };
type DashboardSummary = { open_tasks: number; applications: number; system_status: string };

function subscribeToSessionStorage(onStoreChange: () => void) {
  window.addEventListener('storage', onStoreChange);
  return () => window.removeEventListener('storage', onStoreChange);
}

function getStoredUser() {
  return sessionStorage.getItem('rms_user');
}

const HYDRATING_SESSION = '__rms_session_hydrating__';

function isDashboardSummary(value: unknown): value is DashboardSummary {
  if (!value || typeof value !== 'object') return false;
  const summary = value as Record<string, unknown>;
  return typeof summary.open_tasks === 'number'
    && typeof summary.applications === 'number'
    && typeof summary.system_status === 'string';
}

const actionMap: Record<string, { icon: string; title: string; description: string; href: string }[]> = {
  'System Administrator': [
    { icon: '＋', title: 'Review vacancies', description: 'Monitor recruitment vacancy workflows.', href: '/dashboard/vacancies' },
    { icon: '▣', title: 'View notifications', description: 'Review recorded candidate communications.', href: '/dashboard/notifications' },
    { icon: '✓', title: 'Completed candidates', description: 'Review finalized recruitment outcomes.', href: '/dashboard/completed' },
  ],
  'HR Manager': [
    { icon: '＋', title: 'Manage vacancies', description: 'Create and publish recruitment vacancies.', href: '/dashboard/vacancies' },
    { icon: '◌', title: 'Review applications', description: 'Verify candidate submissions.', href: '/dashboard/applications' },
    { icon: '★', title: 'Shortlist candidates', description: 'Prepare candidates for interviews.', href: '/dashboard/applications' },
    { icon: '✓', title: 'Completed candidates', description: 'Review finalized recruitment outcomes.', href: '/dashboard/completed' },
  ],
  'Head of Department': [{ icon: '✓', title: 'Review vacancies', description: 'Review and approve department requests.', href: '/dashboard/vacancies' }],
  'Managing Director': [{ icon: '◆', title: 'Final approvals', description: 'Approve or reject final candidate selections.', href: '/dashboard/selections' }, { icon: '✓', title: 'Completed candidates', description: 'Review finalized recruitment outcomes.', href: '/dashboard/completed' }],
  'Data Entry Operator': [{ icon: '✎', title: 'Enter candidate data', description: 'Capture and review candidate applications.', href: '/dashboard/applications' }],
  'Interview Panel Member': [{ icon: '▤', title: 'Evaluate interviews', description: 'Record scores and interview recommendations.', href: '/dashboard/interviews' }],
};
const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

export default function DashboardPage() {
  const router = useRouter();
  const storedUser = useSyncExternalStore(subscribeToSessionStorage, getStoredUser, () => HYDRATING_SESSION);
  const user = useMemo<User | null | undefined>(() => {
    if (storedUser === HYDRATING_SESSION) return undefined;
    if (!storedUser) return null;
    try {
      return JSON.parse(storedUser) as User;
    } catch {
      return null;
    }
  }, [storedUser]);
  const [summary, setSummary] = useState<DashboardSummary>({ open_tasks: 0, applications: 0, system_status: 'Online' });

  useEffect(() => {
    if (user === undefined) return;
    if (user === null) {
      router.replace('/');
      return;
    }

    const controller = new AbortController();
    async function loadSummary() {
      try {
        const response = await fetch(`${API}/dashboard/summary`, {
          headers: {
            'X-User-Role': user?.roles[0] ?? '',
            'X-User-Department-Id': String(user?.department_id ?? ''),
          },
          signal: controller.signal,
        });
        if (!response.ok) return;
        const data: unknown = await response.json();
        if (!controller.signal.aborted && isDashboardSummary(data)) setSummary(data);
      } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
          console.error('Unable to load dashboard summary.', error);
        }
      }
    }

    void loadSummary();
    return () => controller.abort();
  }, [router, user]);

  function signOut() { sessionStorage.removeItem('rms_user'); router.replace('/'); }
  const roles = user?.roles ?? [];
  const actions = roles.flatMap((role) => actionMap[role] ?? []);

  if (!user) return <main className="dashboard-loading">Loading workspace…</main>;

  return (
    <main className="dashboard-page">
      <div className="app-wrapper rms-adminlte">
        <DashboardNavigation roles={roles} />
        <section className="app-main">
          <header className="app-header"><span className="app-header__title">Recruitment Management System</span><span className="app-header__meta"><span className="app-header__avatar">{user.name.charAt(0)}</span>{user.name}<button className="dashboard-signout" type="button" onClick={signOut}>Sign out</button></span></header>
          <div className="content-wrapper">
            <div className="content-header"><div><h2>Dashboard</h2><p>Welcome back. Here is your recruitment workspace overview.</p></div><div className="role-badge"><span className="role-badge__dot" />{roles.join(' · ') || 'Staff'}</div></div>
            <div className="small-boxes"><div className="small-box small-box--red"><strong>{summary.open_tasks}</strong><span>Open tasks</span><small>Awaiting your action</small></div><div className="small-box small-box--navy"><strong>{summary.applications}</strong><span>Applications</span><small>Across active vacancies</small></div><div className="small-box small-box--green"><strong>{summary.system_status}</strong><span>System status</span><small>Live database status</small></div></div>
            <div className="dashboard-panel"><h3>Role-based actions</h3><p className="dashboard-panel__hint">Choose an operation available to your assigned designation.</p><div className="dashboard-actions">
              {actions.map((action) => <button className="dashboard-action" type="button" key={`${action.title}-${action.href}`} onClick={() => router.push(action.href)}><span className="dashboard-action__icon">{action.icon}</span><span><strong>{action.title}</strong><small>{action.description}</small></span><span className="dashboard-action__arrow">→</span></button>)}
            </div></div>
          </div>
        </section>
      </div>
    </main>
  );
}
