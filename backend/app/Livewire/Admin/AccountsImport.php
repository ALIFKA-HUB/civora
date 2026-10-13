<?php

namespace App\Livewire\Admin;

use App\Actions\Imports\CommitAccountsImport;
use App\Actions\Imports\PreviewAccountsImport;
use App\Livewire\Admin\Concerns\RequiresRecentAdminConfirmation;
use App\Models\ImportBatch;
use App\Models\Semester;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.admin')]
class AccountsImport extends Component
{
    use RequiresRecentAdminConfirmation, WithFileUploads;

    public string $entityType = 'student';

    public string $semesterId = '';

    public ?UploadedFile $file = null;

    public ?string $batchId = null;

    public string $message = '';

    public function preview(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate([
            'entityType' => ['required', Rule::in(['student', 'teacher'])],
            'semesterId' => [Rule::requiredIf($this->entityType === 'student'), 'nullable', 'uuid', Rule::exists('semesters', 'id')],
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
        ]);
        $semester = $data['semesterId'] ? Semester::findOrFail($data['semesterId']) : null;
        $batch = app(PreviewAccountsImport::class)->execute(auth('web')->user(), $this->file, $data['entityType'], $semester);
        $this->batchId = $batch->id;
        $this->file = null;
        $this->message = 'Pratinjau disimpan secara privat. Tinjau kesalahan sebelum konfirmasi.';
    }

    public function confirm(): void
    {
        $this->requireRecentAdminConfirmation();
        $data = $this->validate(['batchId' => ['required', 'uuid', Rule::exists('import_batches', 'id')]]);
        $batch = ImportBatch::findOrFail($data['batchId']);
        $batch = app(CommitAccountsImport::class)->execute(auth('web')->user(), $batch);
        $this->message = $batch->status === 'confirmed'
            ? 'Import selesai. '.$batch->row_count.' akun baru dibuat.'
            : 'Import tidak diterapkan. Periksa kesalahan pada setiap baris.';
    }

    public function render()
    {
        $batch = $this->batchId ? ImportBatch::where('admin_id', auth('web')->id())->find($this->batchId) : null;
        $semesters = Semester::query()->with('academicYear')->whereIn('status', ['draft', 'active'])->orderByDesc('start_date')->get();

        return view('livewire.admin.accounts-import', compact('batch', 'semesters'), ['title' => 'Import akun']);
    }
}
