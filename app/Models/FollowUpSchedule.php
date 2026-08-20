<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpSchedule extends Model
{
    use HasFactory;

    /**
     * Status constants — gunakan ini di seluruh codebase agar konsisten
     * dan tidak ada typo pada string status.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_BLOCKED = 'blocked_incomplete_profile';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * Type constants — jenis follow-up yang dijadwalkan.
     */
    public const TYPE_H_PLUS_3 = 'h_plus_3';
    public const TYPE_H_PLUS_330 = 'h_plus_330';

    protected $fillable = [
        'customer_id',
        'transaction_id',
        'type',
        'scheduled_date',
        'status',
        'sent_at',
        'notes',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'sent_at'        => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────────

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    // ─── Query Scopes ───────────────────────────────────────────────

    /**
     * Scope: jadwal yang jatuh tempo dan siap kirim.
     * Digunakan oleh command crm:send-followups.
     */
    public function scopeDueToday($query)
    {
        return $query->where('status', self::STATUS_PENDING)
                     ->where('scheduled_date', '<=', now()->toDateString());
    }

    /**
     * Scope: jadwal yang terblokir karena profil belum lengkap.
     * Digunakan saat admin melengkapi profil customer.
     */
    public function scopeBlockedForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId)
                     ->where('status', self::STATUS_BLOCKED);
    }
}
