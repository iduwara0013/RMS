'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';
import { useRmsUser } from '@/lib/use-rms-user';

type User = { name: string; roles: string[] };
type Row = { selection_id: number; application_id: number; candidate_name: string; email: string; vacancy_title: string; status: string; approved_at?: string; interview_score: number | string | null; interview_recommendation: string | null; interview_comments: string | null };
type Rank = { application_id: number; candidate_name: string; email: string; vacancy_title: string; score: number; recommendation: string; rank: number };
type ApiMessage = { message?: string };
const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

export default function SelectionsPage() {
  const router = useRouter();
  const user = useRmsUser<User>();
  const [rankings, setRankings] = useState<Rank[]>([]);
  const [rows, setRows] = useState<Row[]>([]);
  const [message, setMessage] = useState('');

  async function load() {
    const [rankingResponse, selectionResponse] = await Promise.all([fetch(`${API}/rankings`), fetch(`${API}/final-selections`)]);
    if (rankingResponse.ok) setRankings(((await rankingResponse.json()) as { rankings?: Rank[] }).rankings ?? []);
    if (selectionResponse.ok) setRows(((await selectionResponse.json()) as { selections?: Row[] }).selections ?? []);
  }

  useEffect(() => {
    if (user === undefined) return;
    if (user === null) { router.replace('/'); return; }
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [router, user]);

  async function post(path: string, body?: unknown) {
    if (!user) return;
    const response = await fetch(`${API}${path}`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-User-Role': user.roles[0] }, body: body ? JSON.stringify(body) : undefined });
    const payload = await response.json().catch(() => ({})) as ApiMessage;
    setMessage(response.ok ? (payload.message ?? 'Action completed.') : (payload.message ?? 'Unable to complete action.'));
    if (response.ok) await load();
  }

  async function recommendation(row: Row) {
    if (!user) return;
    const response = await fetch(`${API}/final-selections/${row.selection_id}/recommendation`, { headers: { 'X-User-Role': user.roles[0] } });
    const payload = await response.json().catch(() => ({})) as { message?: string; recommendation?: Row };
    if (!response.ok || !payload.recommendation) { setMessage(payload.message ?? 'Unable to generate recommendation.'); return; }
    const item = payload.recommendation;
    const printWindow = window.open('', '_blank', 'width=820,height=700');
    if (!printWindow) { setMessage('Allow pop-ups to generate the recommendation.'); return; }
    printWindow.document.write(`<html><body style="font-family:Arial;padding:56px"><h1 style="color:#c81019">CPSTL Recruitment Management System</h1><h2>Appointment Recommendation</h2><p><b>Candidate:</b> ${item.candidate_name}</p><p><b>Email:</b> ${item.email}</p><p><b>Position:</b> ${item.vacancy_title}</p><p>The candidate has been selected through the approved recruitment process.</p><p><b>Approval date:</b> ${item.approved_at ?? ''}</p><br/><br/><p>____________________ &nbsp;&nbsp;&nbsp;&nbsp; ____________________</p><p>HR Manager &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Managing Director</p><script>window.onload=()=>window.print()<\/script></body></html>`);
    printWindow.document.close();
    setMessage('Recommendation opened. Choose Save as PDF in the print dialog.');
  }

  if (!user) return <main className="dashboard-loading">Loading selections…</main>;
  const role = user.roles[0];
  const nominated = new Set(rows.map((row) => row.application_id));
  const pending = rows.filter((row) => row.status === 'Pending MD Approval');
  const approved = rows.filter((row) => row.status === 'Approved');

  return <main className="dashboard-page"><div className="app-wrapper rms-adminlte">
    <DashboardNavigation roles={user.roles} />
    <section className="app-main"><header className="app-header"><span className="app-header__title">Final selection</span><span className="app-header__meta">{user.name}<button className="dashboard-signout" onClick={() => { sessionStorage.removeItem('rms_user'); router.replace('/'); }}>Sign out</button></span></header><div className="content-wrapper">
      <div className="content-header"><div><h2>Candidate ranking</h2><p>Rank candidates, approve the winner, and close the vacancy.</p></div><div className="role-badge"><span className="role-badge__dot" />{role}</div></div>
      {message && <p className="form-message form-message--success">{message}</p>}
      {role === 'HR Manager' && <section className="dashboard-panel"><h3>Evaluated candidates</h3><div className="selection-list">{rankings.map((ranking) => <article className="selection-row" key={ranking.application_id}><div className="selection-rank">#{ranking.rank}</div><div className="application-info"><h3>{ranking.candidate_name}</h3><p>Application #{ranking.application_id} · {ranking.vacancy_title}</p><small>Score {ranking.score}/100 · {ranking.recommendation} · {ranking.email}</small></div>{nominated.has(ranking.application_id) ? <span className="workflow-status">Submitted</span> : <button className="workflow-actions-button" onClick={() => post('/final-selections', { application_id: ranking.application_id })}>Submit for MD approval</button>}</article>)}</div>{rankings.length === 0 && <div className="vacancy-empty">No evaluated candidates are ready.</div>}</section>}
      {role === 'Managing Director' && <section className="dashboard-panel"><h3>Pending final approvals</h3><div className="selection-list">{pending.map((row) => <article className="selection-row" key={row.selection_id}><div className="application-info"><h3>{row.candidate_name}</h3><p>Application #{row.application_id} · {row.vacancy_title}</p><small><strong>Interview score:</strong> {row.interview_score === null ? 'Not recorded' : `${row.interview_score}/100`} · <strong>Recommendation:</strong> {row.interview_recommendation ?? 'Not recorded'}</small>{row.interview_comments && <p className="selection-comments"><strong>Panel comments:</strong> {row.interview_comments}</p>}</div><div className="application-actions"><button onClick={() => post(`/final-selections/${row.selection_id}/approve`)}>Approve</button><button className="application-actions__secondary" onClick={() => post(`/final-selections/${row.selection_id}/reject`)}>Reject</button></div></article>)}</div>{pending.length === 0 && <div className="vacancy-empty">No final selections are waiting.</div>}</section>}
      {role === 'HR Manager' && approved.length > 0 && <section className="dashboard-panel"><h3>Approved selections</h3><p className="dashboard-panel__hint">Generate the recommendation, then close the vacancy and notify every applicant.</p><div className="selection-list">{approved.map((row) => <article className="selection-row" key={row.selection_id}><div className="application-info"><h3>{row.candidate_name}</h3><p>{row.vacancy_title} · Application #{row.application_id}</p></div><button className="workflow-actions-button" onClick={() => recommendation(row)}>Generate recommendation</button><button className="workflow-actions-button" onClick={() => post(`/final-selections/${row.selection_id}/finalize`)}>Close vacancy &amp; notify candidates</button></article>)}</div></section>}
    </div></section>
  </div></main>;
}
