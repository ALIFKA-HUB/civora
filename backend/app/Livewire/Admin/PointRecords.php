<?php

namespace App\Livewire\Admin;

use App\Actions\Reviews\ReviseRecord;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\Attachment;
use App\Models\PointRecord;
use App\Models\PointTypeVersion;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class PointRecords extends Component
{
    use RequiresRecentAdminConfirmation, WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $kindFilter = '';

    #[Locked]
    public ?string $targetRecordId = null;

    #[Locked]
    public int $expectedRevision = 0;

    public string $operation = 'revise';

    public string $pointTypeVersionId = '';

    public string $description = '';

    public string $attachmentId = '';

    public string $reason = '';

    public string $message = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedKindFilter(): void
    {
        $this->resetPage();
    }

    public function beginRevision(string $recordId): void
    {
        $record = PointRecord::with('latestRevision')->findOrFail($recordId);
        $this->targetRecordId = $record->id;
        $this->expectedRevision = $record->latestRevision->revision_number;
        $this->operation = 'revise';
        $this->pointTypeVersionId = '';
        $this->description = $record->latestRevision->description;
        $this->attachmentId = (string) ($record->latestRevision->attachment_id ?? '');
        $this->reason = '';
        $this->resetValidation();
    }

    public function cancelRevision(): void
    {
        $this->reset('targetRecordId', 'expectedRevision', 'description', 'pointTypeVersionId', 'attachmentId', 'reason');
        $this->operation = 'revise';
        $this->resetValidation();
    }

    public function revise(): void
    {
        $this->requireRecentAdminConfirmation();
        $values = $this->validate([
            'targetRecordId' => ['required', 'uuid', Rule::exists('point_records', 'id')],
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'operation' => ['required', Rule::in(['cancel', 'revise'])],
            'pointTypeVersionId' => ['nullable', 'uuid', Rule::exists('point_type_versions', 'id')],
            'description' => ['nullable', 'string', 'min:5', 'max:1000'],
            'attachmentId' => ['nullable', 'uuid', Rule::exists('attachments', 'id')],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $changes = $values['operation'] === 'cancel'
            ? ['operation' => 'cancel']
            : array_filter([
                'operation' => 'revise',
                'point_type_version_id' => $values['pointTypeVersionId'] ?: null,
                'description' => $values['description'] ?: null,
                'attachment_id' => $values['attachmentId'] ?: null,
            ], fn ($value): bool => $value !== null);

        try {
            app(ReviseRecord::class)->execute(auth('web')->user(), PointRecord::findOrFail($values['targetRecordId']), $values['reason'], $values['expectedRevision'], $changes);
        } catch (HttpResponseException $exception) {
            $this->addError('revision', 'Catatan berubah. Muat ulang daftar sebelum mengoreksi.');

            return;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());

            return;
        }

        $this->message = $values['operation'] === 'cancel' ? 'Catatan dibatalkan dan pemeriksaan pending diselesaikan.' : 'Revisi catatan disimpan.';
        $this->cancelRevision();
    }

    public function render()
    {
        $records = PointRecord::query()
            ->with(['student.user', 'teacher.user', 'semester', 'enrollment.classroom', 'latestRevision.pointTypeVersion', 'latestRevision.attachment', 'reviewRequests'])
            ->when($this->statusFilter !== '', fn ($query) => $query->whereHas('latestRevision', fn ($revision) => $revision->where('status', $this->statusFilter)))
            ->when($this->kindFilter !== '', fn ($query) => $query->whereHas('latestRevision', fn ($revision) => $revision->where('category_snapshot', $this->kindFilter)))
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $query->where(function ($matches) use ($term): void {
                    $matches->whereHas('student.user', fn ($user) => $user->whereRaw('lower(name) like ?', [$term]))
                        ->orWhereHas('teacher.user', fn ($user) => $user->whereRaw('lower(name) like ?', [$term]))
                        ->orWhereHas('latestRevision', fn ($revision) => $revision->whereRaw('lower(description) like ?', [$term]));
                });
            })
            ->orderByDesc('occurred_at')
            ->paginate(15);
        $target = $this->targetRecordId ? PointRecord::with(['latestRevision.attachment', 'teacher.user'])->find($this->targetRecordId) : null;
        $versions = PointTypeVersion::query()
            ->with('pointType')
            ->whereIn('id', PointTypeVersion::query()
                ->selectRaw('DISTINCT ON (point_type_id) id')
                ->whereHas('pointType', fn ($query) => $query->where('active', true))
                ->orderBy('point_type_id')
                ->orderByDesc('version'))
            ->orderBy('name')->get();
        $attachments = $target ? Attachment::query()->where('owner_user_id', $target->teacher->user_id)->where(fn ($query) => $query->whereDoesntHave('recordRevisions')->orWhereHas('recordRevisions', fn ($revision) => $revision->where('point_record_id', $target->id)))->get() : collect();

        return view('livewire.admin.point-records', compact('records', 'target', 'versions', 'attachments'), ['title' => 'Catatan poin']);
    }
}
