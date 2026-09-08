'use client';

import Image from 'next/image';
import Link from 'next/link';
import { FormEvent, useState } from 'react';

type Employee = { name: string; employee_epf: string; phone: string };
type Department = { department_name: string };
type Vacancy = {
  vacancy_id: number; title: string; description: string; vacancy_type: string; vacancy_grade: 'A' | 'B' | 'C';
  audience: 'Internal' | 'Both'; closing_date: string; department?: Department;
};
type ApiResponse = { message?: string; debug_otp?: string; token?: string; employee?: Employee; vacancies?: Vacancy[]; application_id?: number };

const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

export default function InternalVacanciesPage() {
  const [step, setStep] = useState<'identify' | 'otp' | 'vacancies'>('identify');
  const [employeeEpf, setEmployeeEpf] = useState('');
  const [phone, setPhone] = useState('');
  const [otp, setOtp] = useState('');
  const [debugOtp, setDebugOtp] = useState('');
  const [token, setToken] = useState('');
  const [employee, setEmployee] = useState<Employee | null>(null);
  const [vacancies, setVacancies] = useState<Vacancy[]>([]);
  const [selectedVacancy, setSelectedVacancy] = useState<Vacancy | null>(null);
  const [application, setApplication] = useState({ nic: '', email: '', address: '' });
  const [cv, setCv] = useState<File | null>(null);
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  async function requestOtp(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setMessage('');
    try {
      const response = await fetch(`${API}/internal-auth/request-otp`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ employee_epf: employeeEpf, phone }) });
      const payload = await response.json().catch(() => ({})) as ApiResponse;
      if (!response.ok) throw new Error(payload.message ?? 'Unable to verify employee details.');
      setDebugOtp(payload.debug_otp ?? ''); setStep('otp'); setMessage(payload.message ?? 'OTP sent.');
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to request OTP.'); }
    finally { setBusy(false); }
  }

  async function verifyOtp(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setMessage('');
    try {
      const response = await fetch(`${API}/internal-auth/verify-otp`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ employee_epf: employeeEpf, otp }) });
      const payload = await response.json().catch(() => ({})) as ApiResponse;
      if (!response.ok || !payload.token || !payload.employee) throw new Error(payload.message ?? 'Unable to verify OTP.');
      const vacancyResponse = await fetch(`${API}/internal/vacancies`, { headers: { Authorization: `Bearer ${payload.token}` } });
      const vacancyPayload = await vacancyResponse.json().catch(() => ({})) as ApiResponse;
      if (!vacancyResponse.ok) throw new Error(vacancyPayload.message ?? 'Unable to load internal vacancies.');
      setToken(payload.token); setEmployee(payload.employee); setVacancies(vacancyPayload.vacancies ?? []); setStep('vacancies'); setMessage('Employee verified successfully.');
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to verify OTP.'); }
    finally { setBusy(false); }
  }

  async function apply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!selectedVacancy || !cv) { setMessage('Please attach your CV.'); return; }
    setBusy(true); setMessage('');
    try {
      const body = new FormData(); Object.entries(application).forEach(([key, value]) => body.append(key, value)); body.append('cv', cv);
      const response = await fetch(`${API}/internal/vacancies/${selectedVacancy.vacancy_id}/apply`, { method: 'POST', headers: { Authorization: `Bearer ${token}` }, body });
      const payload = await response.json().catch(() => ({})) as ApiResponse;
      if (!response.ok) throw new Error(payload.message ?? 'Unable to submit application.');
      setMessage(`Application submitted successfully. Reference: ${payload.application_id}`); setSelectedVacancy(null); setApplication({ nic: '', email: '', address: '' }); setCv(null);
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Unable to submit application.'); }
    finally { setBusy(false); }
  }

  async function signOut() {
    if (token) await fetch(`${API}/internal-auth/logout`, { method: 'POST', headers: { Authorization: `Bearer ${token}` } }).catch(() => undefined);
    setStep('identify'); setToken(''); setEmployee(null); setVacancies([]); setOtp(''); setDebugOtp(''); setMessage('');
  }

  return <main className="public-vacancies internal-portal"><header className="public-header"><Link href="/vacancies" className="public-brand"><Image src="/cpstl-logo.png" alt="CPSTL logo" width={54} height={54} priority /><span><strong>Ceylon Petroleum Storage Terminals Limited</strong><small>Internal Careers Portal</small></span></Link><Link className="staff-login" href="/vacancies">External vacancies →</Link></header>
    <section className="vacancy-hero vacancy-hero--compact"><p className="eyebrow">Employees only</p><h1>Internal vacancies</h1><p>Confirm your employee record with your EPF and registered phone number before viewing or applying for internal opportunities.</p></section>
    <section className="internal-portal__content">
      {step !== 'vacancies' && <div className="internal-auth-card"><div className="internal-auth-card__step">{step === 'identify' ? 'Step 1 of 2' : 'Step 2 of 2'}</div><h2>{step === 'identify' ? 'Confirm employee details' : 'Enter your OTP'}</h2>
        {step === 'identify' ? <form className="application-form" onSubmit={requestOtp}><input required placeholder="Employee EPF" value={employeeEpf} onChange={(event) => setEmployeeEpf(event.target.value)} /><input required type="tel" placeholder="Registered phone number" value={phone} onChange={(event) => setPhone(event.target.value)} /><button className="vacancy-apply" type="submit" disabled={busy}>Request OTP <span>→</span></button></form>
          : <form className="application-form" onSubmit={verifyOtp}><p>Enter the six-digit verification code for EPF {employeeEpf}.</p>{debugOtp && <div className="internal-debug-otp">Mock OTP: <strong>{debugOtp}</strong></div>}<input required inputMode="numeric" pattern="[0-9]{6}" maxLength={6} placeholder="Six-digit OTP" value={otp} onChange={(event) => setOtp(event.target.value.replace(/\D/g, ''))} /><button className="vacancy-apply" type="submit" disabled={busy}>Verify &amp; continue <span>→</span></button><button className="secondary-button" type="button" onClick={() => { setStep('identify'); setOtp(''); }}>Change details</button></form>}
        {message && <p className="form-message">{message}</p>}
      </div>}

      {step === 'vacancies' && <><div className="internal-employee-bar"><div><span>Verified employee</span><strong>{employee?.name}</strong><small>EPF {employee?.employee_epf}</small></div><button className="dashboard-signout" type="button" onClick={signOut}>Sign out</button></div>
        <div className="vacancy-heading"><div><p className="eyebrow eyebrow--red">Internal opportunities</p><h2>Available vacancies</h2></div><span>{vacancies.length} positions available</span></div>
        {message && <p className="form-message form-message--success">{message}</p>}
        <div className="vacancy-grid">{vacancies.map((vacancy) => <article className="vacancy-card" key={vacancy.vacancy_id}><div className="vacancy-card__top"><span className="vacancy-type">{vacancy.vacancy_type} · Grade {vacancy.vacancy_grade}</span><span className="vacancy-dot" /></div><h3>{vacancy.title}</h3><p>{vacancy.description}</p><div className="vacancy-card__meta"><span>{vacancy.department?.department_name ?? 'CPSTL'}</span><strong>Closes {new Date(vacancy.closing_date).toLocaleDateString('en-GB')}</strong></div><button className="vacancy-apply" type="button" onClick={() => setSelectedVacancy(vacancy)}>Apply now <span>→</span></button></article>)}</div>
        {vacancies.length === 0 && <div className="vacancy-empty">There are no published internal vacancies at the moment.</div>}
        {selectedVacancy && <form className="application-form internal-application" onSubmit={apply}><div><p className="eyebrow eyebrow--red">Internal application</p><h2>{selectedVacancy.title}</h2></div><input required placeholder="NIC number" value={application.nic} onChange={(event) => setApplication({ ...application, nic: event.target.value })} /><input required type="email" placeholder="Contact email" value={application.email} onChange={(event) => setApplication({ ...application, email: event.target.value })} /><textarea required placeholder="Home address" value={application.address} onChange={(event) => setApplication({ ...application, address: event.target.value })} /><label className="cv-upload">CV (PDF, DOC or DOCX)<input required type="file" accept=".pdf,.doc,.docx" onChange={(event) => setCv(event.target.files?.[0] ?? null)} /></label><button className="vacancy-apply" type="submit" disabled={busy}>Submit internal application <span>→</span></button><button className="secondary-button" type="button" onClick={() => setSelectedVacancy(null)}>Cancel</button></form>}
      </>}
    </section><footer className="public-footer">© {new Date().getFullYear()} CPSTL · Internal Careers Portal</footer></main>;
}
