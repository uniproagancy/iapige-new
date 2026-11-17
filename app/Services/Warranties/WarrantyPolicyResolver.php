<?php

namespace App\Services\Warranties;

use App\Models\Category;
use App\Models\OrderDeliveryItem;
use App\Models\WarrantyPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WarrantyPolicyResolver
{
    public function resolve(OrderDeliveryItem $item, string $saleDate): ?WarrantyPolicy
    {
        Validator::make(['sale_date' => $saleDate], ['sale_date' => ['required', 'date_format:Y-m-d']])->validate();
        if (! $item->supplier_id) {
            return null;
        }
        $candidates = WarrantyPolicy::where('status', WarrantyPolicy::APPROVED)
            ->where('supplier_id', $item->supplier_id)->orderBy('id')->get()
            ->filter(fn ($policy) => $this->matches($policy, $item, $saleDate));
        if ($candidates->isEmpty()) {
            return null;
        }
        $rank = $candidates->max(fn ($policy) => $this->specificity($policy));
        $best = $candidates->filter(fn ($policy) => $this->specificity($policy) === $rank);
        if ($best->count() !== 1) {
            throw ValidationException::withMessages(['policy_id' => __('warranty.errors.policy_ambiguous')]);
        }
        return $best->first();
    }

    public function matches(WarrantyPolicy $policy, OrderDeliveryItem $item, string $saleDate): bool
    {
        $item->loadMissing('supplier');
        // Only the owner-confirmed legacy ID/code pair represents Golden Skytech.
        if ($item->supplier?->code === 'citrus'
            && ! WarrantySupplierIdentity::isSkytech((int) $item->supplier_id, $item->supplier?->code)) {
            return false;
        }
        if ((int) $policy->supplier_id !== (int) $item->supplier_id || ! $item->supplier_id) {
            return false;
        }
        if (($policy->approval_mode || $policy->order_delivery_item_id)
            && ! app(WarrantyApproval::class)->matchesBoundPolicy($policy, $item)) { return false; }
        $date = Carbon::createFromFormat('!Y-m-d', $saleDate);
        if (($policy->valid_from && $date->lt($policy->valid_from)) || ($policy->valid_until && $date->gt($policy->valid_until))) {
            return false;
        }
        if ($policy->product_id && (int) $policy->product_id !== (int) $item->product_id) {
            return false;
        }
        $product = $item->product;
        if ($policy->brand_id && (! $product || (int) $policy->brand_id !== (int) $product->brand_id)) {
            return false;
        }
        if ($policy->category_id) {
            $categoryId = $product?->category_id;
            $seen = [];
            while ($categoryId && ! in_array((int) $categoryId, $seen, true)) {
                $seen[] = (int) $categoryId;
                if ((int) $categoryId === (int) $policy->category_id) {
                    return true;
                }
                if (count($seen) > 100) {
                    break;
                }
                $categoryId = Category::whereKey($categoryId)->value('parent_id');
            }
            return false;
        }
        return true;
    }

    public function specificity(WarrantyPolicy $policy): int
    {
        return $policy->order_delivery_item_id ? 6 : ($policy->product_id ? 5 : ($policy->brand_id && $policy->category_id ? 4 : ($policy->brand_id ? 3 : ($policy->category_id ? 2 : 1))));
    }
}
