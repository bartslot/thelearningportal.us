<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NarrationCreditReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One movement of narration credit. A teacher's balance is the SUM of their rows.
 *
 * Append-only, and enforced here rather than left as a convention: a refund is a new negative row,
 * never an edited or deleted purchase. Once a row exists, what it says happened, happened.
 *
 * Read balances through App\Support\NarrationBudget, not from here. That class is the single seam
 * the rest of the app asks "how much may this teacher spend".
 *
 * @property int $characters signed: + bought, - spent
 */
class NarrationCredit extends Model
{
    /** No updated_at: rows are written once and never touched again. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'teacher_id',
        'characters',
        'reason',
        'lesson_id',
        'batch_id',
        'expires_at',
        'amount_net_cents',
        'amount_vat_cents',
        'amount_gross_cents',
        'currency',
        'stripe_event_id',
        'stripe_payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'characters' => 'integer',
            'amount_net_cents' => 'integer',
            'amount_vat_cents' => 'integer',
            'amount_gross_cents' => 'integer',
            'reason' => NarrationCreditReason::class,
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A ledger that can be rewritten is not a ledger. Both of these are bugs, not situations.
        static::updating(function (): void {
            throw new LogicException('The narration credit ledger is append-only: write a correcting row instead of editing one.');
        });
        static::deleting(function (): void {
            throw new LogicException('The narration credit ledger is append-only: write a negative row instead of deleting one.');
        });
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** The purchase these characters came out of. Null on a purchase, which IS the batch. */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'batch_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeForTeacher(Builder $query, int $teacherId): Builder
    {
        return $query->where('teacher_id', $teacherId);
    }

    /** Purchases only: the batches a balance is made of. */
    public function scopePurchases(Builder $query): Builder
    {
        return $query->where('reason', NarrationCreditReason::Purchase);
    }

    /** Purchases whose characters are still spendable. A null expires_at never dies. */
    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /** Has this batch run out of time? Always false while expiry is switched off. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
