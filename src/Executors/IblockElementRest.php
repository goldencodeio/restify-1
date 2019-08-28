<?php

namespace spaceonfire\Restify\Executors;

use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use CCatalog;
use CIBlock;
use CIBlockElement;
use CIBlockFindTools;
use CPrice;
use Emonkak\HttpException\AccessDeniedHttpException;
use Emonkak\HttpException\BadRequestHttpException;
use Emonkak\HttpException\InternalServerErrorHttpException;
use Emonkak\HttpException\NotFoundHttpException;
use goldencode\Helpers\Bitrix\IblockUtility;
use spaceonfire\Restify\Executors\IblockElementRestCache;
use Exception;

// CONSTANTS
define( 'IBLOCKELEMENTREST_LISTING_CODES', [
	'ACTIVE', 'BASE', 'BASE_PRICE', 'CAN_BUY', 'CATALOG_GROUP_ID',
	'CATALOG_GROUP_NAME', 'CODE', 'CONTENT_TYPE', 'CURRENCY', 'DATE_CREATE',
	'DESCRIPTION', 'DETAIL_PICTURE', 'DETAIL_TEXT', 'DETAIL_TEXT_TYPE',
	'ELEMENT_IBLOCK_ID', 'EXTRA_ID', 'FILE_SIZE', 'HEIGHT', 'IBLOCK_ID',
	'IBLOCK_SECTION', 'IBLOCK_SECTION_ID', 'ID', 'IS_IN_COMPARE', 'NAME',
	'ORIGINAL_NAME', 'PREVIEW_PICTURE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE',
	'PRICE', 'PRODUCT_CAN_BUY_ZERO', 'PRODUCT_ID',
	'PRODUCT_NEGATIVE_AMOUNT_TRACE', 'PRODUCT_QUANTITY',
	'PRODUCT_QUANTITY_TRACE', 'PRODUCT_WEIGHT', 'PROPERTY_PHOTOS_DESCRIPTION',
	'QUANTITY_FROM', 'QUANTITY_TO', 'SRC', 'TIMESTAMP_X', 'TMP_ID', 'WIDTH',
	'XML_ID',
] );

//SUBS

class IblockElementRest implements IExecutor {
	use RestTrait {
		prepareQuery as private _prepareQuery;
		buildSchema as private _buildSchema;
	}

	protected $iblockId;
	protected $elementId;
	protected $prices = [];
	public $propName = [];

	private $catalog = false;
	private $permissions = [];
	private $entity = 'Bitrix\Iblock\ElementTable';

	/**
	 * IblockElementRest constructor
	 * @param array $options executor options
	 * @throws \Bitrix\Main\LoaderException
	 * @throws InternalServerErrorHttpException
	 */
	public function __construct($options) {
		$this->loadModules('iblock');

		if (!$options['iblockId']) {
			throw new InternalServerErrorHttpException(Loc::getMessage('REQUIRED_PROPERTY', [
				'#PROPERTY#' => 'iblockId',
			]));
		}

		$this->checkEntity();
		$this->setSelectFieldsFromEntityClass();
		$this->setPropertiesFromArray($options);

		// Support catalog
		if ($this->loadModules('catalog', false)) {
			$this->catalog = (bool) CCatalog::GetByID($this->iblockId);
			if ($this->catalog) {
				$this->registerCatalogTransform();
			}
		}

		$this->registerBasicTransformHandler();
		$this->registerPermissionsCheck();
		$this->buildSchema();

	}

	/**
	 * @throws Exception
	 */
	private function buildSchema() {
		$this->_buildSchema();

		$schema = $this->get('schema');

		$schema['PREVIEW_PICTURE'] = 'file';
		$schema['DETAIL_PICTURE'] = 'file';

		// TODO: cache dependently from iblock
		$propsQ = CIBlock::GetProperties($this->iblockId, [], ['ACTIVE' => 'Y']);
		while ($prop = $propsQ->Fetch()) {
			$type = strtolower($prop['USER_TYPE'] ?: $prop['PROPERTY_TYPE']);
			switch ($type) {
				case 'f': $type = 'file'; break;
				case 's': $type = 'string'; break;
				case 'n': $type = 'number'; break;
				case 'e': $type = 'element'; break;
				case 'elist': $type = 'elementlist'; break;
				case 'eautocomplete': $type = 'elementautocomplete'; break;
			}
			$schema['PROPERTY_' . $prop['CODE']] = $type;
		}

		$this->set('schema', $schema);
	}

