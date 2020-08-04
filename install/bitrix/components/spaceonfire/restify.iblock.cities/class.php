<?php

namespace spaceonfire\Restify;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use spaceonfire\Restify\Executors\IblockCitiesRest;

if (!Loader::includeModule('spaceonfire.restify')) return false;

Loc::loadLanguageFile(__FILE__);

class RestifyIblockCitiesComponent extends RouterComponent {
	public function executeComponent() {
		$executor = new IblockCitiesRest($this->arParams);
		$this->setExecutor($executor);
		parent::executeComponent();
	}
}
