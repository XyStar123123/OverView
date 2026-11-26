import { ReactNode, useEffect, useState } from "react";
import { NavLink, Link, useNavigate } from "react-router-dom";
import { useAuth } from "../auth/AuthProvider";
import { getApprovals } from "../api/dataSource";
import css from "./AppShell.module.css";

interface NavItem {
  to: string;
  label: string;
  badgeKey?: "approvals";
}

const NAV: NavItem[] = [
  { to: "/", label: "Dashboard" },
  { to: "/projects", label: "Projects" },
  { to: "/agents", label: "Agents" },
  { to: "/memory", label: "Memory" },
  { to: "/executions", label: "Executions" },
  { to: "/approvals", label: "Approvals", badgeKey: "approvals" },
  { to: "/settings", label: "Settings" },
];

export function AppShell({ children }: { children: ReactNode }) {
  const { auth, logout } = useAuth();
  const navigate = useNavigate();
  const [pendingApprovals, setPendingApprovals] = useState(0);

  useEffect(() => {
    getApprovals({ status: "pending" })
      .then((r) => setPendingApprovals(r.data.length))
      .catch(() => undefined);
  }, []);

  const badge = (item: NavItem) =>
    item.badgeKey === "approvals" && pendingApprovals > 0 ? (
      <span className={css.navBadge}>{pendingApprovals}</span>
    ) : null;

  return (
    <div className={css.shell}>
      <aside className={css.sidebar}>
        <div className={css.brand}>
          <span className={css.brandMark} aria-hidden="true" />
          OverView
        </div>
        <nav className={css.nav} aria-label="Primary">
          {NAV.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.to === "/"} className={({ isActive }) => `${css.navItem} ${isActive ? css.navItemActive : ""}`}>
              {item.label}
              {badge(item)}
            </NavLink>
          ))}
        </nav>
        <div className={css.sidebarFooter}>
          {auth && (
            <>
              <div className={css.user} title={auth.user.email}>
                <span className={css.userDot} aria-hidden="true" />
                <span className={css.userName}>{auth.user.name}</span>
              </div>
              <div className={css.workspace}>{auth.workspace.name}</div>
              <button
                type="button"
                className={css.logout}
                onClick={() => {
                  void logout();
                  navigate("/login");
                }}
              >
                Log out
              </button>
            </>
          )}
        </div>
      </aside>
      <main className={css.main}>{children}</main>
    </div>
  );
}

/** Re-export for convenience on pages that need to deep-link to a task. */
export function TaskLink({ taskId, children }: { taskId: number; children: ReactNode }) {
  return (
    <Link to={`/tasks/${taskId}`} className={css.taskLink}>
      {children}
    </Link>
  );
}
