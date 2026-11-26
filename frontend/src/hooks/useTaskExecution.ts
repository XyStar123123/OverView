import { useCallback, useRef, useState } from "react";
import { executeTask, getExecution } from "../api/endpoints";
import type { Execution, Task } from "../api/types";

/**
 * Vertical slice of the execute flow on a task:
 * click Execute → POST /tasks/{id}/execute → poll the execution every 2 s
 * while it is queued/running (per contract §6, no SSE) → return the
 * completed/failed execution so the UI can link to the execution detail.
 */
export function useTaskExecution(task: Task, onDone: (execution: Execution) => void) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const onDoneRef = useRef(onDone);
  onDoneRef.current = onDone;

  const run = useCallback(async () => {
    setBusy(true);
    setError(null);
    try {
      const created = await executeTask(task.id);
      let exec = created.data;
      // The mock runner on QUEUE_CONNECTION=sync finishes before the response
      // returns, but keep the polling contract (2 s) for queue drivers that
      // don't: poll while queued/running, up to ~2 minutes.
      const deadline = Date.now() + 2 * 60 * 1000;
      while ((exec.status === "queued" || exec.status === "running") && Date.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, 2000));
        const fresh = await getExecution(exec.id);
        exec = fresh.data;
      }
      onDoneRef.current(exec);
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }, [task.id]);

  return { busy, error, run };
}
