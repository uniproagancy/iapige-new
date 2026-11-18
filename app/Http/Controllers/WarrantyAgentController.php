<?php

namespace App\Http\Controllers;

use App\Models\OrderDeliveryItem;
use App\Models\WarrantyCertificate;
use App\Models\WarrantyPolicy;
use App\Services\Warranties\WarrantyCertificateManager;
use App\Services\Warranties\WarrantyEventRecorder;
use App\Services\Warranties\WarrantySms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarrantyAgentController extends Controller
{
    public function policies(): JsonResponse
    {
        return response()->json(['policies' => WarrantyPolicy::query()->where('status', 'approved')
            ->orderBy('id')->paginate(50, ['id', 'name', 'version', 'supplier_id', 'product_id', 'brand_id', 'category_id', 'term_value', 'term_unit'])]);
    }

    public function show(WarrantyCertificate $certificate): JsonResponse
    {
        return response()->json($this->summary($certificate));
    }

    public function prepare(Request $request, WarrantyCertificateManager $manager): JsonResponse
    {
        $data = $request->validate([
            'delivery_item_id' => ['required', 'integer', 'exists:order_delivery_items,id'],
            'unit_index' => ['required', 'integer', 'min:1'],
        ]);
        $certificate = $manager->prepare(OrderDeliveryItem::findOrFail($data['delivery_item_id']),
            $data['unit_index'], $request->user());
        $this->record($request, 'agent_prepared', $certificate);

        return response()->json($this->summary($certificate));
    }

    public function update(Request $request, WarrantyCertificate $certificate, WarrantyCertificateManager $manager): JsonResponse
    {
        $data = $request->validate([
            'serial_number' => ['nullable', 'string', 'max:190'],
            'not_serialized_reason' => ['nullable', 'string', 'max:1000'],
            'sale_date' => ['nullable', 'date_format:Y-m-d'],
            'sale_date_override_reason' => ['nullable', 'string', 'max:2000'],
            'supplier_native_sku' => ['nullable', 'string', 'max:191'],
            'supplier_native_sku_reference' => ['nullable', 'string', 'max:2000'],
            'supplier_native_sku_reason' => ['nullable', 'string', 'max:2000'],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'manufacturer_date' => ['nullable', 'date_format:Y-m-d'],
            'actual_supplier_confirmed' => ['sometimes', 'boolean'],
            'delivery_date_confirmed' => ['sometimes', 'boolean'],
            'delivery_date_reason' => ['nullable', 'string', 'max:2000'],
            'manufacturer_date_proof' => ['nullable', 'string', 'max:2000'],
            'manual_term_value' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'manual_term_unit' => ['nullable', 'in:days,months,years'],
        ]);
        // Technical agents keep the documented-code contract under the manager's current-source row locks.
        $certificate = $manager->saveDraft($certificate, $data, $request->user(), true);
        $this->record($request, 'agent_updated', $certificate);

        return response()->json($this->summary($certificate));
    }

    public function issue(Request $request, WarrantyCertificate $certificate, WarrantyCertificateManager $manager): JsonResponse
    {
        $certificate = $manager->issue($certificate, $request->user());
        $this->record($request, 'agent_issued', $certificate);

        return response()->json($this->summary($certificate));
    }

    public function send(Request $request, WarrantyCertificate $certificate, WarrantySms $sms): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/\A[A-Za-z0-9_-]{16,100}\z/D', $key), 422);
        $message = $sms->send($certificate, $request->user(), $key);
        $this->record($request, 'agent_sms_requested', $certificate);

        return response()->json(['certificate_id' => $certificate->id, 'message_id' => $message->id,
            'status' => $message->status, 'error_code' => $message->error_code]);
    }

    private function summary(WarrantyCertificate $certificate): array
    {
        // This API deliberately omits customers, phones, source files and public bearer URLs.
        return ['id' => $certificate->id, 'number' => $certificate->number,
            'revision' => $certificate->revision, 'status' => $certificate->status,
            'delivery_item_id' => $certificate->order_delivery_item_id, 'unit_index' => $certificate->unit_index,
            'policy_id' => $certificate->policy_id, 'issued_at' => $certificate->issued_at?->toIso8601String()];
    }

    private function record(Request $request, string $type, WarrantyCertificate $certificate): void
    {
        app(WarrantyEventRecorder::class)->record($type, $request->user(), $certificate, null,
            ['reference' => 'agent-key-'.$request->attributes->get('warranty_agent_key')->id]);
    }
}
