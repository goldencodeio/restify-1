<?php

namespace spaceonfire\Restify\Executors;

use Bitrix\Currency\CurrencyTable;
use Bitrix\Main\EventManager;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;
use CMain;
use CSaleBasket;
use CSaleOrder;
use CSaleOrderProps;
use CSaleOrderPropsValue;
use CSalePersonType;
use Bitrix\Sale\Helpers\Admin\OrderEdit;
use Emonkak\HttpException\BadRequestHttpException;
use Emonkak\HttpException\InternalServerErrorHttpException;
use Emonkak\HttpException\NotFoundHttpException;
use Emonkak\HttpException\UnauthorizedHttpException;
use Exception;

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/sale/lib/helpers/admin/orderedit.php");

class SaleOrderRest implements IExecutor {
	use RestTrait;

	private $entity = 'Bitrix\Sale\Internals\OrderTable';

	/**
	 * SaleOrderRest constructor
	 * @param array $options executor options
	 * @throws \Bitrix\Main\LoaderException
	 * @throws InternalServerErrorHttpException
	 * @throws Exception
	 */
	public function __construct($options) {
		$this->loadModules([
			'sale',
			'catalog',
			'currency',
		]);

		$this->filter = [];
		$this->order = [
			'DATE_INSERT' => 'DESC',
		];

		$this->checkEntity();
		$this->setPropertiesFromArray($options);

		$sep = $this->ormNestedSelectSeparator;
		$this->select = [
			'*',
		];
		$this->basketSelect = [
			'*',
			'PRODUCT' . $sep => 'PRODUCT',
			'ELEMENT' . $sep => 'PRODUCT.IBLOCK',
		];

		$this->registerPermissionsCheck();
		$this->registerBasicTransformHandler();
		$this->buildSchema();
	}

	public function readMany() {
		$this->registerBasketTransfrom();
		return $this->readORM();
	}

	public function readOne($id) {
		$this->registerOneItemTransformHandler();
		$this->filter = array_merge($this->filter, [
			'ID' => $id,
		]);

		// Get only one item
		$this->navParams = ['nPageSize' => 1];

		$results = $this->readMany();

		if (!count($results)) {
			throw new NotFoundHttpException();
		}

		return $results;
	}

	/*
	 * Method
	 * Gets UserID known as anonymous for order
	 * Takes	: n/a
	 * Returns	: Maybe Int user id known as order anon
	 */
	private function getUserOrderAnon(){
		return self::getUserOrderAnonByUserField();
	}

	/*
	 * Function
	 * Gets UserID known as anonymous for order
	 * Takes	: n/a
	 * Returns	: Maybe Int user id known as order anon
	 */
	public function getUserOrderAnonByUserField(){
		$userId = null;

		$res = UserTable::getList([
			'select' => [ 'ID' ],
			'filter' => [ 'UF_ORDER_ANON' => 1 ],
		]);

		$userArr = $res->fetch();
		if( ! empty( $userArr[ 'ID' ] ) ) $userId = $userArr[ 'ID' ];

		return $userId;
	}

