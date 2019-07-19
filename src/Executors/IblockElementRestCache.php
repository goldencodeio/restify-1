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

}
