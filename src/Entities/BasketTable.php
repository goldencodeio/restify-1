<?php

namespace spaceonfire\Restify\Entities;

use CSaleBasket;

class BasketTable extends \Bitrix\Sale\Internals\BasketTable {

    public static function getMap() {
		$mapArr = parent::getMap();

		$mapArr[] = new \Bitrix\Main\ORM\Fields\Relations\Reference(
                'PRICE_ARR',
                '\Bitrix\Catalog\Price',
                array('=this.PRODUCT_PRICE_ID' => 'ref.ID'),
                array('join_type' => 'INNER')
		);

		return $mapArr;
	}
}

class ProductTable extends \Bitrix\Sale\Internals\ProductTable {
}
