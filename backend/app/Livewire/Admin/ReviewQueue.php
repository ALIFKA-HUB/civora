<?php

namespace App\Livewire\Admin;

use App\Models\ReviewRequest;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class ReviewQueue extends Component
{
    use WithPagination;

    public string $search = '';

    public string $kindFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedKindFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $reviews = ReviewRequest::query()
            ->with(['requester', 'pointRecord.student.user', 'pointRecord.teacher.user', 'pointRecord.latestRevision'])
            ->where('status', 'pending')
            ->when($this->kindFilter !== '', fn (Builder $query) => $query->where('kind', $this->kindFilter))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $query->where(function (Builder $matches) use ($term): void {
                    $matches->whereHas('requester', fn (Builder $user) => $user->whereRaw('lower(name) like ?', [$term]))
                        ->orWhereHas('pointRecord.student.user', fn (Builder $user) => $user->whereRaw('lower(name) like ?', [$term]))
                        ->orWhereHas('pointRecord.teacher.user', fn (Builder $user) => $user->whereRaw('lower(name) like ?', [$term]))
                        ->orWhereHas('pointRecord.latestRevision', fn (Builder $revision) => $revision->whereRaw('lower(description) like ?', [$term]));
                });
            })
            ->orderBy('created_at')
            ->paginate(15);

        return view('livewire.admin.review-queue', compact('reviews'), ['title' => 'Pemeriksaan']);
    }
}
