'use client';

import { staffFetch as fetch, signOutStaff } from '@/lib/staff-fetch';

import { FormEvent, useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';
import DateRangeFilter, { matchesDateRange } from '@/components/date-range-filter';
import { useRmsUser } from '@/lib/use-rms-user';

type User = { name: string; roles: string[] };
type Department = { department_name: string };
type Interview = { interview_id: number; interview_date: string; interview_time: string; location: string; status: string; application: { candidate: { name: string; email: string }; vacancy: { title: string; department?: Department | null } } };
type Application = { application_id: number; candidate: { name: string; nic: string }; vacancy: { title: string; department?: Department | null } };
type EvaluationDraft = { score: string; comments: string; recommendation: string };
type ApiMessage = { message?: string };
type InterviewStage = 'ongoing' | 'finished';

const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';
const emptyEvaluation: EvaluationDraft = { score: '', comments: '', recommendation: 'Recommended' };

export default function InterviewsPage() {
  const router = useRouter();
  const user = useRmsUser<User>();
  const [interviews, setInterviews] = useState<Interview[]>([]);
  const [shortlisted, setShortlisted] = useState<Application[]>([]);
  const [message, setMessage] = useState('');
  const [departmentFilter, setDepartmentFilter] = useState('All departments');
  const [stage, setStage] = useState<InterviewStage>('ongoing');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [form, setForm] = useState({ application_ids: [] as number[], interview_date: '', interview_time: '', location: '' });
  const [evaluations, setEvaluations] = useState<Record<number, EvaluationDraft>>({});

  async function load() {
    const [interviewResponse, applicationResponse] = await Promise.all([fetch(`${API}/interviews`), fetch(`${API}/applications?status=Shortlisted`, { headers: { Accept: 'application/json', Authorization: `Bearer ${sessionStorage.getItem('rms_staff_token') ?? ''}` } })]);
    if (interviewResponse.ok) setInterviews(((await interviewResponse.json()) as { interviews?: Interview[] }).interviews ?? []);
    if (applicationResponse.ok) setShortlisted(((await applicationResponse.json()) as { applications?: Application[] }).applications ?? []);
  }

  useEffect(() => {
    if (user === undefined) return;
    if (user === null) { router.replace('/'); return; }
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [router, user]);

  function toggleCandidate(applicationId: number) {
    setForm((current) => ({ ...current, application_ids: current.application_ids.includes(applicationId) ? current.application_ids.filter((id) => id !== applicationId) : [...current.application_ids, applicationId] }));
  }

  async function schedule(event: FormEvent) {
    event.preventDefault();
    if (!user || form.application_ids.length === 0) { setMessage('Select at least one shortlisted candidate.'); return; }
    const response = await fetch(`${API}/interviews`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-User-Role': user.roles[0] }, body: JSON.stringify(form) });
    const payload = await response.json().catch(() => ({})) as ApiMessage;
    setMessage(response.ok ? (payload.message ?? 'Interviews scheduled.') : (payload.message ?? 'Unable to schedule interviews.'));
    if (response.ok) { setForm({ application_ids: [], interview_date: '', interview_time: '', location: '' }); await load(); }
  }

  function evaluationFor(interviewId: number) { return evaluations[interviewId] ?? emptyEvaluation; }
  function updateEvaluation(interviewId: number, changes: Partial<EvaluationDraft>) { setEvaluations((current) => ({ ...current, [interviewId]: { ...(current[interviewId] ?? emptyEvaluation), ...changes } })); }

  async function evaluate(event: FormEvent, id: number) {
    event.preventDefault();
    if (!user) return;
    const response = await fetch(`${API}/interviews/${id}/evaluate`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-User-Role': user.roles[0] }, body: JSON.stringify(evaluationFor(id)) });
    const payload = await response.json().catch(() => ({})) as ApiMessage;
    setMessage(response.ok ? (payload.message ?? 'Evaluation saved.') : (payload.message ?? 'Unable to save evaluation.'));
    if (response.ok) { setEvaluations((current) => { const next = { ...current }; delete next[id]; return next; }); await load(); }
  }

  const categorizedInterviews = useMemo<Record<InterviewStage, Interview[]>>(() => ({
    ongoing: interviews.filter((interview) => interview.status !== 'Evaluated'),
    finished: interviews.filter((interview) => interview.status === 'Evaluated'),
  }), [interviews]);
  const visibleInterviews = useMemo(() => categorizedInterviews[stage].filter((interview) => matchesDateRange(interview.interview_date, fromDate, toDate)), [categorizedInterviews, fromDate, stage, toDate]);
  const departmentNames = useMemo(() => Array.from(new Set([
    ...(stage === 'ongoing' ? shortlisted.map((application) => application.vacancy.department?.department_name ?? 'Unassigned department') : []),
    ...visibleInterviews.map((interview) => interview.application.vacancy.department?.department_name ?? 'Unassigned department'),
  ])).sort(), [shortlisted, stage, visibleInterviews]);
  const groupedShortlisted = useMemo(() => Object.entries(shortlisted.reduce<Record<string, Application[]>>((groups, application) => {
    const department = application.vacancy.department?.department_name ?? 'Unassigned department';
    if (departmentFilter === 'All departments' || department === departmentFilter) (groups[department] ??= []).push(application);
    return groups;
  }, {})).sort(([left], [right]) => left.localeCompare(right)), [departmentFilter, shortlisted]);
  const groupedInterviews = useMemo(() => Object.entries(visibleInterviews.reduce<Record<string, Interview[]>>((groups, interview) => {
    const department = interview.application.vacancy.department?.department_name ?? 'Unassigned department';
    if (departmentFilter === 'All departments' || department === departmentFilter) (groups[department] ??= []).push(interview);
    return groups;
  }, {})).sort(([left], [right]) => left.localeCompare(right)), [departmentFilter, visibleInterviews]);

  if (!user) return <main className="dashboard-loading">Loading interviews…</main>;
  const role = user.roles[0];

  return <main className="dashboard-page"><div className="app-wrapper rms-adminlte">
    <DashboardNavigation roles={user.roles} />
    <section className="app-main"><header className="app-header"><span className="app-header__title">Interview management</span><span className="app-header__meta">{user.name}<button className="dashboard-signout" type="button" onClick={() => { void signOutStaff(); }}>Sign out</button></span></header><div className="content-wrapper">
      <div className="content-header"><div><h2>Interviews</h2><p>Schedule shortlisted candidates and record panel evaluations.</p></div><div className="role-badge"><span className="role-badge__dot" />{role}</div></div>
      {message && <p className="form-message form-message--success">{message}</p>}
      <div className="workflow-category-tabs workflow-category-tabs--two" role="tablist" aria-label="Interview stages">
        {([
          ['ongoing', 'Ongoing'],
          ['finished', 'Finished'],
        ] as const).map(([value, label]) => <button
          key={value}
          type="button"
          role="tab"
          aria-selected={stage === value}
          className={`workflow-category-tab${stage === value ? ' active' : ''}`}
          onClick={() => { setStage(value); setDepartmentFilter('All departments'); }}
        ><span>{label}</span><strong>{categorizedInterviews[value].length}</strong></button>)}
      </div>
      <DateRangeFilter fromDate={fromDate} toDate={toDate} dateLabel="interview date" onFromDateChange={setFromDate} onToDateChange={setToDate} />
      <div className="application-toolbar"><div><strong>{visibleInterviews.length}</strong><span>{stage === 'ongoing' ? 'Ongoing interviews' : 'Finished interviews'}</span><small>Across {departmentNames.length} department{departmentNames.length === 1 ? '' : 's'}</small></div><label><span>Filter by department</span><select value={departmentFilter} onChange={(event) => setDepartmentFilter(event.target.value)}><option>All departments</option>{departmentNames.map((department) => <option key={department}>{department}</option>)}</select></label></div>
      {stage === 'ongoing' && role === 'HR Manager' && <form className="workflow-create-form" onSubmit={schedule}><div className="candidate-picker__heading"><div><h3>Schedule interviews</h3><p>Select one or more shortlisted candidates. The same date, time, and location will be applied to everyone selected.</p></div><strong>{form.application_ids.length} selected</strong></div><fieldset className="candidate-picker"><legend>Shortlisted candidates by department</legend>{groupedShortlisted.map(([department, applications]) => <div className="candidate-picker__department" key={department}><div className="candidate-picker__department-title"><strong>{department}</strong><span>{applications.length}</span></div>{applications.map((application) => <label className="candidate-picker__option" key={application.application_id}><input type="checkbox" checked={form.application_ids.includes(application.application_id)} onChange={() => toggleCandidate(application.application_id)} /><span><strong>{application.candidate.name}</strong><small>Application #{application.application_id} · {application.vacancy.title} · {application.candidate.nic}</small></span></label>)}</div>)}{shortlisted.length === 0 && <p className="candidate-picker__empty">No shortlisted candidates are ready for scheduling.</p>}{shortlisted.length > 0 && groupedShortlisted.length === 0 && <p className="candidate-picker__empty">No shortlisted candidates are available for this department.</p>}</fieldset><div className="workflow-form-grid"><input required placeholder="Location or meeting link" value={form.location} onChange={(event) => setForm({ ...form, location: event.target.value })} /><input required type="date" aria-label="Interview date" value={form.interview_date} onChange={(event) => setForm({ ...form, interview_date: event.target.value })} /><input required type="time" aria-label="Interview time" value={form.interview_time} onChange={(event) => setForm({ ...form, interview_time: event.target.value })} /></div><button className="submit-button workflow-submit" type="submit" disabled={form.application_ids.length === 0}><span>Schedule {form.application_ids.length || ''} interview{form.application_ids.length === 1 ? '' : 's'}</span><span>→</span></button></form>}
      <div className="department-groups">{groupedInterviews.map(([department, departmentInterviews]) => <section className="department-group" key={department}><header className="department-group__header"><div className="department-group__icon" aria-hidden="true">▤</div><div><h3>{department}</h3><p>{departmentInterviews.length} interview{departmentInterviews.length === 1 ? '' : 's'}</p></div><span>{departmentInterviews.length}</span></header><div className="interview-list">{departmentInterviews.map((interview) => <article className="interview-row" key={interview.interview_id}><div><span className="workflow-status">{interview.status}</span><h3>{interview.application.candidate.name}</h3><p>{interview.application.vacancy.title} · {interview.application.candidate.email}</p><small>{interview.interview_date} at {interview.interview_time} · {interview.location}</small></div>{role === 'Interview Panel Member' && interview.status === 'Scheduled' && <form className="evaluation-form" onSubmit={(event) => evaluate(event, interview.interview_id)}><input required type="number" min="0" max="100" placeholder="Score / 100" value={evaluationFor(interview.interview_id).score} onChange={(event) => updateEvaluation(interview.interview_id, { score: event.target.value })} /><select value={evaluationFor(interview.interview_id).recommendation} onChange={(event) => updateEvaluation(interview.interview_id, { recommendation: event.target.value })}><option>Recommended</option><option>Not recommended</option><option>Keep in reserve</option></select><textarea required placeholder="Panel comments" value={evaluationFor(interview.interview_id).comments} onChange={(event) => updateEvaluation(interview.interview_id, { comments: event.target.value })} /><button className="workflow-actions-button" type="submit">Save evaluation</button></form>}</article>)}</div></section>)}</div>
      {visibleInterviews.length === 0 && <div className="vacancy-empty">{fromDate || toDate ? `No ${stage} interviews match the selected interview-date range.` : stage === 'ongoing' ? 'No interviews are currently awaiting evaluation.' : 'No interviews have been evaluated yet.'}</div>}
      {visibleInterviews.length > 0 && groupedInterviews.length === 0 && <div className="vacancy-empty">No {stage} interviews are available for the selected department.</div>}
    </div></section>
  </div></main>;
}
