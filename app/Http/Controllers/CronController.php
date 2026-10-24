<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentManager;
use App\Support\Redact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 | For hosting where `schedule:run` cannot be set up: the same sweep the
 | scheduler runs every five minutes, behind an address cron can fetch.
 |
 | The address does nothing at all until CRON_TOKEN is set, so a server that
 | never configured it has no such page to find.
 */
class CronController extends Controller
{
    public function payments(Request $request, PaymentManager $payments): JsonResponse
    {
        $this->guard($request);

        $hours = (int) ($request->integer('hours') ?: config('shop.payment_check_hours', 168));
        $hours = max(1, min($hours, 24 * 90));

        /* Two cron hits landing together would ask the bank the same questions
           twice; the second one simply reports that the first is still going. */
        $lock = Cache::lock('cron:payments', 600);

        if (! $lock->get()) {
            return response()->json([
                'ok' => true,
                'running' => true,
                'message' => 'A sweep is already under way.',
            ]);
        }

        $started = microtime(true);

        try {
            [$checked, $confirmed, $failed] = $this->sweep($payments, $hours);
        } finally {
            $lock->release();
        }

        $took = round(microtime(true) - $started, 1);

        Log::channel('payments')->info('cron sweep finished', [
            'hours' => $hours,
            'checked' => $checked,
            'confirmed' => $confirmed,
            'unanswered' => $failed,
            'seconds' => $took,
        ]);

        return response()->json([
            'ok' => true,
            'hours' => $hours,
            'checked' => $checked,
            'confirmed' => $confirmed,
            'unanswered' => $failed,
            'seconds' => $took,
        ]);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    protected function sweep(PaymentManager $payments, int $hours): array
    {
        $checked = $confirmed = $failed = 0;

        $query = PaymentTransaction::where('status', PaymentTransaction::PENDING)
            ->where('created_at', '>=', now()->subHours($hours))
            ->with('order');

        /* There can be thousands of these, so they are walked, not loaded. */
        foreach ($query->lazyById(200) as $transaction) {
            if (! $payments->has($transaction->driver)) {
                continue;
            }

            $checked++;

            try {
                if ($payments->driver($transaction->driver)->confirm($transaction)) {
                    $payments->settle($transaction, true);
                    $confirmed++;
                }
            } catch (\Throwable $e) {
                $failed++;

                Log::channel('payments')->warning('cron sweep could not ask', [
                    'transaction' => $transaction->id,
                    'driver' => $transaction->driver,
                    'error' => Redact::secrets($e->getMessage()),
                ]);
            }
        }

        return [$checked, $confirmed, $failed];
    }

    protected function guard(Request $request): void
    {
        $expected = (string) config('shop.cron_token');

        if ($expected === '') {
            throw new NotFoundHttpException;
        }

        /* A header is the better way round, since it stays out of the server's
           access log; the query string is there for cron that cannot send one. */
        $given = (string) ($request->bearerToken()
            ?: $request->header('X-Cron-Token')
            ?: $request->query('token', ''));

        if (! hash_equals($expected, $given)) {
            Log::channel('payments')->warning('cron sweep refused', [
                'ip' => $request->ip(),
            ]);

            throw new NotFoundHttpException;
        }
    }
}
