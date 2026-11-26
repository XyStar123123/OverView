import { Routes, Route, Navigate, Outlet } from "react-router-dom";
import { AppShell } from "./components/AppShell";
import { useAuth } from "./auth/AuthProvider";
import DashboardPage from "./pages/DashboardPage";
import ProjectListPage from "./pages/ProjectListPage";
import ProjectDetailPage from "./pages/ProjectDetailPage";
import TaskDetailPage from "./pages/TaskDetailPage";
import AgentsPage from "./pages/AgentsPage";
import AgentDetailPage from "./pages/AgentDetailPage";
import MemoryExplorerPage from "./pages/MemoryExplorerPage";
import ExecutionsPage from "./pages/ExecutionsPage";
import ExecutionDetailPage from "./pages/ExecutionDetailPage";
import ApprovalsPage from "./pages/ApprovalsPage";
import SettingsPage from "./pages/SettingsPage";
import LoginPage from "./pages/LoginPage";

/** Protected layout: every §6 route renders inside AppShell; no session → /login. */
function ProtectedLayout() {
  const { auth, loading } = useAuth();
  if (loading) return <div className="ov-page ov-muted">Loading…</div>;
  if (!auth) return <Navigate to="/login" replace />;
  return (
    <AppShell>
      <Outlet />
    </AppShell>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<ProtectedLayout />}>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/projects" element={<ProjectListPage />} />
        <Route path="/projects/:id" element={<ProjectDetailPage tab="overview" />} />
        <Route path="/projects/:id/board" element={<ProjectDetailPage tab="board" />} />
        <Route path="/projects/:id/tasks" element={<ProjectDetailPage tab="tasks" />} />
        <Route path="/tasks/:id" element={<TaskDetailPage />} />
        <Route path="/agents" element={<AgentsPage />} />
        <Route path="/agents/:id" element={<AgentDetailPage />} />
        <Route path="/memory" element={<MemoryExplorerPage />} />
        <Route path="/executions" element={<ExecutionsPage />} />
        <Route path="/executions/:id" element={<ExecutionDetailPage />} />
        <Route path="/approvals" element={<ApprovalsPage />} />
        <Route path="/settings" element={<SettingsPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
