<?php

/* IblockElementRestCache - re-implement start of writing of variables into cache
 *
 * ABSTRACT: Work around re-init and returning false
 *
 * This make it possible to (try) read from cache before start writing
 * to it. At the moment the cache it initialized, the parent's
 * startDataCache() function re-initializes cache with initCache() and
 * returns false. Nothing is cached thereafter if your code treats the
 * return vaue as the condition of writing to cache
 *
 * To be placed as: 'src/Executors/IblockElementRestCache.php'
 */

namespace spaceonfire\Restify\Executors;

use \Bitrix\Main\Data\Cache;

class IblockElementRestCache extends Cache {

	public function startDataCache($TTL = false, $uniqueString = false, $initDir = false, $vars = array(), $baseDir = "cache")
	{
		$narg = func_num_args();
		if($narg<=0)
			$TTL = $this->TTL;
		if($narg<=1)
			$uniqueString = $this->uniqueString;
		if($narg<=2)
			$initDir = $this->initDir;
		if($narg<=3)
			$vars = $this->vars;

		if ($TTL <= 0)
			return true;

		// No cache re-init and no return false here

		ob_start();
		$this->vars = $vars;
		$this->isStarted = true;

		return true;
	}

	/*
	 * Function
	 * Sorts array by keys recursively
	 * Takes	: Arr
	 * Changes	: Arr supplied as argument
	 * Returns	: n/a
	 */
	private function sortArray(&$arr){
		ksort($arr);
		foreach ($arr as &$a){
			if(is_array($a)){
				self::sortArray($a);
			}
		}
	}

	/*
	 * Function
	 * Finds cache key for the array supplied
	 * Takes	: Arr
	 * Returns	: Str unique key for every Array supplied
	 */
	public function findCacheKey($arr){

		// sort array as it may be associative without keys order needed
		self::sortArray( $arr );

		// serialize
		$key = json_encode( $arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		$key = base64_encode( gzcompress( $key ) );

		return $key;
	}

	/* Function
	 *
	 * Initialize cache for scalar key given
	 * Takes:	Str key of cache
	 * Returns:	Cache object
	 */
	public function getCache(){
		// $cache = IblockElementRestCache::createInstance();
		// $cache->initCache(3600, "IblockElementRest.$cacheKey");
		$app = \Bitrix\Main\Application::getInstance();

		$cache = $app->getManagedCache();

		return $cache;
	}

}
