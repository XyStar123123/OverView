<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiException;
use App\Models\Artifact;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ArtifactsController extends ApiV1Controller
{
    /** GET /artifacts/{id}. */
    public function show(Request $request, int $id)
    {
        return $this->data($this->transformArtifact($this->artifactFor($request, $id)));
    }

    /**
     * GET /artifacts/{id}/download — binary file with
     * Content-Disposition: attachment (§4.2).
     */
    public function download(Request $request, int $id)
    {
        $artifact = $this->artifactFor($request, $id);
        $disk = Storage::disk('overview');

        if (! $disk->exists($artifact->file_path)) {
            throw ApiException::notFound('Artifact file is missing from storage.');
        }

        $download = $disk->get($artifact->file_path);

        return response($download, 200, [
            'Content-Type' => $artifact->content_type ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $artifact->name . '"',
            'Content-Length' => (string) strlen($download),
        ]);
    }

    /**
     * POST /artifacts/{id}/review — accept/reject + note (§4.2):
     * status → accepted/rejected, reviewer + note recorded, audit row.
     */
    public function review(Request $request, int $id)
    {
        $artifact = $this->artifactFor($request, $id);
        $this->requireMutation($request);

        $validated = $request->validate([
            'decision' => 'required|in:accept,reject',
            'note' => 'sometimes|nullable|string',
        ]);

        $status = $validated['decision'] === 'accept' ? 'accepted' : 'rejected';
        $before = ['status' => $artifact->status];

        $artifact->update([
            'status' => $status,
            'reviewed_by' => $request->user()->id,
            'review_note' => $validated['note'] ?? null,
        ]);

        AuditLog::record(
            $artifact->workspace_id,
            'user',
            $request->user()->id,
            'artifact',
            $artifact->id,
            'artifact.reviewed',
            $before,
            ['status' => $status],
        );

        return $this->data($this->transformArtifact($artifact->fresh()));
    }

    private function artifactFor(Request $request, int $id): Artifact
    {
        $artifact = Artifact::where('workspace_id', $this->workspaceFor($request)->id)->find($id);

        if ($artifact === null) {
            throw ApiException::notFound('Artifact not found.');
        }

        return $artifact;
    }
}
