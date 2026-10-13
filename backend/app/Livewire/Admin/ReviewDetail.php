<?php

namespace App\Livewire\Admin;

use App\Actions\Reviews\DecideReviewRequest;
use App\Actions\Reviews\ReviewChanges;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\Attachment;
use App\Models\PointTypeVersion;
use App\Models\ReviewRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.admin')]
class ReviewDetail extends Component
{
    use RequiresRecentAdminConfirmation;

    #[Locked]
    public string $reviewId;

    #[Locked]
    public int $expectedRevision = 0;

    public string $decision = 'approve';

    public string $decisionReason = '';

    public string $operation = 'revise';

    public string $pointTypeVersionId = '';

    public string $description = '';

    public string $attachmentId = '';

    public string $message = '';

    public function mount(string $reviewId): void
    {
        $this->reviewId = $reviewId;
        $review = $this->review();
        $revision = $review->pointRecord->latestRevision;
        abort_unless($revision, 404);
        $this->expectedRevision = $revision->revision_number;
        $proposal = $review->proposed_change ?? [];
        $this->operation = $proposal['operation'] ?? 'revise';
        $this->description = $proposal['description'] ?? $revision->description;
        $this->attachmentId = $proposal['attachment_id'] ?? (string) ($revision->attachment_id ?? '');
        $this->pointTypeVersionId = $proposal['point_type_version_id'] ?? '';
    }

    public function decide(): void
    {
        $this->requireRecentAdminConfirmation();
        $values = $this->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'decisionReason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $review = $this->review();
        $changes = [];
        if ($values['decision'] === 'approve') {
            $changeValues = $this->validate([
                'operation' => ['required', Rule::in(['cancel', 'revise'])],
                'pointTypeVersionId' => ['nullable', 'uuid', Rule::exists('point_type_versions', 'id')],
                'description' => ['nullable', 'string', 'min:5', 'max:1000'],
                'attachmentId' => ['nullable', 'uuid', Rule::exists('attachments', 'id')],
            ]);
            $changes = $changeValues['operation'] === 'cancel'
                ? ['operation' => 'cancel']
                : array_filter([
                    'operation' => 'revise',
                    'point_type_version_id' => $changeValues['pointTypeVersionId'] ?: null,
                    'description' => $changeValues['description'] ?: null,
                    'attachment_id' => $changeValues['attachmentId'] ?: null,
                ], fn ($value): bool => $value !== null);

            try {
                ReviewChanges::validate($changes);
            } catch (ValidationException $exception) {
                $this->setErrorBag($exception->errors());

                return;
            }
        }

        try {
            app(DecideReviewRequest::class)->execute(
                auth('web')->user(),
                $review,
                $values['decision'],
                $values['decisionReason'],
                $this->expectedRevision,
                $changes,
            );
        } catch (HttpResponseException $exception) {
            $message = $exception->getResponse()->getStatusCode() === 409
                ? 'Catatan atau katalog berubah. Muat ulang pemeriksaan sebelum memutuskan.'
                : 'Pemeriksaan tidak dapat disimpan.';
            $this->addError('revision', $message);

            return;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return;
        }

        $this->message = $values['decision'] === 'approve' ? 'Pemeriksaan disetujui.' : 'Pemeriksaan ditolak.';
    }

    public function render()
    {
        $review = $this->review();
        $record = $review->pointRecord;
        $current = $record->latestRevision;
        $before = $record->revisions()->where('revision_number', $this->expectedRevision)->first();
        $proposalVersion = isset($review->proposed_change['point_type_version_id'])
            ? PointTypeVersion::with('pointType')->find($review->proposed_change['point_type_version_id'])
            : null;
        $versions = PointTypeVersion::query()
            ->with('pointType')
            ->whereIn('id', PointTypeVersion::query()->selectRaw('DISTINCT ON (point_type_id) id')->whereHas('pointType', fn ($query) => $query->where('active', true))->orderBy('point_type_id')->orderByDesc('version'))
            ->orderBy('name')->get();
        $attachments = Attachment::query()->where(function ($query) use ($record): void {
            $query->where('owner_user_id', $record->teacher->user_id)
                ->where(fn ($eligible) => $eligible->whereDoesntHave('recordRevisions')->orWhereHas('recordRevisions', fn ($revision) => $revision->where('point_record_id', $record->id)));
        })->get();

        return view('livewire.admin.review-detail', compact('review', 'record', 'current', 'before', 'proposalVersion', 'versions', 'attachments'), ['title' => 'Detail pemeriksaan']);
    }

    private function review(): ReviewRequest
    {
        return ReviewRequest::with([
            'requester',
            'pointRecord.student.user',
            'pointRecord.teacher.user',
            'pointRecord.semester',
            'pointRecord.enrollment.classroom',
            'pointRecord.latestRevision.pointTypeVersion',
            'pointRecord.latestRevision.attachment',
            'pointRecord.revisions',
        ])->findOrFail($this->reviewId);
    }
}
