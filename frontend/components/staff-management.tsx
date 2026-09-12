'use client';

import { FormEvent, useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import DashboardNavigation from '@/components/dashboard-navigation';
import { useRmsUser } from '@/lib/use-rms-user';
import { staffFetch, signOutStaff } from '@/lib/staff-fetch';

type Role = { role_id: number; role_name: string };
type Department = { department_id: number; department_name: string; description: string | null; is_active: boolean };
type Employee = { id: number; name: string; employee_pin: string; employee_epf: string; email: string | null; phone: string | null; department_id: number | string | null; department?: Department | null; is_active: boolean; first_login: boolean; roles: Role[] };
type Directory = { users: Employee[]; departments: Department[]; roles: Role[] };
type EmployeeForm = { id?: number; name: string; employee_pin: string; employee_epf: string; email: string; phone: string; department_id: string; role_ids: number[]; is_active: boolean };
type DepartmentForm = { department_id?: number; department_name: string; description: string; is_active: boolean };
const blankEmployee: EmployeeForm = { name: '', employee_pin: '', employee_epf: '', email: '', phone: '', department_id: '', role_ids: [], is_active: true };
const blankDepartment: DepartmentForm = { department_name: '', description: '', is_active: true };
const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

async function request(path: string, init?: RequestInit) {
  const response = await staffFetch(`${API}/management${path}`, init);
  const raw: unknown = await response.json().catch(() => null);
  const body = raw && typeof raw === 'object' ? raw as Record<string, unknown> : {};
  if (!response.ok) {
    const details = body.errors && typeof body.errors === 'object' ? Object.values(body.errors).flat().filter(value => typeof value === 'string').join(' ') : '';
    throw new Error(details || (typeof body.message === 'string' ? body.message : 'Unable to complete this request. Please try again.'));
  }
  return body;
}

export default function StaffManagement({ mode }: { mode: 'users' | 'departments' }) {
  const user = useRmsUser<{ id: number; name: string; roles: string[] }>();
  const router = useRouter();
  const [directory, setDirectory] = useState<Directory>({ users: [], departments: [], roles: [] });
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('active');
  const [departmentFilter, setDepartmentFilter] = useState('');
  const [employee, setEmployee] = useState<EmployeeForm | null>(null);
  const [department, setDepartment] = useState<DepartmentForm | null>(null);
  const [assignExisting, setAssignExisting] = useState(false);
  const [employeeSearch, setEmployeeSearch] = useState('');
  const permitted = user?.roles.some(role => ['HR Manager', 'System Administrator'].includes(role));
  const administrator = user?.roles.includes('System Administrator');
  const title = mode === 'users' ? 'User management' : 'Departments';

  const load = useCallback(async (signal?: AbortSignal) => {
    const data: unknown = await request('', { signal });
    if (!data || typeof data !== 'object' || !('users' in data) || !('departments' in data) || !('roles' in data) || !Array.isArray(data.users) || !Array.isArray(data.departments) || !Array.isArray(data.roles)) throw new Error('The server returned an invalid directory.');
    return data as Directory;
  }, []);

  useEffect(() => {
    if (user === null) { router.replace('/'); return; }
    if (!permitted) return;
    const controller = new AbortController();
    load(controller.signal).then(data => { if (!controller.signal.aborted) setDirectory(data); }).catch((cause: unknown) => {
      if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : 'Unable to load records.');
    }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [load, permitted, router, user]);

  async function save(path: string, method: string, body?: object, selfEdit = false) {
    setBusy(true); setError(''); setMessage('');
    try {
      const result = await request(path, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body ?? {}) });
      setEmployee(null); setDepartment(null); setAssignExisting(false);
      if (selfEdit) { await signOutStaff(); return; }
      setMessage(typeof result.message === 'string' ? result.message : 'Saved successfully.');
      setDirectory(await load());
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Unable to save.'); }
    finally { setBusy(false); }
  }

  function saveEmployee(event: FormEvent) {
    event.preventDefault();
    if (!employee) return;
    if (employee.id === user?.id && !window.confirm('Saving your account signs you out. Continue?')) return;
    void save(`/users${employee.id ? `/${employee.id}` : ''}`, employee.id ? 'PUT' : 'POST', { ...employee, email: employee.email || null, phone: employee.phone || null, department_id: employee.department_id ? Number(employee.department_id) : null }, employee.id === user?.id);
  }

  function saveDepartment(event: FormEvent) {
    event.preventDefault();
    if (!department) return;
    void save(`/departments${department.department_id ? `/${department.department_id}` : ''}`, department.department_id ? 'PUT' : 'POST', department);
  }

  function editEmployee(record: Employee) {
    setEmployee({ ...record, email: record.email ?? '', phone: record.phone ?? '', department_id: String(record.department_id ?? ''), role_ids: record.roles.filter(role => role.role_name !== 'External Candidate').map(role => role.role_id) });
    setError(''); setMessage('');
    document.getElementById('management-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  const matchesStatus = (active: boolean) => status === 'all' || (status === 'active' ? active : !active);
  const query = search.trim().toLowerCase();
  const employees = directory.users.filter(record => matchesStatus(record.is_active) && (!departmentFilter || String(record.department_id) === departmentFilter) && [record.name, record.employee_pin, record.employee_epf, record.email, ...record.roles.map(role => role.role_name)].join(' ').toLowerCase().includes(query));
  const departments = directory.departments.filter(record => matchesStatus(record.is_active) && `${record.department_name} ${record.description ?? ''}`.toLowerCase().includes(query));
  const editable = (record: Employee) => administrator || !record.roles.some(role => role.role_name === 'System Administrator');
  const existingEmployees = directory.users.filter(record => editable(record) && [record.name, record.employee_pin, record.employee_epf].join(' ').toLowerCase().includes(employeeSearch.trim().toLowerCase()));

  if (!user) return <main className="dashboard-loading">Loading your workspace…</main>;
  return <main className="dashboard-page"><div className="app-wrapper rms-adminlte">
    <DashboardNavigation roles={user.roles} />
    <section className="app-main">
      <header className="app-header"><span className="app-header__title">{title}</span><span className="app-header__meta">{user.name}<button className="dashboard-signout" onClick={() => void signOutStaff()}>Sign out</button></span></header>
      <div className="content-wrapper">
        <div className="content-header"><div><h2>{title}</h2><p>{mode === 'users' ? 'Manage employee access, roles and registered contact details.' : 'Organize recruitment teams and see their assigned department heads.'}</p></div></div>
        {!permitted ? <p role="alert">Only HR managers and system administrators can access this screen.</p> : <>
          {error && <p role="alert" className="form-message form-message--error">{error}</p>}
          {message && <p role="status" className="form-message form-message--success">{message}</p>}
          <div className="management-toolbar">
            <label>Search<input value={search} onChange={event => setSearch(event.target.value)} placeholder={mode === 'users' ? 'Name, PIN, EPF or role' : 'Department name'} /></label>
            <label>Status<select value={status} onChange={event => setStatus(event.target.value)}><option value="active">Active</option><option value="inactive">Inactive</option><option value="all">All records</option></select></label>
            {mode === 'users' && <label>Department<select value={departmentFilter} onChange={event => setDepartmentFilter(event.target.value)}><option value="">All departments</option>{directory.departments.map(item => <option key={item.department_id} value={item.department_id}>{item.department_name}</option>)}</select></label>}
            {mode === 'users' && <button type="button" disabled={busy || loading} onClick={() => { setAssignExisting(true); setEmployee(null); setEmployeeSearch(''); setError(''); setMessage(''); }}>Assign existing employee</button>}
            <button className="management-primary" disabled={busy || loading} onClick={() => { setAssignExisting(false); setError(''); setMessage(''); if (mode === 'users') setEmployee({ ...blankEmployee }); else setDepartment({ ...blankDepartment }); }}>＋ {mode === 'users' ? 'Add employee' : 'Add department'}</button>
          </div>
          <div id="management-editor" />
          {assignExisting && <section className="dashboard-panel management-editor"><h3>Assign roles to an existing employee</h3><p>Select an employee already in this system, including employees without roles. This updates their existing account—it does not create a duplicate or reset their password.</p><div className="management-form-grid"><label>Find employee<input value={employeeSearch} onChange={event => setEmployeeSearch(event.target.value)} placeholder="Search name, PIN or EPF" disabled={busy} /></label><label>Existing employee<select value={employee?.id ?? ''} disabled={busy} onChange={event => { const selected = directory.users.find(record => String(record.id) === event.target.value); if (selected) editEmployee(selected); else setEmployee(null); }}><option value="">Select employee</option>{existingEmployees.map(record => <option key={record.id} value={record.id}>{record.name} · PIN {record.employee_pin} · EPF {record.employee_epf}{!record.is_active ? ' · Inactive' : ''}{record.roles.length === 0 ? ' · No roles' : ''}</option>)}</select></label></div>{existingEmployees.length === 0 && <p>No employees match. External applicants are not included.</p>}<div className="management-buttons"><button type="button" disabled={busy} onClick={() => { setAssignExisting(false); setEmployee(null); }}>Cancel assignment</button></div></section>}
          {employee && <section className="dashboard-panel management-editor"><h3>{employee.id ? assignExisting ? 'Review employee and assign roles' : 'Edit employee' : 'New employee'}</h3><p>{employee.id ? 'Select the roles and department below. Saving updates this existing employee and signs out their current sessions.' : 'New accounts use PIN or EPF for the first password and must change it on sign-in.'}</p>
            <form onSubmit={saveEmployee}><fieldset disabled={busy}><div className="management-form-grid">
              {(['name', 'employee_pin', 'employee_epf', 'phone', 'email'] as const).map(key => <label key={key}>{{ name: 'Full name', employee_pin: 'Employee PIN', employee_epf: 'Employee EPF', phone: 'Registered phone', email: 'Email (optional)' }[key]}<input required={['name', 'employee_pin', 'employee_epf'].includes(key)} type={key === 'email' ? 'email' : 'text'} inputMode={key === 'employee_pin' || key === 'employee_epf' ? 'numeric' : undefined} value={employee[key]} onChange={event => setEmployee({ ...employee, [key]: event.target.value })} maxLength={key === 'phone' ? 30 : key.startsWith('employee_') ? 50 : 255} /></label>)}
              <label>Department<select value={employee.department_id} onChange={event => setEmployee({ ...employee, department_id: event.target.value })}><option value="">No department</option>{directory.departments.filter(item => item.is_active || String(item.department_id) === employee.department_id).map(item => <option key={item.department_id} value={item.department_id} disabled={!item.is_active}>{item.department_name}{!item.is_active && ' (inactive — select another)'}</option>)}</select></label>
            </div><fieldset className="management-roles"><legend>Assigned roles</legend>{directory.roles.map(role => <label key={role.role_id}><input type="checkbox" checked={employee.role_ids.includes(role.role_id)} onChange={event => setEmployee({ ...employee, role_ids: event.target.checked ? [...employee.role_ids, role.role_id] : employee.role_ids.filter(id => id !== role.role_id) })} />{role.role_name}</label>)}</fieldset>
            <p className="management-help">To assign a HOD, choose Head of Department and their department. Internal employees also require a registered phone. Permission rules are controlled by the backend, not editable here.</p>
            <label className="management-check"><input type="checkbox" checked={employee.is_active} onChange={event => setEmployee({ ...employee, is_active: event.target.checked })} />Account active</label>
            <div className="management-buttons"><button className="management-primary" disabled={employee.role_ids.length === 0}>{busy ? 'Saving…' : assignExisting ? 'Save role assignment' : 'Save employee'}</button><button type="button" onClick={() => setEmployee(null)}>Cancel</button></div></fieldset></form>
          </section>}
          {department && <section className="dashboard-panel management-editor"><h3>{department.department_id ? 'Edit department' : 'New department'}</h3><form onSubmit={saveDepartment}><fieldset disabled={busy}><div className="management-form-grid"><label>Department name<input required maxLength={255} value={department.department_name} onChange={event => setDepartment({ ...department, department_name: event.target.value })} /></label><label>Description<textarea maxLength={2000} value={department.description} onChange={event => setDepartment({ ...department, description: event.target.value })} /></label></div><label className="management-check"><input type="checkbox" checked={department.is_active} onChange={event => setDepartment({ ...department, is_active: event.target.checked })} />Department active</label><p className="management-help">Inactive departments are hidden from new vacancy dropdowns. Active employees and ongoing vacancies must be reassigned or completed first.</p><div className="management-buttons"><button className="management-primary">{busy ? 'Saving…' : 'Save department'}</button><button type="button" onClick={() => setDepartment(null)}>Cancel</button></div></fieldset></form></section>}
          {loading ? <p role="status">Loading directory…</p> : <section className="dashboard-panel">
            <h3>{mode === 'users' ? `${employees.length} employees` : `${departments.length} departments`}</h3>
            {mode === 'users' ? <div className="management-table-wrap"><table className="management-table"><thead><tr><th>Employee</th><th>PIN / EPF</th><th>Department & roles</th><th>Status</th><th>Actions</th></tr></thead><tbody>{employees.map(record => <tr key={record.id}>
              <td><strong>{record.name}</strong><small>{record.email || 'No email'}</small><small>{record.phone || 'No registered phone'}</small></td><td><small>PIN: {record.employee_pin}</small><small>EPF: {record.employee_epf}</small></td>
              <td><strong>{record.department?.department_name ?? directory.departments.find(item => String(item.department_id) === String(record.department_id))?.department_name ?? 'No department assigned'}</strong><small>Role: {record.roles.map(role => role.role_name).join(', ') || 'No role assigned'}</small>{!record.department && !directory.departments.some(item => String(item.department_id) === String(record.department_id)) && record.roles.some(role => role.role_name === 'Head of Department') && <small className="management-assignment-warning">Select a department in Edit to enable HOD approvals.</small>}</td><td><span className={`workflow-status management-status--${record.is_active ? 'active' : 'inactive'}`}>{record.is_active ? 'Active' : 'Inactive'}</span>{record.first_login && <small>Password change required</small>}</td>
              <td><div className="management-buttons"><button disabled={busy || !editable(record)} onClick={() => editEmployee(record)}>Edit</button><button disabled={busy || !editable(record) || record.id === user.id} onClick={() => { if (window.confirm(`Reset ${record.name}’s password to their PIN or EPF and sign out their sessions?`)) void save(`/users/${record.id}/reset-password`, 'POST'); }}>Reset password</button><button type="button" className={record.is_active ? 'management-deactivate' : 'management-reactivate'} disabled={busy || !editable(record) || record.id === user.id} onClick={() => {
                const prompt = record.is_active ? `Deactivate ${record.name}? They will be signed out and cannot sign in. Their recruitment history will be kept.` : `Reactivate ${record.name}? They will be able to sign in again.`;
                if (window.confirm(prompt)) void save(`/users/${record.id}/active`, 'PATCH', { is_active: !record.is_active });
              }}>{record.is_active ? 'Deactivate' : 'Reactivate'}</button></div>{!editable(record) && <small>Administrator managed</small>}</td>
            </tr>)}</tbody></table>{employees.length === 0 && <p className="vacancy-empty">No employees match these filters.</p>}</div> : <div className="management-departments">{departments.map(record => {
              const members = directory.users.filter(item => String(item.department_id) === String(record.department_id) && item.is_active);
              const heads = members.filter(item => item.roles.some(role => role.role_name === 'Head of Department'));
              return <article className="management-department" key={record.department_id}><div><h3>{record.department_name}</h3><span className={`workflow-status management-status--${record.is_active ? 'active' : 'inactive'}`}>{record.is_active ? 'Active' : 'Inactive'}</span></div><p>{record.description || 'No description added.'}</p><p><strong>HOD:</strong> {heads.map(head => head.name).join(', ') || 'Not assigned'}</p><small>{members.length} active employees</small><div className="management-buttons"><button disabled={busy} onClick={() => { setDepartment({ ...record, description: record.description ?? '' }); document.getElementById('management-editor')?.scrollIntoView({ behavior: 'smooth' }); }}>Edit department</button><Link href="/dashboard/users">Assign HOD in Users →</Link></div></article>;
            })}{departments.length === 0 && <p className="vacancy-empty">No departments match these filters.</p>}</div>}
          </section>}
        </>}
      </div>
    </section>
  </div></main>;
}
