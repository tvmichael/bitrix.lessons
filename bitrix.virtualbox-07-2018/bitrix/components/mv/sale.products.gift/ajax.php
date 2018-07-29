<?
/** @global \CMain $APPLICATION */

$siteId = isset($_REQUEST['siteId']) && is_string($_REQUEST['siteId']) ? $_REQUEST['siteId'] : '';
$siteId = substr(preg_replace('/[^a-z0-9_]/i', '', $siteId), 0, 2);
if (!empty($siteId) && is_string($siteId))
{
	define('SITE_ID', $siteId);
}

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
$request->addFilter(new \Bitrix\Main\Web\PostDecodeFilter);

if (!\Bitrix\Main\Loader::includeModule('iblock'))
	return;

$signer = new \Bitrix\Main\Security\Sign\Signer;
try
{
	$template = $signer->unsign($request->get('template'), 'sale.products.gift');
	$parameters = $signer->unsign($request->get('parameters'), 'sale.products.gift');
}
catch (\Bitrix\Main\Security\Sign\BadSignatureException $e)
{
	die();
}



//-------------------------------------------------
Bitrix\Main\Diag\Debug::writeToFile(array(
    'N' => 'ajax.php',
    //'data'=>unserialize(base64_decode($parameters))
),"","logfile.txt");




$APPLICATION->IncludeComponent(
	'mv:sale.products.gift',
	$template,
	unserialize(base64_decode($parameters)),
	false
);