	public function create() {
		$el = new CIBlockElement;
		$id = $el->Add($this->body);

		if (!$id) {
			throw new BadRequestHttpException($el->LAST_ERROR);
		}

		return $this->readOne($id);
	}

	// Object method
	// Gets iBlock's  properties with GetProperty()
	// Takes	: n/a
	// Changes	: $this->select
	// Returns	: [
	// 					Hash[Str] of properties' codes and names,
	// 					Hash[Str] of properties codes and values
	//				]
	private function getPropNamesValues(){
		$propName = [];
		$propValue = [];
		$isMultiple = false;
		$iBlockId = IblockUtility::getIblockIdByCode('catalog');

		// query properties with GetProperty()
		$rsObject = CIBlockElement::GetProperty(
			$iBlockId,
			$this->$elementId,
			array(), array()
		);
		while($arObject = $rsObject->Fetch()) {

			// fetch and normalize properties from GetProperty()
			list( $code, $name, $value, $linkIblockId ) =
				array_map( function ( $key ) use ( $arObject ) {
					return $arObject[ $key ];
				}, [
					'CODE', 'NAME', 'VALUE', 'LINK_IBLOCK_ID'
				])
			;
			$code = strtoupper( $code );
			$value = ! empty( $arObject['VALUE_ENUM'] )
				? $arObject[ 'VALUE_ENUM' ]
				: $arObject[ 'VALUE' ]
			;
			$isMultiple = ( 'Y' === $arObject[ 'MULTIPLE' ] );

			// Non-empty arrays, strings, not 'null's ( but '0's )
			if( ( ! empty( $value ) )
				||
				(  $value === 0 )
			){

				// Assign properties
				$propName[ $code ] = $name;
				array_push($this->select, $code);

				if ( ( $linkIblockId != 0 )
						&&
						( $linkIblockId != $iBlockId )

					){
					$linkNameValue = $isMultiple

						// Remind latest asignment for multiple linked properties, e. g., tags
						?( 	! is_null( $propValue[ $code ] )
							?	$propValue[ $code ]
							:	[]
						) : ''
					;

					// Take values from property-linked iBlock
					// Element's 'ID' in the linked iBlock is the item's
					// value
					$linkedElementResultHandle = CIBlockElement::GetList( [],
						[ IBLOCK_ID => $linkIblockId,
						  ID		=> $value
					  ]
					);
					while($linkObject = $linkedElementResultHandle->Fetch()) {
						$linkObjectName = $linkObject[ 'NAME' ];

						if( ! is_null( $linkObjectName ) ){
							if( $isMultiple ){
								$linkNameValue[] = $linkObjectName;
							} else {
								$linkNameValue = $linkObjectName;
							}
						}
					}

					$propValue[ $code ] = $linkNameValue;
				} else {

				// Take values from 'catalog' iBlock
				if( $isMultiple ){
					if ( ! is_null($propValue[ $code ] ) ) {
						$propValue[ $code ][] = $value;
					} else {
						$propValue[ $code ] = [ $value ];
					}
				} else { // $isMultiple
					$propValue[ $code ] =  $value ;
				}

				}

			}
		} // GetProperty()

		return [ $propName, $propValue ];
	}

	// Static method
	// Takes	: Array
	// Retuurns	: Array without empty/null values
	function deleteEmpties( $item ){
		$item = array_map('array_filter', $item);
		$item = array_filter( $item, function(  ){} );

		return $item;
	}

