<?php

namespace App\Domain\Runtime;

use App\Models\Memory;
use App\Models\Task;

/**
 * Builds the deterministic prompt array sent to the LLM adapter
 * (ARCHITECTURE.md 5.3 steps 3/5). Sections: project context, task,
 * agent instructions, retrieved memories (bounded by the context budget,
 * 5.3 step 4: estimate tokens = ceil(strlen/4)).
 */
class ContextAssembler
{
    /**
     * @param  array<int, Memory>  $memories  retrieved memories, ranked
     * @return array{messages: array<int, array{role: string, content: string}>, sections: array<int, string>, promptTokens: int}
     */
    public function assemble(Task $task, ?\App\Models\Agent $agent, array $memories, ?Memory $workingMemory): array
    {
        $project = $task->project;
        $sections = ['project', 'task', 'agent_instructions'];
        $messages = [];

        $projectSection = "PROJECT: {$project->name} — {$project->description}\nObjectives: "
            . implode('; ', $project->objectives ?? []);
        $messages[] = ['role' => 'system', 'content' => $projectSection];

        $taskSection = "TASK: {$task->title}\nType: {$task->type}\nStatus: {$task->status}\nDescription: {$task->description}";
        $messages[] = ['role' => 'user', 'content' => $taskSection];

        if ($agent && $agent->instructions !== null) {
            $messages[] = ['role' => 'system', 'content' => "AGENT: {$agent->name} ({$agent->role})\n{$agent->instructions}"];
        }

        if ($workingMemory !== null) {
            $sections[] = 'working_memory';
            $messages[] = ['role' => 'system', 'content' => "[working-memory] {$workingMemory->title}: {$workingMemory->content}"];
        }

        if ($memories !== []) {
            $sections[] = 'memory(' . count($memories) . ')';
            foreach ($memories as $memory) {
                $messages[] = ['role' => 'system', 'content' => "[memory] {$memory->title}: {$memory->content}"];
            }
        }

        $promptTokens = 0;
        foreach ($messages as $message) {
            $promptTokens += (int) ceil(strlen($message['content']) / 4);
        }

        return ['messages' => $messages, 'sections' => $sections, 'promptTokens' => $promptTokens];
    }

    /**
     * Trim the ranked candidate list to fit the context budget
     * (ARCHITECTURE.md 5.3 step 4: cut the longest list until it fits).
     *
     * @return array{kept: Memory[], dropped: int}
     */
    public function fitBudget(array $memories, int $budgetTokens): array
    {
        $kept = [];
        $used = 0;

        foreach ($memories as $memory) {
            $tokens = (int) ceil(strlen($memory->title . $memory->content) / 4);

            if ($used + $tokens > $budgetTokens && $kept !== []) {
                continue;
            }

            $kept[] = $memory;
            $used += $tokens;
        }

        return ['kept' => $kept, 'dropped' => count($memories) - count($kept)];
    }
}
