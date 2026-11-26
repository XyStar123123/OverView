<?php

namespace App\Domain\Orchestration;

use App\Domain\Runtime\MockRunner;
use App\Domain\Runtime\RunnerContract;
use App\Models\Execution;
use App\Models\Runtime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued job that runs a queued execution on its agent's runtime
 * (ARCHITECTURE.md 5.2/5.3). Under QUEUE_CONNECTION=sync the mock runner
 * completes in-process before the HTTP response returns; with
 * database/redis queues the same job runs on a worker — the only swap
 * point (ADR-2, 5.5).
 */
class ExecuteAgentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $executionId,
        public ?string $memorySearchQuery = null,
    ) {
    }

    public function handle(): void
    {
        $execution = Execution::find($this->executionId);

        if ($execution === null || $execution->status !== 'queued') {
            return; // cancelled while queued, or already moved on
        }

        $runner = $this->runnerFor($execution);

        $runner->run($execution, $this->memorySearchQuery);
    }

    private function runnerFor(Execution $execution): RunnerContract
    {
        $adapter = Runtime::find($execution->runtime_id)?->adapter ?? 'mock';

        // Prototype binds the mock adapter; a real runtime adapter would
        // register its own RunnerContract implementation here (5.5).
        if ($adapter !== 'mock') {
            throw new \RuntimeException("No runner bound for adapter: {$adapter}");
        }

        return app(MockRunner::class);
    }
}
