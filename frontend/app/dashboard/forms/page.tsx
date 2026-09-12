'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';
import VacancyFormFields, { type FormQuestion, type VacancyForm } from '@/components/vacancy-form-fields';
import { useRmsUser } from '@/lib/use-rms-user';
import { staffFetch, signOutStaff } from '@/lib/staff-fetch';

type Vacancy = { vacancy_id: number; title: string; status: string; department?: { department_name: string } };
type Version = { id: number; version: number; title: string; created_at: string };
const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';
const questionTypes: Record<FormQuestion['type'], string> = { text: 'Text answer', number: 'Number', date: 'Date', select: 'Dropdown', yesno: 'Yes / No', file: 'Document upload' };

async function api(path: string, init?: RequestInit) {
  const response = await staffFetch(`${API}${path}`, init);
  const body = await response.json() as { message?: string; errors?: Record<string, string[]>; vacancies?: Vacancy[]; form?: VacancyForm | null; versions?: Version[] };
  if (!response.ok) throw new Error(body.errors ? Object.values(body.errors).flat().join(' ') : body.message ?? 'Unable to load the application form.');
  return body;
}

export default function FormBuilderPage() {
  const user = useRmsUser();
  const router = useRouter();
  const [vacancies, setVacancies] = useState<Vacancy[]>([]);
  const [selected, setSelected] = useState('');
  const [title, setTitle] = useState('Additional application details');
  const [questions, setQuestions] = useState<FormQuestion[]>([]);
  const [current, setCurrent] = useState<VacancyForm | null>(null);
  const [versions, setVersions] = useState<Version[]>([]);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);
  const [ready, setReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [preview, setPreview] = useState(false);
  const permitted = user?.roles.includes('HR Manager');
  const vacancy = vacancies.find(item => String(item.vacancy_id) === selected);
  const closed = vacancy && ['Closed', 'Cancelled'].includes(vacancy.status);

  useEffect(() => {
    if (user === null) { router.replace('/'); return; }
    if (!permitted) return;
    const controller = new AbortController();
    api('/vacancies/all', { signal: controller.signal }).then(body => setVacancies(body.vacancies ?? [])).catch((cause: unknown) => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : 'Unable to load vacancies.'); });
    return () => controller.abort();
  }, [user, permitted, router]);

  useEffect(() => {
    if (!dirty) return;
    function warn(event: BeforeUnloadEvent) { event.preventDefault(); event.returnValue = ''; }
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  async function selectVacancy(id: string) {
    if (dirty && !window.confirm('Discard your unsaved form changes?')) return;
    setSelected(id); setLoading(true); setReady(false); setDirty(false); setError(''); setMessage(''); setPreview(false);
    setQuestions([]); setVersions([]); setCurrent(null); setTitle('Additional application details');
    if (!id) { setLoading(false); return; }
    try {
      const body = await api(`/vacancies/${id}/form`);
      setCurrent(body.form ?? null); setQuestions(body.form?.questions ?? []); setTitle(body.form?.title ?? 'Additional application details'); setVersions(body.versions ?? []); setReady(true);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to load form.'); }
    finally { setLoading(false); }
  }

  function changeQuestion(id: string, changes: Partial<FormQuestion>) {
    setQuestions(items => items.map(item => item.id === id ? { ...item, ...changes } : item)); setDirty(true);
  }

  function move(index: number, direction: number) {
    const next = [...questions]; [next[index], next[index + direction]] = [next[index + direction], next[index]];
    setQuestions(next); setDirty(true);
  }

  async function save() {
    if (!window.confirm('Use this new form version for future applications to this vacancy? Earlier applications will stay unchanged.')) return;
    setBusy(true); setError(''); setMessage('');
    try {
      const body = await api(`/vacancies/${selected}/form`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ title, questions, base_version_id: current?.id ?? 0 }) });
      if (!body.form) throw new Error('The server did not return the saved version. Reload to check before saving again.');
      setCurrent(body.form); setQuestions(body.form.questions); setDirty(false); setMessage(body.message ?? 'Form saved.');
      setVersions(items => [{ id: body.form!.id, version: body.form!.version, title: body.form!.title, created_at: new Date().toISOString() }, ...items]);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to save form.'); }
    finally { setBusy(false); }
  }

  if (!user) return <main className="dashboard-loading">Loading workspace…</main>;
  return <main className="dashboard-page"><div className="app-wrapper rms-adminlte"><DashboardNavigation roles={user.roles} /><section className="app-main"><header className="app-header"><span className="app-header__title">Application form builder</span><span className="app-header__meta">{user.name}<button className="dashboard-signout" onClick={() => void signOutStaff()}>Sign out</button></span></header><div className="content-wrapper">
    <div className="content-header"><div><h2>Application forms</h2><p>Tailor the questions candidates answer for each vacancy.</p></div></div>
    {!permitted ? <p role="alert">Only HR Managers can build application forms.</p> : <>
      {error && <p role="alert" className="form-message form-message--error">{error}</p>}{message && <p role="status" className="form-message form-message--success">{message}</p>}
      <section className="dashboard-panel"><div className="management-form-grid"><label>Vacancy<select value={selected} disabled={busy || loading} onChange={event => void selectVacancy(event.target.value)}><option value="">Select a vacancy</option>{vacancies.map(item => <option value={item.vacancy_id} key={item.vacancy_id}>{item.title} · {item.department?.department_name ?? 'Unassigned'} · {item.status}</option>)}</select></label></div><p>The standard contact details and CV upload remain required. These questions appear below them in both applicant portals.</p></section>
      {loading && <p role="status">Loading linked form…</p>}
      {selected && ready && <section className="dashboard-panel form-builder"><div className="form-builder-heading"><h3>{current ? `Current version ${current.version}` : 'No custom form linked yet'}</h3><span>{dirty ? 'Unsaved changes' : 'Up to date'}</span></div>{closed && <p>This vacancy is finished. Its form is read-only.</p>}
        <div className="management-buttons"><button type="button" onClick={() => setPreview(!preview)}>{preview ? 'Back to editor' : 'Preview candidate form'}</button></div>
        {preview ? <div className="form-builder-preview"><h3>Candidate preview</h3><p>Contact details and CV upload appear first.</p><VacancyFormFields preview form={{ id: current?.id ?? 0, version: (current?.version ?? 0) + (dirty || !current ? 1 : 0), title, questions }} /></div> : <fieldset disabled={busy || !!closed} className="form-builder-fields"><div className="management-form-grid"><label>Form title<input maxLength={255} value={title} onChange={event => { setTitle(event.target.value); setDirty(true); }} /></label></div>
          {questions.map((question, index) => <article className="form-question-editor" key={question.id}><div className="form-builder-heading"><h4>Question {index + 1}</h4><div className="management-buttons"><button type="button" disabled={index === 0} aria-label={`Move question ${index + 1} up`} onClick={() => move(index, -1)}>↑</button><button type="button" disabled={index === questions.length - 1} aria-label={`Move question ${index + 1} down`} onClick={() => move(index, 1)}>↓</button><button type="button" onClick={() => { setQuestions(items => items.filter(item => item.id !== question.id)); setDirty(true); }}>Remove</button></div></div><div className="management-form-grid"><label>Question label<input value={question.label} maxLength={255} onChange={event => changeQuestion(question.id, { label: event.target.value })} /></label><label>Answer type<select value={question.type} onChange={event => changeQuestion(question.id, { type: event.target.value as FormQuestion['type'] })}>{Object.entries(questionTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>{question.type === 'select' && <label>Dropdown options (one per line)<textarea value={question.options.join('\n')} onChange={event => changeQuestion(question.id, { options: event.target.value.split('\n') })} /></label>}</div><label className="management-check"><input type="checkbox" checked={question.required} onChange={event => changeQuestion(question.id, { required: event.target.checked })} />Required answer</label></article>)}
          {!questions.length && <p>No extra questions. Candidates will use the standard contact details and CV fields.</p>}
          <div className="management-buttons"><button type="button" disabled={questions.length >= 30} onClick={() => { setQuestions(items => [...items, { id: `q${crypto.randomUUID().replaceAll('-', '')}`, label: '', type: 'text', required: true, options: [] }]); setDirty(true); }}>＋ Add question</button></div>
        </fieldset>}
        <div className="management-buttons"><button type="button" className="management-primary" disabled={busy || !!closed || !title.trim() || (!dirty && !!current) || questions.some(question => !question.label.trim())} onClick={() => void save()}>{busy ? 'Saving…' : `Save & link version ${(current?.version ?? 0) + 1}`}</button></div><p className="management-help">Saving links this version immediately. It does not publish the vacancy or bypass its approval workflow.</p>
        {!!versions.length && <details><summary>Version history ({versions.length})</summary><ul>{versions.map(version => <li key={version.id}>Version {version.version} — {version.title} · {new Date(version.created_at).toLocaleDateString('en-GB')}</li>)}</ul></details>}
      </section>}
    </>}
  </div></section></div></main>;
}
