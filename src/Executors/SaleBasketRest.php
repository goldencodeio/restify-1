<?php

namespace spaceonfire\Restify\Executors;

use Bitrix\Main\Localization\Loc;
use CSaleBasket;
use Emonkak\HttpException\BadRequestHttpException;
use Emonkak\HttpException\InternalServerErrorHttpException;
use Emonkak\HttpException\NotFoundHttpException;
use Exception;

class SaleBasketRest implements IExecutor {
	use RestTrait;

	private $entity = 'Bitrix\Sale\Internals\BasketTable';

	/**
	 * SaleBasketRest constructor
	 * @param array $options executor options
	 * @throws \Bitrix\Main\LoaderException
	 * @throws InternalServerErrorHttpException
	 * @throws Exception
	 */
	public function __construct($options) {
		$this->loadModules([
			'sale',
			'catalog',
		]);

		$this->filter = [];

		$this->checkEntity();
		$this->setSelectFieldsFromEntityClass();
		$this->setPropertiesFromArray($options);

		$sep = $this->ormNestedSelectSeparator;
		$this->select = [
			'*',
			'PRODUCT' . $sep => 'PRODUCT',
			'ELEMENT' . $sep => 'PRODUCT.IBLOCK',
		];

		$this->registerBasicTransformHandler();
		$this->buildSchema();
	}

	public function read() {

		// outputs only first array element, e. g., basket hash
		$this->registerOneItemTransformHandler();

		$this->filter = array_merge($this->filter, [
			'FUSER_ID' => (int) CSaleBasket::GetBasketUserID(true),
			'LID' => SITE_ID,
			'ORDER_ID' => 'NULL',
		]);
		$basketArr = $this->getBasketArr(); // calls ->registerBasicTransformHandler();

		return [ $basketArr ];
	}

	private function _read() {
		$this->filter = array_merge($this->filter, [
			'FUSER_ID' => (int) CSaleBasket::GetBasketUserID(true),
			'LID' => SITE_ID,
			'ORDER_ID' => 'NULL',
		]);
		return $this->readORM();
	}

	public function update($id = null) {
		$this->registerOneItemTransformHandler();

		if (!$id) {
			$id = $this->body['ID'];
			unset($this->body['ID']);
		}

		if (!$id) {
			throw new NotFoundHttpException();
		}

		$quantity = (int) $this->body['QUANTITY'] ?: 1;

		// Find basket item by product id
		$action = 'add';
		$this->filter = ['PRODUCT_ID' => $id];
		$this->select = ['ID'];
		$item = array_pop($this->_read());
		if ($item) {
			$action = 'update';
		}

		switch ($action) {
			case 'update':
				$result = (new CSaleBasket())->Update($item['ID'], ['QUANTITY' => $quantity]);
				$successMessage = Loc::getMessage('SALE_BASKET_UPDATE');
				break;

			case 'add':
			default:
				$result = \Add2BasketByProductID($id, $quantity);
				$successMessage = Loc::getMessage('SALE_BASKET_ADD');
				break;
		}

		if (!$result) {
			global $APPLICATION;
			throw new BadRequestHttpException($APPLICATION->LAST_ERROR);
		}

		return [
			$this->success($successMessage),
		];
	}

	public function delete($id) {
		$this->registerOneItemTransformHandler();

		$this->filter = ['PRODUCT_ID' => $id];
		$this->select = ['ID'];
		$item = array_pop($this->_read());

		$result = true;
		if (isset($item)) {
			$result = (new CSaleBasket())->Delete($item['ID']);
		}

		if (!$result) {
			global $APPLICATION;
			throw new InternalServerErrorHttpException($APPLICATION->LAST_ERROR);
		}

		return [
			$this->success(Loc::getMessage('SALE_BASKET_DELETE')),
		];
	}

	// Function
	// Returns iblock's id based on item's id
	// Takes	:	Int id of item in catalog;
	// Returns	:	Int id of 'iblock'
	private static function getIBlockIdByItemId( $itemId ){
		$iblockId = null;

		$iblockSth = \Bitrix\Iblock\ElementTable::getlist([ 'filter' => [
				'ID' => $itemId,
			], 'select' => [ 'IBLOCK_ID' ],
		]);
		$itemArr = $iblockSth->fetch();
		if( empty( $itemArr ) ){
			throw new NotFoundHttpException();
		} else{
			$itemArrMore = $iblockSth->fetch();
			if ( empty( $itemArrMore ) ) {
				$iblockId = $itemArr[ 'IBLOCK_ID' ];
			} else {
				throw new NotFoundHttpException();
			}
		}
		return $iblockId;
	}

