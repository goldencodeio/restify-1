<?php

namespace spaceonfire\Restify\Executors;

use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use CIBlock;
use CIBlockElement;
use CIBlockFindTools;
use CIBlockSection;
use CPrice;
use Emonkak\HttpException\AccessDeniedHttpException;
use Emonkak\HttpException\BadRequestHttpException;
use Emonkak\HttpException\InternalServerErrorHttpException;
use Emonkak\HttpException\NotFoundHttpException;
use goldencode\Helpers\Bitrix\IblockUtility;
use spaceonfire\Restify\Executors\IblockElementRestCache;
use Exception;

// CONSTANTS

//SUBS

class IblockCitiesRest implements IExecutor {
	use RestTrait {
		prepareQuery as private _prepareQuery;
		buildSchema as private _buildSchema;
	}

	protected $iblockId;
	protected $prices = [];
	public $propName = [];

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

	public function create() {
		$el = new CIBlockElement;
		$id = $el->Add($this->body);

		if (!$id) {
			throw new BadRequestHttpException($el->LAST_ERROR);
		}

		return $this->readOne($id);
	}

	// Static method
	// Takes	: Array
	// Retuurns	: Array without empty/null values
	function deleteEmpties( $item ){
		$item = array_map('array_filter', $item);
		$item = array_filter( $item, function(  ){} );

		return $item;
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
		$cacheKeyArr = [ 'Cities', (int) $itemId ];
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
			$cacheKey = [ 'Cities', (int) $itemId ];
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

	public function readMany() {
		if(
			( ! empty( $this->navParams ) )
				&&
			is_array ( $this->navParams )
		){
			$this->navParams[ 'nPageSize' ] = 20000;
		}

		// Find in cache first
		$cacheKey = [ 'call'	=> 'readMany',		'order' 	=> $this->order,
					'filter'	=> $this->filter,	'navParams' => $this->navParams,
		];
		$items = $this->tryCacheThenCall( $cacheKey, function() {

			// Take values from GetProperty() then from GetList()
			$items = [];

			$query = CIBlockElement::GetList( $this->order, $this->filter,
				false, $this->navParams, $this->select
			);

			while ($item = $query->GetNext(true, false)) {
				$items[] = $item;
			}

			return $items;
		} );

		// To clear on update/delete
		$this->putRequestsForItems( $cacheKey, $items );

		return $items;
	}

	public function readOne($id) {
		$results = [];
		$this->registerOneItemTransformHandler();

		// Set id to filter
		$idInt = ( $id == (string) (int) $id ) ? $id : 0; // '1s*' resolves to '1' otherwise
		$id = CIBlockFindTools::GetElementID($idInt, $id, null, null, $this->filter);
		if( ! empty( $id ) ){

			// Used to get properties
			$this->filter['ID'] = $id;

			// Get only one item
			$this->navParams = ['nPageSize' => 1];

			$results = $this->readMany();

			if (!count($results)) {
				throw new NotFoundHttpException();
			}
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

