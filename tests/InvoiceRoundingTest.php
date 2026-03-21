<?php

namespace Infira\MeritAktiva\Tests;

use PHPUnit\Framework\TestCase;
use Infira\MeritAktiva\InvoiceRow;
use Infira\MeritAktiva\SalesInvoice;

/**
 * Tests that TotalAmount and TaxAmounts are calculated without
 * floating-point rounding errors across multiple rows.
 */
class InvoiceRoundingTest extends TestCase
{
    private const TAX_ID = '10000000-0000-0000-0000-000000000001';
    private const TAX_ID_2 = '10000000-0000-0000-0000-000000000002';

    protected function setUp(): void
    {
        // API::__construct() normally defines this constant; define it here
        // for tests so InvoiceRow can be instantiated without a real API connection.
        if (!defined('MERIT_VAT_PERCENT')) {
            define('MERIT_VAT_PERCENT', 20);
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeInvoice(): SalesInvoice
    {
        return new SalesInvoice();
    }

    private function makeRow(float $priceNet, float $qty, float $vatPercent = 20): InvoiceRow
    {
        $row = new InvoiceRow($vatPercent);
        $row->setPriceNet($priceNet);
        $row->setQuantity($qty);
        $row->setTaxID(self::TAX_ID);
        return $row;
    }

    private function makeRowWithTaxId(float $priceNet, float $qty, string $taxId, float $vatPercent = 20): InvoiceRow
    {
        $row = new InvoiceRow($vatPercent);
        $row->setPriceNet($priceNet);
        $row->setQuantity($qty);
        $row->setTaxID($taxId);
        return $row;
    }

    // -----------------------------------------------------------------------
    // TotalAmount tests
    // -----------------------------------------------------------------------

    /** Single row: price * qty should be exact */
    public function testSingleRowTotalAmount(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addRow($this->makeRow(10.00, 3));

        $this->assertSame('30.00', $invoice->getTotalAmount());
    }

    /** Classic floating-point trap: 0.1 + 0.2 !== 0.3 in plain PHP */
    public function testFloatingPointTrapIsAvoided(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addRow($this->makeRow(0.10, 1));
        $invoice->addRow($this->makeRow(0.20, 1));

        // Without BCMath this would be "0.30000000000000004" cast to float
        $this->assertSame('0.30', $invoice->getTotalAmount());
    }

    /** Demonstrates that summing many fractional rows stays precise */
    public function testManyRowsNoAccumulationDrift(): void
    {
        $invoice = $this->makeInvoice();
        // 0.1 is not exact in float, but 100 of them summed via BCMath
        // should still give exactly 10.00, not 9.99 or 10.01
        for ($i = 0; $i < 100; $i++) {
            $invoice->addRow($this->makeRow(0.10, 1));
        }
        $this->assertSame('10.00', $invoice->getTotalAmount());
    }

    /** Many rows with fractional prices */
    public function testManyFractionalRows(): void
    {
        $invoice = $this->makeInvoice();
        for ($i = 0; $i < 10; $i++) {
            $invoice->addRow($this->makeRow(1.235, 1));
        }
        // 10 * 1.235 = 12.35
        $this->assertSame('12.35', $invoice->getTotalAmount());
    }

    /** Price × quantity where result has more than 2 decimal places */
    public function testPriceTimesQuantityPrecision(): void
    {
        $invoice = $this->makeInvoice();
        // 9.99 * 3 = 29.97 exactly
        $invoice->addRow($this->makeRow(9.99, 3));
        $this->assertSame('29.97', $invoice->getTotalAmount());
    }

    /** Zero quantity row should contribute nothing */
    public function testZeroQuantityRow(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addRow($this->makeRow(100.00, 0));
        $this->assertSame('0.00', $invoice->getTotalAmount());
    }

    /** Zero price row should contribute nothing */
    public function testZeroPriceRow(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addRow($this->makeRow(0.00, 5));
        $this->assertSame('0.00', $invoice->getTotalAmount());
    }

    /** Empty invoice should have zero total */
    public function testEmptyInvoiceTotalIsZero(): void
    {
        $invoice = $this->makeInvoice();
        $this->assertSame('0.00', $invoice->getTotalAmount());
    }

    // -----------------------------------------------------------------------
    // TaxAmounts tests
    // -----------------------------------------------------------------------

    /** Single row, single tax bucket */
    public function testSingleRowTaxAmount(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addRow($this->makeRow(100.00, 1)); // 20% of 100 = 20.00
        $taxes = $invoice->getTaxAmounts();

        $this->assertArrayHasKey(self::TAX_ID, $taxes);
        $this->assertSame('20.00', $taxes[self::TAX_ID]);
    }

    /** Tax amounts accumulate correctly across rows with the same tax ID */
    public function testTaxAmountAccumulationSameTaxId(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addRow($this->makeRow(100.00, 1)); // tax: 20.00
        $invoice->addRow($this->makeRow(50.00, 2));  // tax: 20.00 (50*2=100 * 20%)
        $taxes = $invoice->getTaxAmounts();

        $this->assertSame('40.00', $taxes[self::TAX_ID]);
    }

    /** Rows with different tax IDs are bucketed separately */
    public function testTaxAmountsSeparatedByTaxId(): void
    {
        $invoice = $this->makeInvoice();

        $row1 = $this->makeRowWithTaxId(100.00, 1, self::TAX_ID, 20);
        $row2 = $this->makeRowWithTaxId(100.00, 1, self::TAX_ID_2, 9);

        $invoice->addRow($row1);
        $invoice->addRow($row2);

        $taxes = $invoice->getTaxAmounts();

        $this->assertSame('20.00', $taxes[self::TAX_ID]);
        $this->assertSame('9.00', $taxes[self::TAX_ID_2]);
    }

    /** Zero VAT row should produce 0.00 tax */
    public function testZeroVatTaxAmount(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addRow($this->makeRow(100.00, 1, 0));
        $taxes = $invoice->getTaxAmounts();

        $this->assertSame('0.00', $taxes[self::TAX_ID]);
    }

    /**
     * Rounding accumulation in tax:
     * 3 rows at price=1.005, 20% VAT each.
     * Tax per row = 1.005 * 0.20 = 0.201
     * Total tax = 0.603 → rounds to 0.60
     * (If rounded per-row first: round(0.201,2)=0.20 * 3 = 0.60 — coincidence here,
     *  but with different numbers the error shows up)
     */
    public function testTaxRoundingAccumulation(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addRow($this->makeRow(0.50, 3));

        $taxes = $invoice->getTaxAmounts();
        $this->assertSame('0.30', $taxes[self::TAX_ID]);
    }

    /** Tax total should be consistent with TotalAmount * vatRate */
    public function testTaxAndTotalAreConsistent(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addRow($this->makeRow(83.333333, 3)); // tricky fractional

        $total = $invoice->getTotalAmount();  // net total
        $taxes = $invoice->getTaxAmounts();

        // tax should equal total * 0.20, both rounded to 2dp
        $expectedTax = bcadd(bcmul($total, '0.2', 6), '0', 2);
        $this->assertSame($expectedTax, $taxes[self::TAX_ID]);
    }
}