	// Function
	// Gets 'OFFERS_EXIST' property for items
	// This takes info from 'CML_*' field of 'sku's/'offer's table
	// Takes	: Array[Hash[Any]] items ready for output without	'OFFERS_EXIST' key
	// Throws	: NotFoundHttpException if non-existent items supplied as an argument
	// Returns	: Array[Hash[Any]] items ready for output with		'OFFERS_EXIST' key
	private function getOffersExists( &$items ) {
		$items_new	= [];
		$itemIds 	= [];

		foreach( $items as $item ){
			$id = $item[ 'ID' ];

			// Array of IDs
			$itemIds[] = $id;
		}

		$offersExists = \CCatalogSKU::getExistOffers($itemIds);

		if( false === $offersExists ){

			// Wrong arguments
			throw new NotFoundHttpException();
		}

		foreach( $items as $item ){
			$id = $item[ 'ID' ];
			$offersExist = $offersExists[ $id ];

			// Put value to output
			$item[ 'OFFERS_EXIST' ] = $offersExist;

			$items_new[] = $item;
		}

		return $items_new;
	}

	// Object method
	// Gets iBlock's  properties with GetList()
	// Takes	: Hash[Str] of properties' codes and their names,
	// Returns	: Hash[ Str or Hash[Str] ]
	// 			  where keys are properties' codes surrounded with
	// 			  'PROPERTY_'  and '_VALUE',
	// 			  and values are Str some of properties values taken
	// 			  from $propValue argument,
	// 			  or arrays or anything returned by iBlock's GetList()
	private function getListing( $propName, $propValue  ){

		$results = [];
		$chunk_size = 50; // balance performance versus memory_limit fit
		$items = [];

		$listingCodesChunks = array_chunk( IBLOCKELEMENTREST_LISTING_CODES, $chunk_size );

		foreach( $listingCodesChunks as $listingCodesChunk ){
			$listingCodesChunk[] = 'ID';

			$query = CIBlockElement::GetList(
				$this->order,
				$this->filter,
				false,
				$this->navParams,
				$listingCodesChunk
			);

			while ($item = $query->GetNext(true, false)) {
				$itemId = $item[ 'ID' ];

				// $item = $this->deleteEmpties( $item );
				if ( empty( $items[ $itemId ] ) ) {
					$items[ $itemId ] = $item;
				} else {
					$items[ $itemId ] = array_merge( $items[ $itemId ], $item );
				}
			}
		}

		foreach ( $items as $itemId => $item ){
				foreach($propName as $code => $name){

					// Find stuff from GetProperty()
					$value = $propValue[ $code ];
					$itemKey = join( '_', [ 'PROPERTY', $code, 'VALUE' ] );

					// Assign normalized to output
					$item[$itemKey] = [ "NAME" => $name, "VALUE" => $value ];
				}

			$items[ $itemId ] = $item;
		}

		$items_new = [];
		foreach ( $items as $item ){
			if( ! empty( $item['NAME' ]	) ) $items_new[] = $item;
		}
		$items = $items_new;

		$items_new = &self::getOffersExists( $items );
		$items = $items_new;

		$results = $items;
		return $results;

	}

	/* Method
	 *
	 * Initialize cache for scalar key given
	 * Takes:	Str key of cache
	 * Returns:	Cache object
	 */
	function getCache( $cacheKey ){
		$cache = IblockElementRestCache::createInstance();
		$cache->initCache(3600, "IblockElementRest.$cacheKey");

		return $cache;
	}

	/* Object method
	 *
	 * Tries to find variable in cache, calls callback supplied if none was
	 *
	 * Takes	: Arr[any] key of cache to find variable in,
	 * 			  Function to call if no variable found in cache
	 * Returns	: Variable from cache or from function
	 */
	private function tryCacheThenCall( $cacheKeyArr, $cb ){
		$cacheKey = findCacheKey( $cacheKeyArr );
		$cache = $this->getCache( $cacheKey );

		$vars = $cache->getVars();
		$rv = [];
		if( isset( $vars[ $cacheKey ] ) ){

			// Found in cache
			$rv = $vars[ $cacheKey ];
		} else {

			// startDataCache() is re-implemented without returning false here
			$cacheStartRv = $cache->startDataCache();
			if( $cacheStartRv ){

				// Found in database put in cache
				$rv = $cb();
				$cache->endDataCache( [ $cacheKey =>  $rv, ] );
			}
		}

		return $rv;
	}

	public function readMany() {

		// Find in cache first
		$results = $this->tryCacheThenCall( [
			'call'		=> 'readMany',
			'order'		=> $this->order,
			'filter'	=> $this->filter,
			'navParams'	=> $this->navParams,
		], function() {

			// Take values from GetProperty() then from GetList()
			list( $propName, $propValue ) = $this->getPropNamesValues();
			return $this->getListing( $propName, $propValue );
		} );

		return $results;
	}

