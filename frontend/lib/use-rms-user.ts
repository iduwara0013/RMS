'use client';

import { useMemo, useSyncExternalStore } from 'react';

type RmsUser = { name: string; roles: string[] };
const HYDRATING_SESSION = '__rms_session_hydrating__';

function subscribe(onStoreChange: () => void) {
  window.addEventListener('storage', onStoreChange);
  return () => window.removeEventListener('storage', onStoreChange);
}

function getSnapshot() { return sessionStorage.getItem('rms_user'); }

export function useRmsUser<T extends RmsUser = RmsUser>() {
  const storedUser = useSyncExternalStore(subscribe, getSnapshot, () => HYDRATING_SESSION);
  return useMemo<T | null | undefined>(() => {
    if (storedUser === HYDRATING_SESSION) return undefined;
    if (!storedUser) return null;
    try { return JSON.parse(storedUser) as T; } catch { return null; }
  }, [storedUser]);
}
