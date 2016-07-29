<?php
/**
 * Copyright (c) 2014 - 2016. ООО "БАРС - 46" 
 */

IncludeModuleLangFile(__FILE__);

if(class_exists('bars46_nstree')){
    return;
}

class bars46_nstree extends CModule{
	var $MODULE_ID = "bars46.nstree";
	var $MODULE_VERSION;
	var $MODULE_VERSION_DATE;
	var $MODULE_NAME;
	var $MODULE_DESCRIPTION;
	var $MODULE_CSS;
    
    function __construct(){
        $arModuleVersion = array();
        
        include(dirname(__FILE__)."/version.php");
        
        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
        
		$this->MODULE_NAME = GetMessage("BARS46_NS_TREE_INSTALL_NAME");
		$this->MODULE_DESCRIPTION = GetMessage("BARS46_NS_TREE_INSTALL_DESCRIPTION");
		$this->PARTNER_NAME = GetMessage("BARS46_NS_TREE_PARTNER");
		$this->PARTNER_URI = GetMessage("BARS46_NS_TREE_PARTNER_URI");
    }

	function InstallEvents()
	{
	}
	
	function UnInstallEvents()
	{
	}
	
	function InstallFiles($arParams = array())
	{
		return true;
	}

	function UnInstallFiles()
	{
		return true;
	}

	function DoInstall()
	{
		global $DOCUMENT_ROOT, $APPLICATION;
		$this->InstallFiles();
		$this->InstallEvents();
		RegisterModule($this->MODULE_ID);
		$APPLICATION->IncludeAdminFile(GetMessage("BARS46_NS_TREE_INSTALL_MODULE"), dirname(__FILE__)."/step.php");
	}

	function DoUninstall()
	{
		global $DOCUMENT_ROOT, $APPLICATION;
		$this->UnInstallFiles();
		$this->UnInstallEvents();
		UnRegisterModule($this->MODULE_ID);
		$APPLICATION->IncludeAdminFile(GetMessage("BARS46_NS_TREE_UNINSTALL_MODULE"), dirname(__FILE__)."/unstep.php");
	}
}