	// Function
	// Returns item's properties' names to be set into the basket based on
	// item's id
	// Takes	:	Int id of item's 'iblock' in catalog;
	//				Int id of item in catalog;
	// Returns	:	Array[Str] item's properties' names
	private static function getPropsKeys( $iblockId, $itemId ){
		global $USER_FIELD_MANAGER;
		$propsKeysStr = '[]';

		$userFields = $USER_FIELD_MANAGER->GetUserFields( "ASD_IBLOCK" );
		if( empty( $userFields ) ){
			throw new NotFoundHttpException();
		} else {
			if( empty( $userFields[ 'UF_PROPS_TO_ORDER' ] ) ){
				throw new NotFoundHttpException();
			} else {
				$propsToOrder = $userFields[ 'UF_PROPS_TO_ORDER' ] ;
				if ( empty( $propsToOrder[ 'VALUE' ] ) ){
					if ( empty( $propsToOrder[ 'SETTINGS' ] ) ){
						throw new NotFoundHttpException();
					} else {
						if ( empty( $propsToOrder[ 'SETTINGS' ][ 'DEFAULT_VALUE' ] ) ){
							throw new NotFoundHttpException();
						} else {

							// Take default value
							$propsKeysStr = $propsToOrder[ 'SETTINGS' ][ 'DEFAULT_VALUE' ];
						}
					}
				} else {

					// Take configured value
					$propsKeysStr = $propsToOrder[ 'VALUE' ];
				}
			}
		}

		$propsKeys = json_decode( $propsKeysStr );
		if( empty( $propsKeys ) ){
			throw new NotFoundHttpException();
		}

		return $propsKeys;
	}

	// Function
	// Returns item's properties' names
	// Takes	:	Int iblock's id;
	//				Int item's id;
	//				Array[Str] properties' codes
	// Returns	:	Array[Str] properties human-readable names
	private static function getPropsNames( $iblockId, $itemId, $propsKeys ){
		$propsNames = [];
		$propsHash	= [];
		foreach ( $propsKeys as $propsKey ){
			$propsHash[ $propsKey ] = true;
		}

		$propHandle	= \CIBlockElement::GetProperty( $iblockId, $itemId, $propsKeys );
		while ( $propArr = $propHandle->Fetch() ){
			$code = $propArr[ 'CODE' ];
			if( ! empty( $propsHash[ $code ] ) ){
				$name = $propArr[ 'NAME' ];
				$propsNames[ $code ] = $name;
			}
		}

		return $propsNames;
	}

	// Function
	// Returns item's properties as a set keyed by argument
	// Takes	:	Int id of item in catalog;
	// 				Array[Str] of properties' keys
	// Returns	:	Array item's properties listed in argument
	private static function getItemPropsByKeys( $iblockId, $itemId, $propsKeys ){
		$props = [];
		list( $getListProps, $getListPropsSuffixed ) = [ [], [], ];
		foreach( $propsKeys as $propCode ){
			$propCodePrefixed = "PROPERTY_$propCode";
			$propCodeSuffixed = "PROPERTY_$propCode" . "_VALUE";

			$getListProps[]			= $propCodePrefixed;
			$getListPropsSuffixed[]	= $propCodeSuffixed;
		}
		$propsNames = self::getPropsNames( $iblockId, $itemId, $propsKeys );

		$propsHandle = \CIBlockElement::GetList( [], [ 'IBLOCK_ID' => $iblockId,
				'ID' => $itemId,
			], false, false, $getListProps
		);
		while( $propArr = $propsHandle->Fetch() ){
			$propKeys = array_keys( $propArr );
			$propKeysNeeded = array_intersect( $propKeys, $getListPropsSuffixed );
			foreach( $propKeysNeeded as $key ){
				$code = preg_replace( '/^PROPERTY_(.+)_VALUE$/', '$1', $key );
				$props[] = [ 'NAME' => $propsNames[ $code ], 'VALUE' => $propArr[ $key ],
					'CODE' => $code,
				];
			}
		}
		return $props;
	}

