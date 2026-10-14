<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $revision = $this->latestRevision;

        return [
            'id' => $this->id,
            'latest_revision' => $revision ? [
                'revision_number' => $revision->revision_number,
                'point_type_version_id' => $revision->point_type_version_id,
                'name' => $revision->name_snapshot,
                'category' => $revision->category_snapshot,
                'points' => $revision->points_snapshot,
                'description' => $revision->description,
                'attachment_id' => $revision->attachment_id,
                'status' => $revision->status,
            ] : null,
            'source' => $this->source,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'student' => ['id' => $this->student_id, 'name' => $this->student?->user?->name],
            'teacher' => ['id' => $this->teacher_id, 'name' => $this->teacher?->user?->name],
            'semester' => ['id' => $this->semester_id, 'name' => $this->semester?->name],
            'review_status' => $this->reviewRequests()->latest('created_at')->value('status'),
        ];
    }
}
