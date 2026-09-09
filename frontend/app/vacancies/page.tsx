'use client';

import Image from 'next/image';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { splitVacancyDescription } from '@/lib/vacancy-copy';

type Vacancy = {
  vacancy_id: number;
  title: string;
  description: string;
  vacancy_type: string;
  vacancy_grade?: string | null;
  audience?: string | null;
  opening_date: string;
  closing_date: string;
  department?: { department_name: string } | null;
};

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

function localDateKey() {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function formatDate(value: string) {
  return new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function daysUntil(value: string) {
  const closing = new Date(`${value.slice(0, 10)}T23:59:59`);
  return Math.max(0, Math.ceil((closing.getTime() - Date.now()) / 86_400_000));
}

export default function VacanciesPage() {
  const [vacancies, setVacancies] = useState<Vacancy[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);

  useEffect(() => {
    fetch(`${API_BASE_URL}/vacancies`)
      .then((response) => response.ok ? response.json() : Promise.reject())
      .then((payload: unknown) => {
        if (!payload || typeof payload !== 'object' || !('vacancies' in payload) || !Array.isArray(payload.vacancies)) throw new Error('Invalid vacancies response');
        setVacancies(payload.vacancies as Vacancy[]);
      })
      .catch(() => setLoadError(true))
      .finally(() => setLoading(false));
  }, []);

  const today = localDateKey();
  const positionLabel = vacancies.length === 1 ? '1 position available' : `${vacancies.length} positions available`;

  return <main className="public-vacancies">
    <header className="public-header">
      <Link href="/" className="public-brand"><Image src="/cpstl-logo.png" alt="CPSTL logo" width={48} height={48} /><span><strong>Ceylon Petroleum Storage Terminals Limited</strong><small>Recruitment Management System</small></span></Link>
      <Link className="staff-login" href="/">Staff login →</Link>
    </header>
    <nav className="public-nav" aria-label="Careers navigation"><Link href="/vacancies" className="public-nav__active" aria-current="page">External careers</Link><Link href="/internal-vacancies">Internal employees</Link></nav>
    <section className="vacancy-hero"><p className="eyebrow">Join the team that powers the nation</p><h1>Current vacancies</h1><p>Explore external opportunities at Ceylon Petroleum Storage Terminals Limited and build a career that makes a difference.</p></section>
    <section className="vacancy-content">
      <div className="vacancy-heading"><div><p className="eyebrow eyebrow--red">External opportunities</p><h2>Find your next role</h2><p className="vacancy-heading__help">Review the role summary and requirements, then open the vacancy to apply.</p></div><span>{loading ? 'Loading vacancies…' : positionLabel}</span></div>
      {loading && <div className="vacancy-loading" aria-label="Loading vacancies"><span /><span /></div>}
      {!loading && <div className="vacancy-grid">{vacancies.map((vacancy) => {
        const isOpen = vacancy.opening_date.slice(0, 10) <= today;
        const openingLabel = formatDate(vacancy.opening_date);
        const closingLabel = formatDate(vacancy.closing_date);
        const remainingDays = daysUntil(vacancy.closing_date);
        const description = splitVacancyDescription(vacancy.description);
        const deadlineText = remainingDays === 0 ? 'Closes today' : remainingDays === 1 ? '1 day remaining' : `${remainingDays} days remaining`;

        return <article className="vacancy-card" key={vacancy.vacancy_id}>
          <div className="vacancy-card__main">
            <div className="vacancy-card__top"><span className={`vacancy-availability${isOpen ? '' : ' vacancy-availability--upcoming'}`}><i aria-hidden="true" />{isOpen ? 'Accepting applications' : 'Opening soon'}</span><span className="vacancy-reference">VAC-{String(vacancy.vacancy_id).padStart(3, '0')}</span></div>
            <h3>{vacancy.title}</h3>
            <div className="vacancy-card__tags"><span>{vacancy.department?.department_name ?? 'Department not specified'}</span><span>{vacancy.vacancy_type}</span>{vacancy.vacancy_grade && <span>Grade {vacancy.vacancy_grade}</span>}<span>{vacancy.audience === 'Both' ? 'External & internal' : 'External vacancy'}</span></div>
            <div className="vacancy-card__overview"><h4>About the role</h4><p>{description.overview}</p></div>
            {!!description.requirements.length && <div className="vacancy-card__requirements"><h4>Key requirements</h4><ul>{description.requirements.slice(0, 4).map((requirement) => <li key={requirement}>{requirement}</li>)}</ul>{description.requirements.length > 4 && <small>+ {description.requirements.length - 4} more requirements in the full vacancy</small>}</div>}
          </div>
          <aside className="vacancy-card__action">
            <span className="vacancy-card__date-label">{isOpen ? 'Applications close' : 'Applications open'}</span>
            <strong>{isOpen ? closingLabel : openingLabel}</strong>
            <span className={`vacancy-card__deadline${remainingDays <= 3 && isOpen ? ' vacancy-card__deadline--urgent' : ''}`}>{isOpen ? deadlineText : 'Save this date'}</span>
            {isOpen ? <Link href={`/vacancies/${vacancy.vacancy_id}/apply`} className="vacancy-apply"><span><strong>View job &amp; apply</strong><small>See complete details</small></span><b aria-hidden="true">→</b></Link> : <span className="vacancy-apply vacancy-apply--disabled" aria-disabled="true">Applications open {openingLabel}</span>}
          </aside>
        </article>;
      })}</div>}
      {!loading && loadError && <div className="vacancy-empty"><strong>Vacancies could not be loaded</strong><p>Please refresh the page or try again shortly.</p></div>}
      {!loading && !loadError && vacancies.length === 0 && <div className="vacancy-empty"><strong>No vacancies are open right now</strong><p>Please check again later for new CPSTL career opportunities.</p></div>}
    </section>
    <footer className="public-footer">© {new Date().getFullYear()} CPSTL · Ceylon Petroleum Storage Terminals Limited</footer>
  </main>;
}
