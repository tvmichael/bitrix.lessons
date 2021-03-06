BX.namespace('BX.Sale.ArhicodeSaleOrder');

BX.Sale.ArhicodeSaleOrder = {

    init: function(parameters)
    {
        var i;
        this.arrId = parameters.arrId;
        this.siteId = parameters.siteID;
        this.ajaxUrl = parameters.ajaxUrl;

        this.productId = null;
        this.basketCode = null;
        this.basePrice = null;
        this.totalPrice = null;
        this.discount = null;
        this.action = null;
        this.nextOperation = true;
        this.tempInputQuantity = true;
        this.popupWindowByOneClick = null;

        // ARRAY
        this.arrButtonStepText = ['Оформить заказ', 'Доставка и оплата', 'Проверить данные', 'Все верно, заказываю'];

        this.signedParamsString = parameters.signedParamsString;
        this.result = parameters.result;
        this.actionVariable = parameters.actionVariable;

        this.orderCheck = false;
        this.order = {
            userInfo: {
                email:'',
                phone:'',
                name:'',
            },
            paySystem: {
                id:'',
                name:'',
            },
            delivery: {
                address:'',
            },
        };

        // OBJECT
        this.obOrderForm = BX(this.arrId.formId);

        this.obSwiftOrder = BX.findChildren(this.obOrderForm, {"class":"ahc-swift-order"}, true)[0];
        this.obStepBack = BX.findChildren(this.obOrderForm, {"class":"ahc-back"}, true);
        this.obAllowOrder = BX(this.arrId.allowOrder);
        this.obStepNavButton = BX.findChildren(this.obOrderForm, {"class":"ahc-step-btn"}, true);


        this.obBtnMinus = null;
        this.obBtnPlus = null;
        this.obBtnDelete = null;
        this.obInputQuantity = null;

        this.obGroupPay = BX.findChildren(this.obOrderForm, {"property":{'name':'group-pay'}, 'attribute':{'type':"radio"}}, true);
        this.obUserName = BX(this.arrId.userName);
        this.obUserEmail = BX(this.arrId.userEmail);
        this.obUserPhone = BX(this.arrId.userPhone);

        this.obDdelivery = BX.findChildren(this.obOrderForm, {"class":'ahc-delivery'}, true)[0];
        this.obDdeliveryName = BX.findChildren(this.obDdelivery, {'attribute':{'data-id':"name"}}, true)[0];
        this.obDdeliveryEmail = BX.findChildren(this.obDdelivery, {'attribute':{'data-id':"email"}}, true)[0];
        this.obDdeliveryPhone = BX.findChildren(this.obDdelivery, {'attribute':{'data-id':"phone"}}, true)[0];
        this.obDdeliveryAdres = BX.findChildren(this.obDdelivery, {'tag':'textarea'}, true)[0];

        this.obConfirmOrder = BX.findChildren(this.obOrderForm, {"class":'ahc-confirm-order'}, true)[0];
        this.obConfirmOrderName = BX.findChildren(this.obConfirmOrder, {'attribute':{'data-id':"name"}}, true)[0];
        this.obConfirmOrderPhone = BX.findChildren(this.obConfirmOrder, {'attribute':{'data-id':"phone"}}, true)[0];
        this.obConfirmOrderEmail = BX.findChildren(this.obConfirmOrder, {'attribute':{'data-id':"email"}}, true)[0];
        this.obConfirmOrderAddress = BX.findChildren(this.obConfirmOrder, {'attribute':{'data-id':"address"}}, true)[0];
        this.obConfirmOrderPay = BX.findChildren(this.obConfirmOrder, {'attribute':{'data-id':"delivery"}}, true)[0];

        this.obPaySystem = BX.findChildren(this.obOrderForm, {"class":'ahc-pay-system'}, true)[0];

        this.basePrice = BX(this.arrId.basePrice);
        this.totalPrice = BX(this.arrId.totalPrice);
        this.discount = BX(this.arrId.discount);

        this.panelList = [];
        for(i = 1; i < 5; i++)
            this.panelList[i] = BX.findChildren(this.obOrderForm, {"class":"ahc-panel-" + i}, true)[0];

        this.obActiveProductBlock = null;

        this.bindBtnAction();
        this.setPhoneMask(BX(this.arrId.userPhone));
    },

    calculateDiscount: function()
    {
        var i,
            discountSum = 0,
            discount = this.result.DISCOUNT_APPLY_ORDER.BASKET;

        if(typeof discount === 'object')
        {
            for (i in discount)
            {
                if (this.result.QUANTITY_LIST[i])
                    discountSum += discount[i].DISCOUNT * this.result.QUANTITY_LIST[i];
            }
        }
        discountSum = Math.round(discountSum * 100) / 100;
        return discountSum;
    },

    bindBtnAction: function()
    {
        var i;

        this.obBtnMinus = BX.findChildren(this.obOrderForm, {"class":"ahc-minus"}, true);
        for(i=0; i < this.obBtnMinus.length; i++)
            BX.bind(this.obBtnMinus[i], 'click', BX.proxy(this.changeQuantityAction, this));

        this.obBtnPlus = BX.findChildren(this.obOrderForm, {"class":"ahc-plus"}, true);
        for(i=0; i < this.obBtnPlus.length; i++)
            BX.bind(this.obBtnPlus[i], 'click', BX.proxy(this.changeQuantityAction, this));

        this.obInputQuantity = BX.findChildren(this.obOrderForm, {"class":"ahc-quantity"}, true);
        for(i=0; i < this.obInputQuantity.length; i++) {
            BX.bind(this.obInputQuantity[i], 'change', BX.proxy(this.changeQuantityAction, this));
            BX.bind(this.obInputQuantity[i], 'focus', BX.proxy(this.inputQuantityAction, this));
        }

        this.obBtnDelete = BX.findChildren(this.obOrderForm, {"class":"ahc-delete"}, true);
        for(i=0; i < this.obBtnDelete.length; i++)
            BX.bind(this.obBtnDelete[i], 'click', BX.proxy(this.productDeleteAction, this));


        BX.bind(this.obUserName, 'change', BX.proxy(this.validateUserName, this));
        BX.bind(this.obUserPhone, 'focusout', BX.proxy(this.validateUserPhone, this));
        BX.bind(this.obUserEmail, 'change', BX.proxy(this.validateUserEmail, this));


        this.obButtonStep = BX(this.arrId.buttonStep);
        BX.bind(this.obButtonStep, 'click', BX.proxy(this.buttonStepAction, this));

        BX.bind(this.obStepBack[0], 'click', BX.proxy(this.clickBackAction, this));
        BX.bind(this.obStepBack[1], 'click', BX.proxy(this.clickBackAction, this));

        BX.bind(BX('show-swift-order'), 'click', BX.proxy(this.showWindowBuyOneClick, this));

        for(i=0; i < this.obStepNavButton.length; i++)
            BX.bind(this.obStepNavButton[i], 'click', BX.proxy(this.clickNavBackAction, this));


    },

    changeQuantityAction: function(e)
    {
        var element = e.target,
            productBlock,
            input,
            product,
            value;

        var minValue = parseInt(element.getAttribute('data-min'));

        if( this.nextOperation )
        {
            productBlock = BX.findParent(element, {"class":"ahc-product-panel"}),
            input = BX.findChildren(productBlock, {"class":'ahc-quantity'}, true)[0],

            value = input.value;
            if(BX.hasClass(element,'ahc-quantity'))
            {
                if(value < 1) input.value = value = 1;
            }
            else if (BX.hasClass(element, 'ahc-plus')) {
                this.tempInputQuantity = value;
                value++;
                input.value = value;
            }
            else if (BX.hasClass(element, 'ahc-minus')) {
                this.tempInputQuantity = value;
                value--;
                if (value < minValue) input.value = value = minValue;
                else input.value = value;
            }

            this.productId = productBlock.getAttribute('data-product-id');
            if(this.productId && this.tempInputQuantity != value)
            {
                product = this.result.PRODUCT_LIST[this.productId];
                this.basketCode = product.BASKET_CODE;
                this.tempInputQuantity = input.value;
                this.action = 'changeQuantity';

                this.nextOperation = false;
                this.sendRequest(this.action);
            }
        }
    },

    inputQuantityAction: function(e)
    {
        var element = e.target;
        this.tempInputQuantity = element.value;
    },

    productDeleteAction: function (e)
    {
        var productBlock;

        if( this.nextOperation )
        {
            productBlock = BX.findParent(e.target, {"class":"ahc-product-panel"});
            this.obActiveProductBlock = BX.findParent(e.target, {"class":"ahc-product"});
            this.productId = productBlock.getAttribute('data-product-id');

            this.basketCode = this.result.PRODUCT_LIST[this.productId].BASKET_CODE;
            this.action = 'deleteProduct';
            this.nextOperation = false;
            this.sendRequest(this.action);
        }
    },

    getData: function()
    {
        var data = {
            via_ajax: 'Y',
            sessid: BX.bitrix_sessid(),
            SITE_ID: this.siteId,
            signedParamsString: this.signedParamsString
        };

        data[this.actionVariable] = this.action;
        data['is_ajax_post'] = 'Y';
        data['currencyCode'] = this.result.ORDER.CURRENCY;
        switch (this.action)
        {
            case 'deleteProduct':
                data['basketCode'] = this.basketCode;
                break;
            case 'changeQuantity':
                data['basketCode'] = this.basketCode;
                data['quantity'] = this.tempInputQuantity;
                break;
            case "makeCurrentOrder":
                data['userName'] = this.order.userInfo.name;
                data['userPhone'] = this.order.userInfo.phone;
                data['userEmail'] = this.order.userInfo.email;
                data['userPaySystemId'] = this.order.paySystem.id;
                data['userAddress'] = this.order.delivery.address;
                break;
        }
        return data;
    },

    sendRequest: function(action)
    {
        BX.ajax({
            method: 'POST',
            dataType: 'json',
            url: this.ajaxUrl,
            data: this.getData(),
            onsuccess: BX.delegate(function(result) {
                if(result.ERROR == 'N')
                switch (result.ACTION)
                {
                    case 'deleteProduct':
                        if(result.ERROR == 'basket-item-empty')
                        {

                        }
                        else
                        {
                            this.deleteProductBlockAction(result.DATA);
                        }
                        break;
                    case 'changeQuantity':
                        this.nextOperation = true;
                        this.setTotalPrise(result.DATA);
                        break;
                    case 'makeCurrentOrder':
                        this.showCurrentOrder(result);
                        break;
                }
            }, this),
            onfailure: BX.delegate(function(message){
                console.log(message);
            }, this)
        });
    },

    showCurrentOrder: function(result)
    {
        //console.log('showCurrentOrder');

        var order = document.getElementsByClassName('order-executed')[0],
            number = document.getElementById('oe-number'),
            sum = document.getElementById('oe-sum'),
            name = document.getElementById('oe-name'),
            phone = document.getElementById('oe-phone'),
            email = document.getElementById('oe-email'),
            address = document.getElementById('oe-address'),
            pay = document.getElementById('oe-pay'),
            // form
            form = order.getElementsByClassName('platon-ps-form')[0],
            input = form.getElementsByTagName("input");

        var i, key;

        number.innerHTML = result.ORDER_ID;
        sum.innerHTML = BX.Currency.currencyFormat(result.ORDER.PRICE, this.result.ORDER.CURRENCY, true);

        name.innerHTML = this.order.userInfo.name;
        phone.innerHTML = this.order.userInfo.phone;
        email.innerHTML = this.order.userInfo.email;
        address.innerHTML = this.order.delivery.address,
        pay.innerHTML = this.order.paySystem.name;

		dataLayerLastStep(result.ORDER_ID, result.ORDER.PRICE);
        console.log(result);

        if(result.PLATON)
        {
            for(i = 0; i < input.length; i++)
            {
                key = input[i].getAttribute('name');
                switch (key)
                {
                    case "key":
                    case "sign":
                    case "data":
                    case "order":
                    case "last_name":
                    case "email":
                    case "phone":
                        input[i].value = result.PLATON[key];
                        break;
                }
            }
            form.style.display = 'block';
        }

        order.style.display = 'block';
        BX.remove(this.obOrderForm);

        if(this.popupWindowByOneClick)
        {
            this.popupWindowByOneClick.close();
            this.popupWindowByOneClick.destroy();
        }
        this.popupWindowByOneClick = null;
    },

    deleteProductBlockAction: function(data)
    {
        var self = this,
            easing = new BX.easing({
            duration: 500,
            start: {opacity: 100},
            finish: {opacity: 0},
            transition : BX.easing.transitions.linear,
            step : function(state){
                BX.style(self.obActiveProductBlock, 'opacity', state.opacity/100);
            }
        });
        easing.animate();

        setTimeout(function () {
            BX.remove(self.obActiveProductBlock);
            self.obActiveProductBlock = null;
            self.nextOperation = true;

            if(data) self.setTotalPrise(data);
        }, 500);

        // перевіряємо чи є ще товари в кошику
        if(this.result.QUANTITY_LIST[data.PRODUCT_ID])
            delete this.result.QUANTITY_LIST[data.PRODUCT_ID];
        if (Object.keys(this.result.QUANTITY_LIST).length === 0 && this.result.QUANTITY_LIST.constructor === Object)
        {
            BX.remove(this.obOrderForm);
            var be = document.getElementsByClassName('bascket-empty')[0];
            be.style.display = 'block';
        }
    },

    setPhoneMask: function(element)
    {
        /*var result = new BX.MaskedInput({
            mask: '(999) 999 99 99',
            input: BX(this.arrId.userPhone),
            placeholder: '_'
        });
        var phone = false;

        if(this.result.USER_INFO.PERSONAL_PHONE != '')
            phone = this.result.USER_INFO.PERSONAL_PHONE;
        else if (this.result.USER_INFO.PERSONAL_MOBILE != '')
            phone = this.result.USER_INFO.PERSONAL_MOBILE;

        if (phone) result.setValue(phone);
        else result.setValue('0__ ___ __ __');*/

        /*
        var phone = false;

        //var element = BX(this.arrId.userPhone);
        var maskOptions = {
            mask: '(000) 000 00 00'
        };
        var mask = IMask(element, maskOptions);

        if(this.result.USER_INFO.PERSONAL_PHONE != '')
            phone = this.result.USER_INFO.PERSONAL_PHONE;
        else if (this.result.USER_INFO.PERSONAL_MOBILE != '')
            phone = this.result.USER_INFO.PERSONAL_MOBILE;

        if (phone) mask.value = phone;
        */

        var phoneNumber = '';

        if(this.result.USER_INFO.PERSONAL_PHONE != '')
            phoneNumber = this.result.USER_INFO.PERSONAL_PHONE;
        else if (this.result.USER_INFO.PERSONAL_MOBILE != '')
            phoneNumber = this.result.USER_INFO.PERSONAL_MOBILE;

        $(document).ready(function(){
            $(element).val(phoneNumber);
            $(element).mask('+38(000) 000 00 00');
        });
    },

    setTotalPrise: function(dataPrice)
    {
        this.basePrice.innerHTML = BX.Currency.currencyFormat(dataPrice.PRICE_DISCOUNT.FULL_PRICE_BASE,this.result.ORDER.CURRENCY,true);
        this.totalPrice.innerHTML = dataPrice.PRICE_DISCOUNT.FULL_DISCOUNT_PRICE_FORMAT;
        this.discount.innerHTML = BX.Currency.currencyFormat((dataPrice.PRICE_DISCOUNT.FULL_PRICE_BASE - dataPrice.PRICE_DISCOUNT.FULL_DISCOUNT_PRICE),this.result.ORDER.CURRENCY,true);
    },

    buttonStepAction: function(e)
    {
        var step = parseInt(this.obButtonStep.getAttribute('data-step')),
            prevStep = step,
            nextStep = false,
            allowDiv = BX.findParent(this.obAllowOrder, {"class":"ahc-allow-order"}),
            error = false,
            i;
        var title = document.getElementsByClassName('h1-title-block')[0];

        switch (step)
        {
            case 1:
                
                this.obSwiftOrder.style.display = 'none';
                allowDiv.style.display = 'block';
                this.obStepBack[0].style.display = 'block';
                this.obStepBack[1].style.display = 'block';
                nextStep = true;

                BX.removeClass(this.obStepNavButton[0], 'current-step');
                BX.addClass(this.obStepNavButton[0], 'active-step');
                BX.addClass(this.obStepNavButton[1], 'current-step');
				
				dataLayerNextStepBascet(step);

				if(window.innerWidth < 769) title.scrollIntoView();
				step = 2;
                break;
            case 2:
                if(!this.validateUserName() || !this.validateUserEmail() || !this.validateUserPhone())
                    error = true;

                if(this.obAllowOrder.checked && !error)
                {

                    BX.removeClass(this.obStepNavButton[1], 'current-step');
                    BX.addClass(this.obStepNavButton[1], 'active-step');
                    BX.addClass(this.obStepNavButton[2], 'current-step');
                    this.obStepBack[0].innerHTML = 'Вернуться назад';
                    this.obStepBack[1].innerHTML = 'Вернуться назад';

                    this.order.userInfo.name = this.obUserName.value;
                    this.order.userInfo.phone = this.obUserPhone.value;
                    this.order.userInfo.email = this.obUserEmail.value;

                    this.obDdeliveryName.innerHTML = this.order.userInfo.name;
                    this.obDdeliveryEmail.innerHTML = this.order.userInfo.email;
                    this.obDdeliveryPhone.innerHTML = this.order.userInfo.phone;
					
					dataLayerNextStepBascet(step);
					
                    step = 3;
                    allowDiv.style.display = 'none';
                    nextStep = true;

                    if(window.innerWidth < 769) title.scrollIntoView();
                }
                break;
            case 3:
                this.order.delivery.address = this.obDdeliveryAdres.value;

                for(i = 0; i < this.obGroupPay.length; i++)
                {
                    if(this.obGroupPay[i].checked)
                    {
                        BX.removeClass(this.obStepNavButton[2], 'current-step');
                        BX.addClass(this.obStepNavButton[2], 'active-step');
                        BX.addClass(this.obStepNavButton[3], 'current-step');

                        this.order.paySystem.id = this.obGroupPay[i].value;
                        this.order.paySystem.name = this.obGroupPay[i].getAttribute('data-name');
                        nextStep = true;
						
						dataLayerNextStepBascet(step);
						
                        step = 4;

                        this.obConfirmOrderName.innerHTML = this.order.userInfo.name;
                        this.obConfirmOrderPhone.innerHTML = this.order.userInfo.phone;
                        this.obConfirmOrderEmail.innerHTML = this.order.userInfo.email;
                        this.obConfirmOrderAddress.innerHTML = this.order.delivery.address;
                        this.obConfirmOrderPay.innerHTML = this.order.paySystem.name;

                        if(window.innerWidth < 769) title.scrollIntoView();
                        break;
                    }
                }

                var obPaySystemLabel = BX.findChildren(this.obPaySystem, {'tag':'label'}, true),
                    color;
                if (nextStep) color = '#888888';
                else color = 'red';

                for( i=0; i<obPaySystemLabel.length; i++)
                    obPaySystemLabel[i].style.color = color;

                break;
            case 4:
				
                this.orderCheck = true;
                this.action = 'makeCurrentOrder';
                this.sendRequest(this.action);
                break;
        }

        if(nextStep)
        {
            this.panelList[prevStep].style.display = 'none';
            this.panelList[step].style.display = 'block';
            this.obButtonStep.setAttribute('data-step', step);
            this.obButtonStep.innerHTML = this.arrButtonStepText[step-1];
        }
    },

    validateUserName: function()
    {
        var value = this.obUserName.value;
        if(value.length > 3)
        {
            this.obUserName.style.borderColor = '#eaebec';
            return true;
        }
        else
        {
            this.obUserName.style.borderColor = 'red';
            return false;
        }
    },

    validateUserEmail: function()
    {
        var value = this.obUserEmail.value,
            re;
        value = BX.util.trim(value);

        if (value.length)
        {
            re = /^(([^<>()[\]\.,;:\s@\"]+(\.[^<>()[\]\.,;:\s@\"]+)*)|(\".+\"))@(([^<>()[\]\.,;:\s@\"]+\.)+[^<>()[\]\.,;:\s@\"]{2,})$/i;
            if (!re.test(value))
            {
                this.obUserEmail.style.borderColor = 'red';
                return false;
            }
            else
            {
                this.obUserEmail.style.borderColor = '#eaebec';
                return true;
            }
        }
        else
        {
            this.obUserEmail.style.borderColor = 'red';
            return false;
        }
    },

    validateUserPhone: function()
    {
        var value = this.obUserPhone.value,
            re = /[(]?[0-9]{3}[)]? [0-9]{3} [0-9]{2} [0-9]{2}/i;

        if(!re.test(value))
        {
            this.obUserPhone.style.borderColor = 'red';
            return false;
        }
        else
        {
            this.obUserPhone.style.borderColor = '#eaebec';
            return true;
        }
    },

    clickBackAction: function(e)
    {
        var step = parseInt(this.obButtonStep.getAttribute('data-step')),
            prevStep = step,
            allowDiv = BX.findParent(this.obAllowOrder, {"class":"ahc-allow-order"}),
            i;

        switch (step)
        {
            case 1:
                step = 1;
                break;
            case 2:
                step = 1;
                this.obSwiftOrder.style.display = 'block';
                allowDiv.style.display = 'none';
                this.obStepBack[0].style.display = 'none';
                this.obStepBack[1].style.display = 'none';
                BX.removeClass(this.obStepNavButton[1], 'current-step');
                BX.removeClass(this.obStepNavButton[0], 'active-step');
                BX.addClass(this.obStepNavButton[0], 'current-step');
                break;
            case 3:
                step = 2;
                allowDiv.style.display = 'block';
                this.obStepBack[0].innerHTML = 'Вернуться в корзину';
                this.obStepBack[1].innerHTML = 'Вернуться в корзину';
                BX.removeClass(this.obStepNavButton[2], 'current-step');
                BX.removeClass(this.obStepNavButton[1], 'active-step');
                BX.addClass(this.obStepNavButton[1], 'current-step');
                break;
            case 4:
                step = 3;
                BX.removeClass(this.obStepNavButton[3], 'current-step');
                BX.removeClass(this.obStepNavButton[2], 'active-step');
                BX.addClass(this.obStepNavButton[2], 'current-step');
                break;
        }

        this.panelList[prevStep].style.display = 'none';
        this.panelList[step].style.display = 'block';
        this.obButtonStep.setAttribute('data-step', step);
        this.obButtonStep.innerHTML = this.arrButtonStepText[step-1];
    },

    clickNavBackAction: function(e)
    {
        var element = e.target,
            step = parseInt(this.obButtonStep.getAttribute('data-step'));

        if(parseInt(element.textContent) == (step - 1)) this.clickBackAction();
    },

    showWindowBuyOneClick: function ()
    {
        console.log('showWindowBuyOneClick');
        if(!this.popupWindowByOneClick) {
            this.popupWindowByOneClick = BX.PopupWindowManager.create("popup-message-buy", null, {
                content: BX('popup-swift-order'),
                width: 400, // ширина окна
                height: 360, // высота окна
                zIndex: 100, // z-index
                closeIcon: {
                    // объект со стилями для иконки закрытия, при null - иконки не будет
                    opacity: 1
                },
                titleBar: 'Быстрый заказ',
                closeByEsc: true, // закрытие окна по esc
                darkMode: false, // окно будет светлым или темным
                autoHide: false, // закрытие при клике вне окна
                draggable: false, // можно двигать или нет
                resizable: false, // можно ресайзить
                min_height: 100, // минимальная высота окна
                min_width: 100, // минимальная ширина окна
                lightShadow: true, // использовать светлую тень у окна
                angle: false, // появится уголок
                overlay: {
                    // объект со стилями фона
                    opacity: 200
                },
            });
            this.setPhoneMask(BX(this.arrId.userPhonePopup));
            BX.bind(BX('ach-popup-btn'), 'click', BX.proxy(this.popupSwiftOrder, this));
        }
        //console.log(this.PopupWindowByOneClick);
        this.popupWindowByOneClick.show();
    },

    popupSwiftOrder: function ()
    {
        var name = BX(this.arrId.userNamePopup),
            phone = BX(this.arrId.userPhonePopup),
            re = /[(]?[0-9]{3}[)]? [0-9]{3} [0-9]{2} [0-9]{2}/i,
            i;

        if(name.value.length > 3)
        {
            name.style.borderColor = '#eaebec';
        }
        else
        {
            name.style.borderColor = 'red';
            return false;
        }

        if(!re.test(phone.value))
        {
            phone.style.borderColor = 'red';
            return false;
        }
        else
        {
            phone.style.borderColor = '#eaebec';
        }

        for(i = 0; i < this.result.PAY_SYSTEM.length; i++)
        {
            if(this.result.PAY_SYSTEM[i].ACTIVE == 'Y')
            {
                this.order.paySystem.id = this.result.PAY_SYSTEM[i].ID;
                break;
            }
        }

        this.orderCheck = true;
        this.action = 'makeCurrentOrder';
        this.order.userInfo.name = name.value;
        this.order.userInfo.phone = phone.value;
        this.order.userInfo.email = '';
        this.order.delivery.address = '';

        this.sendRequest(this.action);
        BX.unbind(BX('ach-popup-btn'), 'click', BX.proxy(this.popupSwiftOrder, this));
        this.popupWindowByOneClick.setContent('<br><h3>Загрузка ... </h3>');
    }
};
