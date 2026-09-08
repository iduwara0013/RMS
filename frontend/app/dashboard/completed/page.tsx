'use client';

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';
import { useRmsUser } from '@/lib/use-rms-user';

type User = { name: string; roles: string[] };
type CompletedCandidate = {
  application_id: number;
  applicant_type: 'Internal' | 'External';
  submitted_at: string;
  updated_at: string;
  status: 'Selected' | 'Not Selected';
  candidate: { name: string; nic: string; email: string; phone: string; address: string };
  vacancy: { title: string; vacancy_grade: string; department?: { department_name: string } | null };
};

const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

export default function CompletedCandidatesPage() {
  const router = useRouter();
  const user = useRmsUser<User>();
  const [candidates, setCandidates] = useState<CompletedCandidate[]>([]);
  const [departmentFilter, setDepartmentFilter] = useState('All departments');
  const [outcomeFilter, setOutcomeFilter] = useState('All outcomes');
  const [error, setError] = useState('');

  useEffect(() => {
    if (user === undefined) return;
    if (user === null) { router.replace('/'); return; }
    fetch(`${API}/applications/completed`, { headers: { 'X-User-Role': user.roles[0] } }).then(async (response) => {
      const payload = await response.json().catch(() => ({})) as { candidates?: CompletedCandidate[]; message?: string };
      if (response.ok) setCandidates(payload.candidates ?? []);
      else setError(payload.message ?? 'Unable to load completed candidates.');
    }).catch(() => setError('Unable to load completed candidates.'));
  }, [router, user]);

  const departmentNames = useMemo(() => Array.from(new Set(candidates.map((candidate) => candidate.vacancy.department?.department_name ?? 'Unassigned department'))).sort(), [candidates]);
  const groupedCandidates = useMemo(() => Object.entries(candidates.reduce<Record<string, CompletedCandidate[]>>((groups, candidate) => {
    const department = candidate.vacancy.department?.department_name ?? 'Unassigned department';
    const departmentMatches = departmentFilter === 'All departments' || departmentFilter === department;
    const outcomeMatches = outcomeFilter === 'All outcomes' || outcomeFilter === candidate.status;
    if (departmentMatches && outcomeMatches) (groups[department] ??= []).push(candidate);
    return groups;
  }, {})).sort(([left], [right]) => left.localeCompare(right)), [candidates, departmentFilter, outcomeFilter]);

  if (!user) return <main className="dashboard-loading">Loading completed candidates…</main>;
  const selectedCount = candidates.filter((candidate) => candidate.status === 'Selected').length;

  return <main className="dashboard-page"><div className="app-wrapper rms-adminlte">
    <DashboardNavigation roles={user.roles} />
    <section className="app-main"><header className="app-header"><span className="app-header__title">Completed candidates</span><span className="app-header__meta">{user.name}<button className="dashboard-signout" type="button" onClick={() => { sessionStorage.removeItem('rms_user'); router.replace('/'); }}>Sign out</button></span></header><div className="content-wrapper">
      <div className="content-header"><div><h2>Completed candidates</h2><p>Review candidates whose recruitment process has been finalized.</p></div><div className="role-badge"><span className="role-badge__dot" />{candidates.length} completed</div></div>
      {error && <p className="form-message form-message--error">{error}</p>}
      <div className="completion-summary"><div><span>Total completed</span><strong>{candidates.length}</strong></div><div><span>Selected</span><strong>{selectedCount}</strong></div><div><span>Not selected</span><strong>{candidates.length - selectedCount}</strong></div></div>
      <div className="completed-toolbar"><label><span>Department</span><select value={departmentFilter} onChange={(event) => setDepartmentFilter(event.target.value)}><option>All departments</option>{departmentNames.map((department) => <option key={department}>{department}</option>)}</select></label><label><span>Final outcome</span><select value={outcomeFilter} onChange={(event) => setOutcomeFilter(event.target.value)}><option>All outcomes</option><option>Selected</option><option>Not Selected</option></select></label></div>
      <div className="department-groups">{groupedCandidates.map(([department, departmentCandidates]) => <section className="department-group" key={department}><header className="department-group__header"><div className="department-group__icon" aria-hidden="true">✓</div><div><h3>{department}</h3><p>{departmentCandidates.length} completed candidate{departmentCandidates.length === 1 ? '' : 's'}</p></div><span>{departmentCandidates.length}</span></header><div className="completed-list">{departmentCandidates.map((candidate) => <article className="completed-candidate" key={candidate.application_id}><div className={`completed-candidate__outcome completed-candidate__outcome--${candidate.status === 'Selected' ? 'selected' : 'not-selected'}`}><span>{candidate.status === 'Selected' ? '✓' : '—'}</span>{candidate.status}</div><div className="completed-candidate__identity"><div className="application-avatar">{candidate.candidate.name.charAt(0)}</div><div><h3>{candidate.candidate.name}</h3><p>{candidate.vacancy.title} · Grade {candidate.vacancy.vacancy_grade}</p><small>Application #{candidate.application_id} · {candidate.applicant_type} candidate</small></div></div><dl className="completed-candidate__details"><div><dt>NIC</dt><dd>{candidate.candidate.nic}</dd></div><div><dt>Email</dt><dd>{candidate.candidate.email}</dd></div><div><dt>Phone</dt><dd>{candidate.candidate.phone}</dd></div><div><dt>Completed</dt><dd>{new Date(candidate.updated_at).toLocaleDateString('en-GB')}</dd></div></dl></article>)}</div></section>)}</div>
      {candidates.length === 0 && !error && <div className="vacancy-empty">No candidates have completed the recruitment process yet. Records appear here after HR closes a vacancy.</div>}
      {candidates.length > 0 && groupedCandidates.length === 0 && <div className="vacancy-empty">No completed candidates match the selected filters.</div>}
    </div></section>
  </div></main>;
}
