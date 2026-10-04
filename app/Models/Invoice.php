<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Invoice extends Model
{
    protected $table = 'invoice';

    public $timestamps = true;

    protected $fillable = [
        'slug',
        'username',
        'profile',
        'amount',
        'status',
        'due_date',
        'paid_at',
        'confirmed_at',
        'confirmed_by',
        'payment_proof',
        'bank_to',
        'extended_to',
        'notes',
    ];

    protected $casts = [
        'amount' => 'integer',
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'extended_to' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PENDING = 'pending_confirmation';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if (empty($invoice->slug)) {
                $invoice->slug = self::generateSlug();
            }
        });
    }

    public static function generateSlug(): string
    {
        do {
            $slug = 'INV-' . Str::random(24);
        } while (self::where('slug', $slug)->exists());

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function isPayable(): bool
    {
        return $this->status === self::STATUS_UNPAID;
    }

    public function isAwaitingConfirmation(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function customerStatusBadge(): array
    {
        return match ($this->status) {
            self::STATUS_DRAFT     => ['info', 'Diproses'],
            self::STATUS_UNPAID    => ['warn', 'Belum Bayar'],
            self::STATUS_PENDING   => ['info', 'Menunggu Konfirmasi'],
            self::STATUS_PAID      => ['ok',   'Lunas'],
            self::STATUS_CANCELLED => ['err',  'Dibatalkan'],
            default                => ['info', $this->status],
        };
    }

    public function bankLabel(): ?string
    {
        return $this->bank_to ? strtoupper(str_replace('-', ' / ', $this->bank_to)) : null;
    }
}
