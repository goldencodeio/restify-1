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
							ID		=> $value,
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

		$items_new = [];
		foreach ( $items as $item ){
			$itemId = $item[ 'ID' ];
			$item[ 'PRICE' ] = \CCatalogProduct::GetOptimalPrice( $itemId );
			$items_new[] = $item;
		}
		$items = $items_new;

		$results = $items;
		return $results;

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
		$rv = [];
		$cacheKey = IblockElementRestCache::findCacheKey( $cacheKeyArr );
		$cache = IblockElementRestCache::getCache();


		$foundInCache = $cache->read( 3600, $cacheKey ); // $cache->getVars();
		if( false !== $foundInCache ){ // isset( $vars[ $cacheKey ] ) ){

			// Found in cache
			$vars = $cache->get( $cacheKey );
			if( ! empty( $vars[ 'data' ] ) ){ $vars = $vars['data']; } else { $vars = []; }
			$rv = $vars; // [ $cacheKey ];

		} else {

			// Put to cache
				$vars = $cb();

				$cache->set( $cacheKey, [ 'data' => $vars, ] );
				$cache->finalize();
				$rv = $vars;

		}

		return $rv;
	}

	// Add particular request to cache keys known to contain certain product ID
	// to be found and deleted from cache on products' update/delete
	private function putRequestForItem( $requestKeys, $requestCacheKey, $itemId ){
		$cacheKeyArr = [ 'Catalog', (int) $itemId ];
		$cacheKey = IblockElementRestCache::findCacheKey( $cacheKeyArr );

		$cache = IblockElementRestCache::getCache();
		$requestKeys[ $requestCacheKey ] = 1;
		$cache->set( $cacheKey, [ 'data' => $requestKeys, ] );
		$cache->finalize();
	}


	// Save cache keys known to contain certain product IDs
	// to be found and deleted from cache on products' update/delete
	private function putRequestsForItems( $requestCacheKeyArr, $items ){
		$itemIds = [];

		foreach( $items as $item ){
			$itemIds[] = $item[ 'ID' ];
		}

		$requestCacheKey = IblockElementRestCache::findCacheKey( $requestCacheKeyArr );
		foreach( $itemIds as $itemId ){
			$cacheKey = [ 'Catalog', (int) $itemId ];
			$requestKeys = $this->tryCacheThenCall( $cacheKey, function() use( $requestCacheKey ) {
				return [ $requestCacheKey => 1 ];
			} );
			if( $requestKeys !== [ $requestCacheKey => 1 ] ){
				if( empty( $requestKeys[ $requestCacheKey ] ) ){
					$this->putRequestForItem( $requestKeys, $requestCacheKey, $itemId );
				}
			}
		}
	}

	// Get CAN_BUY_ZERO and other properties missing from GetOptimalPrice()
	private function getPropsMissingFromOptimal( $items ){
		$itemsNew = [];
		foreach ( $items as $item ){

		$priceIds = [];
		if( !empty( $item[ 'OFFERS' ] ) ){

			$offers = $item[ 'OFFERS' ];
			$offersNew = [];
			$offersHash = [];
			foreach( $offers as $offer ){
				$offerId = $offer[ 'ID' ];

				// write to $offersHash and $priceIds
				$offersHash[ $offerId ] = $offer;
				if( !empty( $offer[ 'PRICE' ][ 'PRICE' ] ) ){
					$priceId = $offer[ 'PRICE' ][ 'PRICE' ][ 'ID' ];
					$priceIds[] = $priceId;
				}
			}

			$query = new \Bitrix\Main\Entity\Query(\Bitrix\Catalog\PriceTable::getEntity());
			$query->registerRuntimeField( 'b_catalog_product', [
					'data_type' =>  \Bitrix\Catalog\ProductTable::getEntity(),
					'reference' => [
						'=this.PRODUCT_ID' => 'ref.ID',
					],
					'join_type' => 'INNER',
				]
			);
			$query->setSelect( [ 'ID', 'QUANTITY_FROM', 'QUANTITY_TO', 'PRODUCT_ID',
				'PRODUCT_CAN_BUY_ZERO'			=> 'b_catalog_product.CAN_BUY_ZERO',
				'PRODUCT_NEGATIVE_AMOUNT_TRACE' => 'b_catalog_product.NEGATIVE_AMOUNT_TRACE',
				'PRODUCT_QUANTITY'				=> 'b_catalog_product.QUANTITY',
			] );
			$query->setFilter( [
				'@ID' => $priceIds,
			] );

			// Query some fields from database for every price found
			$sth = $query->exec();
			while( $priceArr = $sth->fetch() ){
				$offerId = $priceArr[ 'PRODUCT_ID' ];

				$priceArr['CAN_BUY'] = $priceArr['PRODUCT_CAN_BUY_ZERO'] === 'Y' ||
					$priceArr['PRODUCT_NEGATIVE_AMOUNT_TRACE'] === 'Y' ||
					(int) $priceArr['PRODUCT_QUANTITY'] > 0
				;

				// Find offer and put propertires in place for it
				$offer = $offersHash[ $offerId ];
				$offer[ 'PRICE' ][ 'PRICE' ] = array_merge( $priceArr, $offer[ 'PRICE' ][ 'PRICE' ] );
				$offersNew[] = $offer;
			}
			$item[ 'OFFERS' ] = $offersNew;

		}
		$itemsNew[] = $item;
		}
		return $itemsNew;
	}

	public function readMany() {

		// Find in cache first
		$cacheKey = [ 'call'	=> 'readMany',		'order' 	=> $this->order,
					'filter'	=> $this->filter,	'navParams' => $this->navParams,
		];
		$items = $this->tryCacheThenCall( $cacheKey, function() {

			// Take values from GetProperty() then from GetList()
			list( $propName, $propValue ) = $this->getPropNamesValues();
			$items = $this->getListing( $propName, $propValue );
			$itemsNew = self::getOffers( $items );
			$items = $itemsNew;
			$itemsNew = self::getPropsMissingFromOptimal( $items );

			$results = $itemsNew;
			return $results;
		} );

		// To clear on update/delete
		$this->putRequestsForItems( $cacheKey, $items );

		return $items;
	}

	// For the items known as having offers, take their data and out into
	// item
	private function getOffers( $items ){
		$iblockId	= $items[0][ 'IBLOCK_ID' ]; // iblock is the same for every item
		$offerArrs	= [];
		$itemIds	= [];
		$itemsHash	= [];

		// 'offers' iblock info - ID and property ID
		$offerIBlockInfo = \CCatalogSKU::GetInfoByProductIBlock( $iblockId );
		if( empty( $offerIBlockInfo ) ){
			return $items;
		}
		list( $offerIBlockId, $offerPropId ) = [ $offerIBlockInfo[ 'IBLOCK_ID' ],
			$offerIBlockInfo[ 'SKU_PROPERTY_ID' ],
		];

		foreach( $items as $item ){
			$id = $item[ 'ID' ];

			// Array of IDs and a Hash to find by IDs taken from offers
			$itemIds[]				= $id;
			$item[ 'OFFERS_EXIST' ] = false;
			$itemsHash [ $id ]		= $item;
		}

		// offers info with property Id
		$offerHandle = CIBlockElement::GetList( [], [
				'IBLOCK_ID' => $offerIBlockId, 'ACTIVE'=>'Y',
				"PROPERTY_$offerPropId" => $itemIds,
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
				$offerPropsArr_new = array_filter( $offerPropsArr, function( $propValue ){

					// Omit null values, empty arrays, empty strings, pass integer 0s
					if( is_array( $propValue ) ){
						$propIsNeeded = ! empty( $propValue );
					} else {
						$propIsNeeded = ( 0 < strlen( $propValue ) );
					}

					return $propIsNeeded;
				} );
				$offerPropsArr = $offerPropsArr_new;

				// 'CML2_LINK' is the linked product Id
				if( 'CML2_LINK' == $offerPropsArr[ 'CODE' ] ){
					$offerArr[ 'PRODUCT_ID' ] = $offerPropsArr[ 'VALUE' ];
				} elseif( ! empty( $offerPropsArr ) ) {
					$offerPropsArrs[] = $offerPropsArr;
				}
			}
			if ( ! empty( $offerPropsArrs ) ){
				$offerArr[ 'PROPS' ] = $offerPropsArrs;
			}

			$priceArr = \CCatalogProduct::GetOptimalPrice( $offerId );
			$offerArr[ 'PRICE' ] = $priceArr;

			$offerArrs[] = $offerArr;
		}

		foreach( $offerArrs as $offerArr ){
			if( ! empty( $offerArr[ 'PRODUCT_ID' ] ) ){
				$itemId = $offerArr[ 'PRODUCT_ID' ];
				if ( ! empty( $itemsHash[ $itemId ] ) ){
					$item = $itemsHash[ $itemId ];
					if ( ! empty( $item[ 'OFFERS' ] ) ){

						// property info in 'PROPS', and price info in 'PRICE'
						$item[ 'OFFERS' ][] = $offerArr;
					} else {
						$item[ 'OFFERS' ]		= [ $offerArr ];
						$item[ 'OFFERS_EXIST' ]	= true;
					}
					$itemsHash[ $itemId ] = $item;
				}
			}
		}

		$items_new = [];
		foreach( $itemIds as $itemId ){ $item = $itemsHash[ $itemId ];
			$items_new[] = $item;
		}

		$results = $items_new;
		return $results;
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
		$cacheKey = [ 'call' => 'count', 'filter' => $this->filter, ];

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

