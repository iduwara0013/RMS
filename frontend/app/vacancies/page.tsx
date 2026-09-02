'use client';

import { useEffect, useState } from 'react';

type Vacancy = { vacancy_id: number; title: string; description: string; vacancy_type: string; opening_date: string; closing_date: string };
const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';
const demoVacancies: Vacancy[] = [
  { vacancy_id: 1, title: 'Assistant Manager – Operations', description: 'Support safe, efficient petroleum storage terminal operations and coordinate daily site activities.', vacancy_type: 'Permanent', opening_date: '2026-09-01', closing_date: '2026-09-30' },
  { vacancy_id: 2, title: 'Human Resources Executive', description: 'Help deliver recruitment, employee services, and people-development programmes across CPSTL.', vacancy_type: 'Permanent', opening_date: '2026-09-01', closing_date: '2026-10-05' },
];

export default function VacanciesPage() {
  const [vacancies, setVacancies] = useState<Vacancy[]>(demoVacancies);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetch(`${API_BASE_URL}/vacancies`).then((response) => response.ok ? response.json() : Promise.reject()).then((payload) => setVacancies(payload.vacancies ?? [])).catch(() => {}).finally(() => setLoading(false));
  }, []);

  return <main className="public-vacancies"><header className="public-header"><a href="/" className="public-brand"><img src="/cpstl-logo.png" alt="CPSTL logo" /><span><strong>Ceylon Petroleum Storage Terminals Limited</strong><small>Recruitment Management System</small></span></a><a className="staff-login" href="/">Staff login →</a></header><nav className="public-nav"><a href="/vacancies" className="public-nav__active">Careers</a><a href="#about">About CPSTL</a><a href="#contact">Contact us</a></nav><section className="vacancy-hero"><p className="eyebrow">Join the team that powers the nation</p><h1>Current vacancies</h1><p>Explore opportunities at Ceylon Petroleum Storage Terminals Limited and build a career that makes a difference.</p></section><section className="vacancy-content"><div className="vacancy-heading"><div><p className="eyebrow eyebrow--red">Open opportunities</p><h2>Find your next role</h2></div><span>{loading ? 'Loading…' : `${vacancies.length} positions available`}</span></div><div className="vacancy-grid">{vacancies.map((vacancy) => <article className="vacancy-card" key={vacancy.vacancy_id}><div className="vacancy-card__top"><span className="vacancy-type">{vacancy.vacancy_type}</span><span className="vacancy-dot" /></div><h3>{vacancy.title}</h3><p>{vacancy.description}</p><div className="vacancy-card__meta"><span>Closing date</span><strong>{new Date(vacancy.closing_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}</strong></div><a href={`/vacancies/${vacancy.vacancy_id}/apply`} className="vacancy-apply">View details & apply <span>→</span></a></article>)}</div>{!loading && vacancies.length === 0 && <div className="vacancy-empty">There are no published vacancies at the moment. Please check back soon.</div>}</section><footer className="public-footer">© {new Date().getFullYear()} CPSTL · Ceylon Petroleum Storage Terminals Limited</footer></main>;
}