	// For the item known as having offers, take their data and out into
	// item
	private function getOffers( $item ){
		$itemId = $item[ 'ID' ];
		$iblockId = $item[ 'IBLOCK_ID' ];
		$offerArrs = [];

		// 'offers' iblock info - ID and property ID
		$offerIBlockInfo = \CCatalogSKU::GetInfoByProductIBlock( $iblockId );
		if( empty( $offerIBlockInfo ) ){
			throw new NotFoundHttpException();
		}
		list( $offerIBlockId, $offerPropId ) = [ $offerIBlockInfo[ 'IBLOCK_ID' ],
			$offerIBlockInfo[ 'SKU_PROPERTY_ID' ],
		];

		// offers info with property Id
		$offerHandle = CIBlockElement::GetList( [], [
				'IBLOCK_ID' => $offerIBlockId, 'ACTIVE'=>'Y',
				"PROPERTY_$offerPropId" => $itemId,
			], false, false, [
				'ID', 'NAME', "PROPERTY_$offerPropId",
			]
		);
		while( $offerArr = $offerHandle->GetNext() ){
			$offerId = $offerArr[ 'ID' ];

			// offer property info - name, value
			$offerPropsHandle = CIBlockElement::GetProperty( $offerIBlockId, $offerId );
			$offerPropsArrs = [];
			while( $offerPropsArr = $offerPropsHandle->Fetch() ){

				// 'CML2_LINK' is the linked product Id
				if( 'CML2_LINK' != $offerPropsArr[ 'CODE' ] ){
					$offerPropsArrs[] = $offerPropsArr;
				}
			}
			$offerArr[ 'PROPS' ] = $offerPropsArrs;

			$priceArr =  GetCatalogProductPrice( $offerId, 1 );
			$offerArr[ 'PRICE' ] = $priceArr;

			$offerArrs[] = $offerArr;
		}
		if( ! empty( $offerArrs ) ){

			// property info in 'PROPS', and price info in 'PRICE'
			$item[ 'OFFERS' ] = $offerArrs;
		}

		return $item;
	}

	public function readOne($id) {
		$this->registerOneItemTransformHandler();
		$this->$elementId = $id;

		// Set id to filter
		if (is_numeric($id)) {
			$this->filter['ID'] = $id;
		} else {
			$this->filter['CODE'] = $id;
		}

		// Get only one item
		$this->navParams = ['nPageSize' => 1];

		$results = $this->readMany();

		if (!count($results)) {
			throw new NotFoundHttpException();
		}

		if( !empty( $results[ 0 ][ 'OFFERS_EXIST' ] ) && true ===
				$results[ 0 ][ 'OFFERS_EXIST' ]
			){
			$item = $results[ 0 ];

			// Get offers for item
			$item_new = self::getOffers( $item );
			$results = [ $item_new ];
		}

		return $results;
	}

	public function update($id = null) {
		if (!$id) {
			$id = $this->body['ID'];
		}

		if (!$id) {
			throw new NotFoundHttpException();
		}

		$id = CIBlockFindTools::GetElementID($id, $id, null, null, $this->filter);

		unset($this->body['ID']);
		unset($this->body['IBLOCK_ID']);

		$el = new CIBlockElement;
		if (!$el->Update($id, $this->body)) {
			throw new BadRequestHttpException($el->LAST_ERROR);
		}

		return $this->readOne($id);
	}

	public function delete($id) {
		$this->registerOneItemTransformHandler();

		global $APPLICATION, $DB;
		$DB->StartTransaction();

		try {
			$id = CIBlockFindTools::GetElementID($id, $id, null, null, $this->filter);
			$result = CIBlockElement::Delete($id);
			if (!$result) {
				throw new InternalServerErrorHttpException($APPLICATION->LAST_ERROR);
			}
		} catch (Exception $exception) {
			$DB->Rollback();
			throw $exception;
		}

		$DB->Commit();

		return [
			$this->success(Loc::getMessage('IBLOCK_ELEMENT_DELETE')),
		];
	}

