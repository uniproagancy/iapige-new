<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every destructive button was dead, and nothing said so.
 *
 * They carry a confirmation, so they had no wire:click: a handler of ours read
 * data-confirm, showed a dialog, and called the action through $wire. It called
 * it as $wire.call(name) — and Livewire 4's proxy has no "call", so the name was
 * read as a server method, the component was asked to run one called "call",
 * there is none, and the request was refused without a word. Deleting a product,
 * deleting a batch of them, marking an order paid, closing an account: all of it
 * went that way, and every one of them still showed its dialog first, so they
 * looked like they were working right up to the moment nothing happened.
 *
 * They now use Livewire's own wire:confirm, which needs no code of ours at all.
 * This reads the templates to make sure none of them drifts back, because the
 * failure is silent and a browser test would be the only other way to catch it.
 */
class ConfirmButtonsTest extends TestCase
{
    /** Every view that had one of these buttons. */
    public static function views(): array
    {
        return [
            'products' => ['livewire/admin/products/index.blade.php'],
            'product form' => ['livewire/admin/products/form.blade.php'],
            'brands' => ['livewire/admin/brands/index.blade.php'],
            'attributes' => ['livewire/admin/attributes/index.blade.php'],
            'mapping' => ['livewire/admin/mapping/index.blade.php'],
            'callbacks' => ['livewire/admin/callbacks/index.blade.php'],
            'order' => ['livewire/admin/orders/show.blade.php'],
            'user' => ['livewire/admin/users/show.blade.php'],
            'account' => ['livewire/account/index.blade.php'],
        ];
    }

    /**
     * The old bridge is gone from the templates.
     */
    #[DataProvider('views')]
    public function test_no_view_still_uses_the_old_bridge(string $view): void
    {
        $this->assertStringNotContainsString(
            'data-confirm',
            $this->read($view),
            "{$view} still asks a handler of ours to call the action.",
        );
    }

    /**
     * A confirmation always sits on a button that calls something.
     *
     * wire:confirm on its own confirms nothing — it only gates the wire:click
     * beside it, so one without the other is a button that asks a question and
     * then does nothing with the answer.
     */
    #[DataProvider('views')]
    public function test_every_confirmation_guards_a_real_action(string $view): void
    {
        $source = $this->read($view);

        /*
         * Blade's own expressions are taken out before the tags are read.
         *
         * Every one of these buttons calls an action with a model's id, which
         * in Blade is written $product->id — and a regex is first-match, not
         * longest-match, so a pattern for "up to the next >" ends happily at
         * the > of that arrow and never reaches the attribute this came to
         * read. It reported the buttons as having lost their confirmation
         * while the markup was perfectly fine.
         */
        $source = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', 'x', $source);

        preg_match_all('/<(?:button|a)\b[^>]*>/s', $source, $tags);

        $guarded = 0;

        foreach ($tags[0] as $tag) {
            if (! str_contains($tag, 'wire:confirm')) {
                continue;
            }

            $guarded++;

            $this->assertStringContainsString(
                'wire:click',
                $tag,
                "A wire:confirm in {$view} sits on something that calls nothing.",
            );
        }

        $this->assertGreaterThan(0, $guarded, "{$view} has lost its confirmations altogether.");
    }

    /** The two the shop would most regret losing. */
    public function test_the_product_delete_buttons_are_wired(): void
    {
        $source = $this->read('livewire/admin/products/index.blade.php');

        $this->assertStringContainsString('wire:click="delete({{ $product->id }})"', $source);
        $this->assertStringContainsString('wire:click="bulkDelete"', $source);
    }

    /** And the bundles no longer carry a handler that would swallow the click. */
    public function test_the_front_end_no_longer_bridges_confirmations(): void
    {
        foreach (['resources/dashboard/admin.js', 'resources/js/app.js'] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertStringNotContainsString('confirmAction', $source, "{$path} still bridges confirmations.");
            $this->assertStringNotContainsString('.call(action', $source, "{$path} still calls an action the old way.");
        }
    }

    protected function read(string $view): string
    {
        $path = resource_path('views/'.$view);

        $this->assertFileExists($path);

        return file_get_contents($path);
    }
}
