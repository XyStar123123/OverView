import { Link } from "react-router-dom";
import { getDashboardStats } from "../api/dataSource";
import { useApi } from "../hooks/useApi";
import { EmptyState } from "../components/EmptyState";
import { ActivityList, Card, Meta, PageHead, StateView } from "./ui";
import css from "./Page.module.css";

export default function DashboardPage() {
  const stats = useApi(() => getDashboardStats());

  return (
    <div>
      <PageHead title="Dashboard" sub="Workspace KPIs, last-7-day signals and recent activity." />
      <StateView state={stats} empty={<EmptyState title="No dashboard data" />}>
        {(s) => {
          const kpis = [
            { label: "Projects", value: `${s.totals.activeProjects} of ${s.totals.projects} active`, to: "/projects" },
            { label: "Agents", value: `${s.totals.activeAgents} of ${s.totals.agents} active`, to: "/agents" },
            { label: "Executions running", value: String(s.executions.running), to: "/executions" },
            { label: "Tasks blocked", value: String(s.tasks.blocked), to: "/projects" },
            { label: "Tasks in review", value: String(s.tasks.inReview), to: "/approvals" },
            { label: "Pending approvals", value: String(s.pendingApprovals), to: "/approvals" },
          ];
          return (
            <>
              <div className={`${css.cards} ${css.kpis}`}>
                {kpis.map((k) => (
                  <Link key={k.label} to={k.to} className="ov-card" style={{ display: "block", textDecoration: "none" }}>
                    <div style={{ fontSize: 12, color: "var(--ov-text-secondary)" }}>{k.label}</div>
                    <div style={{ fontSize: 20, fontWeight: 600, marginTop: 4 }}>{k.value}</div>
                  </Link>
                ))}
              </div>
              <div className={css.split}>
                <Card title="Recent activity" right={<Link to="/executions">All executions</Link>}>
                  <ActivityList items={s.recentActivity} />
                </Card>
                <Card title="Last 7 days">
                  <Meta
                    items={[
                      { label: "Executions completed", value: s.executions.completedLast7Days },
                      { label: "Executions failed", value: s.executions.failedLast7Days },
                      { label: "Tasks completed", value: s.tasks.completedLast7Days },
                      { label: "Memories", value: s.totals.memories },
                      { label: "Pending memory reviews", value: s.totals.pendingMemoryReviews },
                    ]}
                  />
                </Card>
              </div>
            </>
          );
        }}
      </StateView>
    </div>
  );
}
