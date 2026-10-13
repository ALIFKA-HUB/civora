<?php

namespace App\Livewire\Admin;

use App\Actions\Catalog\CreatePointType;
use App\Actions\Catalog\DeactivatePointType;
use App\Actions\Catalog\RevisePointType;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\PointType;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class PointTypes extends Component
{
    use RequiresRecentAdminConfirmation;

    public string $search = '';

    public string $categoryFilter = '';

    public string $statusFilter = '';

    public string $code = '';

    public string $category = 'achievement';

    public string $name = '';

    public string $points = '';

    public string $description = '';

    public ?string $targetTypeId = null;

    public string $message = '';

    public function create(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'code' => ['required', 'string', 'max:50', 'unique:point_types,code'],
            'category' => ['required', Rule::in(['achievement', 'violation'])],
            'name' => ['required', 'string', 'max:255'],
            'points' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:1000'],
        ]);

        app(CreatePointType::class)->execute(auth('web')->user(), $data);
        $this->reset('code', 'name', 'points', 'description');
        $this->category = 'achievement';
        $this->message = 'Jenis poin dibuat pada versi katalog 1.';
    }

    public function beginRevision(string $typeId): void
    {
        $type = PointType::with('pointTypeVersions')->findOrFail($typeId);
        $latest = $type->pointTypeVersions->sortByDesc('version')->firstOrFail();
        $this->targetTypeId = $type->id;
        $this->code = $type->code;
        $this->category = $type->category;
        $this->name = $latest->name;
        $this->points = (string) $latest->points;
        $this->description = $latest->description;
        $this->resetValidation();
    }

    public function revise(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'targetTypeId' => ['required', 'uuid', Rule::exists('point_types', 'id')],
            'category' => ['required', Rule::in(['achievement', 'violation'])],
            'name' => ['required', 'string', 'max:255'],
            'points' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:1000'],
        ]);

        $type = PointType::findOrFail($data['targetTypeId']);
        app(RevisePointType::class)->execute(auth('web')->user(), $type, [
            'category' => $data['category'],
            'name' => $data['name'],
            'points' => $data['points'],
            'description' => $data['description'],
        ]);
        $this->reset('targetTypeId', 'code', 'name', 'points', 'description');
        $this->category = 'achievement';
        $this->message = 'Revisi katalog disimpan sebagai versi baru. Nilai catatan lama tetap tersimpan.';
    }

    public function cancelRevision(): void
    {
        $this->reset('targetTypeId', 'code', 'name', 'points', 'description');
        $this->category = 'achievement';
        $this->resetValidation();
    }

    public function deactivate(string $typeId): void
    {
        $this->requireRecentAdminConfirmation();
        $type = PointType::findOrFail($typeId);
        app(DeactivatePointType::class)->execute(auth('web')->user(), $type);
        $this->message = 'Jenis poin dinonaktifkan. Versi dan catatan lama tetap tersedia.';
    }

    public function render()
    {
        $types = PointType::query()
            ->with('pointTypeVersions')
            ->when(trim($this->search) !== '', fn ($query) => $query->where(function ($matches): void {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $matches->whereRaw('lower(code) like ?', [$term])
                    ->orWhereHas('pointTypeVersions', fn ($versions) => $versions->whereRaw('lower(name) like ?', [$term]));
            }))
            ->when($this->categoryFilter !== '', fn ($query) => $query->where('category', $this->categoryFilter))
            ->when($this->statusFilter === 'active', fn ($query) => $query->where('active', true))
            ->when($this->statusFilter === 'inactive', fn ($query) => $query->where('active', false))
            ->orderBy('code')->get();

        return view('livewire.admin.point-types', compact('types'), ['title' => 'Daftar poin']);
    }
}
