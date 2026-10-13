<?php

namespace Tests\Feature;

use App\Support\XlsxReader;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A spreadsheet's numbers reached the database as it had spelled them.
 *
 * Excel writes a long number as "2.0000000158E+12" and a small fraction as
 * "2.4E-2". Only the whole ones were ever spelled out again, so every reader
 * downstream had to know about exponents on its own — and each learned it
 * separately, or didn't. Stripping the non-digits out of "2.4E-2" leaves
 * "2.42": a hundred times the real 0.024, which went into the supplier's
 * stored row, became the figure struck through on the product, and set the
 * margin against a price nobody was ever charged. Nothing could notice.
 *
 * The spelling out happens once, in the reader, so a cell is a number written
 * the way a person would write it and nothing after it has to care.
 */
class XlsxNumbersTest extends TestCase
{
    /* ------------------------------------------------------------------ fractions */

    public function test_a_small_fraction_is_spelled_out(): void
    {
        $this->assertSame('0.024', $this->spell(2.4E-2));
    }

    /** The noise a spreadsheet leaves behind is quietly repaired. */
    public function test_a_fraction_written_with_noise_comes_back_clean(): void
    {
        $this->assertSame('0.036', $this->spell(3.5999999999999997E-2));
        $this->assertSame('0.096', $this->spell(9.6000000000000002E-2));
    }

    /* ------------------------------------------------------------------ whole numbers */

    /** A barcode written in scientific notation is no longer a barcode. */
    public function test_a_long_whole_number_keeps_every_digit(): void
    {
        $this->assertSame('2000000015800', $this->spell(2.0000000158E+12));
    }

    public function test_a_small_whole_number_carries_no_decimal_point(): void
    {
        $this->assertSame('12', $this->spell(1.2E+1));
    }

    /* ------------------------------------------------------------------ the edges */

    /**
     * Too small to spell keeps its own text.
     *
     * Formatting it would round it to zero, and a price silently becoming
     * nothing is worse than one that looks odd.
     */
    public function test_a_number_too_small_to_spell_is_left_alone(): void
    {
        $this->assertNull($this->spell(1E-15));
    }

    public function test_a_number_too_large_to_spell_is_left_alone(): void
    {
        $this->assertNull($this->spell(1E+20));
    }

    public function test_zero_is_zero(): void
    {
        $this->assertSame('0', $this->spell(0.0));
    }

    public function test_a_negative_fraction_is_spelled_out(): void
    {
        $this->assertSame('-0.024', $this->spell(-2.4E-2));
    }

    /* ------------------------------------------------------------------ helpers */

    protected function spell(float $number): ?string
    {
        $method = new ReflectionMethod(XlsxReader::class, 'spellOut');
        $method->setAccessible(true);

        return $method->invoke(
            (new ReflectionClass(XlsxReader::class))->newInstanceWithoutConstructor(),
            $number,
        );
    }
}
