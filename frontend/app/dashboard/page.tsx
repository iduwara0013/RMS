'use client';

import Image from 'next/image';
import { useEffect, useMemo, useState, useSyncExternalStore } from 'react';
import { useRouter } from 'next/navigation';

type User = { name: string; roles: string[]; employee_epf: string; employee_pin: string };
type DashboardSummary = { open_tasks: number; applications: number; system_status: string };

function subscribeToSessionStorage(onStoreChange: () => void) {
  window.addEventListener('storage', onStoreChange);
  return () => window.removeEventListener('storage', onStoreChange);
}

function getStoredUser() {
  return sessionStorage.getItem('rms_user');
}

function isDashboardSummary(value: unknown): value is DashboardSummary {
  if (!value || typeof value !== 'object') return false;
  const summary = value as Record<string, unknown>;
  return typeof summary.open_tasks === 'number'
    && typeof summary.applications === 'number'
    && typeof summary.system_status === 'string';
}

const actionMap: Record<string, { icon: string; title: string; description: string }[]> = {
  'System Administrator': [
    { icon: '◎', title: 'Manage users', description: 'Create staff accounts and assign access.' },
    { icon: '◇', title: 'Manage roles', description: 'Review roles and permissions.' },
    { icon: '▦', title: 'Manage departments', description: 'Maintain department records.' },
  ],
  'HR Manager': [
    { icon: '＋', title: 'Manage vacancies', description: 'Create and publish recruitment vacancies.' },
    { icon: '◌', title: 'Review applications', description: 'Verify candidate submissions.' },
    { icon: '★', title: 'Shortlist candidates', description: 'Prepare candidates for interviews.' },
  ],
  'Head of Department': [{ icon: '✓', title: 'Review vacancies', description: 'Review and approve department requests.' }],
  'Managing Director': [{ icon: '◆', title: 'Final approvals', description: 'Approve or reject recruitment workflows.' }],
  'Data Entry Operator': [{ icon: '✎', title: 'Enter candidate data', description: 'Capture applications and supporting documents.' }],
  'Interview Panel Member': [{ icon: '▤', title: 'Evaluate interviews', description: 'Record scores and interview recommendations.' }],
};
const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

export default function DashboardPage() {
  const router = useRouter();
  const storedUser = useSyncExternalStore(subscribeToSessionStorage, getStoredUser, () => null);
  const user = useMemo<User | null>(() => {
    if (!storedUser) return null;
    try {
      return JSON.parse(storedUser) as User;
    } catch {
      return null;
    }
  }, [storedUser]);
  const [summary, setSummary] = useState<DashboardSummary>({ open_tasks: 0, applications: 0, system_status: 'Online' });

  useEffect(() => {
    if (!user) {
      router.replace('/');
      return;
    }

    const controller = new AbortController();
    async function loadSummary() {
      try {
        const response = await fetch(`${API}/dashboard/summary`, {
          headers: { 'X-User-Role': user?.roles[0] ?? '' },
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
        <aside className="app-sidebar">
          <a className="sidebar-brand" href="/dashboard"><Image src="/cpstl-logo.png" alt="CPSTL logo" width={48} height={48} priority /><span><strong>CPSTL RMS</strong><small>Recruitment portal</small></span></a>
          <div className="sidebar-wrapper"><nav aria-label="Dashboard navigation">
            <div className="nav-header">Workspace</div>
            <a className="nav-link active" href="/dashboard"><span className="nav-icon">⌂</span>Dashboard</a>
            {roles.some((role) => ['HR Manager', 'Head of Department', 'Managing Director', 'System Administrator'].includes(role)) && <a className="nav-link" href="/dashboard/vacancies"><span className="nav-icon">＋</span>{roles.includes('HR Manager') ? 'Vacancies' : 'Approvals'}</a>}
            {roles.some((role) => ['HR Manager', 'Data Entry Operator'].includes(role)) && <a className="nav-link" href="/dashboard/applications"><span className="nav-icon">◌</span>Applications</a>}
            {roles.some((role) => ['HR Manager', 'Interview Panel Member'].includes(role)) && <a className="nav-link" href="/dashboard/interviews"><span className="nav-icon">▤</span>Interviews</a>}
            {roles.some((role) => ['HR Manager', 'Managing Director'].includes(role)) && <a className="nav-link" href="/dashboard/selections"><span className="nav-icon">◆</span>Final selection</a>}
            {roles.some((role) => ['HR Manager', 'System Administrator'].includes(role)) && <a className="nav-link" href="/dashboard/notifications"><span className="nav-icon">▣</span>Notifications</a>}
            <div className="nav-header">Account</div><a className="nav-link" href="#profile"><span className="nav-icon">◉</span>My profile</a>
            <a className="nav-link" href="/vacancies"><span className="nav-icon">↗</span>Public careers</a>
          </nav></div>
        </aside>
        <section className="app-main">
          <header className="app-header"><span className="app-header__title">Recruitment Management System</span><span className="app-header__meta"><span className="app-header__avatar">{user.name.charAt(0)}</span>{user.name}<button className="dashboard-signout" type="button" onClick={signOut}>Sign out</button></span></header>
          <div className="content-wrapper">
            <div className="content-header"><div><h2>Dashboard</h2><p>Welcome back. Here is your recruitment workspace overview.</p></div><div className="role-badge"><span className="role-badge__dot" />{roles.join(' · ') || 'Staff'}</div></div>
            <div className="small-boxes"><div className="small-box small-box--red"><strong>{summary.open_tasks}</strong><span>Open tasks</span><small>Awaiting your action</small></div><div className="small-box small-box--navy"><strong>{summary.applications}</strong><span>Applications</span><small>Across active vacancies</small></div><div className="small-box small-box--green"><strong>{summary.system_status}</strong><span>System status</span><small>Live database status</small></div></div>
            <div className="dashboard-panel"><h3>Role-based actions</h3><p className="dashboard-panel__hint">Choose an operation available to your assigned designation.</p><div className="dashboard-actions">
              {actions.map((action) => <button className="dashboard-action" type="button" key={action.title} onClick={() => { const title = action.title.toLowerCase(); if (title.includes('vacanc')) router.push('/dashboard/vacancies'); else if (title.includes('approval')) router.push(title.includes('final') ? '/dashboard/selections' : '/dashboard/vacancies'); else if (title.includes('application') || title.includes('shortlist')) router.push('/dashboard/applications'); else if (title.includes('interview')) router.push('/dashboard/interviews'); }}><span className="dashboard-action__icon">{action.icon}</span><span><strong>{action.title}</strong><small>{action.description}</small></span><span className="dashboard-action__arrow">→</span></button>)}
            </div></div>
          </div>
        </section>
      </div>
    </main>
  );
}
