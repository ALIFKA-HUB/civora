<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids;

    protected $table = 'users';

    protected $fillable = ['role', 'name', 'password_hash', 'account_status', 'must_change_password'];

    protected $hidden = ['password_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'must_change_password' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'password_hash' => 'hashed',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function loginIdentifiers(): HasMany
    {
        return $this->hasMany(LoginIdentifier::class, 'user_id');
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class, 'user_id');
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class, 'user_id');
    }

    public function temporaryCredentials(): HasMany
    {
        return $this->hasMany(TemporaryCredential::class, 'user_id');
    }

    public function generatedTemporaryCredentials(): HasMany
    {
        return $this->hasMany(TemporaryCredential::class, 'generated_by');
    }

    public function createdPointTypeVersions(): HasMany
    {
        return $this->hasMany(PointTypeVersion::class, 'created_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'owner_user_id');
    }

    public function changedRecordRevisions(): HasMany
    {
        return $this->hasMany(RecordRevision::class, 'changed_by');
    }

    public function requestedReviews(): HasMany
    {
        return $this->hasMany(ReviewRequest::class, 'requester_user_id');
    }

    public function decidedReviews(): HasMany
    {
        return $this->hasMany(ReviewRequest::class, 'decided_by');
    }

    public function createdThresholdPolicies(): HasMany
    {
        return $this->hasMany(ThresholdPolicy::class, 'created_by');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'performed_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id');
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class, 'user_id');
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'admin_id');
    }

    public function reportExports(): HasMany
    {
        return $this->hasMany(ReportExport::class, 'admin_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }
}
