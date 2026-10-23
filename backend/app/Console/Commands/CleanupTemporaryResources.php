<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\ImportBatch;
use App\Models\ReportExport;
use App\Models\StudentSelection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CleanupTemporaryResources extends Command
{
    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    protected $signature = 'civora:cleanup-temporary-resources';

    protected $description = 'Remove expired private and temporary Civora resources';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now();

        StudentSelection::query()->where('expires_at', '<=', $cutoff)->orderBy('id')->chunkById(100, function ($selections): void {
            foreach ($selections as $selection) {
                DB::transaction(function () use ($selection): void {
                    $locked = StudentSelection::query()->whereKey($selection->id)->lockForUpdate()->first();
                    if (! $locked || $locked->pointRecords()->exists() || $locked->expires_at->isFuture()) {
                        return;
                    }
                    DB::table('qr_redemptions')->where('selection_id', $locked->id)->delete();
                    $locked->delete();
                });
            }
        });
        DB::table('qr_tokens')->where('expires_at', '<=', $cutoff)->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('qr_redemptions')->whereColumn('qr_redemptions.qr_token_id', 'qr_tokens.id');
        })->delete();

        Attachment::query()->where('created_at', '<=', $cutoff->copy()->subHours(24))->orderBy('id')->chunkById(100, function ($attachments) use ($disk): void {
            foreach ($attachments as $attachment) {
                $path = DB::transaction(function () use ($attachment): ?string {
                    $locked = Attachment::query()->whereKey($attachment->id)->lockForUpdate()->first();
                    if (! $locked || $locked->recordRevisions()->exists()
                        || DB::table('review_requests')->where('status', 'pending')
                            ->whereRaw("proposed_change ->> 'attachment_id' = ?", [$locked->id])->exists()) {
                        return null;
                    }
                    if (! in_array($locked->private_path, ['evidence/'.$locked->id.'.jpg', 'evidence/'.$locked->id.'.png'], true)) {
                        return null;
                    }

                    $path = $locked->private_path;
                    $locked->delete();

                    return $path;
                });
                if ($path) {
                    $disk->delete($path);
                }
            }
        });

        ImportBatch::query()->where('created_at', '<=', $cutoff->copy()->subDays(7))->orderBy('id')->chunkById(100, function ($batches) use ($disk): void {
            foreach ($batches as $batch) {
                $path = DB::transaction(function () use ($batch): ?string {
                    $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();
                    if (! $locked || $locked->created_at->greaterThan(now()->subDays(7))) {
                        return null;
                    }
                    $trusted = $this->trustedImportPath($locked->file_path);
                    $path = $trusted ? $locked->file_path : null;
                    $locked->update([
                        'file_path' => $trusted ? '' : $locked->file_path,
                        'parsed_rows' => [],
                        'error_summary' => [],
                    ]);

                    return $path;
                });
                if ($path) {
                    $disk->delete($path);
                }
            }
        });

        ReportExport::query()->where('status', 'completed')->whereNotNull('expires_at')->where('expires_at', '<=', $cutoff)
            ->orderBy('id')->chunkById(100, function ($exports) use ($disk): void {
                foreach ($exports as $export) {
                    $path = DB::transaction(function () use ($export): ?string {
                        $locked = ReportExport::query()->whereKey($export->id)->lockForUpdate()->first();
                        if (! $locked || $locked->status !== 'completed' || ! $locked->expires_at?->lessThanOrEqualTo(now())) {
                            return null;
                        }
                        $path = $this->trustedReportPath($locked->id, $locked->file_path) ? $locked->file_path : null;
                        if ($path) {
                            $locked->update(['file_path' => null]);
                        }

                        return $path;
                    });
                    if ($path) {
                        $disk->delete($path);
                    }
                }
            });

        foreach ($disk->allFiles('reports') as $path) {
            if (! $this->trustedReportOrphanPath($path) || DB::table('report_exports')->where('file_path', $path)->exists()
                || $this->belongsToLiveReportClaim($path)) {
                continue;
            }
            try {
                if ($disk->lastModified($path) <= $cutoff->copy()->subDays(7)->timestamp) {
                    $disk->delete($path);
                }
            } catch (Throwable) {
                // Keep the database claim authoritative if the private disk is unavailable.
            }
        }

        return self::SUCCESS;
    }

    private function trustedImportPath(string $path): bool
    {
        return preg_match('/\Aimports\/'.self::UUID_PATTERN.'\.(?:csv|xlsx)\z/i', $path) === 1;
    }

    private function trustedReportPath(string $exportId, ?string $path): bool
    {
        return $path !== null && preg_match('/\Areports\/'.preg_quote($exportId, '/').'\/'.self::UUID_PATTERN.'\.(?:pdf|xlsx)\z/i', $path) === 1;
    }

    private function trustedReportOrphanPath(string $path): bool
    {
        return preg_match('/\Areports\/'.self::UUID_PATTERN.'\/(?:\.'.self::UUID_PATTERN.'\.tmp\.(?:pdf|xlsx)|'.self::UUID_PATTERN.'\.(?:pdf|xlsx))\z/i', $path) === 1;
    }

    private function belongsToLiveReportClaim(string $path): bool
    {
        if (! preg_match('/\Areports\/('.self::UUID_PATTERN.')\/(?:\.('.self::UUID_PATTERN.')\.tmp|('.self::UUID_PATTERN.'))\.(?:pdf|xlsx)\z/i', $path, $matches)) {
            return false;
        }
        $token = $matches[2] !== '' ? $matches[2] : $matches[3];

        return ReportExport::query()->whereKey($matches[1])->where('status', 'processing')
            ->where('generation_token', $token)->where('generation_lease_until', '>', now())->exists();
    }
}
