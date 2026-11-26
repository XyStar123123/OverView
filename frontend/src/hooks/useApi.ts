import { useCallback, useEffect, useRef, useState } from "react";

interface ApiState<T> {
  data: T | null;
  error: string | null;
  loading: boolean;
}

/**
 * Minimal data-fetch hook around an endpoint promise.
 * `deps` controls refetch; passing a changing `refreshToken` refetches too.
 */
export function useApi<T>(fetcher: () => Promise<T>, deps: unknown[] = [], refreshToken = 0): ApiState<T> & {
  refresh: () => void;
} {
  const [state, setState] = useState<ApiState<T>>({ data: null, error: null, loading: true });
  const [force, setForce] = useState(0);
  const fetcherRef = useRef(fetcher);
  fetcherRef.current = fetcher;

  useEffect(() => {
    let active = true;
    setState((s) => ({ data: s.data, error: null, loading: s.data === null }));
    fetcherRef
      .current()
      .then((data) => {
        if (active) setState({ data, error: null, loading: false });
      })
      .catch((err: unknown) => {
        if (active)
          setState({ data: null, error: err instanceof Error ? err.message : String(err), loading: false });
      });
    return () => {
      active = false;
    };
  }, [...deps, refreshToken, force]);

  const refresh = useCallback(() => setForce((n) => n + 1), []);
  return { ...state, refresh };
}

export function usePolling(active: boolean, intervalMs: number, fn: () => void): void {
  const fnRef = useRef(fn);
  fnRef.current = fn;

  useEffect(() => {
    if (!active) return;
    const id = window.setInterval(() => fnRef.current(), intervalMs);
    return () => window.clearInterval(id);
  }, [active, intervalMs]);
}
