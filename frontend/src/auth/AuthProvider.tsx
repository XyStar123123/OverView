import { createContext, useCallback, useContext, useEffect, useMemo, useState, ReactNode } from "react";
import { getAuthContext, login as dataSourceLogin, logout as dataSourceLogout } from "../api/dataSource";
import type { AuthContext } from "../api/client";

interface AuthState {
  auth: AuthContext | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContextValue = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [auth, setAuth] = useState<AuthContext | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    getAuthContext()
      .then((ctx) => setAuth(ctx))
      .finally(() => setLoading(false));
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const ctx = await dataSourceLogin(email, password);
    setAuth(ctx);
  }, []);

  const logout = useCallback(async () => {
    await dataSourceLogout();
    setAuth(null);
  }, []);

  const value = useMemo(() => ({ auth, loading, login, logout }), [auth, loading, login, logout]);
  return <AuthContextValue.Provider value={value}>{children}</AuthContextValue.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContextValue);
  if (!ctx) throw new Error("useAuth must be used inside AuthProvider");
  return ctx;
}
