<?php

declare(strict_types=1);

namespace App\Support\SpecialOrders;

use Illuminate\Database\Eloquent\Builder;

/** Mirrors `resources/js/lib/specialOrderWorkflow.ts` resolution order. */
final class SpecialOrderWorkflowStatus
{
    public const PENDING_QUOTE = 'pending_quote';

    public const QUOTED = 'quoted';

    public const PRICED = 'priced';

    public const CONSIDERING = 'considering';

    public const OFFER_LOCKED = 'offer_locked';

    public const DEPOSIT_IN = 'deposit_in';

    public const ORDERED = 'ordered';

    public const RECEIVED = 'received';

    public const BALANCE_PAID = 'balance_paid';

    public const DONE = 'done';

    public const REJECTED = 'rejected';

    /** @var array<int, string> */
    public const ALL_VALUES = [
        self::PENDING_QUOTE,
        self::QUOTED,
        self::PRICED,
        self::CONSIDERING,
        self::OFFER_LOCKED,
        self::DEPOSIT_IN,
        self::ORDERED,
        self::RECEIVED,
        self::BALANCE_PAID,
        self::DONE,
        self::REJECTED,
    ];

    /** Default list view: active pipeline (excludes thinking + rejected). */
    /** @var array<int, string> */
    public const DEFAULT_LIST_VISIBLE = [
        self::PENDING_QUOTE,
        self::QUOTED,
        self::PRICED,
        self::OFFER_LOCKED,
        self::DEPOSIT_IN,
        self::ORDERED,
        self::RECEIVED,
        self::BALANCE_PAID,
        self::DONE,
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL_VALUES, true);
    }

    /**
     * @param  array<int, string>  $statuses
     */
    public static function applyFilter(Builder $query, array $statuses): void
    {
        $normalized = [];
        foreach ($statuses as $status) {
            $trimmed = trim((string) $status);
            if ($trimmed !== '' && self::isValid($trimmed)) {
                $normalized[] = $trimmed;
            }
        }

        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            return;
        }

        $query->where(function (Builder $outer) use ($normalized): void {
            foreach ($normalized as $status) {
                $outer->orWhere(function (Builder $inner) use ($status): void {
                    self::scopeStatus($inner, $status);
                });
            }
        });
    }

    private static function scopeStatus(Builder $query, string $status): void
    {
        match ($status) {
            self::REJECTED => $query->whereNotNull('rejected_at'),
            self::DONE => self::scopeBeforeBalancePaid($query)
                ->whereNotNull('balance_received_at')
                ->whereNotNull('product_received_at'),
            self::BALANCE_PAID => self::scopeBeforeBalancePaid($query)
                ->whereNotNull('balance_received_at')
                ->whereNull('product_received_at'),
            self::RECEIVED => self::scopeBeforeReceived($query)->whereNotNull('product_received_at'),
            self::ORDERED => self::scopeBeforeOrdered($query)->whereNotNull('merchandiser_ordered_at'),
            self::DEPOSIT_IN => self::scopeBeforeDepositIn($query)->whereNotNull('deposit_received_at'),
            self::CONSIDERING => self::scopeBeforeConsidering($query)->whereNotNull('customer_considering_at'),
            self::OFFER_LOCKED => self::scopeBeforeOfferLocked($query)->whereNotNull('customer_offer_locked_at'),
            self::PRICED => self::scopeBeforePriced($query)->where(function (Builder $q): void {
                self::applyPricedConditions($q);
            }),
            self::QUOTED => self::scopeBeforeQuoted($query)->where(function (Builder $q): void {
                self::applyQuotedConditions($q);
            }),
            default => self::scopeBeforeQuoted($query)->where(function (Builder $q): void {
                $q->whereNull('product_cost_amount')
                    ->orWhereNull('shipping_cost_amount')
                    ->orWhereNull('landed_cost_cad')
                    ->orWhereNull('receive_delay_days');
            }),
        };
    }

    private static function scopeBeforeBalancePaid(Builder $query): Builder
    {
        return $query->whereNull('rejected_at');
    }

    private static function scopeBeforeReceived(Builder $query): Builder
    {
        return self::scopeBeforeBalancePaid($query)->whereNull('balance_received_at');
    }

    private static function scopeBeforeOrdered(Builder $query): Builder
    {
        return self::scopeBeforeReceived($query)->whereNull('product_received_at');
    }

    private static function scopeBeforeDepositIn(Builder $query): Builder
    {
        return self::scopeBeforeOrdered($query)->whereNull('merchandiser_ordered_at');
    }

    private static function scopeBeforeConsidering(Builder $query): Builder
    {
        return self::scopeBeforeDepositIn($query)->whereNull('deposit_received_at');
    }

    private static function scopeBeforeOfferLocked(Builder $query): Builder
    {
        return self::scopeBeforeConsidering($query)->whereNull('customer_considering_at');
    }

    private static function scopeBeforePriced(Builder $query): Builder
    {
        return self::scopeBeforeOfferLocked($query)->whereNull('customer_offer_locked_at');
    }

    private static function scopeBeforeQuoted(Builder $query): Builder
    {
        return self::scopeBeforePriced($query)->where(function (Builder $q): void {
            $q->whereNull('customer_price_cad')
                ->orWhere(function (Builder $inner): void {
                    $inner->whereNull('deposit_percent')->whereNull('deposit_amount_override_cad');
                });
        });
    }

    private static function applyQuotedConditions(Builder $query): void
    {
        $query->whereNotNull('product_cost_amount')
            ->whereNotNull('shipping_cost_amount')
            ->whereNotNull('landed_cost_cad')
            ->whereNotNull('receive_delay_days');
    }

    private static function applyPricedConditions(Builder $query): void
    {
        $query->whereNotNull('customer_price_cad')
            ->where(function (Builder $inner): void {
                $inner->whereNotNull('deposit_percent')
                    ->orWhereNotNull('deposit_amount_override_cad');
            });
    }
}
