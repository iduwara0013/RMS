'use client';

import { staffFetch as fetch, signOutStaff } from '@/lib/staff-fetch';

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';
import DateRangeFilter, { matchesDateRange } from '@/components/date-range-filter';
import { useRmsUser } from '@/lib/use-rms-user';

type User = { name: string; roles: string[]; department_id?: number | null };
type CandidateDocument = { document_id: number; document_type: string; file_name: string; uploaded_at: string };
type CvProfile = {
  cv_profile_id: number;
  professional_summary?: string | null;
  skills?: string[] | null;
  education?: string[] | null;
  experience?: string[] | null;
  projects?: string[] | null;
  certifications?: string[] | null;
  languages?: string[] | null;
  parse_status: string;
  parse_message?: string | null;
  confidence_score?: string | number | null;
  review_status?: string | null;
  parser_version?: string | null;
  extracted_at?: string | null;
};
type Application = {
  application_id: number;
  status: string;
  submitted_at: string;
  applicant_type?: string;
  candidate: { name: string; nic: string; email: string; phone: string; address?: string };
  vacancy: { title: string; vacancy_grade?: string; audience?: string; department?: { department_name: string } | null };
  documents?: CandidateDocument[];
};
type ApiMessage = { message?: string };
type ApplicationStage = 'ongoing' | 'finished';

const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';
const FINISHED_APPLICATION_STATUSES = new Set(['Rejected', 'Selected', 'Not Selected']);

