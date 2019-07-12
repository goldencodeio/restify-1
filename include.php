<?php

use spaceonfire\BMF\Module;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/options.php');
Loc::loadMessages(__DIR__ . '/install/index.php');
Loc::loadMessages(__DIR__ . '/options.php');

global $SPACEONFIRE_RESTIFY;
$SPACEONFIRE_RESTIFY = new Module([
	'MODULE_ID' => 'spaceonfire.restify',
	'MODULE_VERSION' => '1.0.0',
	'MODULE_VERSION_DATE' => '2018-10-10',
]);

$SPACEONFIRE_RESTIFY->logger->setAuditTypeId('RESTIFY');

$SPACEONFIRE_RESTIFY->options->addTabs([
	'default' => [
		'TAB' => Loc::getMessage('DEFAULT_TAB_NAME'),
		'TITLE' => Loc::getMessage('DEFAULT_TAB_TITLE', [
			'#MODULE_NAME#' => Loc::getMessage('RESTIFY_MODULE_NAME'),
		]),
	],
]);

/*
 * Function
 * Sorts array by keys recursively
 * Takes	: Arr
 * Changes	: Arr supplied as argument
 * Returns	: n/a
 */
function sortArray(&$arr){
	ksort($arr);
	foreach ($arr as &$a){
		if(is_array($a)){
			sortArray($a);
		}
	}
}

/*
 * Function
 * Finds cache key for the array supplied
 * Takes	: Arr
 * Returns	: Str unique key for every Array supplied
 */
function findCacheKey($arr){

	// sort array as it may be associative without keys order needed
	sortArray( $arr );

	// serialize
	$key = json_encode( $arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	$key = base64_encode( gzcompress( $key ) );

	return $key;
}
