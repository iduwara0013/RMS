'use client';

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';
import { useRmsUser } from '@/lib/use-rms-user';

type User = { name: string; roles: string[] };
type Application = {
  application_id: number;
  status: string;
  candidate: { name: string; nic: string; email: string; phone: string };
  vacancy: { title: string; department?: { department_name: string } | null };
};
type ApiMessage = { message?: string };

const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

export default function ApplicationsPage() {
  const router = useRouter();
  const user = useRmsUser<User>();
  const [applications, setApplications] = useState<Application[]>([]);
  const [message, setMessage] = useState('');
  const [departmentFilter, setDepartmentFilter] = useState('All departments');

  async function load() {
    const response = await fetch(`${API}/applications`);
    if (response.ok) setApplications(((await response.json()) as { applications?: Application[] }).applications ?? []);
  }

  useEffect(() => {
    if (user === undefined) return;
    if (user === null) { router.replace('/'); return; }
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [router, user]);

  async function updateStatus(id: number, status: string) {
    if (!user) return;
    const response = await fetch(`${API}/applications/${id}/status`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-User-Role': user.roles[0] },
      body: JSON.stringify({ status }),
    });
    const payload = await response.json().catch(() => ({})) as ApiMessage;
    setMessage(response.ok ? (payload.message ?? 'Application updated.') : (payload.message ?? 'Unable to update application.'));
    if (response.ok) await load();
  }

  const departmentNames = useMemo(() => Array.from(new Set(applications.map((application) => application.vacancy.department?.department_name ?? 'Unassigned department'))).sort(), [applications]);
  const groupedApplications = useMemo(() => {
    const visible = departmentFilter === 'All departments'
      ? applications
      : applications.filter((application) => (application.vacancy.department?.department_name ?? 'Unassigned department') === departmentFilter);
    return Object.entries(visible.reduce<Record<string, Application[]>>((groups, application) => {
      const department = application.vacancy.department?.department_name ?? 'Unassigned department';
      (groups[department] ??= []).push(application);
      return groups;
    }, {})).sort(([left], [right]) => left.localeCompare(right));
  }, [applications, departmentFilter]);

  if (!user) return <main className="dashboard-loading">Loading applications…</main>;

  return (
    <main className="dashboard-page">
      <div className="app-wrapper rms-adminlte">
        <DashboardNavigation roles={user.roles} />
        <section className="app-main">
          <header className="app-header">
            <span className="app-header__title">Application management</span>
            <span className="app-header__meta">{user.name}<button className="dashboard-signout" onClick={() => { sessionStorage.removeItem('rms_user'); router.replace('/'); }}>Sign out</button></span>
          </header>
          <div className="content-wrapper">
            <div className="content-header"><div><h2>Applications</h2><p>Verify candidate submissions and prepare the shortlist.</p></div><div className="role-badge"><span className="role-badge__dot" />{user.roles.join(' · ')}</div></div>
            {message && <p className="form-message form-message--success">{message}</p>}
            <div className="application-toolbar">
              <div><strong>{applications.length}</strong><span>Total applications</span><small>Across {departmentNames.length} department{departmentNames.length === 1 ? '' : 's'}</small></div>
              <label><span>Filter by department</span><select value={departmentFilter} onChange={(event) => setDepartmentFilter(event.target.value)}><option>All departments</option>{departmentNames.map((department) => <option key={department}>{department}</option>)}</select></label>
            </div>
            <div className="department-groups">{groupedApplications.map(([department, departmentApplications]) => <section className="department-group" key={department}>
              <header className="department-group__header"><div className="department-group__icon" aria-hidden="true">▦</div><div><h3>{department}</h3><p>{departmentApplications.length} application{departmentApplications.length === 1 ? '' : 's'}</p></div><span>{departmentApplications.length}</span></header>
              <div className="applications-list">{departmentApplications.map((application) => <article className="application-row" key={application.application_id}>
                  <div className="application-avatar">{application.candidate.name.charAt(0)}</div>
                  <div className="application-info"><div className="application-row__heading"><h3>{application.candidate.name}</h3><span className="workflow-status">{application.status}</span></div><p>Application #{application.application_id} · {application.vacancy.title} · {application.candidate.nic}</p><small>{application.candidate.email} · {application.candidate.phone}</small></div>
                  <div className="application-actions">
                    {application.status === 'Submitted' && <><button onClick={() => updateStatus(application.application_id, 'Verified')}>Verify</button><button className="application-actions__secondary" onClick={() => updateStatus(application.application_id, 'Rejected')}>Reject</button></>}
                    {application.status === 'Verified' && <button onClick={() => updateStatus(application.application_id, 'Shortlisted')}>Shortlist</button>}
                  </div>
                </article>)}</div>
            </section>)}</div>
            {applications.length === 0 && <div className="vacancy-empty">No applications have been submitted yet.</div>}
            {applications.length > 0 && groupedApplications.length === 0 && <div className="vacancy-empty">No applications are available for the selected department.</div>}
          </div>
        </section>
      </div>
    </main>
  );
}
