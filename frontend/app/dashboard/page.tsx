'use client';

import { staffFetch as fetch, signOutStaff } from '@/lib/staff-fetch';

import { useEffect, useMemo, useState, useSyncExternalStore } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';

type User = { name: string; roles: string[]; employee_epf: string; employee_pin: string; department_id?: number | null; department_name?: string | null };
type FunnelStage = { key: string; label: string; count: number };
type PendingTask = { icon: string; label: string; description: string; count: number; href: string };
type MetricTone = 'red' | 'navy' | 'yellow' | 'green';
type MetricCard = { key: string; label: string; value: number; help: string; tone: MetricTone };
type DashboardSummary = { open_tasks: number; applications: number; active_applications: number; published_vacancies: number; closing_soon: number; scheduled_interviews: number; system_status: string; metric_cards: MetricCard[]; funnel: FunnelStage[]; pending_tasks: PendingTask[] };

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
    && typeof summary.system_status === 'string'
    && Array.isArray(summary.metric_cards)
    && Array.isArray(summary.funnel)
    && Array.isArray(summary.pending_tasks);
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
  'Head of Department': [{ icon: '✓', title: 'Review vacancies', description: 'Review and approve department requests.', href: '/dashboard/vacancies' }, { icon: '◌', title: 'Department applications', description: 'Review candidates for your department.', href: '/dashboard/applications' }],
  'Managing Director': [{ icon: '＋', title: 'Approve Grade A vacancies', description: 'Review executive-grade vacancy requests.', href: '/dashboard/vacancies' }, { icon: '◆', title: 'Final candidate approvals', description: 'Approve or reject final candidate selections.', href: '/dashboard/selections' }, { icon: '✓', title: 'Completed candidates', description: 'Review finalized recruitment outcomes.', href: '/dashboard/completed' }],
  'Data Entry Operator': [{ icon: '✎', title: 'Review candidate records', description: 'Check newly submitted application details.', href: '/dashboard/applications' }],
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
  const [summary, setSummary] = useState<DashboardSummary>({ open_tasks: 0, applications: 0, active_applications: 0, published_vacancies: 0, closing_soon: 0, scheduled_interviews: 0, system_status: 'Online', metric_cards: [], funnel: [], pending_tasks: [] });

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

  function signOut() { void signOutStaff(); }
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
            <div className="small-boxes dashboard-metrics">{summary.metric_cards.map((metric) => <div className={`small-box small-box--${metric.tone}`} key={metric.key}><strong>{metric.value}</strong><span>{metric.label}</span><small>{metric.help}</small></div>)}</div>
            <div className="dashboard-overview-grid"><section className="dashboard-panel dashboard-funnel"><div className="dashboard-panel__title-row"><div><h3>Recruitment progress</h3><p className="dashboard-panel__hint">Applications moving through the hiring process.</p></div><span className="dashboard-panel__status"><i />{summary.system_status}</span></div><div className="recruitment-funnel">{summary.funnel.map((stage, index) => <div className="recruitment-funnel__step" key={stage.key}><div className="recruitment-funnel__bar"><strong>{stage.count}</strong><span style={{ height: `${Math.max(stage.count > 0 ? 18 : 8, Math.min(100, (stage.count / Math.max(summary.applications, 1)) * 100))}%` }} /></div><span>{stage.label}</span>{index < summary.funnel.length - 1 && <b aria-hidden="true">→</b>}</div>)}</div></section><section className="dashboard-panel dashboard-pending"><div className="dashboard-panel__title-row"><div><h3>My pending tasks</h3><p className="dashboard-panel__hint">Open an item to continue your work.</p></div><span className="dashboard-panel__count">{summary.open_tasks}</span></div><div className="pending-task-list">{summary.pending_tasks.length ? summary.pending_tasks.map((task) => <button className="pending-task" type="button" key={`${task.href}-${task.label}`} onClick={() => router.push(task.href)}><span className="pending-task__icon">{task.icon}</span><span><strong>{task.label}</strong><small>{task.description}</small></span><b>{task.count}<i aria-hidden="true">→</i></b></button>) : <p className="dashboard-empty">You have no pending tasks.</p>}</div></section></div>
            <div className="dashboard-panel"><h3>Role-based actions</h3><p className="dashboard-panel__hint">Choose an operation available to your assigned designation.</p><div className="dashboard-actions">
              {actions.map((action) => <button className="dashboard-action" type="button" key={`${action.title}-${action.href}`} onClick={() => router.push(action.href)}><span className="dashboard-action__icon">{action.icon}</span><span><strong>{action.title}</strong><small>{action.description}</small></span><span className="dashboard-action__arrow">→</span></button>)}
            </div></div>
          </div>
        </section>
      </div>
    </main>
  );
}