	// Function
	// Returns item's properties as a set keyed by the one of iblock's 'user
	// field's
	// Takes	:	Int id of item in catalog
	// Returns	:	Array item's properties listed in iblock's 'user field'
	private static function getItemProps( $itemId ){
		$iblockId	= self::getIBlockIdByItemId( $itemId );
		$propsKeys	= self::getPropsKeys( $iblockId, $itemId );
		$props 		= self::getItemPropsByKeys( $iblockId, $itemId, $propsKeys );

		return $props;
	}

	// Function
	// Changes array argument to contain item's properties in 'PROPS'
	// taken as a set keyed by the one of iblock's 'user field's
	public static function putItemPropsToBasket ( &$itemArr ){
		$itemId		= $itemArr[ 'PRODUCT_ID' ];
		$propsArr	= self::getItemProps( $itemId );

		$itemArr[ 'PROPS' ] = array_merge( $itemArr[ 'PROPS' ], $propsArr );
	}

	// requires the 'Bitrix\Sale\AdminPage\AjaxProcessor' class
	// to find and display basket variables
	function requireAdminOrderAjax(){
		global $APPLICATION;

		$orderAjaxArr = file( $_SERVER[ 'DOCUMENT_ROOT' ] . '/bitrix/modules/sale/admin/order_ajax.php' );
		$orderAjaxStr = array_shift( $orderAjaxArr );
		$requestFound = false;
		$dieFound = false;
		$orderAjaxStr = '';
		foreach( $orderAjaxArr as $str ){
			if( $requestFound ){
				if( ! $dieFound ){
					$dieFound = ( false !== strpos( $str, 'die();' ) );
					if( $dieFound ) { continue; }
				}
			} else {

				// request not found yet
				$requestFound = ( false !== strpos( $str, '$_REQUEST' ) );
			}

			// Every variable is known for simple decision to make
			if( $dieFound || ( ! $requestFound ) ){
				$orderAjaxStr = $orderAjaxStr . $str;
			}
		}

		try {
			eval( $orderAjaxStr );
		} catch (Exception $exception) {
			throw new InternalServerErrorHttpException($APPLICATION->LAST_ERROR);
		}
	}

	// Gets fake 'request' object for 'addProductToBasket' based on
	// basket items stored
	function getRequestFromItems( $items ){

		// $items assumed as non-empty
		$firstItem = array_pop( $items );
		$productId	= $firstItem[ 'PRODUCT_ID'	];
		$quantity	= $firstItem[ 'QUANTITY'	];

		$request = [
		  'action' => 'addProductToBasket',
		  'productId' => $productId,
		  'quantity' => $quantity,
		  'formData' =>
		  [
			'SITE_ID' => 's1',
			'PRODUCT' => [],
		  ],
		  'refreshOrderData' => 'Y',
		];

		for ( $i = 0; $i < count(  $items ); $i ++ ){
			$item = $items[ $i ];
			$offerId	= $item[ 'PRODUCT_ID'	];
			$quantity	= $item[ 'QUANTITY'		];

			$productArrKey = 'n' . ( $i + 2 );
			$productArrVal = [
				'PRODUCT_PROVIDER_CLASS' => '\\Bitrix\\Catalog\\Product\\CatalogProvider',
				'MODULE' => 'catalog', 'OFFER_ID' => $offerId, 'QUANTITY' => $quantity,
			];

			$request[ 'formData' ][ 'PRODUCT' ][ $productArrKey ] = $productArrVal;
		}

			return $request;
	}

	// Take result of basket save on server (e. g., without discounts)
	// and turn it into a result to display (e. g., with discounts )
	function getBasketArr(){
		global $USER, $APPLICATION;
		self::requireAdminOrderAjax();
		$result = [];

		$items =  $this->_read();

		if( ! empty( $items ) ){

			$request = self::getRequestFromItems( $items );

			// Simulate addition of goods to client-side basket of admin
			$processor = new \Bitrix\Sale\AdminPage\AjaxProcessor($USER->GetID(), $request );
			$result = $processor->processRequest();
			if ( ! $result->isSuccess() ){
				throw new InternalServerErrorHttpException($APPLICATION->LAST_ERROR);
			}

			$result = $result->get( 'ORDER_DATA' );
		}

		$basket = $result;
		return $basket;
	}
}
