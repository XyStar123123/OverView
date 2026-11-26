<?php

namespace App\Enums;

/**
 * Execution event types (ARCHITECTURE.md 3.6 / PRD 6.12).
 */
enum ExecutionEventType: string
{
    case TaskReceived = 'task_received';
    case AgentInitialized = 'agent_initialized';
    case ContextAssembled = 'context_assembled';
    case MemoryRetrieval = 'memory_retrieval';
    case MemoryStored = 'memory_stored';
    case MemoryRejected = 'memory_rejected';
    case LlmRequest = 'llm_request';
    case LlmResponse = 'llm_response';
    case ToolRequested = 'tool_requested';
    case ToolExecuted = 'tool_executed';
    case ToolRejected = 'tool_rejected';
    case ApprovalRequested = 'approval_requested';
    case ArtifactSubmitted = 'artifact_submitted';
    case TaskStatusUpdated = 'task_status_updated';
    case ExecutionCompleted = 'execution_completed';
    case ExecutionFailed = 'execution_failed';
    case Error = 'error';
}
