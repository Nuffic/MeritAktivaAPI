<?php

namespace Infira\MeritAktiva;

use Error;

if (!function_exists('debug')) {
	function debug()
	{
		$GLOBALS["debugIsActive"] = TRUE;
		$args                     = func_get_args();
		$html                     = "";

		if (count($args) == 1) {
			$html .= mertiApiDump($args[0]);
		} else {
			$html .= mertiApiDump($args);
		}
		$html = "<pre>$html</pre>";
		echo ($html);
	}
}

function mertiApiDump($variable, $echo = FALSE)
{
	if (is_array($variable) or is_object($variable)) {
		$html = print_r($variable, TRUE);
	} else {
		ob_start();
		var_dump($variable);
		$html = ob_get_clean();
	}
	if ($echo == TRUE) {
		exit($html);
	}

	return $html;
}

class API extends \Infira\MeritAktiva\General
{
	public const API_V1 = 'v1';
	public const API_V2 = 'v2';

	private $apiID;
	private $apiKey;
	private $lastRequestData = "";
	private $lastRequestUrl  = "";
	private $url             = "";
	private $debug           = FALSE;

	public function __construct($apiID, $apiKey, $country = 'ee', $vatPercent = 20)
	{
		$this->apiID  = $apiID;
		$this->apiKey = $apiKey;
		if ($country == 'ee') {
			$this->url = 'https://aktiva.merit.ee/api/';
		} elseif ($country == 'fi') {
			$this->url = 'https://aktiva.meritaktiva.fi/api/';
		} elseif ($country == 'pl') {
			$this->url = 'https://program.360ksiegowosc.pl/api/';
		} else {
			throw new Error("Unknown country");
		}

		if (!defined('MERIT_VAT_PERCENT')) {
			define("MERIT_VAT_PERCENT", $vatPercent);
		}
	}

	public function setDebug(bool $bool)
	{
		$this->debug = $bool;
	}


	public function getLastRequestData()
	{
		return $this->lastRequestData;
	}

	public function getLastRequestURL()
	{
		return $this->lastRequestUrl;
	}

