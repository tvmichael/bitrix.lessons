<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
    die();

use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;
use Bitrix\Sale;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Delivery\Services\Manager;
use Bitrix\Sale\Discount;
use Bitrix\Sale\DiscountCouponsManager;
use Bitrix\Sale\Order;
use Bitrix\Sale\Fuser;
use Bitrix\Sale\PaySystem;
use \Bitrix\Main;
use \Bitrix\Main\Localization\Loc;
use \Bitrix\Main\Loader;
use \Bitrix\Main\Context;
//
use Bitrix\Main\Diag\Debug;


Class CArhicodeBasketSale extends CBitrixComponent
{
    /**
     * @var \Bitrix\Sale\Order
     */
    public $order;
    public $basket;
    public $context;
    public $userId = NULL;
    public $fUserId = NULL;
    public $ajaxData = NULL;
    public $basketStorage = NULL;
    public $currency = NULL;
    public $calculateBasket;

    protected $errors = [];
    protected $checkSession = false;


    /**
     * arhicodeBasketSale constructor.
     * @param null $component
     * @throws \Bitrix\Main\LoaderException
     */
    function __construct($component = null)
    {
        parent::__construct($component);

        if(!loader::includeModule('sale'))
            $this->errors[] = 'No sale module';

        if(!Loader::includeModule('catalog'))
            $this->errors[] = 'No catalog module';
    }

    /**
     * Підключаємо мовний файл
     */
    public function onIncludeComponentLang()
    {
        Loc::loadMessages(__FILE__);
    }

    /**
     * Метод обрабатывает параметры компонента. Нестатический метод.
     * Родительский метод проходит по всем параметрам переданным в $APPLICATION->IncludeComponent и применяет к ним функцию htmlspecialcharsex.
     * @param $arParams - Массив со значениями необработанных параметров компонента.
     * @return array
     */
    function onPrepareComponentParams($arParams)
    {
        if(isset($arParams['PERSON_TYPE_ID']) && intval($arParams['PERSON_TYPE_ID']) > 0)
            $arParams['PERSON_TYPE_ID'] = intval($arParams['PERSON_TYPE_ID']);
        else
            $arParams['PERSON_TYPE_ID'] = 1;

        $arParams['ACTION_VARIABLE'] = isset($arParams['ACTION_VARIABLE']) ? trim($arParams['ACTION_VARIABLE']) : '';
        if ($arParams['ACTION_VARIABLE'] == '')
        {
            $arParams['ACTION_VARIABLE'] = 'soa-action';
        }

        $arParams['IBLOCK_TYPE_ID'] = (int)$arParams['IBLOCK_TYPE_ID'];

        return parent::onPrepareComponentParams($arParams);
    }

    /**
     * Create new virtual order
     */
    protected function createVirtualOrder()
    {
        global $USER;
        try
        {
            $siteId = Context::getCurrent()->getSite(); // імя сайта
            $this->fUserId = $this->getFuserId();
            $basket = Basket::loadItemsForFUser($this->fUserId, $siteId);

            if(count($basket->getOrderableItems()) == 0) // якщо кошик пустий перехід на головну сторінку сайта, або на вказану в налаштуванні
                LocalRedirect('/personal/cart/'); // TODO - доробити, якщо кошик пустий

            // створюємо віртуальний заказ
            $this->order = Order::create($siteId, $this->fUserId);
            $this->order->setPersonTypeId($this->arParams['PERSON_TYPE_ID']);
            // приєднеємо до заказу поточний кошик
            $this->order->setBasket($basket);

            $this->basket = $this->order->getBasket();
        }
        catch (Exception $e)
        {
            $this->errors[] = $e->getMessage();
        }
    }

    /**
     * Для получения Внутреннего кода владельца корзины,
     * (если нужно сделать заказ с существующей корзиной)
    */
    protected function getFuserId()
    {
        if ($this->fUserId === null)
        {
            $this->fUserId = Fuser::getId();
        }
        return $this->fUserId;
    }

    /**
     * Отримати дані для поточного замовлення
     */
    protected function getOrderProps()
    {
        global $USER;
        try {
            // Дані по поточному кошику покупця
            $this->arResult['ORDER'] = [
                'USER_ID'=>$this->order->getUserId(), // ID користувача(для поточного кошика)
                'ORDER_ID'=>$this->order->getId(), // ID заказа
                'SITE_ID'=>$this->order->getSiteId(), // ID сайта
                'PERSON_TYPE_ID'=>$this->order->getPersonTypeId(), // ID тип покупця

                'PRICE'=>$this->order->getPrice(),// Ціна зі знижкою
                //'DELIVERY_PRICE'=>$this->order->getDeliveryPrice(), // Стоимость доставки
                //'DISCOUNT_PRICE'=>$this->order->getDiscountPrice(), // Размір знижки
                //'SUM_PAID'=>$this->order->getSumPaid(), // Оплаченная сумма
                'CURRENCY'=>$this->order->getCurrency(), // Валюта замовлення
                //'Fields_#Name#_'=>$this->order->getField('PRICE'), // Также любое поле можно получить по имени
                //'AVAILABLE_FIELDS'=>$this->order->getAvailableFields(), // Список доступных полей
            ];

            $this->arResult['BASE_PRICE'] = ceil($this->basket->getBasePrice()); // Цена без учета скидок
            $this->arResult['QUANTITY_LIST'] = $this->basket->getQuantityList(); // возвращает массив "количеств" товаров в корзине
            //$this->arResult['LIST_OF_FORMAT_TEXT'] = $this->basket->getListOfFormatText(); // возвращает корзину в читаемом виде

            // получение объекта корзины и массива товаров в корзине:
            // getBasketItems(); // все товары
            // getOrderableItems(); // только товары, доступные для заказа
            $arr = [];

            // формуємо масив даних по товарах
            foreach ($this->basket->getBasketItems() as $items)
            {
                $arr[$items->getProductId()] = [
                    'ID' => $items->getId(), // ID позиции в корзине
                    'BASKET_CODE' => $items->getBasketCode(), // код корзины
                    'QUANTITY' => $items->getQuantity(), // количество товара
                    'PRODUCT_ID' => $items->getProductId(), // код товара
                    'FUSER_ID' => $items->getFUserId(), // id владельца корзины
                    'CAN_BUY' => $items->canBuy(), // товар доступен для покупки
                    'CURRENCY' => $items->getCurrency(), // код валюты
                    'FINAL_PRICE' => $items->getFinalPrice(), // стоимость всех единиц позиции товара
                    'PRICE' => $items->getPrice(), // цена с учетом скидок
                    'FORMAT_PRICE' => CurrencyFormat($items->getPrice(), $items->getCurrency()),
                    'BASE_PRICE' => ceil($items->getBasePrice()), // цена без учета скидок
                    'FORMAT_BASE_PRICE' => CurrencyFormat($items->getBasePrice(), $items->getCurrency()),
                    'DEFAULT_PRICE' => $items->getDefaultPrice(), // цена по умолчанию
                    'DISCOUNT_PRICE' => $items->getDiscountPrice(), // величина скидки
                    //'CUSTOMP_RICE' => $items->isCustomPrice(), // цена указана вручную (без использования провайдера)
                    //'AVAILABLE_FIELDS' => $items->getAvailableFields(), //  массив кодов всех полей
                    'PROPS' => $this->getProductDataList($this->arParams['IBLOCK_TYPE_ID'], $items->getProductId()),
                ];
            }
            $this->arResult['PRODUCT_LIST'] = $arr;
            unset($arr);

            //$this->arResult['PAYMENT_SYSTEM_ORDER'] = $this->order->getPaymentSystemId(); // массив id способів оплат (в поточному замовленні)
            //$this->arResult['DELIVERY_SYSTEM_ORDER'] = $this->order->getDeliverySystemId(); // массив id способів доставки (в поточному замовленні)

            $this->arResult['USER_INFO'] =  $this->getUserInfo();

            //$this->arResult['DISCOUNT_APPLY_ORDER'] = $this->order->getDiscount()->getApplyResult(); // список застосованих до замавлення знижок
            //$this->arResult['DISCOUNT_APPLY_ORDER'] = $this->arResult['DISCOUNT_APPLY_ORDER']['PRICES'];

        } catch (Exception $e) {
            $this->errors[] = ['message'=>'getOrderProps', 'error'=>$e->getMessage()];
        }
    }

    /**
     * Set basket items data from order object to $this->arResult
     */
    protected function obtainBasket()
    {
        $arResult =& $this->arResult;

        //$arResult["MAX_DIMENSIONS"] = $arResult["ITEMS_DIMENSIONS"] = array();
        $arResult["BASKET_ITEMS"] = array();

        $this->calculateBasket = $this->order->getBasket()->createClone();

        $discounts = $this->order->getDiscount();
        $showPrices = $discounts->getShowPrices();
        if (!empty($showPrices['BASKET']))
        {
            foreach ($showPrices['BASKET'] as $basketCode => $data)
            {
                $basketItem = $this->calculateBasket->getItemByBasketCode($basketCode);
                if ($basketItem instanceof Sale\BasketItemBase)
                {
                    $basketItem->setFieldNoDemand('BASE_PRICE', $data['SHOW_BASE_PRICE']);
                    $basketItem->setFieldNoDemand('PRICE', $data['SHOW_PRICE']);
                    $basketItem->setFieldNoDemand('DISCOUNT_PRICE', $data['SHOW_DISCOUNT']);
                }
            }
        }
        unset($showPrices);

        /** @var Sale\BasketItem $basketItem */
        foreach ($this->calculateBasket as $basketItem)
        {
            $arBasketItem = $basketItem->getFieldValues();
            if ($basketItem->getVatRate() > 0)
            {
                $arResult["bUsingVat"] = "Y";
                $arBasketItem["VAT_VALUE"] = $basketItem->getVat();
            }
            $arBasketItem["QUANTITY"] = $basketItem->getQuantity();
            $arBasketItem["PRICE_FORMATED"] = SaleFormatCurrency($basketItem->getPrice(), $this->order->getCurrency());
            $arBasketItem["WEIGHT_FORMATED"] = roundEx(doubleval($basketItem->getWeight()/$arResult["WEIGHT_KOEF"]), SALE_WEIGHT_PRECISION)." ".$arResult["WEIGHT_UNIT"];
            $arBasketItem["DISCOUNT_PRICE"] = $basketItem->getDiscountPrice();

            $arBasketItem["DISCOUNT_PRICE_PERCENT"] = 0;
            if ($arBasketItem['CUSTOM_PRICE'] != 'Y')
            {
                $arBasketItem['DISCOUNT_PRICE_PERCENT'] = Sale\Discount::calculateDiscountPercent(
                    $arBasketItem['BASE_PRICE'],
                    $arBasketItem['DISCOUNT_PRICE']
                );
                if ($arBasketItem['DISCOUNT_PRICE_PERCENT'] === null)
                    $arBasketItem['DISCOUNT_PRICE_PERCENT'] = 0;
            }
            $arBasketItem["DISCOUNT_PRICE_PERCENT_FORMATED"] = $arBasketItem['DISCOUNT_PRICE_PERCENT'].'%';

            $arBasketItem["BASE_PRICE_FORMATED"] = SaleFormatCurrency($basketItem->getBasePrice(), $this->order->getCurrency());

            /*$arDim = $basketItem->getField('DIMENSIONS');

            if (is_string($arDim))
            {
                $arDim = unserialize($basketItem->getField('DIMENSIONS'));
            }

            if (is_array($arDim))
            {
                $arResult["MAX_DIMENSIONS"] = CSaleDeliveryHelper::getMaxDimensions(
                    array(
                        $arDim["WIDTH"],
                        $arDim["HEIGHT"],
                        $arDim["LENGTH"]
                    ),
                    $arResult["MAX_DIMENSIONS"]);

                $arResult["ITEMS_DIMENSIONS"][] = $arDim;
            }*/

            $arBasketItem["PROPS"] = array();
            /** @var Sale\BasketPropertiesCollection $propertyCollection */
            $propertyCollection = $basketItem->getPropertyCollection();
            $propList = $propertyCollection->getPropertyValues();
            foreach ($propList as $key => &$prop)
            {
                if ($prop['CODE'] == 'CATALOG.XML_ID' || $prop['CODE'] == 'PRODUCT.XML_ID' || $prop['CODE'] == 'SUM_OF_CHARGE')
                    continue;

                $prop = array_filter($prop, array("CSaleBasketHelper", "filterFields"));
                $arBasketItem["PROPS"][] = $prop;
            }

            $this->arElementId[] = $arBasketItem["PRODUCT_ID"];
            $arBasketItem["SUM_NUM"] = $basketItem->getPrice() * $basketItem->getQuantity();
            $arBasketItem["SUM"] = SaleFormatCurrency($basketItem->getPrice() * $basketItem->getQuantity(), $this->order->getCurrency());
            $arBasketItem["SUM_BASE"] = $basketItem->getBasePrice() * $basketItem->getQuantity();
            $arBasketItem["SUM_BASE_FORMATED"] = SaleFormatCurrency($basketItem->getBasePrice() * $basketItem->getQuantity(), $this->order->getCurrency());

            $arResult["BASKET_ITEMS"][] = $arBasketItem;
        }
    }

    /**
     * Set order total prices data from order object to $this->arResult
     */
    protected function obtainTotal()
    {
        $arResult =& $this->arResult;

        $basket = $this->calculateBasket;

        $arResult['ORDER_PRICE'] = $basket->getPrice();
        $arResult['ORDER_PRICE_FORMATED'] = SaleFormatCurrency($arResult['ORDER_PRICE'], $this->order->getCurrency());

        //$arResult['ORDER_WEIGHT'] = $basket->getWeight();
        //$arResult['ORDER_WEIGHT_FORMATED'] = roundEx(floatval($arResult['ORDER_WEIGHT'] / $arResult['WEIGHT_KOEF']), SALE_WEIGHT_PRECISION).' '.$arResult['WEIGHT_UNIT'];

        $arResult['PRICE_WITHOUT_DISCOUNT_VALUE'] = $basket->getBasePrice();
        $arResult['PRICE_WITHOUT_DISCOUNT'] = SaleFormatCurrency($arResult['PRICE_WITHOUT_DISCOUNT_VALUE'], $this->order->getCurrency());

        $arResult['DISCOUNT_PRICE'] = Sale\PriceMaths::roundPrecision(
            $this->order->getDiscountPrice() + ($arResult['PRICE_WITHOUT_DISCOUNT_VALUE'] - $arResult['ORDER_PRICE'])
        );
        $arResult['DISCOUNT_PRICE_FORMATED'] = SaleFormatCurrency($arResult['DISCOUNT_PRICE'], $this->order->getCurrency());

        $arResult['DELIVERY_PRICE'] = Sale\PriceMaths::roundPrecision($this->order->getDeliveryPrice());
        $arResult['DELIVERY_PRICE_FORMATED'] = SaleFormatCurrency($arResult['DELIVERY_PRICE'], $this->order->getCurrency());

        $arResult['ORDER_TOTAL_PRICE'] = Sale\PriceMaths::roundPrecision($this->order->getPrice());
        $arResult['ORDER_TOTAL_PRICE_FORMATED'] = SaleFormatCurrency($arResult['ORDER_TOTAL_PRICE'], $this->order->getCurrency());
    }

    /**
     * Витягуємо інформацію по товару з кошика
     * @param integer $id - товара з корзини
     * @return array - масив з інформацією по товарy в кошику
     */
    public function getProductDataList($iblock_id, $id)
    {
        // перевіряємо 'товар' чи 'торгова пропозиція' (false - в случае ошибки;)
        $productSku =  CCatalogSku::GetProductInfo($id); // Метод позволяет получить по ID торгового предложения ID товара.

        if(is_array($productSku)) // торгова пропозиція, беремо ІД товара по торговій пропозиції
            $products = $this->makeProductDetailInfo($iblock_id, $productSku['ID'], $productSku);
        else // товар
            $products = $this->makeProductDetailInfo($iblock_id, $id);

        return $products;
    }

    /**
     * Беремо дані по товару
     * @param integer $id - код товару (чи тогргової пропозиції)
     * @param bool $sku - масив з торговою пропозицією
     * @return array
     */
    public function makeProductDetailInfo($iblock_id, $id, $sku=false)
    {
        $arr = [];
        $element = CIBlockElement::GetByID($id)->GetNext();

        $props = [];
        $res = CIBlockElement::GetProperty($iblock_id, $id, array("sort" => "asc"), Array()); // 'свойства' елемента
        while ($ob = $res->GetNext())
        {
            if ($ob['CODE'] == 'min_count_4_order')
            {
                $props['min_count_4_order'] = $ob['VALUE'];
                break;
            }
        }
        unset($res, $ob);

        if(is_array($element))
        {
            $arr = [
                'ID'=>$element['ID'],
                'TIMESTAMP_X'=>$element['TIMESTAMP_X'],
                'DATE_CREATE'=>$element['DATE_CREATE'],
                'IBLOCK_ID'=>$element['IBLOCK_ID'],
                'IBLOCK_SECTION_ID'=>$element['IBLOCK_SECTION_ID'],
                'ACTIVE'=>$element['ACTIVE'],
                'NAME'=>$element['NAME'],
                'PREVIEW_PICTURE'=>$this->getImageDataById($element['PREVIEW_PICTURE']),
                'DETAIL_PICTURE'=>$this->getImageDataById($element['DETAIL_PICTURE']),
                'CODE'=>$element['CODE'],
                'XML_ID'=>$element['XML_ID'],
                'USER_NAME'=>$element['USER_NAME'],
                'IBLOCK_TYPE_ID'=>$element['IBLOCK_TYPE_ID'],
                'IBLOCK_CODE'=>$element['IBLOCK_CODE'],
                'IBLOCK_NAME'=>$element['IBLOCK_NAME'],
                'DETAIL_PAGE_URL'=>$element['DETAIL_PAGE_URL'],
                'DETAIL_PAGE_URL'=>$element['DETAIL_PAGE_URL'],
                'ITEMS_PROPS'=>$props,
            ];
        }
        if($sku) $arr['SKU'] = $sku;

        return $arr;
    }

    /**
     * @param $id
     * @return string|null
     */
    public function getImageDataById($id)
    {
        $image = NULL;
        $id = (int)$id;
        if($id > 0) $image = CFile::GetPath($id);
        return $image;
    }

    /**
     * Отримати способи оплати товарів
     */
    public function getSalePaySystem()
    {
        $this->arResult["PAY_SYSTEM"] = [];
        $resPaySystem = CSalePaySystem::GetList();
        while ($arPaySystemItem = $resPaySystem->Fetch()) {
            $this->arResult["PAY_SYSTEM"][] = $arPaySystemItem;
        }
        /*
        $dbPaySystem = CSalePaySystemAction::GetList(Array(), Array(), false, false, Array());
        while ($arPaySystem = $dbPaySystem->Fetch())
            $this->arResult["PAY_SYSTEM"][] = $arPaySystem;
        */
    }

    /**
     * Отримати способи доставки товарів
     */
    public function getSaleDelivery()
    {
        $this->arResult["DELIVERY_TYPE"] = [];
        $resDelivery = CSaleDelivery::GetList(
            Array(),
            Array("LID" => SITE_ID, "ACTIVE" => "Y"),
            false,
            false,
            Array()
        );
        if($arDelivery = $resDelivery->Fetch()) {
            do {
                $this->arResult["DELIVERY_TYPE"][] = $arDelivery;
            } while ($arDelivery = $resDelivery->Fetch());
        }
    }

    /**
     * Метод повертає інформацію по поточному користувачу
     * @return array
     */
    public function getUserInfo()
    {
        global $USER;
        $userId = $USER->GetID() ? $USER->GetID() : CSaleUser::GetAnonymousUserID();

        $arUser = [];
        if($USER->IsAuthorized())
        {
            $rsUser = CUser::GetByID($userId);
            $arUser = $rsUser->Fetch();
            $arUser = [
                'ID'=>$arUser['ID'],
                'LOGIN'=>$arUser['LOGIN'],
                'NAME'=>$arUser['NAME'],
                'LAST_NAME'=>$arUser['LAST_NAME'],
                'EMAIL'=>$arUser['EMAIL'],
                'PERSONAL_PHONE'=>$arUser['PERSONAL_PHONE'],
                'PERSONAL_MOBILE'=>$arUser['PERSONAL_MOBILE'],
            ];
        }
        else
        {
            $arUser = [
                'FUSER_ID'=> Fuser::getId(),
                'USER_ID'=> $USER->GetID(),
                'ANONYMOUS_USER_ID'=> CSaleUser::GetAnonymousUserID(),
                'BASKET_USER_ID'=> CSaleBasket::GetBasketUserID(), // Метод возвращает внутренний код владельца корзины
            ];
        }

        return $arUser;
    }

    /* ACTION ------------------------------------------------------------------------------------------------------- */

    /**
     * Prepares action string to execute in doAction
     *  - processOrder - process order [including component template]
     *  - showOrder - show created order [including component template]
     *  -
     *
     * @return array|string|null
     */
    protected function prepareAction()
    {
        global $USER;
        $this->userId = $USER->GetID() ? $USER->GetID() : CSaleUser::GetAnonymousUserID();

        // TODO - перевірити чи при зміні кількості товару вибирається правильна валюта, переключити форматування на JS
        $this->currency = $this->request->get('currencyCode');
        if(!$this->currency) $this->currency = CCurrency::GetBaseCurrency();


        $action = $this->request->offsetExists($this->arParams['ACTION_VARIABLE'])
            ? $this->request->get($this->arParams['ACTION_VARIABLE'])
            : $this->request->get('action');

        if (empty($action))
        {
            if ($this->request->get('ORDER_ID') == '')
            {
                $action = 'processOrder';
            }
            else
            {
                $action = 'showOrder';
            }
        }

        $this->checkSession = check_bitrix_sessid();
        return $action;
    }

    /**
     * Executes prepared action with postfix 'Action'
     * @param $action
     */
    protected function doAction($action)
    {
        if (is_callable(array($this, $action."Action")))
        {
            call_user_func(
                array($this, $action."Action")
            );
        }
    }

    /**
     * Виводимо форму для оформлення замовлення
     * Формуємо масив `arResult` з основними параметрами
     */
    public function processOrderAction()
    {
        try {
            // Створюємо замовлення
            $this->createVirtualOrder();

            // формуємо масив з даними для сторінки оформлення замовлення
            $this->getOrderProps(); // збираємо основні властивості кошика і товарів
            $this->getSalePaySystem(); // доступні системи оплати
            $this->getSaleDelivery(); // отримати доступні доставки
            $this->obtainBasket();
            $this->obtainTotal();
        } catch (Exception $e) {
            $this->arResult['errors'] = ['error'=>'processOrderAction', 'message'=>$e->getMessage()];
        }
    }

    /**
     * Метод видаляє товар з кошика (по його коду в кощику)
     * Спрацьовує через `Ajax` запит і повертає відповідь методом 'sendAjaxAnswer()'
     */
    public function deleteProductAction()
    {
        $basket = $this->getBasketStorage()->getBasket();
        $basketCode = (int)$this->request->get('basketCode');

        $ajaxData = [
            'ACTION'=>'deleteProduct',
            'DATA'=>[
                'PRODUCT_ID'=>$basketCode,
            ]
        ];

        if (!$basket->isEmpty())
        {
            // @var Sale\BasketItem $item
            $item = $basket->getItemByBasketCode($basketCode);
            if ($item)
            {
                $deleteResult = $item->delete();

                if ($deleteResult->isSuccess())
                {
                    $saveResult = $basket->save();

                    if ($saveResult->isSuccess())
                    {
                        $ajaxData['ERROR'] = 'N';
                        //$ajaxData['DATA']['PRICE'] = $basket->getPrice();
                        $ajaxData['DATA']['BASE_PRICE'] = round($basket->getBasePrice(), 2);
                        $ajaxData['DATA']['FORMATED_BASE_PRICE'] = CurrencyFormat($basket->getBasePrice(), $this->currency);
                        $ajaxData['DATA']['PRICE_DISCOUNT'] = $this->calculateBasketTotalDiscountPrice($basket);
                    }
                    else
                    {
                        $ajaxData['ERROR'] ='deleteProductAction';
                    }
                }
            }
            else $ajaxData['ERROR'] ='basket-item-empty';
        }
        else $ajaxData['ERROR'] ='basket-isEmpty';

        $this->sendAjaxAnswer($ajaxData);
    }

    /**
     * Метод змінює кількість товарів в кошику (по його коду в кощику)
     * Спрацьовує через `Ajax` запит і повертає відповідь методом 'sendAjaxAnswer()'
     */
    public function changeQuantityAction()
    {
        $quantity = (int)$this->request->get('quantity');
        $basketCode = (int)$this->request->get('basketCode');

        $ajaxData = [
            'ACTION' => 'changeQuantity',
            'DATA' => [
                'PRODUCT_ID' => $basketCode ,
            ]
        ];

        $basket = $this->getBasketStorage()->getBasket();
        $basketItem = $basket->getItemByBasketCode($basketCode);

        $basketItemQuanyity = $basketItem->getQuantity();
        $ajaxData['quantity'] = $basketItemQuanyity;

        if($basketItemQuanyity != $quantity && $quantity > 0 )
        {
            $res = $basketItem->setField('QUANTITY', $quantity);
            if ($res->isSuccess())
            {
                $saveResult = $basket->save();

                if ($saveResult->isSuccess())
                {
                    $ajaxData['ERROR'] = 'N';
                    $ajaxData['DATA']['QUANTITY_NEW'] = $basketItem->getQuantity();
                    $ajaxData['DATA']['BASE_PRICE'] = round($basket->getBasePrice(),2);

                    //$ajaxData['DATA']['ORDER_PRICE'] = $basket->getPrice();
                    ///$arResult['DATA']['ORDER_PRICE_FORMATED'] = SaleFormatCurrency($arResult['DATA']['ORDER_PRICE'], $this->order->getCurrency());

                    $ajaxData['DATA']['FORMATED_BASE_PRICE'] = CurrencyFormat($basket->getBasePrice(), $this->currency);
                    $ajaxData['DATA']['PRICE_DISCOUNT'] = $this->calculateBasketTotalDiscountPrice($basket);
                }
                else
                {
                    $ajaxData['ERROR'] = 'changeQuantityAction. Q='.$basketItem->getQuantity();
                }
            }
            else $ajaxData['ERROR'] = 'changeQuantity';
        } else $ajaxData['ERROR'] = 'quantity = 0 or quantity equal';

        $this->sendAjaxAnswer($ajaxData);
    }


    /**
     * Оформлюємо замовлення
    */
    public function makeCurrentOrderAction()
    {
        global $USER;

        $ajaxData = [
            'ACTION' => 'makeCurrentOrder',
        ];

        $name = trim($this->request->get('userName'));

        $phone = trim($this->request->get('userPhone'));
        $phone = preg_replace("/[^0-9]/", "", $phone);

        $email = trim($this->request->get('userEmail'));

        $paySystemId = (int)$this->request->get('userPaySystemId');

        $address = trim($this->request->get('userAddress'));

        //
        $this->userId = $this->verifyUserIsRegistered($email, $phone);

        // Кошик
        $registry = Sale\Registry::getInstance(Sale\Registry::REGISTRY_TYPE_ORDER);
        /** @var Order $orderClassName */
        $orderClassName = $registry->getOrderClassName();
        $this->order = $orderClassName::create($this->getSiteId(),  $this->userId);
        $this->order->isStartField();
        $this->order->setField('STATUS_ID', Sale\OrderStatus::getInitialStatus());
        $this->initBasket($this->order);
        // перкладаємо товари з кошику в доставку
        $this->initShipment($this->order);

        $this->order->doFinalAction(true); // Метод выполняет расчет скидок, налогов и применяет их к заказу.


        // Властивості замовлення
        $propertyCollection = $this->order->getPropertyCollection();
        $nameProp = $propertyCollection->getPayerName();
        $nameProp->setValue($name);
        $phoneProp = $propertyCollection->getPhone();
        $phoneProp->setValue($phone);
        $emailProp = $propertyCollection->getUserEmail();
        $emailProp->setValue($email);
        $addressProp = $propertyCollection->getAddress();
        $addressProp->setValue($address);


        // Оплата
        $paymentCollection = $this->order->getPaymentCollection();
        $payment = $paymentCollection->createItem(PaySystem\Manager::getObjectById($paySystemId));
        $payment->setField("SUM", $this->order->getPrice());
        $payment->setField("CURRENCY", $this->order->getCurrency());


        $this->saveProfileData($this->order);


        $result = $this->order->save();
        if ($result->isSuccess())
        {
            $ajaxData['ORDER_ID'] =  $this->order->getId();
            $ajaxData['PAY_SYSTEM_ID'] =  $paySystemId;
            $ajaxData['ERROR'] = 'N';
            $this->showOrderAction($ajaxData);
        }
        else
        {
            $ajaxData['ERROR'] = $result->getErrors();
            $this->sendAjaxAnswer($ajaxData);
        }
    }

    /**
     * Метод перекладає всі товари з кошика в замовлення
    */
    public function initShipment($order)
    {
        $delivertId = 1; // id доставки
        $shipmentCollection = $order->getShipmentCollection();
        $shipment = $shipmentCollection->createItem(
            Bitrix\Sale\Delivery\Services\Manager::getObjectById($delivertId)
        );
        $shipmentItemCollection = $shipment->getShipmentItemCollection();
        $shipment->setField('CURRENCY', $order->getCurrency());

        /** @var Sale\BasketItem $item */
        foreach ($order->getBasket()->getOrderableItems() as $item)
        {
            /** @var Sale\ShipmentItem $shipmentItem */
            $shipmentItem = $shipmentItemCollection->createItem($item);
            $shipmentItem->setQuantity($item->getQuantity());
        }

        return $shipment;
    }

    /**
     * @param bool $isMeaningfulField
     * @return bool
     */
    public function isStartField($isMeaningfulField = false)
    {
        if ($this->isStartField === null)
        {
            $this->isStartField = true;
        }
        else
        {
            $this->isStartField = false;
        }

        if ($isMeaningfulField === true)
        {
            $this->isMeaningfulField = true;
        }

        return $this->isStartField;
    }

    /**
     * Додаємо кошик (до поточного FUser) to order object
     *
     * @param Order $order
     * @throws Main\ObjectNotFoundException
     */
    protected function initBasket($order)
    {
        $basketStorage = $this->getBasketStorage();
        $this->basket = $basketStorage->getBasket();
        $result = $this->basket->refresh();
        if ($result->isSuccess())
        {
            $this->basket->save();
        }
        // right NOW we decide to work only with available basket
        // full basket won't update anymore
        $availableBasket = $basketStorage->getOrderableBasket();

        if ($availableBasket->isEmpty())
        {
            $this->showEmptyBasket(); // TODO - доробити
        }
        $order->appendBasket($availableBasket);
    }

    /**
    */
    public function getBasket()
    {
        if (!isset($this->basket))
        {
            $this->basket = Sale\Basket::loadItemsForFUser($this->getFUserId(), $this->getSiteId());
        }
        return $this->basket;
    }



    /* METHOD ------------------------------------------------------------------------------------------------------- */

    /**
     * Зберігаємо профіль користувача
     */
    protected function saveProfileData($order)
    {
        $arResult = $this->arResult;
        $profileId = 0;
        $profileName = '';
        $properties = array();

        if (isset($arResult['ORDER_PROP']) && is_array($arResult['ORDER_PROP']['USER_PROFILES']))
        {
            foreach ($arResult['ORDER_PROP']['USER_PROFILES'] as $profile)
            {
                if ($profile['CHECKED'] === 'Y')
                {
                    $profileId = (int)$profile['ID'];
                    break;
                }
            }
        }

        $propertyCollection = $order->getPropertyCollection();
        if (!empty($propertyCollection))
        {
            if ($profileProp = $propertyCollection->getProfileName())
                $profileName = $profileProp->getValue();

            /** @var Sale\PropertyValue $property */
            foreach ($propertyCollection as $property)
            {
                $properties[$property->getField('ORDER_PROPS_ID')] = $property->getValue();
            }
        }

        $arFields = array(
            "NAME" => "Профиль 001",
            "USER_ID" => $this->userId,
            "PERSON_TYPE_ID" => 1
        );
        $USER_PROPS_ID = CSaleOrderUserProps::Add($arFields);

        Debug::writeToFile([$order->getUserId(), $profileId, $profileName, $order->getPersonTypeId(), $properties,],'','/test/log.txt');


        CSaleOrderUserProps::DoSaveUserProfile(
            $order->getUserId(),
            $profileId,
            $profileName,
            $order->getPersonTypeId(),
            $properties,
            $arResult["ERROR"]
        );
    }

    /**
     * Initialization of user profiles. Set user profiles data to $this->arResult.
     */
    protected function initUserProfiles()
    {
        $bFirst = false;
        $this->arResult['USER_PROFILES'] = [];
        $dbUserProfiles = CSaleOrderUserProps::GetList(
            array('DATE_UPDATE' => 'DESC'),
            array(
                'PERSON_TYPE_ID' => $this->order->getPersonTypeId(),
                'USER_ID' => $this->userId
            )
        );
        while ($arUserProfiles = $dbUserProfiles->GetNext())
        {
            $this->arResult['USER_PROFILES'][$arUserProfiles['ID']] = $arUserProfiles;
        }
    }



    public function verifyUserIsRegistered($email = '', $phone= '')
    {
        global $USER;


        $userID = $this->userId;

        if(!$USER->IsAuthorized())
        {



            // -------------



            if($email != '' || $phone != '')
            {
                if(filter_var($email, FILTER_VALIDATE_EMAIL) !== false) $userEmail = $email;
                else $userEmail = false;

                if(strlen($phone) == 10 && is_int(intval($phone))) $userPhone = $phone;
                else $userPhone = false;
            }

            if(!$userEmail && !$userPhone)
            {
                $ajaxData['ERROR'] = 'Invalid Email or Phone';
                $this->sendAjaxAnswer($ajaxData);
            }

            $userLogin = '';
            if ($userEmail)
            {
                $userLogin = $userEmail;
                $userPhone = '';
            }
            elseif ($userPhone)
            {
                $userLogin = 'USER_'.$userPhone;
                $userEmail = 'USER_'.$userPhone.'@email.ssk';
            }

            if( strlen($name) < 3) $name = $userEmail;

            $user = new CUser;
            $arFields = Array(
                "NAME"              => $name,
                "LAST_NAME"         => "",
                "EMAIL"             => $userEmail,
                "LOGIN"             => $userLogin,
                "LID"               => 'ru',
                "ACTIVE"            => "Y",
                "GROUP_ID"          => array(2,3,5),
                "PASSWORD"          => "123456",
                "CONFIRM_PASSWORD"  => "123456",
                'PERSONAL_PHONE'    => $userPhone,
            );

            $userID = $user->Add($arFields);
        }




        return $userID;
    }

    /**
     * Генеруємо дані для платіжної системи `Platon`
     * @param $ORDER_ID
     * @return array
     */
    public function getPlatonOrder($ORDER_ID)
    {
        $arr = [];
        $arOrder = Array();
        $arFilter = Array('ID'=>$ORDER_ID, 'USER_ID'=>$this->userId, 'PAYED'=>'N', '!STATUS_ID'=>array('P','F','E'));
        $db_order = CSaleOrder::GetList($arOrder, $arFilter, false, false, Array());
        while ($dbo = $db_order->Fetch())
        {
            $pass = 'jXSbAu6nBqDyKpeAejMDHthvHJ6Q17Dv';
            $arr['key'] = 'YPKRRKZXZX';
            $arr['url'] = 'https://ssk-market.com.ua/personal/order/payment/result.php';
            $arr['data'] = base64_encode(serialize(array(
                'amount' => number_format(floatval($dbo['PRICE']), 2, '.', ''),
                'currency' => $dbo['CURRENCY'],
                'name' => 'Buying '.$dbo['USER_ID'].'/'.$dbo['ID'],
                'recurring' => 'init'
            )));
            $ip_server = COption::GetOptionString('platon.paysystem', "PLATON_IP_SERVER")!=='CHANGE_IP' ? strrev($_SERVER["REMOTE_ADDR"]) : trim(COption::GetOptionString('platon.paysystem', "PLATON_CHANGE_IP_SERVER"));
            $arr['ip'] = $ip_server;
            $arr['sign'] = md5(strtoupper(
                $ip_server.
                strrev($arr['key']).
                strrev($arr['data']).
                strrev($arr['url']).
                strrev($pass)
            ));
            $arr['last_name'] = $dbo['USER_LAST_NAME'];
            $arr['first_name'] = $dbo['USER_NAME'];
            $arr['email'] = $dbo['USER_EMAIL'];
            $arr['order'] = $dbo['ID'];
            $arr['amount'] = $dbo['PRICE'].' '.$dbo['CURRENCY'];

            $db_sales_ord = CSaleOrderPropsValue::GetList(array(), Array("ORDER_ID" => $dbo['ID']));
            while ($ar_sale_ord = $db_sales_ord->Fetch())
                if ($ar_sale_ord['CODE'] == 'PHONE')
                    $arr['phone'] = $ar_sale_ord['VALUE'];
            print_r($arr);
        }
        return $arr;
    }

    /**
     * V1.
     * Метод помертає повний масив знижок на товари що в кошику
     * @param $basket
     * @return array
     */
    public function getDiscountBasket($basket)
    {
        $discounts = Discount::loadByBasket($basket);
        $basket->refreshData(array('PRICE', 'COUPONS'));
        $discounts->calculate();
        $discountResult = $discounts->getApplyResult();
        return $discountResult;
    }

    /**
     * V2.
     * Метод розраховує повну вартість кошика зі скидкою
     * а також повертає список цін для кожного з товарів
     * @basket - обєк кошика
     * @return array
     */
    public function calculateBasketTotalDiscountPrice($basket)
    {
        if ($basket->count() == 0)
            return 0;

        Bitrix\Sale\DiscountCouponsManager::freezeCouponStorage();
        $discounts = Discount::loadByBasket($basket);
        $basket->refreshData(array('PRICE', 'COUPONS'));
        $discounts->calculate();
        $discountResult = $discounts->getApplyResult();
        Bitrix\Sale\DiscountCouponsManager::unFreezeCouponStorage();
        if (empty($discountResult['PRICES']['BASKET']))
            return 0;
        $result = 0;
        $resultBase = 0;
        $discountResult = $discountResult['PRICES']['BASKET'];
        /** @var BasketItem $basketItem */
        foreach ($basket as $basketItem)
        {
            if (!$basketItem->canBuy())
                continue;
            $code = $basketItem->getBasketCode();
            if (!empty($discountResult[$code])) {
                $result += $discountResult[$code]['PRICE'] * $basketItem->getQuantity();
                $resultBase += ceil($discountResult[$code]['BASE_PRICE']) * $basketItem->getQuantity();
            }
            unset($code);
        }
        unset($basketItem);

        return [
            'FULL_DISCOUNT_PRICE'=>round($result,2),
            'FULL_PRICE_BASE'=>round($resultBase,2),
            'FULL_DISCOUNT_PRICE_FORMAT'=>CurrencyFormat(round($result,2), $this->currency),
            'DISCOUNT_PRICE_LIST'=>$discountResult,
        ];
    }

    /**
     * V3.
     * Метод повертає всі скидки по кошику
     *  - Застарілий метод
     */
    public function getDiscountBasketOld()
    {
        $fuserId = CSaleBasket::GetBasketUserID();

        $dbBasketItems = CSaleBasket::GetList(
            array("ID" => "ASC"),
            array(
                "FUSER_ID" => $fuserId,
                "LID" => SITE_ID,
                "ORDER_ID" => "NULL",
                "DELAY"=>"N"
            ),
            false,
            false,
            array(
                "ID", "NAME", "CALLBACK_FUNC", "MODULE", "PRODUCT_ID", "PRODUCT_PRICE_ID", "QUANTITY", "DELAY", "CAN_BUY",
                "PRICE", "WEIGHT", "DETAIL_PAGE_URL", "NOTES", "CURRENCY", "VAT_RATE", "CATALOG_XML_ID",
                "PRODUCT_XML_ID", "SUBSCRIBE", "DISCOUNT_PRICE", "PRODUCT_PROVIDER_CLASS", "TYPE", "SET_PARENT_ID"
            )
        );

        $allSum = 0;
        $allWeight = 0;
        $arResult = array();

        while ($arBasketItems = $dbBasketItems->Fetch())
        {
            $allSum += ($arBasketItems["PRICE"] * $arBasketItems["QUANTITY"]);
            $allWeight += ($arBasketItems["WEIGHT"] * $arBasketItems["QUANTITY"]);
            $arResult[] = $arBasketItems;
        }

        $arOrder = array(
            'SITE_ID' => SITE_ID,
            'USER_ID' => $GLOBALS["USER"]->GetID(),
            'ORDER_PRICE' => $allSum, // сумма всей корзины
            'ORDER_WEIGHT' => $allWeight, // вес всей корзины
            'BASKET_ITEMS' => $arResult // товары сами
        );

        $arOptions = array(
            'COUNT_DISCOUNT_4_ALL_QUANTITY' => "Y",
        );

        $arErrors = array();

        CSaleDiscount::DoProcessOrder($arOrder, $arOptions, $arErrors);

        return $arOrder;
    }

    /**
     * Метод повертає кошик користувача
     * @return Object
     */
    protected function getBasketStorage()
    {
        if (!isset($this->basketStorage))
        {
            $this->basketStorage = Sale\Basket\Storage::getInstance(Fuser::getId(), Context::getCurrent()->getSite());
        }
        return $this->basketStorage;
    }

    /**
     * @param $data -
     * @throws Main\ArgumentException
     */
    public function sendAjaxAnswer($ajaxData)
    {
        global $APPLICATION;
        $APPLICATION->RestartBuffer();  // очистити буфер, вивести вміст компонента без header'a і footer`a
        header('Content-Type: application/json');
        echo Json::encode($ajaxData);

        CMain::FinalActions();
        die();
    }

    /**
     * Виводимо результат оформлення замовлення
     */
    public function showOrderAction($ajaxData)
    {
        $user = CSaleOrder::GetByID($ajaxData['ORDER_ID']);

        $this->arParams['PAY_SYSTEM_ID'] = 9; // TODO - доробити вибір платіжної системи
        if($ajaxData['PAY_SYSTEM_ID'] == $this->arParams['PAY_SYSTEM_ID'])
            $ajaxData['PLATON'] = $this->getPlatonOrder($ajaxData['ORDER_ID']);

        $ajaxData['ORDER'] = [
            'PRICE'=>$user['PRICE'],
        ];
        $this->sendAjaxAnswer($ajaxData);
    }

    /**
     * Без файла 'component.php' Для цього достатньо перекрити метод 'executeComponent'.
     *  - запускается при виконанні компонента.
     *  - Данний метод, підключає шаблон.
     */
    public function executeComponent()
    {
        Sale\Compatible\DiscountCompatibility::stopUsageCompatible();
        $this->doAction($this->prepareAction());
        Sale\Compatible\DiscountCompatibility::revertUsageCompatible();

        $this->includeComponentTemplate(); // підключається шаблон 'template.php'.
        return parent::executeComponent();
    }
}
?>