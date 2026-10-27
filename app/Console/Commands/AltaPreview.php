<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Import\Drivers\Alta\AltaClient;
use App\Services\Import\Drivers\Alta\AltaDriver;
use App\Services\Import\Drivers\Alta\AltaUnavailable;
use Illuminate\Console\Command;
use JsonException;

class AltaPreview extends Command
{
    protected $signature = 'alta:preview {item : Exact B2B item code} {--supplier=alta} {--from= : Private directory containing item-code.json exports}';

    protected $description = 'Preview matched Alta retail price, content and B2B quantity without writing products or queuing jobs';

    public function handle(): int
    {
        $supplier = Supplier::where('code', $this->option('supplier'))->firstOrFail();
        $config = array_replace((array) config('services.alta', []), $supplier->config ?? []);

        if ($this->option('from')) {
            $config['retail_directory'] = $this->option('from');
        }

        try {
            $driver = new AltaDriver($supplier, site: new AltaClient($config));
            $payload = $driver->fetch((string) $this->argument('item'));

            if (! $payload) {
                $this->error('No complete exact-match retail product was found. Nothing was changed.');

                return self::FAILURE;
            }

            $locale = $supplier->config['locale'] ?? 'ka';
            $this->line(json_encode([
                'read_only' => true,
                'item' => $payload->externalId,
                'sku' => $payload->sku,
                'name' => $payload->translations[$locale]['name'] ?? null,
                'retail_price' => $payload->costPrice,
                'previous_retail_price' => $payload->oldCostPrice,
                'iapi_price' => $supplier->markup($payload->costPrice),
                'quantity' => $payload->stock,
                'images' => count($payload->images),
                'specifications' => count($payload->specs),
                'description_characters' => mb_strlen(strip_tags($payload->translations[$locale]['description'] ?? '')),
                'brand' => $payload->brandName,
                'category' => $payload->categoryName,
                'retail_source' => ! empty($config['retail_directory']) ? 'private_export' : 'alta_api',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (AltaUnavailable|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
