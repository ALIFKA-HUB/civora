<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\SchoolSetting;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class AuditLogViewer extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $logs = AuditLog::query()->with('actor')
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $query->where(function ($matches) use ($term): void {
                    $matches->whereRaw('lower(action) like ?', [$term])
                        ->orWhereRaw('lower(resource_type) like ?', [$term])
                        ->orWhereHas('actor', fn ($actor) => $actor->whereRaw('lower(name) like ?', [$term]));
                });
            })->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
        $schoolTimezone = SchoolSetting::query()->value('timezone') ?? config('app.school_timezone', 'Asia/Jakarta');
        $entries = $logs->through(fn (AuditLog $log): array => [
            'id' => $log->id,
            'time' => $log->created_at?->timezone($schoolTimezone)->format('d M Y H:i'),
            'actor' => $log->actor?->name ?? 'Sistem',
            'action' => $log->action,
            'resource' => $log->resource_type,
            'resource_id' => $log->resource_id,
            'reason' => $this->sanitize($log->reason),
            'before' => $this->sanitize($log->safe_before ?? []),
            'after' => $this->sanitize($log->safe_after ?? []),
        ]);

        return view('livewire.admin.audit-log-viewer', compact('entries'), ['title' => 'Audit']);
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if (preg_match('/password|secret|token|credential|qr|authorization|private_path|file_path|api[_-]?key/i', (string) $key)) {
                    continue;
                }
                $safe[$key] = $this->sanitize($item);
            }

            return $safe;
        }
        if (is_string($value)) {
            $value = preg_replace('/\b(?:Bearer\s+)?[A-Za-z0-9_\-]{32,}\b/i', '[disamarkan]', $value) ?? '';

            return preg_replace('/\b(password|secret|token|credential|qr|api[_-]?key)\s*[:=]\s*\S+/i', '$1=[disamarkan]', $value) ?? '';
        }

        return is_scalar($value) || $value === null ? $value : '[disamarkan]';
    }
}