export default function ApplicationsPage() {
  const router = useRouter();
  const user = useRmsUser<User>();
  const [applications, setApplications] = useState<Application[]>([]);
  const [message, setMessage] = useState('');
  const [departmentFilter, setDepartmentFilter] = useState('All departments');
  const [stage, setStage] = useState<ApplicationStage>('ongoing');
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [selectedApplication, setSelectedApplication] = useState<Application | null>(null);
  const [selectedCvProfile, setSelectedCvProfile] = useState<CvProfile | null>(null);
  const [cvProfileLoading, setCvProfileLoading] = useState(false);
  const [cvProfileError, setCvProfileError] = useState('');

  async function load() {
    const token = sessionStorage.getItem('rms_staff_token');
    const response = await fetch(`${API}/applications`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token ?? ''}` } });
    const payload = await response.json().catch(() => ({})) as { applications?: Application[]; message?: string };
    if (response.ok) setApplications(payload.applications ?? []);
    else setMessage(payload.message ?? 'Unable to load applications. Please sign in again.');
  }

  useEffect(() => {
    if (user === undefined) return;
    if (user === null) { router.replace('/'); return; }
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [router, user]);

  useEffect(() => {
    if (!selectedApplication) return;
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === 'Escape') closeCandidateProfile();
    }
    window.addEventListener('keydown', closeOnEscape);
    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [selectedApplication]);

  function closeCandidateProfile() {
    setSelectedApplication(null);
    setSelectedCvProfile(null);
    setCvProfileError('');
    setCvProfileLoading(false);
  }

  async function openCandidateProfile(application: Application) {
    setSelectedApplication(application);
    setSelectedCvProfile(null);
    setCvProfileError('');

    const canAccessCv = user?.roles.some((role) => ['HR Manager', 'Head of Department', 'Managing Director'].includes(role));
    if (!canAccessCv) return;

    setCvProfileLoading(true);
    const response = await fetch(`${API}/applications/${application.application_id}/cv-profile`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${sessionStorage.getItem('rms_staff_token') ?? ''}` },
    });
    const payload = await response.json().catch(() => ({})) as { cv_profile?: CvProfile | null; message?: string };
    if (response.ok) setSelectedCvProfile(payload.cv_profile ?? null);
    else setCvProfileError(payload.message ?? 'Unable to load extracted CV details.');
    setCvProfileLoading(false);
  }

  async function updateStatus(id: number, status: string) {
    if (!user) return;
    const response = await fetch(`${API}/applications/${id}/status`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${sessionStorage.getItem('rms_staff_token') ?? ''}` },
      body: JSON.stringify({ status }),
    });
    const payload = await response.json().catch(() => ({})) as ApiMessage;
    setMessage(response.ok ? (payload.message ?? 'Application updated.') : (payload.message ?? 'Unable to update application.'));
    if (response.ok) await load();
  }

  async function accessCv(applicationId: number, document: CandidateDocument, download: boolean) {
    const previewWindow = download ? null : window.open('about:blank', '_blank');
    if (previewWindow) previewWindow.opener = null;
    const response = await fetch(`${API}/applications/${applicationId}/documents/${document.document_id}?download=${download ? '1' : '0'}`, {
      headers: { Accept: document.file_name.toLowerCase().endsWith('.pdf') ? 'application/pdf' : 'application/octet-stream', Authorization: `Bearer ${sessionStorage.getItem('rms_staff_token') ?? ''}` },
    });
    if (!response.ok) {
      previewWindow?.close();
      const payload = await response.json().catch(() => ({})) as ApiMessage;
      setMessage(payload.message ?? 'Unable to open the candidate CV.');
      return;
    }

    const url = URL.createObjectURL(await response.blob());
    if (download) {
      const link = window.document.createElement('a');
      link.href = url;
      link.download = document.file_name;
      link.click();
    } else if (previewWindow) {
      previewWindow.location.href = url;
    } else {
      const link = window.document.createElement('a');
      link.href = url;
      link.target = '_blank';
      link.rel = 'noreferrer';
      link.click();
    }
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
  }

  const categorizedApplications = useMemo<Record<ApplicationStage, Application[]>>(() => ({
    ongoing: applications.filter((application) => !FINISHED_APPLICATION_STATUSES.has(application.status)),
    finished: applications.filter((application) => FINISHED_APPLICATION_STATUSES.has(application.status)),
  }), [applications]);
  const visibleApplications = useMemo(() => categorizedApplications[stage].filter((application) => matchesDateRange(application.submitted_at, fromDate, toDate)), [categorizedApplications, fromDate, stage, toDate]);
  const departmentNames = useMemo(() => Array.from(new Set(visibleApplications.map((application) => application.vacancy.department?.department_name ?? 'Unassigned department'))).sort(), [visibleApplications]);
  const groupedApplications = useMemo(() => {
    const visible = departmentFilter === 'All departments'
      ? visibleApplications
      : visibleApplications.filter((application) => (application.vacancy.department?.department_name ?? 'Unassigned department') === departmentFilter);
    return Object.entries(visible.reduce<Record<string, Application[]>>((groups, application) => {
      const department = application.vacancy.department?.department_name ?? 'Unassigned department';
      (groups[department] ??= []).push(application);
      return groups;
    }, {})).sort(([left], [right]) => left.localeCompare(right));
  }, [visibleApplications, departmentFilter]);

  if (!user) return <main className="dashboard-loading">Loading applications…</main>;

  return (
    <main className="dashboard-page">
      <div className="app-wrapper rms-adminlte">
        <DashboardNavigation roles={user.roles} />
        <section className="app-main">
          <header className="app-header">
            <span className="app-header__title">Application management</span>
            <span className="app-header__meta">{user.name}<button className="dashboard-signout" onClick={() => { void signOutStaff(); }}>Sign out</button></span>
          </header>
          <div className="content-wrapper">
            <div className="content-header"><div><h2>Applications</h2><p>Verify candidate submissions and prepare the shortlist.</p></div><div className="role-badge"><span className="role-badge__dot" />{user.roles.join(' · ')}</div></div>
            {message && <p className="form-message form-message--success">{message}</p>}
            <div className="workflow-category-tabs workflow-category-tabs--two" role="tablist" aria-label="Application stages">
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
              ><span>{label}</span><strong>{categorizedApplications[value].length}</strong></button>)}
            </div>
            <DateRangeFilter fromDate={fromDate} toDate={toDate} dateLabel="submission date" onFromDateChange={setFromDate} onToDateChange={setToDate} />
            <div className="application-toolbar">
              <div><strong>{visibleApplications.length}</strong><span>{stage === 'ongoing' ? 'Ongoing applications' : 'Finished applications'}</span><small>Across {departmentNames.length} department{departmentNames.length === 1 ? '' : 's'}</small></div>
              <label><span>Filter by department</span><select value={departmentFilter} onChange={(event) => setDepartmentFilter(event.target.value)}><option>All departments</option>{departmentNames.map((department) => <option key={department}>{department}</option>)}</select></label>
            </div>
            <div className="department-groups">{groupedApplications.map(([department, departmentApplications]) => <section className="department-group" key={department}>
              <header className="department-group__header"><div className="department-group__icon" aria-hidden="true">▦</div><div><h3>{department}</h3><p>{departmentApplications.length} application{departmentApplications.length === 1 ? '' : 's'}</p></div><span>{departmentApplications.length}</span></header>
              <div className="applications-list">{departmentApplications.map((application) => {
                return <article className="application-row" key={application.application_id}>
                  <div className="application-avatar">{application.candidate.name.charAt(0)}</div>
                  <div className="application-info"><div className="application-row__heading"><button className="candidate-name-button" type="button" onClick={() => void openCandidateProfile(application)} aria-label={`View details for ${application.candidate.name}`}>{application.candidate.name}</button><span className="workflow-status">{application.status}</span></div><p>Application #{application.application_id} · {application.vacancy.title} · {application.candidate.nic}</p><small>{application.candidate.email} · {application.candidate.phone}</small></div>
                  <div className="application-actions">
                    {user.roles.includes('HR Manager') && application.status === 'Submitted' && <><button onClick={() => updateStatus(application.application_id, 'Verified')}>Verify</button><button className="application-actions__secondary" onClick={() => updateStatus(application.application_id, 'Rejected')}>Reject</button></>}
                    {user.roles.includes('HR Manager') && application.status === 'Verified' && <button onClick={() => updateStatus(application.application_id, 'Shortlisted')}>Shortlist</button>}
                  </div>
                </article>;
              })}</div>
            </section>)}</div>
            {visibleApplications.length === 0 && <div className="vacancy-empty">{fromDate || toDate ? `No ${stage} applications match the selected submission-date range.` : stage === 'ongoing' ? 'No applications are currently being processed.' : 'No applications have reached a final outcome yet.'}</div>}
            {visibleApplications.length > 0 && groupedApplications.length === 0 && <div className="vacancy-empty">No {stage} applications are available for the selected department.</div>}
          </div>
        </section>
        {selectedApplication && (() => {
          const cv = selectedApplication.documents?.find((document) => document.document_type === 'CV');
          const canAccessCv = user.roles.some((role) => ['HR Manager', 'Head of Department', 'Managing Director'].includes(role));
          const parsedSkills = selectedCvProfile?.skills ?? [];
          const parsedExperience = selectedCvProfile?.experience ?? [];
          const storedProjects = selectedCvProfile?.projects ?? [];
          const inferredProjects = storedProjects.length > 0 ? storedProjects : parsedExperience.filter((item) => /github|project|application|platform/i.test(item));
          const workExperience = storedProjects.length > 0 ? parsedExperience : parsedExperience.filter((item) => !inferredProjects.includes(item));
          const confidence = selectedCvProfile?.confidence_score == null ? null : Math.round(Number(selectedCvProfile.confidence_score));
          const profileSections = [
            ['Work experience', workExperience],
            ['Education', selectedCvProfile?.education],
            ['Certifications', selectedCvProfile?.certifications],
            ['Languages', selectedCvProfile?.languages],
          ] as const;
          return <div className="candidate-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) closeCandidateProfile(); }}>
            <section className="candidate-modal" role="dialog" aria-modal="true" aria-labelledby="candidate-modal-title">
              <header className="candidate-modal__header">
                <div className="candidate-modal__identity"><div className="candidate-modal__avatar">{selectedApplication.candidate.name.charAt(0)}</div><div><span>Candidate profile</span><h2 id="candidate-modal-title">{selectedApplication.candidate.name}</h2><p>{selectedApplication.vacancy.title}</p></div></div>
                <button className="candidate-modal__close" type="button" onClick={closeCandidateProfile} aria-label="Close candidate details">×</button>
              </header>
              <div className="candidate-modal__body">
                <div className="candidate-modal__summary"><span className="workflow-status">{selectedApplication.status}</span><span>Application #{selectedApplication.application_id}</span><span>Submitted {new Date(selectedApplication.submitted_at).toLocaleDateString('en-GB')}</span></div>
                <section className="candidate-modal__section"><div className="candidate-modal__section-heading"><span aria-hidden="true">●</span><div><h3>Personal information</h3><p>Candidate contact and identification details</p></div></div><dl className="candidate-detail-grid"><div><dt>NIC number</dt><dd>{selectedApplication.candidate.nic}</dd></div><div><dt>Phone number</dt><dd><a href={`tel:${selectedApplication.candidate.phone}`}>{selectedApplication.candidate.phone}</a></dd></div><div><dt>Email address</dt><dd><a href={`mailto:${selectedApplication.candidate.email}`}>{selectedApplication.candidate.email}</a></dd></div><div><dt>Address</dt><dd>{selectedApplication.candidate.address || 'Not provided'}</dd></div></dl></section>
                <section className="candidate-modal__section"><div className="candidate-modal__section-heading"><span aria-hidden="true">◆</span><div><h3>Application information</h3><p>Position and recruitment classification</p></div></div><dl className="candidate-detail-grid"><div><dt>Vacancy</dt><dd>{selectedApplication.vacancy.title}</dd></div><div><dt>Department</dt><dd>{selectedApplication.vacancy.department?.department_name ?? 'Unassigned'}</dd></div><div><dt>Candidate type</dt><dd>{selectedApplication.applicant_type ?? selectedApplication.vacancy.audience ?? 'External'}</dd></div><div><dt>Vacancy grade</dt><dd>{selectedApplication.vacancy.vacancy_grade ? `Grade ${selectedApplication.vacancy.vacancy_grade}` : 'Not specified'}</dd></div></dl></section>
                <section className="candidate-modal__section candidate-modal__document"><div className="candidate-modal__section-heading"><span aria-hidden="true">▤</span><div><h3>Original CV</h3><p>Use the original document whenever you need to confirm a detail</p></div></div>{cv ? <div className="candidate-document-card"><div className="candidate-document-card__icon">{cv.file_name.toLowerCase().endsWith('.pdf') ? 'PDF' : 'DOC'}</div><div><strong>{cv.file_name}</strong><span>Uploaded {new Date(cv.uploaded_at).toLocaleDateString('en-GB')}</span></div>{canAccessCv && <div className="candidate-document-card__actions"><button type="button" onClick={() => void accessCv(selectedApplication.application_id, cv, false)}>Open original</button><button type="button" className="candidate-document-card__download" onClick={() => void accessCv(selectedApplication.application_id, cv, true)}>Download</button></div>}</div> : <p className="candidate-document-empty">No CV is attached to this application.</p>}</section>
                {canAccessCv && <section className="candidate-modal__section candidate-cv-profile"><div className="candidate-modal__section-heading"><span aria-hidden="true">✦</span><div><h3>CV overview</h3><p>A quick summary to help review this application</p></div></div>
                  {cvProfileLoading ? <div className="candidate-cv-loading"><span /><span /><span /></div> : cvProfileError ? <div className="candidate-cv-state candidate-cv-state--error"><strong>CV details unavailable</strong><p>{cvProfileError}</p></div> : selectedCvProfile ? <>
                    <div className={`candidate-cv-state candidate-cv-state--${selectedCvProfile.parse_status === 'Parsed' ? 'parsed' : 'review'}`}><div><strong>{selectedCvProfile.parse_status === 'Parsed' ? 'Summary ready for HR review' : 'Manual review recommended'}</strong><p>{selectedCvProfile.parse_status === 'Parsed' ? 'Important details are arranged below. Confirm them against the original CV before making a decision.' : selectedCvProfile.parse_message}</p></div>{confidence !== null && <div className="candidate-cv-confidence" aria-label={`Extraction confidence ${confidence}%`}><strong>{confidence}%</strong><span>Confidence</span></div>}</div>
                    <div className="candidate-cv-highlights"><div><strong>{parsedSkills.length}</strong><span>Skills found</span></div><div><strong>{inferredProjects.length}</strong><span>Projects</span></div><div><strong>{workExperience.length}</strong><span>Work entries</span></div><div><strong>{selectedCvProfile.education?.length ?? 0}</strong><span>Education entries</span></div></div>
                    {selectedCvProfile.professional_summary && <div className="candidate-cv-summary"><span className="candidate-cv-eyebrow">Candidate profile</span><h4>Professional summary</h4><p>{selectedCvProfile.professional_summary}</p></div>}
                    {!!parsedSkills.length && <details className="candidate-cv-disclosure" open><summary><span>Key skills</span><strong>{parsedSkills.length}</strong></summary><div className="candidate-cv-skills"><div>{parsedSkills.map((skill) => <span key={skill}>{skill}</span>)}</div></div></details>}
                    {!!inferredProjects.length && <details className="candidate-cv-disclosure" open><summary><span>Projects</span><strong>{inferredProjects.length}</strong></summary><div className="candidate-projects">{inferredProjects.map((project, index) => <article key={`project-${index}`}><span>{String(index + 1).padStart(2, '0')}</span><p>{project}</p></article>)}</div></details>}
                    <div className="candidate-cv-sections">{profileSections.map(([title, items]) => !!items?.length && <details className="candidate-cv-disclosure" key={title} open><summary aria-label={`${title}, ${items.length} entries`}><span>{title}</span><strong>{items.length}</strong></summary><ul>{items.map((item, index) => <li key={`${title}-${index}`}>{item}</li>)}</ul></details>)}</div>
                    {!selectedCvProfile.professional_summary && !parsedSkills.length && !inferredProjects.length && !profileSections.some(([, items]) => items?.length) && <p className="candidate-document-empty">No structured details could be identified. Review the original CV for this candidate.</p>}
                  </> : <div className="candidate-cv-state candidate-cv-state--review"><strong>Not processed</strong><p>No extracted CV profile is available yet.</p></div>}
                </section>}
              </div>
              <footer className="candidate-modal__footer"><button type="button" onClick={closeCandidateProfile}>Close profile</button></footer>
            </section>
          </div>;
        })()}
      </div>
    </main>
  );
}