	/* Method
	 * Find count according by filter and cache
	 * Takes	: n/a
	 * Returns	: Int count
	 */
	public function count() {
		$cache = $this->cache;
		$this->select = ['ID'];
		$countFound = 0;

		$this->registerOneItemTransformHandler();

		// Compose cache key from filter values
		$cacheKey = findCacheKey( [
			'call' => 'count',
			'filter' => $this->filter,
		] );

		$countFound = $this->tryCacheThenCall( $cacheKey, function(){
			$query = CIBlockElement::GetList(
				$this->order,
				$this->filter,
				false,
				$this->navParams,
				$this->select
			);
			$countFound = $query->SelectedRowsCount();

			return $countFound;
		});

		return [
			[
				'count' => $countFound,
			],
		];
	}

	public function prepareQuery() {
		$this->_prepareQuery();

		// Delete iblock props from filter
		unset($this->filter['IBLOCK_ID']);
		unset($this->filter['IBLOCK_CODE']);
		unset($this->filter['IBLOCK_SITE_ID']);
		unset($this->filter['IBLOCK_TYPE']);

		// Set IBLOCK_ID filter
		$this->filter['IBLOCK_ID'] = $this->iblockId;

		// Force check permissions
		$this->filter['CHECK_PERMISSIONS'] = 'Y';

		// Force set IBLOCK_ID to body
		if (!empty($this->body)) {
			$this->body['IBLOCK_ID'] = $this->iblockId;
		}

		// Extend select with properties
		if (in_array('*', $this->select)) {
			$this->select = array_merge(
				$this->select,
				array_filter(array_keys($this->get('schema')), function ($path) {
					return strpos($path, 'PROPERTY_') !== false;
				})
			);
		}
	}

	private function registerCatalogTransform() {
		global $SPACEONFIRE_RESTIFY;
		// Register transform
		EventManager::getInstance()->addEventHandler(
			$SPACEONFIRE_RESTIFY->getId(),
			'transform',
			[$this, 'catalogTransform']
		);
	}

	public function catalogTransform(Event $event) {
		$params = $event->getParameters();

		// Skip for count endpoint
		if ($params['params']['METHOD'] === 'count') {
			return;
		}

		foreach ($params['result'] as $key => $item) {
			$item['BASE_PRICE'] = CPrice::GetBasePrice($item['ID']);
			$item['CAN_BUY'] =
				$item['BASE_PRICE']['PRICE'] &&
				(
					$item['BASE_PRICE']['PRODUCT_CAN_BUY_ZERO'] === 'Y' ||
					$item['BASE_PRICE']['PRODUCT_NEGATIVE_AMOUNT_TRACE'] === 'Y' ||
					(int) $item['BASE_PRICE']['PRODUCT_QUANTITY'] > 0
				);

			$prices = array_filter($this->prices, function ($p) use ($item) {
				return $p !== $item['BASE_PRICE']['CATALOG_GROUP_NAME'];
			});

			if (!empty($prices)) {
				$pricesValues = [];
				foreach ($prices as $price) {
					$pricesValues[$price] = CPrice::GetList([], [
						'PRODUCT_ID' => $item['ID'],
						'CATALOG_GROUP_ID' => $pricesValues,
					])->Fetch();
				}
				$item['PRICES'] = $pricesValues;
			}

			if (!$item['BASE_PRICE'] && !$item['PRICE']) {
				unset($item['BASE_PRICE']);
				unset($item['PRICE']);
				unset($item['CAN_BUY']);
			}

			$params['result'][$key] = $item;
		}
	}

	private function registerPermissionsCheck() {
		global $SPACEONFIRE_RESTIFY;
		$events = [
			'pre:create',
			'pre:update',
			'pre:delete',
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

		$this->permissions = CIBlock::GetGroupPermissions($this->iblockId);
		$permissions = $this->permissions;

		$userGroupsPermissions = array_map(function ($group) use ($permissions) {
			return $permissions[$group];
		}, $USER->GetUserGroupArray());

		$canWrite = in_array('W', $userGroupsPermissions) || in_array('X', $userGroupsPermissions);

		if (!$canWrite) {
			throw new AccessDeniedHttpException();
		}
	}
}

