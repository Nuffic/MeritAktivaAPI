<?php

namespace Infira\MeritAktiva;
abstract class InvoiceGeneral extends \Infira\MeritAktiva\General
{
	protected $taxRows    = [];
	protected $taxAmounts = [];

	/**
	 * @param string $date - string to use in strtotime
	 */
	public function setDocDate(string $date)
	{
		$this->set("DocDate", $this->convertDate($date));
	}

	/**
	 * @param string $date - string to use in strtotime
	 */
	public function setDueDate($date)
	{
		$this->set("DueDate", $this->convertDate($date));
	}

	/**
	 * @param string $date - string to use in strtotime
	 */
	public function setTransactionDate($date)
	{
		$this->set("TransactionDate", $this->convertDate($date));
	}

	/**
	 * @see https://www.pangaliit.ee/settlements-and-standards/reference-number-of-the-invoice/check-digit-calculator-of-domestic-account-number
	 * @param integer $regNO
	 */
	public function setRefNo(int $regNO)
	{
		$this->set("RefNo", $regNO);
	}

	public function setCurrencyCode($code)
	{
		$this->set("CurrencyCode", $code);
	}

	public function setDepartmentCode($code)
	{
		$this->set("DepartmentCode", $code);
	}

	public function setProjectCode($code)
	{
		$this->set("ProjectCode", $code);
	}

	public function addRow(\Infira\MeritAktiva\InvoiceRow $Row)
	{
		$rows   = $this->getRows();
		$rows[] = $Row;
		$this->setRows($rows);
		$taxID = $Row->getTaxID();
		if (!array_key_exists($taxID, $this->taxAmounts))
		{
			$this->taxAmounts[$taxID] = 0;
		}
		$this->taxAmounts[$taxID] += $Row->getPriceTaxAmount();

		$rowAmountRounded = round($Row->getPriceNET() * $Row->getQuantity(), 2);

		$totalSum = $this->get("TotalAmount", 0) + $rowAmountRounded;
		$this->setTotalAmount($totalSum);
	}

	private function setRows(array $Rows)
	{
		$this->set("InvoiceRow", $Rows);
	}

	public function getRows()
	{
		return $this->get("InvoiceRow", []);
	}

    /**
     * @return array
     */
    public function getTaxAmounts(): array
    {
        $totals = [];
        foreach ($this->getRows() as $Row) {
            $taxID = $Row->getTaxID();
            if (!array_key_exists($taxID, $totals)) {
                $totals[$taxID] = '0';
            }

            $totalNet = bcmul(
                (string) $Row->getPriceNET(),
                (string) $Row->getQuantity(),
                6
            );

            // Recompute vatNr cleanly: (taxPercent / 100) + 1
            $vatNr = bcadd(
                bcdiv((string) $Row->getTaxPercent(), '100', 6),
                '1',
                6
            );

            // mirrors: ($price * $vatNr) - $price
            $taxAmount = bcsub(
                bcmul($totalNet, $vatNr, 6),
                $totalNet,
                6
            );

            $totals[$taxID] = bcadd($totals[$taxID], $taxAmount, 6);
        }

        return array_map(fn($amount) => bcadd($amount, '0', 2), $totals);
    }

	public function setTaxAmount(\Infira\MeritAktiva\VATObject $VATObject)
	{
		$this->taxRows[$VATObject->getTaxID()] = $VATObject;
		$this->set("TaxAmount", array_values($this->taxRows));
	}

	/**
	 * Use it for getting PDF invoice to round number. Does not affect TotalAmount.
	 *
	 * @param float $amount
	 */
	public function setRoundingAmount($amount)
	{
		$this->set("RoundingAmount", $this->toFloat($amount));
	}

	public function setTotalAmount($amount)
	{
		$this->set("TotalAmount", $this->toFloat($amount));
	}

	/**
	 * Get total amount
	 *
	 * @return string
     */
    public function getTotalAmount()
    {
        $total = '0';
        foreach ($this->getRows() as $Row) {
            $total = bcadd($total, bcmul(
                (string) $Row->getPriceNET(),
                (string) $Row->getQuantity(),
                6
            ), 6);
        }
        return bcadd($total, '0', 2);
    }

	/**
	 * Get total amount
	 *
	 * @param $amount
	 * @return mixed
	 */
	public function getTotalAmountGross()
	{
		return round($this->addTAX($this->getTotalAmount()), 2);
	}

	public function setPayment(\Infira\MeritAktiva\PurchasePayment $Payment)
	{
		$this->set("Payment", $Payment);
	}

	/**
	 * If not specified, API will get it from client record, if it is written there.
	 * Comment after invoice rows
	 *
	 * @param string $comment
	 */
	public function setFcomment(string $comment)
	{
		$this->set("Fcomment", $comment);
	}

	/**
	 * If not specified, API will get it from client record, if it is written there.
	 * Comment before invoice rows
	 *
	 * @param string $comment
	 */
	public function setHcomment(string $comment)
	{
		$this->set("Hcomment", $comment);
	}

    /**
     * @param Dimension[] $dimensions
     * @return void
     */
    public function setDimensions(array $dimensions)
    {
        $this->set("Dimensions", $dimensions);
    }
}
