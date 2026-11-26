import { useState, type FormEvent } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import { useAuth } from "../auth/AuthProvider";
import css from "./Page.module.css";

export default function LoginPage() {
  const { auth, loading, login } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState("demo@overview.local");
  const [password, setPassword] = useState("demo");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (loading) {
    return (
      <div className={css.login}>
        <div className="ov-muted">Loading…</div>
      </div>
    );
  }
  if (auth) return <Navigate to="/" replace />;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await login(email, password);
      navigate("/", { replace: true });
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className={css.login}>
      <div className={css.loginCard}>
        <div className="ov-card">
          <h1>OverView</h1>
          <p className="ov-muted" style={{ marginTop: 0 }}>
            Sign in to your workspace
          </p>
          <form className={css.form} onSubmit={(e) => void submit(e)}>
            <label className={css.field}>
              <span>Email</span>
              <input value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" required />
            </label>
            <label className={css.field}>
              <span>Password</span>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="current-password"
                required
              />
            </label>
            {error && <div className="ov-error">{error}</div>}
            <div className={css.actions}>
              <button type="submit" className="btn btn-primary" disabled={busy}>
                {busy ? "Signing in..." : "Sign in"}
              </button>
            </div>
            <p className="ov-muted" style={{ fontSize: 12, margin: 0 }}>
              Mock session (backend down): demo@overview.local / demo
            </p>
          </form>
        </div>
      </div>
    </div>
  );
}
