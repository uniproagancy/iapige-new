<?php

namespace App\Http\Controllers;

use App\Services\Warranties\WarrantyPdf;
use App\Services\Warranties\WarrantyDealerForm;
use App\Services\Warranties\WarrantyServiceDirectory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** A blank supplier form preview; never creates a certificate or grants a warranty. */
class WarrantyServiceTemplateController extends Controller
{
    public function __invoke(Request $request, string $supplierCode, string $routeKey, WarrantyServiceDirectory $directory, WarrantyPdf $pdf): Response
    {
        abort_unless(config('warranty.enabled'), 404);
        abort_unless($request->user()?->isStaff(), 403);
        $data = $directory->all();
        $suppliers = array_values(array_filter(array_merge($data['suppliers'], $data['unlinked_suppliers'] ?? []),
            fn ($supplier) => is_array($supplier) && ($supplier['code'] ?? null) === $supplierCode && ($supplier['excluded'] ?? true) === false));
        abort_unless(count($suppliers) === 1, 404);
        $supplier = $suppliers[0];
        $routes = array_values(array_filter($supplier['routes'] ?? [],
            fn ($route) => is_array($route) && ($route['key'] ?? null) === $routeKey));
        abort_unless(count($routes) === 1, 404);
        $route = $routes[0];
        $contact = ($route['contact_confirmed'] ?? false) === true;
        $conditions = ($route['conditions_confirmed'] ?? false) === true;
        $snapshot = [
            'schema' => 'iapi.warranty.v1',
            'certificate' => ['number' => 'ნიმუში', 'revision' => 1, 'status' => 'draft', 'issued_at' => null],
            'seller' => config('warranty.seller'),
            'customer' => ['name' => null, 'phone' => null],
            'item' => ['name' => null, 'brand' => null, 'model' => null, 'sku' => null,
                'supplier_id' => $supplier['id'] ?? null, 'supplier_name' => $supplier['name'], 'supplier_sku' => null,
                'supplier_legal_name' => isset($supplier['id'])
                    ? $directory->legalNameForSupplier((int) $supplier['id'], (string) $supplier['code']) : null,
                'quantity' => 1, 'unit_index' => 1, 'serial_number' => null, 'not_serialized_reason' => null, 'order_number' => null],
            'dates' => ['order_date' => null, 'sale_date' => null, 'delivery_date' => null],
            'warranty' => ['policy_id' => null, 'version' => null, 'name' => null, 'policy_approved' => false,
                'reference_terms' => $directory->officialTerms($route),
                'issuer_name' => null, 'issuer_address' => null, 'issuer_contact' => null,
                'term_value' => null, 'term_unit' => null, 'term_text' => null, 'starts_on' => null, 'ends_on' => null,
                'territory' => null, 'coverage' => null, 'component_terms' => null,
                'conditions' => $conditions ? ($route['conditions'] ?? null) : null, 'claim_instructions' => null,
                'service' => ['name' => $contact ? ($route['service_name'] ?? null) : null,
                    'phone' => $contact ? ($route['service_phone'] ?? null) : null,
                    'address' => $contact ? ($route['service_address'] ?? null) : null,
                    'email' => $contact ? ($route['service_email'] ?? null) : null, 'scope' => null],
                'source_url' => null, 'source_reference' => null, 'original_requirement' => null],
            'original_forms' => [],
            'legal' => collect(['rights_text', 'seller_responsibility_text', 'identity_text', 'mark_notice'])
                ->mapWithKeys(fn ($key) => [$key => __('warranty.legal.'.$key, [], 'ka')])->all(),
        ];

        if (($supplier['id'] ?? null) === 2 && $supplier['code'] === 'alta') {
            $snapshot['warranty']['paper_signatures_required'] = true;
        }
        $dealerForm = app(WarrantyDealerForm::class)->forSupplier((int) ($supplier['id'] ?? 0), (string) ($supplier['code'] ?? ''));
        if ($dealerForm !== null) {
            $snapshot['supplier_dealer_form'] = $dealerForm;
        }

        return response()->view('warranties.print', $pdf->viewData($snapshot), 200, [
            'Cache-Control' => 'private, no-store, no-transform, max-age=0', 'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
        ]);
    }
}
