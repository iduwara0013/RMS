'use client';

import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';
import DateRangeFilter, { matchesDateRange } from '@/components/date-range-filter';
import { useRmsUser } from '@/lib/use-rms-user';
import { staffFetch as fetch, signOutStaff } from '@/lib/staff-fetch';

type User = { id: number; name: string; roles: string[] };
type Department = { department_name: string };
type Criterion = { id: number; name: string; weight: number };
type Member = { id: number; name: string };
type PanelScore = { criterion_id: number; score: number };
type PanelEvaluation = { id: number; evaluator_id: number; weighted_score: number; recommendation: string; comments: string; status: 'Submitted' | 'Reopened'; submitted_at: string; reopened_at?: string | null; member: Member; scores: PanelScore[] };
type Interview = { interview_id: number; interview_date: string; interview_time: string; location: string; status: string; combined_score: number | null; completed_evaluations: number; assigned_members: number; criteria: Criterion[]; assignments: { user_id: number; member: Member }[]; panel_evaluations: PanelEvaluation[]; my_evaluation?: PanelEvaluation | null; application: { candidate: { name: string; email: string }; vacancy: { title: string; department?: Department | null } } };
type Application = { application_id: number; candidate: { name: string; nic: string }; vacancy: { title: string; department?: Department | null } };
type CriterionDraft = { name: string; weight: string };
type ScheduleDraft = { application_ids: number[]; panel_member_ids: number[]; interview_date: string; interview_time: string; location: string; criteria: CriterionDraft[] };
type EvaluationDraft = { scores: Record<string, string>; comments: string; recommendation: string };
const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';
const defaultCriteria: CriterionDraft[] = [{ name: 'Technical knowledge', weight: '40' }, { name: 'Relevant experience', weight: '25' }, { name: 'Communication', weight: '20' }, { name: 'Leadership', weight: '15' }];
const blankSchedule = (): ScheduleDraft => ({ application_ids: [], panel_member_ids: [], interview_date: '', interview_time: '', location: '', criteria: defaultCriteria.map(item => ({ ...item })) });
const departmentOf = (interview: Interview) => interview.application.vacancy.department?.department_name ?? 'Unassigned department';