	public function create() {
		$this->registerOneItemTransformHandler();

		global $APPLICATION, $USER;

		$basketSaved = new CSaleBasket();
		$order = new CSaleOrder();

		// Find basket values
		$fuserId = $basketSaved->GetBasketUserID();

		$itemsHandle = $basketSaved->GetList([], [
			'FUSER_ID' => $fuserId,
			'LID' => SITE_ID,
			'ORDER_ID' => 'NULL'
		]);
		$items = [];
		while ($item = $itemsHandle->GetNext(true, false)) {
			$itemId = $item[ 'PRODUCT_ID' ];
			$quantity = $item[ 'QUANTITY' ];
			$canBuy = $item[ 'CAN_BUY' ];
			$items[] = [ 'OFFER_ID' => $itemId,
				'QUANTITY' => $quantity, 'CAN_BUY' => $canBuy,
			];
		}

		if ( empty( $items ) ) {
			throw new BadRequestHttpException(Loc::getMessage('SALE_ORDER_CREATE_BASKET_EMPTY'));
		}

		$userId = $USER->GetID();
		if( empty( $userId ) ) $userId = $this->getUserOrderAnon();

		if( empty( $userId ) ) {
			throw new NotFoundHttpException(
				$APPLICATION->LAST_ERROR ?: Loc::getMessage('SALE_ORDER_ANON_USER_FIND')
			);
		}


		// Add props and coupons
		$orderProps = [];
		$propsIntroHandle = (new CSaleOrderProps())->GetList();
		$i = 0;
		while ($propIntro = $propsIntroHandle->GetNext(true, false)) {
			$i ++; // starting from '1'
			$propCode =  $propIntro[ 'CODE' ];
			if ( isset( $this->body[$propCode] ) ){
				$propValue = $this->body[$propCode];
				$orderProps[ $i ] = $propValue;
			}
		}

		$coupons = (string) $this->body[ 'COUPONS' ];

		// Every value is taken; arrange them for function
		// no more user input below
		$createOrderVars = [
		  'SITE_ID' => SITE_ID,
		  'USER_ID' => $userId,
		];
		$createOrderVarsItems = [
		];
		for ( $i = 0; $i < count( $items ); $i ++ ){
			$item = $items[ $i ];
			$key = 'n' . ( $i + 2 );

			$itemArr = $item;
			$itemArr[ 'MODULE' ] = 'catalog';

			$createOrderVarsItems[ $key ] = $itemArr;
		}

		$createOrderVars[ 'PRODUCT' ] = $createOrderVarsItems;
		$createOrderVars[ 'PROPERTIES' ] = $orderProps;

		$result = new \Bitrix\Sale\Result();

		// Coupons
		$couponsArg = [ 'COUPONS' => $coupons, ];

		// Create order
		$order = OrderEdit::createOrderFromForm($createOrderVars, null, true, [], $result);
		if( $order && $result->isSuccess() ){

			// Calculate
			$rv = OrderEdit::saveCoupons($order->getUserId(), $couponsArg);
			if(!$rv) {
				throw new InternalServerErrorHttpException(
					$APPLICATION->LAST_ERROR ?: Loc::getMessage('SALE_ORDER_CREATE_ERROR')
				);
			}

			// To apply discounts depended on paysystems, or delivery services
			if (!($orderBasket = $order->getBasket()))
				throw new BadRequestHttpException(Loc::getMessage('SALE_ORDER_CREATE_BASKET_EMPTY'));

			// Put new cost values to basket object
			$rv = $orderBasket->refreshData( [ 'PRICE', 'QUANTITY', 'COUPONS', ] );
			if (!$rv->isSuccess()) {
				throw new InternalServerErrorHttpException(
					$APPLICATION->LAST_ERROR ?: Loc::getMessage('SALE_ORDER_CREATE_ERROR')
				);
			}

			$rv = $order->verify();
			if ( !$rv->isSuccess() ){
				throw new InternalServerErrorHttpException(
					$APPLICATION->LAST_ERROR ?: Loc::getMessage('SALE_ORDER_CREATE_ERROR')
				);
			}

			// Save
			$rv = $order->save();

			// Throw with errors, if any
			if (!$rv->isSuccess()) {
					$result->addErrors($rv->getErrors());
				throw new InternalServerErrorHttpException(
					$APPLICATION->LAST_ERROR ?: Loc::getMessage('SALE_ORDER_CREATE_ERROR')
				);
			}
		} else {
			throw new InternalServerErrorHttpException(
				$APPLICATION->LAST_ERROR ?: Loc::getMessage('SALE_ORDER_CREATE_ERROR')
			);
		}

		// Order was successful; empty out the user's cart
		$basketSaved->DeleteAll( $fuserId );

		$orderId = $order->getId();

		foreach(GetModuleEvents("sale", "OnBasketOrder", true) as $arEvent)
		{
			ExecuteModuleEventEx($arEvent, [$orderId, $fuserId, SITE_ID, false]);
		}

		return [[
			'result' => 'ok',
			'message' => Loc::getMessage('SALE_ORDER_CREATE_SUCCESS', [
				'#ORDER_ID#' => $orderId,
			]),
			'id' => $orderId,
		]];
	}

	private function registerPermissionsCheck() {
		global $SPACEONFIRE_RESTIFY;
		$events = [
			'pre:readMany',
			'pre:readOne',
		];

		foreach ($events as $event) {
			EventManager::getInstance()->addEventHandler(
				$SPACEONFIRE_RESTIFY->getId(),
				$event,
				[$this, 'checkPermissions']
			);
		}
	}

	public function checkPermissions() {
		global $USER;
		$permissions = CMain::GetUserRight('sale');

		if (!$USER->GetID()) {
			throw new UnauthorizedHttpException();
		}

		switch ($permissions) {
			case 'W':
			case 'U': {
				// Full access to orders, skip check
				return;
				break;
			}

			default: {
				// Can read and change only self orders
				$this->filter = array_merge($this->filter, [
					'CREATED_BY' => $USER->GetID(),
				]);
				break;
			}
		}
	}

	private function registerBasketTransfrom() {
		global $SPACEONFIRE_RESTIFY;
		EventManager::getInstance()->addEventHandler(
			$SPACEONFIRE_RESTIFY->getId(),
			'transform',
			[$this, 'basketProductsTransform'],
			false,
			99999
		);
	}

	public function basketProductsTransform(Event $event) {
		$params = $event->getParameters();
		$orders = $params['result'];
		$result = [];
		foreach ($orders as $order) {
			$orderId = $order[ 'ID' ];

			$filter = [ 'ORDER_ID' => $orderId, ];
			$select = $this->basketSelect;

			// Get basket for order
			$basket = \Bitrix\Sale\Internals\BasketTable::getList( [
				'select' => $select, 'filter' => $filter,
			] )->fetchAll();

			$order[ 'BASKET' ] = $basket;

			$result[] = $order;
		}
		$params['result'] = $result;
	}

}
