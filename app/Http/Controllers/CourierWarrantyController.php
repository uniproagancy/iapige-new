<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WarrantyCertificate;
use App\Services\Warranties\WarrantyCourierAccess;
use App\Services\Warranties\WarrantyCertificateFiles;
use App\Services\Warranties\WarrantyPdf;
use App\Services\Warranties\WarrantySms;
use App\Services\Warranties\WarrantyCourierSerial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CourierWarrantyController extends Controller
{
    public function __construct(private WarrantyCourierAccess $access) {}

    public function index(Request $request, int $delivery): Response
    {
        $actor = $this->actor($request);
        $assigned = $this->access->delivery($actor, $delivery);
        $certificates = $this->access->certificates($assigned)->with('deliveryItem')->orderBy('order_delivery_item_id')->orderBy('unit_index')->get();
        $keys = [];
        $attempts = [];
        foreach ($certificates as $certificate) {
            $attempt = \App\Models\WarrantySmsMessage::where('warranty_certificate_id', $certificate->id)->latest('id')->first();
            $keyPath = 'warranty_courier_sms_keys.'.$certificate->id.'.'.($attempt?->id ?? 'first');
            $key = $request->session()->get($keyPath);
            if (! is_string($key) || ! preg_match('/\A[a-zA-Z0-9_-]{16,100}\z/D', $key)
                || ($attempt?->status === 'rejected' && $attempt->idempotency_key === $key)) {
                $key = (string) Str::uuid();
                $request->session()->put($keyPath, $key);
            }
            $keys[$certificate->id] = $key;
            $attempts[$certificate->id] = ['latest' => $attempt, 'state' => app(WarrantySms::class)->resendState($attempt)];
        }

        $releasedDrafts = $this->access->releasedDrafts($assigned)->with('deliveryItem')
            ->orderBy('order_delivery_item_id')->orderBy('unit_index')->get();
        $releaseStates = $releasedDrafts->mapWithKeys(fn ($certificate) => [
            $certificate->id => app(WarrantyCourierSerial::class)->releaseState($certificate),
        ])->all();

        return response()->view('warranty.courier', [
            'releasedDrafts' => $releasedDrafts, 'releaseStates' => $releaseStates,
            'delivery' => $assigned, 'certificates' => $certificates, 'keys' => $keys, 'attempts' => $attempts,
            'sendingEnabled' => (bool) config('warranty.sms_enabled') && (bool) config('warranty.public_enabled'),
        ])->withHeaders($this->headers());
    }

    public function sms(Request $request, int $delivery, int $certificate, WarrantySms $sms): RedirectResponse
    {
        $actor = $this->actor($request);
        $assigned = $this->access->delivery($actor, $delivery);
        $current = $this->access->certificates($assigned)->whereKey($certificate)->firstOrFail();
        $input = $request->validate([
            'idempotency_key' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_-]{16,100}\z/D'],
            'previous_attempt_id' => ['nullable', 'integer', 'min:1'],
        ]);
        // No recipient, message, policy or certificate changes are accepted from a courier.
        $message = isset($input['previous_attempt_id'])
            ? $sms->resendForCourier($current, $actor, $delivery, $input['idempotency_key'], (int) $input['previous_attempt_id'])
            : $sms->sendForCourier($current, $actor, $delivery, $input['idempotency_key']);

        return redirect()->route('courier.warranties.index', ['delivery' => $delivery])
            ->with('status', __('warranty.sms_result.'.$message->status))->withHeaders($this->headers());
    }

    public function serial(Request $request, int $delivery, int $certificate, WarrantyCourierSerial $serial): RedirectResponse
    {
        $actor = $this->actor($request);
        $assigned = $this->access->delivery($actor, $delivery);
        // Includes the just-issued result so an identical submitted form can safely be replayed.
        $current = WarrantyCertificate::query()->whereKey($certificate)->where('order_id', $assigned->order_id)
            ->whereHas('deliveryItem', fn (Builder $item) => $item->where('order_delivery_id', $assigned->id)
                ->where('is_active', true))->firstOrFail();
        $input = $request->validate([
            'serial_number' => ['required', 'string', 'max:191', 'regex:/\A[^\x00-\x1f\x7f]+\z/u'],
            'release_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
        ]);
        // No supplier, policy, period, customer, payment or order fields reach the service.
        $serial->finalize($current, $actor, $delivery, $input['serial_number'], $input['release_hash']);

        return redirect()->route('courier.warranties.index', ['delivery' => $delivery])
            ->with('status', __('warranty.courier.serial_saved'))->withHeaders($this->headers());
    }

    public function print(Request $request, int $delivery, int $certificate, WarrantyCertificateFiles $files, WarrantyPdf $pdf): BinaryFileResponse
    {
        $assigned = $this->access->delivery($this->actor($request), $delivery);
        $current = $this->access->certificates($assigned)->whereKey($certificate)->firstOrFail();
        // Serve only the frozen issued document. This never issues a draft or creates a public link.
        $response = response()->file($files->path($current), $this->headers() + ['Content-Type' => 'application/pdf']);
        $response->setContentDisposition('inline', $pdf->filename($current));

        return $response->setPrivate();
    }

    private function actor(Request $request): User
    {
        abort_unless(config('warranty.enabled'), 404);
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isCourier(), 403);

        return $actor;
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"];
    }
}