export default function InterviewsPage() {
  const router = useRouter();
  const user = useRmsUser<User>();
  const [interviews, setInterviews] = useState<Interview[]>([]);
  const [shortlisted, setShortlisted] = useState<Application[]>([]);
  const [members, setMembers] = useState<Member[]>([]);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [departmentFilter, setDepartmentFilter] = useState('All departments');
  const [stage, setStage] = useState<'ongoing' | 'finished'>('ongoing');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [form, setForm] = useState<ScheduleDraft>(blankSchedule);
  const [evaluations, setEvaluations] = useState<Record<number, EvaluationDraft>>({});
  const role = user?.roles[0] ?? '';
  const canReviewScores = ['HR Manager', 'Head of Department', 'Managing Director'].includes(role);

  const load = useCallback(async () => {
    const interviewResponse = await fetch(`${API}/interviews`);
    const interviewPayload = await interviewResponse.json().catch(() => ({})) as { interviews?: Interview[]; panel_members?: Member[]; message?: string };
    if (!interviewResponse.ok) throw new Error(interviewPayload.message ?? 'Unable to load interviews.');
    setInterviews(interviewPayload.interviews ?? []); setMembers(interviewPayload.panel_members ?? []);
    if (role === 'HR Manager') {
      const applicationResponse = await fetch(`${API}/applications?status=Shortlisted`);
      const payload = await applicationResponse.json().catch(() => ({})) as { applications?: Application[]; message?: string };
      if (!applicationResponse.ok) throw new Error(payload.message ?? 'Unable to load shortlisted candidates.');
      setShortlisted(payload.applications ?? []);
    }
  }, [role]);

  useEffect(() => {
    if (user === undefined) return;
    if (user === null) { router.replace('/'); return; }
    const timer = window.setTimeout(() => { load().catch(cause => setError(cause instanceof Error ? cause.message : 'Unable to load interviews.')); }, 0);
    return () => window.clearTimeout(timer);
  }, [load, router, user]);

  const toggle = (key: 'application_ids' | 'panel_member_ids', id: number) => setForm(current => ({ ...current, [key]: current[key].includes(id) ? current[key].filter(item => item !== id) : [...current[key], id] }));
  const weightTotal = form.criteria.reduce((sum, item) => sum + Number(item.weight || 0), 0);

  async function schedule(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError(''); setMessage('');
    try {
      const response = await fetch(`${API}/interviews`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...form, criteria: form.criteria.map(item => ({ ...item, weight: Number(item.weight) })) }) });
      const payload = await response.json().catch(() => ({})) as { message?: string; errors?: Record<string, string[]> };
      if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat().join(' ') : payload.message ?? 'Unable to schedule interviews.');
      setMessage(payload.message ?? 'Interviews scheduled.'); setForm(blankSchedule()); await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to schedule interviews.'); }
    finally { setBusy(false); }
  }

  function evaluationFor(interview: Interview): EvaluationDraft {
    if (evaluations[interview.interview_id]) return evaluations[interview.interview_id];
    const existing = interview.my_evaluation;
    return { scores: Object.fromEntries(interview.criteria.map(criterion => [criterion.id, String(existing?.scores.find(score => score.criterion_id === criterion.id)?.score ?? '')])), comments: existing?.comments ?? '', recommendation: existing?.recommendation ?? 'Recommended' };
  }
  function updateEvaluation(interview: Interview, changes: Partial<EvaluationDraft>) { setEvaluations(current => ({ ...current, [interview.interview_id]: { ...evaluationFor(interview), ...changes } })); }

  async function evaluate(event: FormEvent, interview: Interview) {
    event.preventDefault(); setBusy(true); setError(''); setMessage('');
    try {
      const response = await fetch(`${API}/interviews/${interview.interview_id}/evaluate`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(evaluationFor(interview)) });
      const payload = await response.json().catch(() => ({})) as { message?: string; errors?: Record<string, string[]> };
      if (!response.ok) throw new Error(payload.errors ? Object.values(payload.errors).flat().join(' ') : payload.message ?? 'Unable to save evaluation.');
      setMessage(payload.message ?? 'Evaluation submitted.'); setEvaluations(current => { const next = { ...current }; delete next[interview.interview_id]; return next; }); await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to save evaluation.'); }
    finally { setBusy(false); }
  }

  async function reopen(interview: Interview, evaluation: PanelEvaluation) {
    if (!window.confirm(`Reopen ${evaluation.member.name}'s evaluation? The candidate will return to interview review until it is resubmitted.`)) return;
    setBusy(true); setError(''); setMessage('');
    try {
      const response = await fetch(`${API}/interviews/${interview.interview_id}/reopen`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ evaluator_id: evaluation.evaluator_id }) });
      const payload = await response.json().catch(() => ({})) as { message?: string };
      if (!response.ok) throw new Error(payload.message ?? 'Unable to reopen evaluation.');
      setMessage(payload.message ?? 'Evaluation reopened.'); await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to reopen evaluation.'); }
    finally { setBusy(false); }
  }

  const categorized = useMemo(() => ({ ongoing: interviews.filter(item => item.status !== 'Evaluated'), finished: interviews.filter(item => item.status === 'Evaluated') }), [interviews]);
  const visible = categorized[stage].filter(item => matchesDateRange(item.interview_date, fromDate, toDate));
  const departments = Array.from(new Set([...visible.map(departmentOf), ...(role === 'HR Manager' ? shortlisted.map(item => item.vacancy.department?.department_name ?? 'Unassigned department') : [])])).sort();
  const grouped = Object.entries(visible.filter(item => departmentFilter === 'All departments' || departmentOf(item) === departmentFilter).reduce<Record<string, Interview[]>>((all, item) => { (all[departmentOf(item)] ??= []).push(item); return all; }, {})).sort(([a], [b]) => a.localeCompare(b));
  const groupedShortlisted = Object.entries(shortlisted.filter(item => departmentFilter === 'All departments' || (item.vacancy.department?.department_name ?? 'Unassigned department') === departmentFilter).reduce<Record<string, Application[]>>((all, item) => { (all[item.vacancy.department?.department_name ?? 'Unassigned department'] ??= []).push(item); return all; }, {}));

  if (!user) return <main className="dashboard-loading">Loading interviews…</main>;
  return <main className="dashboard-page"><div className="app-wrapper rms-adminlte"><DashboardNavigation roles={user.roles} /><section className="app-main"><header className="app-header"><span className="app-header__title">Interview management</span><span className="app-header__meta">{user.name}<button className="dashboard-signout" onClick={() => void signOutStaff()}>Sign out</button></span></header><div className="content-wrapper">
    <div className="content-header"><div><h2>Interviews</h2><p>Assign panels, use weighted scorecards, and review combined results.</p></div><div className="role-badge"><span className="role-badge__dot" />{role}</div></div>
    {error && <p role="alert" className="form-message form-message--error">{error}</p>}{message && <p role="status" className="form-message form-message--success">{message}</p>}
    <div className="workflow-category-tabs workflow-category-tabs--two" role="tablist">{(['ongoing', 'finished'] as const).map(value => <button key={value} role="tab" aria-selected={stage === value} className={`workflow-category-tab${stage === value ? ' active' : ''}`} onClick={() => { setStage(value); setDepartmentFilter('All departments'); }}><span>{value === 'ongoing' ? 'Ongoing' : 'Finished'}</span><strong>{categorized[value].length}</strong></button>)}</div>
    <DateRangeFilter fromDate={fromDate} toDate={toDate} dateLabel="interview date" onFromDateChange={setFromDate} onToDateChange={setToDate} />
    <div className="application-toolbar"><div><strong>{visible.length}</strong><span>{stage === 'ongoing' ? 'Ongoing interviews' : 'Finished interviews'}</span><small>Individual and combined scores</small></div><label><span>Filter by department</span><select value={departmentFilter} onChange={event => setDepartmentFilter(event.target.value)}><option>All departments</option>{departments.map(name => <option key={name}>{name}</option>)}</select></label></div>

    {stage === 'ongoing' && role === 'HR Manager' && <form className="workflow-create-form interview-scheduler" onSubmit={schedule}><div className="candidate-picker__heading"><div><h3>Schedule interviews</h3><p>Select candidates, assigned panel members, and a scorecard. The settings apply to every selected candidate.</p></div><strong>{form.application_ids.length} candidates</strong></div>
      <fieldset className="candidate-picker"><legend>Shortlisted candidates</legend>{groupedShortlisted.map(([department, apps]) => <div className="candidate-picker__department" key={department}><div className="candidate-picker__department-title"><strong>{department}</strong><span>{apps.length}</span></div>{apps.map(app => <label className="candidate-picker__option" key={app.application_id}><input type="checkbox" checked={form.application_ids.includes(app.application_id)} onChange={() => toggle('application_ids', app.application_id)} /><span><strong>{app.candidate.name}</strong><small>Application #{app.application_id} · {app.vacancy.title}</small></span></label>)}</div>)}{!shortlisted.length && <p className="candidate-picker__empty">No shortlisted candidates are ready.</p>}</fieldset>
      <fieldset className="candidate-picker"><legend>Assign interview panel members</legend>{members.map(member => <label className="candidate-picker__option" key={member.id}><input type="checkbox" checked={form.panel_member_ids.includes(member.id)} onChange={() => toggle('panel_member_ids', member.id)} /><span><strong>{member.name}</strong><small>Interview Panel Member</small></span></label>)}{!members.length && <p className="candidate-picker__empty">No active interview panel members are available. Assign the role in User management first.</p>}</fieldset>
      <fieldset className="scorecard-builder"><legend>Weighted scorecard</legend>{form.criteria.map((criterion, index) => <div className="scorecard-builder__row" key={index}><label>Criterion<input required value={criterion.name} maxLength={150} onChange={event => setForm(current => ({ ...current, criteria: current.criteria.map((item, i) => i === index ? { ...item, name: event.target.value } : item) }))} /></label><label>Weight %<input required type="number" min="0.01" max="100" step="0.01" value={criterion.weight} onChange={event => setForm(current => ({ ...current, criteria: current.criteria.map((item, i) => i === index ? { ...item, weight: event.target.value } : item) }))} /></label><button type="button" disabled={form.criteria.length === 1} onClick={() => setForm(current => ({ ...current, criteria: current.criteria.filter((_, i) => i !== index) }))}>Remove</button></div>)}<div className={`scorecard-total${Math.abs(weightTotal - 100) < .001 ? ' scorecard-total--valid' : ''}`}><span>Total weight</span><strong>{weightTotal}%</strong></div><button type="button" disabled={form.criteria.length >= 12} onClick={() => setForm(current => ({ ...current, criteria: [...current.criteria, { name: '', weight: '0' }] }))}>＋ Add criterion</button></fieldset>
      <div className="workflow-form-grid"><input required placeholder="Location or meeting link" value={form.location} onChange={event => setForm({ ...form, location: event.target.value })} /><input required type="date" aria-label="Interview date" value={form.interview_date} onChange={event => setForm({ ...form, interview_date: event.target.value })} /><input required type="time" aria-label="Interview time" value={form.interview_time} onChange={event => setForm({ ...form, interview_time: event.target.value })} /></div><button className="submit-button workflow-submit" disabled={busy || !form.application_ids.length || !form.panel_member_ids.length || Math.abs(weightTotal - 100) > .001}><span>{busy ? 'Scheduling…' : 'Schedule interviews & assign panel'}</span><span>→</span></button>
    </form>}

    <div className="department-groups">{grouped.map(([department, items]) => <section className="department-group" key={department}><header className="department-group__header"><div className="department-group__icon">▤</div><div><h3>{department}</h3><p>{items.length} interview{items.length === 1 ? '' : 's'}</p></div><span>{items.length}</span></header><div className="interview-list">{items.map(interview => {
      const draft = evaluationFor(interview); const canEvaluate = role === 'Interview Panel Member' && (!interview.my_evaluation || interview.my_evaluation.status === 'Reopened') && interview.status !== 'Evaluated';
      return <article className="interview-row interview-scorecard" key={interview.interview_id}><div className="interview-summary"><div><span className="workflow-status">{interview.status}</span><h3>{interview.application.candidate.name}</h3><p>{interview.application.vacancy.title} · {interview.application.candidate.email}</p><small>{interview.interview_date} at {interview.interview_time} · {interview.location}</small></div>{canReviewScores && <div className="combined-score"><span>Combined score</span><strong>{interview.combined_score === null ? 'Pending' : `${interview.combined_score}/100`}</strong><small>{interview.completed_evaluations} of {interview.assigned_members} submitted</small></div>}</div>
        <div className="assigned-panel"><strong>Assigned panel</strong><span>{interview.assignments.map(item => item.member.name).join(', ')}</span></div>
        {canEvaluate && <form className="structured-evaluation" onSubmit={event => evaluate(event, interview)}><h4>{interview.my_evaluation?.status === 'Reopened' ? 'Correct and resubmit your evaluation' : 'Your scorecard'}</h4><div className="criterion-score-grid">{interview.criteria.map(criterion => <label key={criterion.id}><span>{criterion.name}<small>{criterion.weight}% weight</small></span><input required type="number" min="0" max="100" step="0.01" value={draft.scores[criterion.id] ?? ''} onChange={event => updateEvaluation(interview, { scores: { ...draft.scores, [criterion.id]: event.target.value } })} placeholder="0–100" /></label>)}</div><label>Recommendation<select value={draft.recommendation} onChange={event => updateEvaluation(interview, { recommendation: event.target.value })}><option>Recommended</option><option>Keep in reserve</option><option>Not recommended</option></select></label><label>Panel comments<textarea required maxLength={2000} value={draft.comments} onChange={event => updateEvaluation(interview, { comments: event.target.value })} /></label><button className="workflow-actions-button" disabled={busy}>Submit & lock evaluation</button></form>}
        {role === 'Interview Panel Member' && interview.my_evaluation?.status === 'Submitted' && <p className="evaluation-locked">Your evaluation was submitted and is locked.</p>}
        {canReviewScores && <section className="panel-results"><h4>Individual panel results</h4>{interview.panel_evaluations.filter(item => item.status === 'Submitted').map(evaluation => <article key={evaluation.id} className="panel-result"><div className="panel-result__heading"><div><strong>{evaluation.member.name}</strong><span>{evaluation.recommendation}</span></div><strong>{evaluation.weighted_score}/100</strong></div><div className="score-breakdown">{interview.criteria.map(criterion => <span key={criterion.id}>{criterion.name}: <strong>{evaluation.scores.find(score => score.criterion_id === criterion.id)?.score ?? '—'}</strong></span>)}</div><p>{evaluation.comments}</p><small>Submitted {new Date(evaluation.submitted_at).toLocaleString()}</small>{role === 'HR Manager' && <button className="reopen-evaluation" disabled={busy} onClick={() => void reopen(interview, evaluation)}>Reopen evaluation</button>}</article>)}{!interview.panel_evaluations.some(item => item.status === 'Submitted') && <p>No panel member has submitted scores yet.</p>}</section>}
      </article>;
    })}</div></section>)}</div>
    {!visible.length && <div className="vacancy-empty">{stage === 'ongoing' ? role === 'Interview Panel Member' ? 'You have no assigned interviews awaiting evaluation.' : 'No interviews are awaiting evaluation.' : 'No completed interviews are available.'}</div>}
  </div></section></div></main>;
}
