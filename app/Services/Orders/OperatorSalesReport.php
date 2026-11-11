<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class OperatorSalesReport
{
    public const COMMISSION_BPS = 200;

    private const COUNT_FIELDS = [
        'orders_count', 'gross_count', 'cancelled_count', 'paid_cancelled_count',
        'net_count', 'unpaid_count', 'inactive_count',
    ];

    private const AMOUNT_FIELDS = [
        'gross_total_cents', 'gross_products_cents',
        'cancelled_total_cents', 'cancelled_products_cents',
        'net_total_cents', 'net_products_cents',
    ];

    /** null selects everyone; zero selects completion events without an actor. */
    public function build(string $from, string $to, ?int $operatorId = null): array
    {
        $operators = $this->summary($from, $to);
        $summary = $operators->filter(fn ($row) => $operatorId === null
            || ($operatorId === 0 ? $row->operator_id === null : $row->operator_id === $operatorId))->values();

        return ['summary' => $summary, 'totals' => $this->totals($summary), 'operators' => $operators];
    }

    /** Amounts are integer cents; cancelled and unpaid orders never earn commission. */
    public function summary(string $from, string $to): Collection
    {
        $conditions = $this->conditions();
        $amounts = $this->amounts();
        $query = $this->baseQuery($from, $to)
            ->select('completion.user_id as operator_id', 'completer.name as operator_name')
            ->selectRaw('COUNT(*) AS orders_count');

        foreach ([
            'gross_count' => $conditions['gross_paid'],
            'cancelled_count' => $conditions['cancelled'],
            'paid_cancelled_count' => $conditions['cancelled_paid'],
            'net_count' => $conditions['net_paid'],
            'unpaid_count' => $conditions['unpaid'],
            'inactive_count' => $conditions['inactive'],
        ] as $field => $condition) {
            $query->selectRaw('SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END) AS '.$field);
        }

        foreach ([
            'gross_total_cents' => [$conditions['gross_paid'], $amounts['total']],
            'gross_products_cents' => [$conditions['gross_paid'], $amounts['products']],
            'cancelled_total_cents' => [$conditions['cancelled_paid'], $amounts['total']],
            'cancelled_products_cents' => [$conditions['cancelled_paid'], $amounts['products']],
            'net_total_cents' => [$conditions['net_paid'], $amounts['total']],
            'net_products_cents' => [$conditions['net_paid'], $amounts['products']],
        ] as $field => [$condition, $amount]) {
            $query->selectRaw('SUM(CASE WHEN '.$condition.' THEN '.$amount.' ELSE 0 END) AS '.$field);
        }

        return $query->groupBy('completion.user_id', 'completer.name')
            ->orderBy('completer.name')->orderBy('completion.user_id')->get()
            ->map(function ($row) {
                // Use plain report rows rather than exposing partially hydrated Order models.
                $result = (object) $row->getAttributes();
                $result->operator_id = $result->operator_id === null ? null : (int) $result->operator_id;
                $result->operator_missing = $result->operator_id !== null && $result->operator_name === null;

                foreach ([...self::COUNT_FIELDS, ...self::AMOUNT_FIELDS] as $field) {
                    $result->{$field} = (int) $result->{$field};
                }

                // An unassigned sale has no operator to receive commission.
                $result->commission_cents = $result->operator_id === null ? 0 : $this->commission($result->net_products_cents);

                return $result;
            });
    }

    public function totals(Collection $summary): array
    {
        $totals = [];
        foreach ([...self::COUNT_FIELDS, ...self::AMOUNT_FIELDS, 'commission_cents'] as $field) {
            $totals[$field] = (int) $summary->sum($field);
        }

        return $totals;
    }

    public function query(string $from, string $to, ?int $operatorId = null): Builder
    {
        $conditions = $this->conditions();
        $amounts = $this->amounts();

        return $this->baseQuery($from, $to)
            ->when($operatorId !== null, fn (Builder $query) => $operatorId === 0
                ? $query->whereNull('completion.user_id')
                : $query->where('completion.user_id', $operatorId))
            ->select('orders.*', 'completion.id as completion_event_id', 'completion.created_at as completed_at',
                'completion.user_id as completed_by_id', 'completer.name as completed_by_name')
            ->selectRaw('CASE WHEN '.$conditions['cancelled'].' THEN 1 ELSE 0 END AS is_cancelled')
            ->selectRaw('CASE WHEN '.$conditions['inactive'].' THEN 1 ELSE 0 END AS is_inactive')
            ->selectRaw($amounts['products'].' AS products_cents')
            ->selectRaw('CASE WHEN '.$conditions['commissionable'].' THEN '.$amounts['products'].' ELSE 0 END AS commissionable_products_cents')
            ->orderByDesc('completion.created_at')->orderByDesc('orders.id');
    }

    protected function baseQuery(string $from, string $to): Builder
    {
        [$start, $end] = $this->period($from, $to);
        $latest = OrderEvent::query()->select('order_id')->selectRaw('MAX(id) AS event_id')
            ->where('type', 'status')->where('to', Order::COMPLETED)->groupBy('order_id');

        // Pick the final completion first, then apply actor/date filters to that exact event.
        return Order::withTrashed()
            ->joinSub($latest, 'last_completion', fn ($join) => $join->on('orders.id', '=', 'last_completion.order_id'))
            ->join('order_events as completion', 'completion.id', '=', 'last_completion.event_id')
            ->leftJoin('users as completer', 'completer.id', '=', 'completion.user_id')
            ->where('completion.created_at', '>=', $start)->where('completion.created_at', '<', $end);
    }

    protected function period(string $from, string $to): array
    {
        Validator::make(['from' => $from, 'to' => $to], [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        $storageTimezone = config('app.timezone', 'UTC');

        return [
            CarbonImmutable::parse($from, 'Asia/Tbilisi')->startOfDay()->setTimezone($storageTimezone)->format('Y-m-d H:i:s'),
            CarbonImmutable::parse($to, 'Asia/Tbilisi')->startOfDay()->addDay()->setTimezone($storageTimezone)->format('Y-m-d H:i:s'),
        ];
    }

    protected function amounts(): array
    {
        $total = 'CAST(ROUND(orders.total * 100, 0) AS SIGNED INTEGER)';
        $shipping = 'CAST(ROUND(orders.shipping * 100, 0) AS SIGNED INTEGER)';
        $difference = '('.$total.' - '.$shipping.')';

        return ['total' => $total, 'products' => '(CASE WHEN '.$difference.' > 0 THEN '.$difference.' ELSE 0 END)'];
    }

    protected function conditions(): array
    {
        $completed = "(orders.deleted_at IS NULL AND orders.status = '".Order::COMPLETED."')";
        $cancelled = "(orders.deleted_at IS NOT NULL OR orders.status = '".Order::CANCELLED."')";
        $inactive = "(orders.deleted_at IS NULL AND orders.status NOT IN ('".Order::COMPLETED."', '".Order::CANCELLED."'))";

        return [
            'gross_paid' => '(orders.is_paid = 1 AND ('.$completed.' OR '.$cancelled.'))',
            'cancelled' => $cancelled,
            'cancelled_paid' => '(orders.is_paid = 1 AND '.$cancelled.')',
            'net_paid' => '(orders.is_paid = 1 AND '.$completed.')',
            'commissionable' => '(completion.user_id IS NOT NULL AND orders.is_paid = 1 AND '.$completed.')',
            'unpaid' => '(orders.is_paid = 0 AND '.$completed.')',
            'inactive' => $inactive,
        ];
    }

    protected function commission(int $cents): int
    {
        // Fixed 2%, rounded once on the operator's period total; no floating-point money.
        return intdiv($cents, 10000) * self::COMMISSION_BPS
            + intdiv(($cents % 10000) * self::COMMISSION_BPS + 5000, 10000);
    }
}
