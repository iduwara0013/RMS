'use client';

import Image from 'next/image';
import Link from 'next/link';
import { usePathname } from 'next/navigation';

type NavigationProps = {
  roles: string[];
};

type NavigationItem = {
  href: string;
  label: string;
  icon: string;
  roles?: string[];
  external?: boolean;
};

const staffRoles = ['HR Manager', 'Head of Department', 'Managing Director', 'System Administrator'];

function canAccess(userRoles: string[], allowedRoles?: string[]) {
  return !allowedRoles || userRoles.some((role) => allowedRoles.includes(role));
}

export default function DashboardNavigation({ roles }: NavigationProps) {
  const pathname = usePathname();
  const vacancyLabel = roles.includes('HR Manager') ? 'Vacancies' : 'Approvals';
  const items: NavigationItem[] = [
    { href: '/dashboard', label: 'Dashboard', icon: '⌂' },
    { href: '/dashboard/vacancies', label: vacancyLabel, icon: '＋', roles: staffRoles },
    { href: '/dashboard/applications', label: 'Applications', icon: '◌', roles: ['HR Manager', 'Data Entry Operator', 'Head of Department', 'Managing Director'] },
    { href: '/dashboard/interviews', label: 'Interviews', icon: '▤', roles: ['HR Manager', 'Interview Panel Member'] },
    { href: '/dashboard/selections', label: 'Final selection', icon: '◆', roles: ['HR Manager', 'Managing Director'] },
    { href: '/dashboard/completed', label: 'Completed candidates', icon: '✓', roles: ['HR Manager', 'System Administrator', 'Managing Director'] },
    { href: '/dashboard/notifications', label: 'Notifications', icon: '▣', roles: ['HR Manager', 'System Administrator'] },
    { href: '/vacancies', label: 'Public careers', icon: '↗', external: true },
  ].filter((item) => canAccess(roles, item.roles));

  function isActive(href: string) {
    return href === '/dashboard' ? pathname === href : pathname.startsWith(href);
  }

  const links = items.map((item) => (
    <Link
      className={`nav-link${isActive(item.href) ? ' active' : ''}`}
      href={item.href}
      key={item.href}
      aria-current={isActive(item.href) ? 'page' : undefined}
      target={item.external ? '_blank' : undefined}
      rel={item.external ? 'noreferrer' : undefined}
    >
      <span className="nav-icon" aria-hidden="true">{item.icon}</span>
      <span>{item.label}</span>
    </Link>
  ));

  return (
    <>
      <aside className="app-sidebar">
        <Link className="sidebar-brand" href="/dashboard">
          <span className="sidebar-brand__mark"><Image src="/cpstl-logo.png" alt="CPSTL logo" width={48} height={48} priority /></span>
          <span><strong>CPSTL RMS</strong><small>Recruitment portal</small></span>
        </Link>
        <div className="sidebar-wrapper">
          <nav aria-label="Dashboard navigation">
            <div className="nav-header">Workspace</div>
            {links}
          </nav>
          <div className="sidebar-footer"><span className="sidebar-footer__dot" />Secure staff workspace</div>
        </div>
      </aside>
      <nav className="mobile-dashboard-nav" aria-label="Mobile dashboard navigation">
        {links}
      </nav>
    </>
  );
}