	private function send($endPoint, $payload = null, bool $stripSlashes = true)
	{
		$timestamp = gmdate("YmdHis");
		$urlParams = "";
		$json      = "";
		if ($payload) {
			if ($this->debug) {
				debug($payload);
			}
			$json = self::toUTF8(json_encode($payload));
		}

		$dataString            = $this->apiID . $timestamp . $json;
		$hash                  = hash_hmac("sha256", $dataString, $this->apiKey, true);
		$signature             = base64_encode($hash);
		$url                   = sprintf(
			'%s%s?ApiId=%s&timestamp=%s&signature=%s' . $urlParams,
			$this->url,
			$endPoint,
			$this->apiID,
			$timestamp,
			$signature
		);
		$this->lastRequestUrl  = $url;
		$this->lastRequestData = $payload;

		$headers = [
			"Content-type: application/json",
		];

		if (isset($json)) {
			$headers[] = "Content-Length: " . strlen($json);
		}

		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_POST, TRUE);
		if ($json) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
		}
		$curlResponse = curl_exec($curl);
		if ($this->debug) {
			debug('$curlResponse', $curlResponse);
		}

		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		if ($status != 200) {
			$error = "Error: call to URL $url <br>STATUS: $status<br>CURL_ERROR: " . curl_error($curl) . "<br> CURL_ERRNO: " . curl_errno($curl);
			$error .= '<br><br>API SAYS:' . mertiApiDump($this->jsonDecode($curlResponse, TRUE));

			return $error;
		}

        return $this->jsonDecode($curlResponse, false, $stripSlashes);
	}

    private function jsonDecode($json, $checkError = false, bool $stripSlashes = true)
    {
        if ($stripSlashes) {
            $json = stripslashes($json);
        }

		if (str_starts_with($json, '"') && str_ends_with($json, '"')) {
			$data = json_decode(substr($json, 1, -1));
		} else {
			$data = json_decode($json);
		}

        $error = json_last_error();
        if ($error and $checkError) {
			return $json;
		}

        return $data;
	}

    private function looksLikeJson(string $data): bool
    {
        return (str_starts_with($data, '[') || str_starts_with($data, '{')) && str_ends_with($data, ']') || str_ends_with($data, '}');
    }

	private static function toUTF8($string)
	{
		$string = trim($string);
		// return mb_convert_encoding($string, 'UTF-8');
		$encoding_list = 'UTF-8, ISO-8859-13, ISO-8859-1, ASCII, UTF-7';
		if (mb_detect_encoding($string, $encoding_list) == 'UTF-8') {
			return $string;
		}

		return mb_convert_encoding($string, 'UTF-8', mb_detect_encoding($string, $encoding_list));
	}

	/************ START OF endpoints ********************/
	/************* Versioned endpoints ******************/

	/**
	 * @param string $periodStart - string what is convertoed to time using strtotime()
	 * @param string $periodEnd - string what is convertoed to time using strtotime()
	 * @param string $apiVersion
	 * @return \Infira\MeritAktiva\APIResult
	 * @see https://api.merit.ee/reference-manual/sales-invoices/get-list-of-invoices/
	 */
	public function getSalesInvoices($periodStart, $periodEnd, string $apiVersion = self::API_V1): APIResult
	{
		$payload = ["PeriodStart" => date("Ymd", strtotime($periodStart)), "PeriodEnd" => date("Ymd", strtotime($periodEnd))];

		return new APIResult($this->send("$apiVersion/getinvoices", $payload));
	}

	/**
	 * Get sales invoice details
	 *
	 * @param string $GUID
	 * @param string $apiVersion
	 * @return \Infira\MeritAktiva\APIResult
	 * @see https://api.merit.ee/reference-manual/sales-invoices/get-invoice-details/
	 */
	public function getSalesInvoiceByID(string $GUID, string $apiVersion = self::API_V1): APIResult
	{
		return new APIResult($this->send("$apiVersion/getinvoice", ['Id' => $GUID]));
	}

	/**
	 * Returns created invoice data
	 *
	 * @param SalesInvoice $Invoice
	 * @param string $apiVersion
	 * @return \Infira\MeritAktiva\APIResult
	 * @see https://api.merit.ee/reference-manual/sales-invoices/create-sales-invoice/
	 */
	public function createSalesInvoice(SalesInvoice $Invoice, string $apiVersion = self::API_V1): APIResult
	{
		return new APIResult($this->send("$apiVersion/sendinvoice", $Invoice->getData()));
	}

    /**
     * Returns created invoice data
     *
     * @param SalesInvoice $Invoice
     * @param string $apiVersion
     * @return \Infira\MeritAktiva\APIResult
     * @see https://api.merit.ee/reference-manual/sales-invoices/create-sales-invoice/
     */
	public function createCreditSalesInvoice(SalesInvoice $Invoice, string $apiVersion = self::API_V1): APIResult
	{
		return new APIResult($this->send("$apiVersion/sendinvoice", $Invoice->getData()));
	}

    /**
     * @param string $periodStart - string what is convertoed to time using strtotime()
     * @param string $periodEnd - string what is convertoed to time using strtotime()
     * @param string $apiVersion
     * @return \Infira\MeritAktiva\APIResult
     * @see https://api.merit.ee/reference-manual/purchase-invoices/get-list-of-purchase-invoices/
     */
	public function getPurchaseInvoices($periodStart, $periodEnd, string $apiVersion = self::API_V1): APIResult
	{
		$payload = ["PeriodStart" => date("Ymd", strtotime($periodStart)), "PeriodEnd" => date("Ymd", strtotime($periodEnd))];

		return new APIResult($this->send("$apiVersion/getpurchorders", $payload));
	}

	/**
	 * Get sales invoice details
	 *
	 * @param string $GUID
	 * @param string $apiVersion
	 * @return \Infira\MeritAktiva\APIResult
	 * @see https://api.merit.ee/reference-manual/purchase-invoices/get-purchase-invoice-details/
	 */
	public function getPurchaseInvoiceByID(string $GUID, string $apiVersion = self::API_V1): APIResult
	{
		return new APIResult($this->send("$apiVersion/getpurchorder", ['Id' => $GUID]));
	}

	/**
	 * Save purcahse invoice
	 *
	 * @param \Infira\MeritAktiva\PurchaseInvoice $Invoice
	 * @param string $apiVersion
	 * @return \Infira\MeritAktiva\APIResult
	 * @see https://api.merit.ee/reference-manual/purchase-invoices/create-purchase-invoice/
	 */
	public function createPurchaseInvoice(\Infira\MeritAktiva\PurchaseInvoice $Invoice, string $apiVersion = self::API_V1)
	{
		return new APIResult($this->send("$apiVersion/sendpurchinvoice", $Invoice->getData()));
	}

    /**
     * Save invoice payment
     *
     * @param \Infira\MeritAktiva\Payment $Invoice
     * @param string $apiVersion
     * @return \Infira\MeritAktiva\APIResult
     */
	public function savePayment(\Infira\MeritAktiva\Payment $Invoice, string $apiVersion = self::API_V1)
	{
		return new APIResult($this->send("$apiVersion/sendpayment", $Invoice->getData()));
	}

    /**
     * @see https://api.merit.ee/reference-manual/payments/list-of-payments/
     * @param $periodStart
     * @param $periodEnd
     * @param string $apiVersion
     * @return \Infira\MeritAktiva\APIResult
     */
	public function getPayments($periodStart, $periodEnd, string $apiVersion = self::API_V1): APIResult
	{
		$payload = ["PeriodStart" => date("Ymd", strtotime($periodStart)), "PeriodEnd" => date("Ymd", strtotime($periodEnd))];

		return new APIResult($this->send("$apiVersion/getpayments", $payload));
	}

    /**
     * @see https://api.merit.ee/connecting-robots/reference-manual/sales-invoices/create-sales-invoice/get-sales-invoice-pdf/
     * @param string $GUID invoice GUID
     * @param bool $delivNote if true then, the invoice is without prices (delivery note)
     * @return APIResult
     */
    public function getSalesInvoicePdf(string $GUID, bool $delivNote = false)
    {
        return new APIResult($this->send('v2/getsalesinvpdf', [
            'Id' => $GUID,
            'DelivNote' => $delivNote
        ]));
    }

	/********** Single version endpoints*****************/

	/*************** V1 API endpoints *******************/

	/**
	 * Delete sales invoice
	 *
	 * @param string $GUID
	 * @return \Infira\MeritAktiva\APIResult
	 * @see https://api.merit.ee/reference-manual/sales-invoices/delete-invoice/
	 */
	public function deleteSalesInvoiceByID(string $GUID): APIResult
	{
		return new APIResult($this->send("v1/deleteinvoice", ['Id' => $GUID]));
	}

	/**
	 * Delete payment
	 *
	 * @param string $paymentId
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function deletePayment(string $paymentId)
	{
		return new APIResult($this->send("v1/deletepayment", ['Id' => $paymentId]));
	}

    /**
     * get Merit customers
     *
     * @param array $payload
     * @param string $apiVersion
     * @return \Infira\MeritAktiva\APIResult
     * @see https://api.merit.ee/reference-manual/get-customer-list/
     */
	private function getCustomersBy(array $payload, string $apiVersion = self::API_V1): APIResult
	{
		return new APIResult($this->send("$apiVersion/getcustomers", $payload));
	}

	/**
	 * Returns created customer data
	 *
	 * @param Customer $Customer
	 * @return \Infira\MeritAktiva\APIResult
	 * @see https://api.merit.ee/connecting-robots/reference-manual/customers/update-customer/
	 */
	public function updateCustomer(Customer $Customer): APIResult
	{
		return new APIResult($this->send("v1/updatecustomer", $Customer->getData()));
	}

	/**
	 * @see https://api.merit.ee/reference-manual/tax-list/
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getTaxes()
	{
		return new APIResult($this->send("v1/gettaxes"));
	}

	/**
	 * @see https://api.merit.ee/connecting-robots/reference-manual/banks-list/
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getBanks()
	{
		return new APIResult($this->send("v1/getbanks"));
	}

	/**
	 * @see https://api.merit.ee/connecting-robots/reference-manual/accounts-list/
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getAccounts()
	{
		return new APIResult($this->send("v1/getaccounts"));
	}

	/**
	 * @param array $payload
	 * @see https://api.merit.ee/reference-manual/get-vendor-list/
	 * @return \Infira\MeritAktiva\APIResult
	 */
	private function getVendorsBy(array $payload): APIResult
	{
		return new APIResult($this->send("v1/getvendors", $payload));
	}

	/*************** V2 API endpoints ****************/

	/**
	 * Returns created customer data
	 *
	 * @param Customer $Customer
	 * @return \Infira\MeritAktiva\APIResult
	 * @see https://api.merit.ee/connecting-robots/reference-manual/customers/create-customer/
	 */
	public function createCustomer(Customer $Customer): APIResult
	{
		return new APIResult($this->send("v2/sendcustomer", $Customer->getData()));
	}

	/**
	 * @see https://api.merit.ee/connecting-robots/reference-manual/dimensions/dimensionslist/
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getDimensions()
	{
		return new APIResult($this->send("v2/getdimensions"));
	}

	/**
	 *
	 * @param string $guid
	 * @return APIResult
	 * @see https://api.merit.ee/connecting-robots/reference-manual/sales-invoices/create-sales-invoice/send-invoice-by-e-mail/
	 */
	public function sendEmail(string $guid)
	{
		return new APIResult($this->send("v2/sendinvoicebyemail", ["Id" => $this->validateGUID($guid)]));
	}

	/**
	 *
	 * @param string $guid
	 * @return APIResult
	 * @see https://api.merit.ee/connecting-robots/reference-manual/sales-invoices/create-sales-invoice/send-sales-invoice-by-einvoice/
	 */
	public function sendEInvoice(string $guid)
	{
		return new APIResult($this->send("v2/sendinvoiceaseinv", ["Id" => $this->validateGUID($guid)]));
	}

    /**
     *
     * @param array $payload
     * @return APIResult
     * @see https://api.merit.ee/connecting-robots/reference-manual/reports/customer-payment-report/
     */
    public function getCustomerPaymentReport(array $payload)
    {
        // Strip slashes malforms the json data here
        $data = $this->send("v2/getcustpaymrep", $payload, false);

        return $this->processCustomerReportData($data);
    }

    /**
     * WARNING: Method not fully tested.
     *
     * During manual testing, the 'hasMore' flag in the getCustomerPaymentReport() method was not activated even when handling over 1500+ data rows per customer.
     * In most scenarios, it is unlikely to exceed this volume per customer.
     *
     * The existing documentation does not provide clear guidelines about this endpoint. However, it is anticipated that the response should mirror the structure of getCustomerPaymentReport().
     *
     * @param string $Id4More
     * @return APIResult
     * @see https://api.merit.ee/connecting-robots/reference-manual/reports/customer-payment-report/
     */
    public function getMoreData(string $Id4More)
    {
        // Strip slashes malforms the json data here
        $data = $this->send("v2/getmoredata", ['Id4More' => $Id4More], false);

        return $this->processCustomerReportData($data);
    }

    public function processCustomerReportData($data): APIResult
    {
        // Try to decode subdata that is json string
        if (!is_object($data)) {
            return new APIResult($data);
        }

        foreach ($data as $key => $value) {
            if ($this->looksLikeJson($value)) {
                $data->{$key} = $this->jsonDecode($value);
            } else {
                $data->{$key} = $value;
            }
        }

        // Parse dates
        foreach ($data->Data as $dataRow) {
            preg_match(':(\d+):i', $dataRow->DocDate ?? '', $parts);
            $dataRow->DocDate = (int)$parts[1];
            preg_match(':(\d+):i', $dataRow->DueDate ?? '', $parts);
            $dataRow->DueDate = (int)$parts[1];
        }

        return new APIResult($data);
    }

	/*************** Endpoint wrappers ***************/
    /**
     * Get customer list
     *
     * @see https://api.merit.ee/reference-manual/get-customer-list/
     * @param string $apiVersion
     * @return \Infira\MeritAktiva\APIResult
     */
	public function getCustomers(string $apiVersion = self::API_V1): APIResult
	{
		return $this->getCustomersBy([], $apiVersion);
	}

	/**
	 * get merit vendor by ID
	 *
	 * @see https://api.merit.ee/reference-manual/get-customer-list/
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getCustomersByID($GUID, string $apiVersion = self::API_V1)
	{
		return $this->getCustomersBy(["Id" => $this->validateGUID($GUID)], $apiVersion);
	}

	/**
	 * get merit vendor by RegNo
	 *
	 * @see https://api.merit.ee/reference-manual/get-customer-list/
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getCustomersByRegNo($registryNumber, string $apiVersion = self::API_V1)
	{
		return $this->getCustomersBy(["RegNo" => $registryNumber], $apiVersion);
	}

	/**
	 * get merit vendor by VatRegNo
	 *
	 * @see https://api.merit.ee/reference-manual/get-customer-list/
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getCustomersByVatRegNo($vatNumber, string $apiVersion = self::API_V1)
	{
		return $this->getCustomersBy(["VatRegNo" => $vatNumber], $apiVersion);
	}

	/**
	 * get merit vendor by Name
	 *
	 * @see https://api.merit.ee/reference-manual/get-customer-list/
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getCustomersByName($name, string $apiVersion = self::API_V1)
	{
		return $this->getCustomersBy(["Name" => $name], $apiVersion);
	}

	/**
	 * Get tax details
	 *
	 * @param string $code
	 * @see https://api.merit.ee/reference-manual/tax-list/
	 * @return \stdClass|null
	 */
	public function getTaxDetails(string $code)
	{
		$Taxes = $this->getTaxes();
		if ($Taxes->isError()) {
			$this->intError($Taxes->getError());
		}
		foreach ($Taxes->getRaw() as $Row) {
			if ($Row->Code == $code) {
				return $Row;
			}
		}

		return NULL;
	}

	/**
	 * get merit vendors
	 *
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getVendors()
	{
		return $this->getVendorsBy([]);
	}

	/**
	 * get vendors by ID
	 *
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getVendorsByID($ID)
	{
		return $this->getVendorsBy(["Id" => $ID]);
	}

	/**
	 * get merit vendor by RegNo
	 *
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getVendorsByRegNo($no)
	{
		return $this->getVendorsBy(["RegNo" => $no]);
	}

	/**
	 * get merit vendor by VatRegNo
	 *
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getVendorsByVatRegNo($no)
	{
		return $this->getVendorsBy(["VatRegNo" => $no]);
	}

	/**
	 * get merit vendor by Name
	 *
	 * @return \Infira\MeritAktiva\APIResult
	 */
	public function getVendorsByName($name)
	{
		return $this->getVendorsBy(["Name" => $name]);
	}
}
