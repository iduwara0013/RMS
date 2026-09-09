'use client';

import Image from 'next/image';
import Link from 'next/link';
import { FormEvent, useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { splitVacancyDescription } from '@/lib/vacancy-copy';

type Vacancy = { vacancy_id: number; title: string; description: string; vacancy_type: string; opening_date: string; closing_date: string };
type ApiResponse = { message?: string; application_id?: number; vacancy?: Vacancy };
const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

export default function ApplyPage() {
  const { id } = useParams<{ id: string }>();
  const [vacancy, setVacancy] = useState<Vacancy | null>(null);
  const [form, setForm] = useState({ nic: '', name: '', email: '', phone: '', address: '' });
  const [cv, setCv] = useState<File | null>(null);
  const [message, setMessage] = useState('');
  const [loadError, setLoadError] = useState(false);
  const [submitted, setSubmitted] = useState(false);

  useEffect(() => {
    fetch(`${API}/vacancies/${id}`).then(async (response) => {
      const payload = await response.json().catch(() => ({})) as ApiResponse;
      if (!response.ok || !payload.vacancy) { setLoadError(true); return; }
      setVacancy(payload.vacancy);
    }).catch(() => setLoadError(true));
  }, [id]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!cv) { setMessage('Please attach your CV as a PDF or Word document.'); return; }
    const body = new FormData();
    Object.entries(form).forEach(([key, value]) => body.append(key, value));
    body.append('cv', cv);
    const response = await fetch(`${API}/vacancies/${id}/applications`, { method: 'POST', body });
    const payload = await response.json().catch(() => ({})) as ApiResponse;
    if (!response.ok) { setMessage(payload.message ?? 'Unable to submit application.'); return; }
    setSubmitted(true);
    setMessage(`Application submitted successfully. Reference: ${payload.application_id}`);
  }

  if (!vacancy) return <main className="public-vacancies"><section className="vacancy-unavailable"><div className="dashboard-card__icon">!</div><h1>{loadError ? 'Vacancy unavailable' : 'Loading vacancy…'}</h1><p>{loadError ? 'This vacancy may have closed or is no longer available. Please return to the current vacancies list.' : 'Please wait while we load the vacancy details.'}</p>{loadError && <Link className="vacancy-apply" href="/vacancies">← View current vacancies</Link>}</section></main>;

  const description = splitVacancyDescription(vacancy.description);

  return <main className="public-vacancies">
    <header className="public-header"><Link href="/vacancies" className="public-brand"><Image src="/cpstl-logo.png" alt="CPSTL logo" width={54} height={54} priority /><span><strong>Ceylon Petroleum Storage Terminals Limited</strong><small>Recruitment Management System</small></span></Link><Link className="staff-login" href="/vacancies">← All vacancies</Link></header>
    <section className="vacancy-hero vacancy-hero--compact"><p className="eyebrow">CPSTL career opportunity</p><h1>{vacancy.title}</h1><p>{vacancy.vacancy_type} · Applications close {new Date(vacancy.closing_date).toLocaleDateString('en-GB')}</p></section>
    <section className="application-content"><div className="application-description"><p className="eyebrow eyebrow--red">Before you apply</p><h2>About this role</h2><p className="application-description__overview">{description.overview}</p>{!!description.requirements.length && <div className="application-description__requirements"><h3>What we are looking for</h3><ul>{description.requirements.map((requirement) => <li key={requirement}>{requirement}</li>)}</ul></div>}<div className="application-description__deadline"><span>Application deadline</span><strong>{new Date(vacancy.closing_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' })}</strong></div></div>{submitted ? <div className="application-success"><div className="dashboard-card__icon">✓</div><h2>Application received</h2><p>{message}</p><Link className="vacancy-apply" href="/vacancies">Return to vacancies <span>→</span></Link></div> : <form className="application-form" onSubmit={submit}><h2>Apply for this position</h2><p>Enter your contact details and attach your latest CV. All fields are required.</p><label className="application-field"><span>NIC number</span><input required placeholder="Example: 200012345678" value={form.nic} onChange={(event) => setForm({ ...form, nic: event.target.value })} /></label><label className="application-field"><span>Full name</span><input required placeholder="Enter your full name" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></label><label className="application-field"><span>Email address</span><input required type="email" placeholder="name@example.com" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></label><label className="application-field"><span>Phone number</span><input required type="tel" placeholder="Example: 0771234567" value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} /></label><label className="application-field"><span>Residential address</span><textarea required placeholder="Enter your current address" value={form.address} onChange={(event) => setForm({ ...form, address: event.target.value })} /></label><label className="cv-upload"><span>Attach your CV</span><small>PDF, DOC or DOCX · Maximum 5 MB</small><input required type="file" accept=".pdf,.doc,.docx" onChange={(event) => setCv(event.target.files?.[0] ?? null)} /></label><button className="vacancy-apply" type="submit">Submit application <span>→</span></button>{message && <p className="form-message form-message--error">{message}</p>}</form>}</section>
    <footer className="public-footer">© {new Date().getFullYear()} CPSTL · Ceylon Petroleum Storage Terminals Limited</footer>
  </main>;
}
