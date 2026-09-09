'use client';

import Image from 'next/image';
import { FormEvent, useState } from 'react';
import { useRouter } from 'next/navigation';

type ScreenState = 'login' | 'first-time-setup';
type AuthenticatedUser = {
  id: number;
  name: string;
  employee_pin: string;
  employee_epf: string;
  department_id: number | null;
  department_name: string | null;
  roles: string[];
};
type LoginResponse = {
  message?: string;
  first_login?: boolean;
  token?: string;
  user?: AuthenticatedUser;
};
type ApiMessageResponse = { message?: string };
const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000/api';

export default function LoginPage() {
  const router = useRouter();
  const [screen, setScreen] = useState<ScreenState>('login');
  
  // Login credentials
  const [employeeIdentifier, setEmployeeIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);

  // First-Time Password Setup state
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  // Status & Feedback
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [statusMessage, setStatusMessage] = useState<{ text: string; type: 'success' | 'error' } | null>(null);

  // Validation checks for new password
  const hasMinLength = newPassword.length >= 8;
  const passwordsMatch = newPassword.length > 0 && newPassword === confirmPassword;

  async function handleLoginSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setStatusMessage(null);
    setIsSubmitting(true);

    try {
      const response = await fetch(`${API_BASE_URL}/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ identifier: employeeIdentifier, password }),
      });
      const payload = await response.json().catch(() => ({})) as LoginResponse;
      if (!response.ok) throw new Error(payload.message ?? 'Unable to sign in.');
      if (payload.user) sessionStorage.setItem('rms_user', JSON.stringify(payload.user));
      if (payload.token) sessionStorage.setItem('rms_staff_token', payload.token);
      if (payload.first_login) setScreen('first-time-setup');
      else router.push('/dashboard');
    } catch (error) {
      setStatusMessage({ type: 'error', text: error instanceof Error ? error.message : 'Unable to sign in.' });
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleFirstTimePasswordSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setStatusMessage(null);

    if (!hasMinLength) {
      setStatusMessage({
        type: 'error',
        text: 'New password must be at least 8 characters long.'
      });
      return;
    }

    if (!passwordsMatch) {
      setStatusMessage({
        type: 'error',
        text: 'New passwords do not match. Please verify and try again.'
      });
      return;
    }

    setIsSubmitting(true);

    try {
      const response = await fetch(`${API_BASE_URL}/change-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${sessionStorage.getItem('rms_staff_token') ?? ''}` },
        body: JSON.stringify({
          identifier: employeeIdentifier,
          password: newPassword,
          password_confirmation: confirmPassword,
        }),
      });
      const payload = await response.json().catch(() => ({})) as ApiMessageResponse;
      if (!response.ok) throw new Error(payload.message ?? 'Unable to change password.');
      router.push('/dashboard');
      setStatusMessage({ type: 'success', text: 'Password changed successfully.' });
    } catch (error) {
      setStatusMessage({ type: 'error', text: error instanceof Error ? error.message : 'Unable to change password.' });
    } finally {
      setIsSubmitting(false);
    }
  }

  function handleSignOut() {
    setEmployeeIdentifier('');
    setPassword('');
    setNewPassword('');
    setConfirmPassword('');
    setStatusMessage(null);
    sessionStorage.removeItem('rms_user');
    sessionStorage.removeItem('rms_staff_token');
    router.push('/');
  }

  return (
    <main className="login-page">
      <section className="brand-panel" aria-labelledby="product-name">
        <div className="brand-panel__glow" />

        <div className="hex-cluster hex-cluster--top" aria-hidden="true">
          <span className="hex hex--outline" />
          <span className="hex hex--orange" />
          <span className="hex hex--muted" />
        </div>

        <header className="brand-mark">
          <span className="brand-mark__logo-wrap">
            <Image className="brand-mark__logo" src="/cpstl-logo.png" alt="CPSTL logo" width={72} height={72} priority />
          </span>
          <span>
            <strong>Ceylon Petroleum Storage Terminals Limited</strong>
            <small>Recruitment Management System</small>
          </span>
        </header>

        <div className="brand-copy">
          <p className="eyebrow">Internal Staff Portal</p>
          <h1 id="product-name">Recruitment<br />Management System</h1>
          <p className="brand-copy__summary">
            One secure workspace for vacancies, approvals, applications,
            interview panel evaluations, and appointment recommendations.
          </p>

          <div className="workflow-preview" aria-label="Recruitment workflow summary">
            <div><span className="workflow-preview__number">01</span><span>Vacancy</span></div>
            <span className="workflow-preview__line" aria-hidden="true" />
            <div><span className="workflow-preview__number">02</span><span>Approval</span></div>
            <span className="workflow-preview__line" aria-hidden="true" />
            <div><span className="workflow-preview__number">03</span><span>Selection</span></div>
          </div>
        </div>

        <footer className="brand-panel__footer">
          <span className="status-dot" aria-hidden="true" />
          Secure Role-based Staff Access
        </footer>

        <div className="hex-cluster hex-cluster--bottom" aria-hidden="true">
          <span className="hex hex--navy" />
          <span className="hex hex--outline-orange" />
        </div>
      </section>

      <section className="form-panel" aria-labelledby="form-heading-title">
        <div className="form-shell">
          <div className="mobile-brand">
            <Image src="/cpstl-logo.png" alt="CPSTL logo" width={48} height={48} priority />
            <span><strong>CPSTL</strong><small>Recruitment Management System</small></span>
          </div>

          {/* SCREEN 1: STANDARD LOGIN */}
          {screen === 'login' && (
            <>
              <div className="form-heading">
                <p className="eyebrow eyebrow--red">Staff Authentication</p>
                <h2 id="form-heading-title">Sign in to your account</h2>
                <p>Enter either your Employee PIN or Employee EPF with your password.</p>
              </div>

              <form className="login-form" onSubmit={handleLoginSubmit}>
                <label className="field">
                  <div className="field__header">
                    <span>Employee PIN or Employee EPF</span>
                  </div>
                  <span className="field__control">
                    <span className="field__icon" aria-hidden="true">
                      <svg viewBox="0 0 24 24">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                      </svg>
                    </span>
                    <input
                      type="text"
                      name="employeeIdentifier"
                      autoComplete="username"
                      placeholder="Enter PIN or EPF (e.g. 10482)"
                      value={employeeIdentifier}
                      onChange={(e) => setEmployeeIdentifier(e.target.value)}
                      required
                    />
                  </span>
                </label>

                <label className="field">
                  <div className="field__header">
                    <span>Password</span>
                    <span className="field__helper">First-time? Use your PIN or EPF</span>
                  </div>
                  <span className="field__control">
                    <span className="field__icon" aria-hidden="true">
                      <svg viewBox="0 0 24 24">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
                      </svg>
                    </span>
                    <input
                      type={showPassword ? 'text' : 'password'}
                      name="password"
                      autoComplete="current-password"
                      placeholder="Enter your password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      required
                    />
                    <button
                      type="button"
                      className="password-toggle"
                      onClick={() => setShowPassword((visible) => !visible)}
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                    >
                      {showPassword ? 'Hide' : 'Show'}
                    </button>
                  </span>
                </label>

                <div className="form-options">
                  <label className="checkbox">
                    <input type="checkbox" name="remember" />
                    <span>Remember me</span>
                  </label>
                  <span className="field__helper">Contact the system administrator for password recovery.</span>
                </div>

                <button className="submit-button" type="submit" disabled={isSubmitting}>
                  <span>{isSubmitting ? 'Signing in…' : 'Sign in securely'}</span>
                  <span aria-hidden="true">→</span>
                </button>
              </form>
            </>
          )}

          {/* SCREEN 2: FIRST-TIME PASSWORD SETUP SCREEN */}
          {screen === 'first-time-setup' && (
            <>
              <div className="form-heading">
                <p className="eyebrow eyebrow--red">First-Time Setup</p>
                <h2 id="form-heading-title">Change Initial Password</h2>
                <p>Welcome! Since this is your first time logging in, please create a new permanent password.</p>
              </div>

              {/* Employee ID Badge */}
              <div className="identity-badge">
                <div className="identity-badge__avatar">
                  {employeeIdentifier ? employeeIdentifier.charAt(0).toUpperCase() : 'U'}
                </div>
                <div className="identity-badge__info">
                  <strong>Staff ID: {employeeIdentifier}</strong>
                  <span>First-Time Security Onboarding</span>
                </div>
              </div>

              <div className="onboarding-alert">
                <span className="onboarding-alert__icon" aria-hidden="true">🔒</span>
                <div>
                Your temporary password is your <strong>Employee PIN or Employee EPF</strong>. Please set a secure permanent password to continue to the system.
                </div>
              </div>

              <form className="login-form" onSubmit={handleFirstTimePasswordSubmit} style={{ marginTop: '20px' }}>
                <label className="field">
                  <div className="field__header">
                    <span>New Password</span>
                  </div>
                  <span className="field__control">
                    <span className="field__icon" aria-hidden="true">
                      <svg viewBox="0 0 24 24">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
                      </svg>
                    </span>
                    <input
                      type={showNewPassword ? 'text' : 'password'}
                      name="newPassword"
                      placeholder="Create a new password"
                      value={newPassword}
                      onChange={(e) => setNewPassword(e.target.value)}
                      required
                    />
                    <button
                      type="button"
                      className="password-toggle"
                      onClick={() => setShowNewPassword((v) => !v)}
                      aria-label={showNewPassword ? 'Hide password' : 'Show password'}
                    >
                      {showNewPassword ? 'Hide' : 'Show'}
                    </button>
                  </span>
                </label>

                <label className="field">
                  <div className="field__header">
                    <span>Confirm New Password</span>
                  </div>
                  <span className="field__control">
                    <span className="field__icon" aria-hidden="true">
                      <svg viewBox="0 0 24 24">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z" />
                      </svg>
                    </span>
                    <input
                      type={showConfirmPassword ? 'text' : 'password'}
                      name="confirmPassword"
                      placeholder="Confirm your new password"
                      value={confirmPassword}
                      onChange={(e) => setConfirmPassword(e.target.value)}
                      required
                    />
                    <button
                      type="button"
                      className="password-toggle"
                      onClick={() => setShowConfirmPassword((v) => !v)}
                      aria-label={showConfirmPassword ? 'Hide password' : 'Show password'}
                    >
                      {showConfirmPassword ? 'Hide' : 'Show'}
                    </button>
                  </span>
                </label>

                <div className="password-checklist">
                  <div className={`checklist-item ${hasMinLength ? 'checklist-item--valid' : ''}`}>
                    <span>{hasMinLength ? '✓' : '○'}</span>
                    <span>Minimum 8 characters</span>
                  </div>
                  <div className={`checklist-item ${passwordsMatch ? 'checklist-item--valid' : ''}`}>
                    <span>{passwordsMatch ? '✓' : '○'}</span>
                    <span>Passwords match</span>
                  </div>
                </div>

                <button
                  className="submit-button"
                  type="submit"
                  disabled={isSubmitting || !hasMinLength || !passwordsMatch}
                >
                  <span>{isSubmitting ? 'Saving password…' : 'Save Password & Enter System'}</span>
                  <span aria-hidden="true">→</span>
                </button>

                <button
                  type="button"
                  className="secondary-button"
                  onClick={handleSignOut}
                >
                  Cancel & Sign Out
                </button>
              </form>
            </>
          )}

          {statusMessage && (
            <p
              className={`form-message form-message--${statusMessage.type}`}
              role="status"
              aria-live="polite"
            >
              {statusMessage.text}
            </p>
          )}

          <p className="support-copy">
            Need help with your EPF or PIN? <a href="mailto:is.support@cpstl.lk">Contact IS support</a>
          </p>
        </div>
      </section>
    </main>
  );
}
