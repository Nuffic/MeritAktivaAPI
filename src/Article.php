<?php

namespace Infira\MeritAktiva;
/**
 * Class Article
 *
 * @see     https://api.merit.ee/reference-manual/sales-invoices/create-sales-invoice/#ItemObject
 * @package Infira\MeritAktiva
 */
class Article extends \Infira\MeritAktiva\General
{
	const TYPE_STOCK_ITEM = 1;
	const TYPE_SERVICE    = 2;
	const TYPE_ITEM       = 3;

	public function __construct($array = [])
	{
		$this->setMandatoryField('Code');
		$this->setMandatoryField('Description');
		$this->setMandatoryField('Type');
	}
	
	public function setCode(string $code)
	{
		$this->set("Code", $code);
	}
	
	/**
	 * @param string $descriptin
	 * @return void
	 */
	public function setDescription(string $descriptin)
	{
		$this->set("Description", $descriptin);
	}
	
	/**
	 * 1 = stock item, 2 = service, 3 = item. Required.
	 *
	 * @param int $int
	 */
	public function setType(int $int)
	{
		if ($int !== self::TYPE_STOCK_ITEM && $int !== self::TYPE_SERVICE && $int !== self::TYPE_ITEM)
		{
			$this->intError("Unknown Item type");
		}
		$this->set("Type", $int);
	}
	
	/**
	 * Name for the unit
	 *
	 * @param $name
	 */
	public function setUOMName($name)
	{
		$this->set("UOMName", $name);
	}
	
	/**
	 * If company has more than one (default) stock, stock code in this field is required for all stock items.
	 *
	 * @param string $code
	 * @return void
	 */
	public function setDefLocationCode(string $code)
	{
		$this->set("DefLocationCode", $code);
	}
}
