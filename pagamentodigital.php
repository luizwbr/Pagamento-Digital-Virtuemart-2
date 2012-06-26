<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

/**
 * @version $Id: pagamentodigital.php,v 1.4 2005/05/27 19:33:57 ei
 *
 * a special type of 'cash on delivey':
 * @author Max Milbers, ValÃ©rie Isaksen, Luiz Weber
 * @version $Id: pagamentodigital.php 5122 2012-02-07 12:00:00Z luizwbr $
 * @package VirtueMart
 * @subpackage payment
 * @copyright Copyright (C) 2004-2008 soeren - All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.net
 */
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentPagamentodigital extends vmPSPlugin {

    // instance of class
    public static $_this = false;

    function __construct(& $subject, $config) {
        //if (self::$_this)
        //   return self::$_this;
        parent::__construct($subject, $config);

        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = array('payment_logos' => array('', 'char'),
            'email_cobranca' => array('', 'string'),
            'cod_loja' => array('', 'string'),
            'chave' => array('', 'string'),
            'limite_parcelamento' => array('', 'int'),
            'status_aprovado'=> array('', 'char'),
            'status_cancelado'=> array('', 'char'),
            'status_aguardando'=> array('', 'char'),
			'segundos_redirecionar'=> array('', 'string')            
        );

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }
    /**
     * Create the table for this plugin if it does not yet exist.
     * @author ValÃ©rie Isaksen
     */
    protected function getVmPluginCreateTableSQL() {
        return $this->createTableSQL('Payment Pagamentodigital Table');
    }

    /**
     * Fields to create the payment table
     * @return string SQL Fileds
     */
    function getTableSQLFields() {
        $SQLfields = array(
            'id' => 'tinyint(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_currency' => 'char(3) ',
            'cost_per_transaction' => ' decimal(10,2) DEFAULT NULL ',
            'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
            'tax_id' => 'smallint(11) DEFAULT NULL'
        );

        return $SQLfields;
    }
    
    function getPluginParams(){
        $db = JFactory::getDbo();
        $sql = "select virtuemart_paymentmethod_id from #__virtuemart_paymentmethods where payment_element = 'pagamentodigital'";
        $db->setQuery($sql);
        $id = (int)$db->loadResult();
        return $this->getVmPluginMethod($id);
    }

    /**
     *
     *
     * @author ValÃ©rie Isaksen
     */
    function plgVmConfirmedOrder($cart, $order) {

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
		// $params = new JParameter($payment->payment_params);

        if (!class_exists('VirtueMartModelOrders'))
            require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
        $this->getPaymentCurrency($method);
        // END printing out HTML Form code (Payment Extra Info)
        $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
        $db = &JFactory::getDBO();
        $db->setQuery($q);
        $currency_code_3 = $db->loadResult();
        $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
        $totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
        $cd = CurrencyDisplay::getInstance($cart->pricesCurrency);


        $this->_virtuemart_paymentmethod_id = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['payment_name'] = $this->renderPluginName($method);
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $currency_code_3;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency;
        $dbValues['tax_id'] = $method->tax_id;
        $this->storePSPluginInternalData($dbValues);

		JFactory::getApplication()->enqueueMessage(utf8_encode(
			"Seu pedido foi realizado com sucesso. Voc&ecirc; ser&aacute; direcionado para o site do Pagamento Digital, onde efetuar&aacute; o pagamento da sua compra.")
		);

		$html = $this->retornaHtmlPagamento( $order, $method, 1);
		
		$novo_status = $method->status_aguardando;
		return $this->processConfirmedOrderPaymentResponse(1, $cart, $order, $html, $dbValues['payment_name'], $novo_status);
    }

	function retornaHtmlPagamento( $order, $method, $redir ) {
		$lang = JFactory::getLanguage();
        $filename = 'com_virtuemart';
        $lang->load($filename, JPATH_ADMINISTRATOR);
        $vendorId = 0;
		
		$html = '<table>' . "\n";
        $html .= $this->getHtmlRow('STANDARD_PAYMENT_INFO', $dbValues['payment_name']);
        if (!empty($payment_info)) {
            $lang = & JFactory::getLanguage();
            if ($lang->hasKey($method->payment_info)) {
                $payment_info = JTExt::_($method->payment_info);
            } else {
                $payment_info = $method->payment_info;
            }
            $html .= $this->getHtmlRow('STANDARD_PAYMENTINFO', $payment_info);
        }
        if (!class_exists('VirtueMartModelCurrency')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}

        $currency = CurrencyDisplay::getInstance('', $order['details']['BT']->virtuemart_vendor_id);
        $html .= $this->getHtmlRow('STANDARD_ORDER_NUMBER', $order['details']['BT']->order_number);
        $html .= $this->getHtmlRow('STANDARD_AMOUNT', $currency->priceDisplay($order['details']['BT']->order_total));
        $html .= '</table>' . "\n";
	
        $html .= '<form name="pagdigital" id="pagdigital" action="https://www.pagamentodigital.com.br/checkout/pay/" method="post">  ';
        $html .= '<input type="hidden" name="email_loja" value="' . $method->email_cobranca . '"  />';
		$html .= '<input type="hidden" name="cod_loja" value="'.$method->cod_loja.'"  />';
		$html .= '<input type="hidden" name="chave" value="'.$method->chave.'"  />';
		$html .= '<input name="tipo_integracao" type="hidden" value="PAD">';
		$html .= '<input type="hidden" name="id_pedido" value="' . $order["details"]["BT"]->order_number . '"  />';
		$url_retorno = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification') ;		
		$html .= '<input type="hidden" name="url_retorno" value="' . $url_retorno . '"  />';
		$url_redirect = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id);
		$html .= '<input type="hidden" name="redirect" value="' . $url_redirect . '"  />';
		
		/*	
		// Desconto do pedido
		if ($db->f("order_discount") != 0.00) {
			echo '<input type="hidden" name="desconto" value="'.$db->f("order_discount").'" />';    
		}
		// Desconto do pedido
		if ($db->f("order_tax") != 0.00) {
			echo '<input type="hidden" name="acrescimo" value="'.$db->f("order_tax").'" />';
		}
		*/

		$zip = $order["details"]["BT"]->zip;
		$replacements = array(" ", ".", ",", "-", ";");	
		$zip = str_replace($replacements, "", $zip);
		
		$html .= '<input type="hidden" name="nome" value="' . $order["details"]["BT"]->first_name . ' ' . $order["details"]["BT"]->last_name . '"  />
		<input type="hidden" name="cep" value="' . $zip . '"  />
		<input type="hidden" name="endereco" value="' . $order["details"]["BT"]->address_1 . (isset($order["details"]["BT"]->numero)?',' .$order["details"]["BT"]->numero:'') . '"  />
		<input type="hidden" name="complemento" value="' . (isset($order["details"]["BT"]->address_2)?$order["details"]["BT"]->address_2:'') . '"  />
		<input type="hidden" name="cidade" value="' . $order["details"]["BT"]->city . '"  />
		<input type="hidden" name="estado" value="' . ShopFunctions::getStateByID($order["details"]["BT"]->virtuemart_state_id, "state_2_code") . '"  />
		<input type="hidden" name="cliente_pais" value="BRA"  />
		<input type="hidden" name="cliente_tel" value="' . $order["details"]["BT"]->phone_1 . '"  />
		<input type="hidden" name="email" value="' . $order["details"]["BT"]->email . '"  />';

		if (isset($order["details"]["BT"]->cpf)) {
			$html .= '<input type="hidden" name="cpf" value="' . $order["details"]["BT"]->cpf . '"  />';
		}		
		$html .= '<input type="hidden" name="valor" value="'.number_format(floatval($order['details']['BT']->order_total), 2, ".", "").'" />';
		$html .= '<input type="hidden" name="tipo_frete" value="'. strip_tags(str_replace('</span><span','</span> - <span',$cart->cartData['shipmentName'])) .'" />';
		$html .= '<input type="hidden" name="frete" value="'.number_format($order["details"]["BT"]->order_shipment ,2,'.','').'" />';	
	
		if(!class_exists('VirtueMartModelCustomfields'))require(JPATH_VM_ADMINISTRATOR.DS.'models'.DS.'customfields.php');
			
		// peso do produto
		foreach ($order['items'] as $p) {
			$i++;
			$product_attribute = strip_tags(VirtueMartModelCustomfields::CustomsFieldOrderDisplay($p,'FE'));
			$html .='<input type="hidden" name="produto_codigo_' . $i . '" value="' . $p->order_item_sku . '">
				<input type="hidden" name="produto_descricao_' . $i . '" value="' . $p->order_item_name . '">
				<input type="hidden" name="produto_qtde_' . $i . '" value="' . $p->product_quantity . '">
				<input type="hidden" name="produto_extra_' . $i . '" value="' . $product_attribute  . '">
				<input type="hidden" name="produto_valor_' . $i . '" value="' . number_format($p->product_final_price, 2, ".", "") .' ">';		
		}
		
		$url 	= JURI::root();
		$url_lib 			= $url.DS.'plugins'.DS.'vmpayment'.DS.'pagamentodigital'.DS;
		$url_imagem_pagamento 	= $url_lib . 'imagens'.DS.'pagamentodigital.png';

		// segundos para redirecionar para o Pagamento Digital
		if ($redir) {
			$segundos = $method->segundos_redirecionar;				
			$html .= '<br/><br/>Voc&ecirc; ser&aacute; direcionado para a tela de pagamento em '.$segundos.' segundo(s), ou ent&atilde;o clique logo abaixo:<br />';
			$html .= '<script>setTimeout(\'document.getElementById("pagdigital").submit();\','.$segundos.'000);</script>';
		} 
		$html .= '<div align="center"><br /><input type="image" value="Clique aqui para efetuar o pagamento" class="button" src="'.$url_imagem_pagamento.'" /></div>';
					
		$html .= '</form>';
		return $html;
	}
	
    /**
     * Display stored payment data for an order
     *
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return null; // Another method was selected, do nothing
        }

        $db = JFactory::getDBO();
        $q = 'SELECT * FROM `' . $this->_tablename . '` '
                . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($paymentTable = $db->loadObject())) {
            vmWarn(500, $q . " " . $db->getErrorMsg());
            return '';
        }
        $this->getPaymentCurrency($paymentTable);

        $html = '<table class="adminlist">' . "\n";
        $html .=$this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        $html .= '</table>' . "\n";
        return $html;
    }

    function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
        if (preg_match('/%$/', $method->cost_percent_total)) {
            $cost_percent_total = substr($method->cost_percent_total, 0, -1);
        } else {
            $cost_percent_total = $method->cost_percent_total;
        }
        return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     * @author: Valerie Isaksen
     *
     * @param $cart_prices: cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices) {

        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        $amount = $cart_prices['salesPrice'];
        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
                OR
                ($method->min_amount <= $amount AND ($method->max_amount == 0) ));
        if (!$amount_cond) {
            return false;
        }
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id']))
            $address['virtuemart_country_id'] = 0;
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
            return true;
        }

        return false;
    }

    /*
     * We must reimplement this triggers for joomla 1.7
     */

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the pagamentodigital method to create the tables
     * @author ValÃ©rie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author ValÃ©rie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /*
     * plgVmonSelectedCalculatePricePayment
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		
		$orderModel = VmModel::getModel('orders');
		$orderDetails = $orderModel->getOrder($virtuemart_order_id);
		if (!($method = $this->getVmPluginMethod($orderDetails['details']['BT']->virtuemart_paymentmethod_id))) {
			return false;
		}

		$view = JRequest::getVar('view');
		// somente retorna se estiver como transação pendente
		if ($method->status_aguardando == $orderDetails['details']['BT']->order_status and $view == 'orders') {
			
			JFactory::getApplication()->enqueueMessage(utf8_encode(
				"O pagamento deste pedido consta como Pendente de pagamento ainda. Clique pra  Voc&ecirc; ser&aacute; direcionado para o site do Pagamento Digital, onde efetuar&aacute; o pagamento da sua compra.")
			);
			
			$redir = 0;
			$html = $this->retornaHtmlPagamento( $orderDetails, $method, $redir );
			echo $html;
		}
	
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     * @author Max Milbers

      public function plgVmOnCheckoutCheckDataPayment(  VirtueMartCart $cart) {
      return null;
      }
     */

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id) {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    //Notice: We only need to add the events, which should work for the specific plugin, when an event is doing nothing, it should not be added

    /**
     * Save updated order data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     * @author Oscar van Eijk
     *
      public function plgVmOnUpdateOrderPayment(  $_formData) {
      return null;
      }

      /**
     * Save updated orderline data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     * @author Oscar van Eijk
     *
      public function plgVmOnUpdateOrderLine(  $_formData) {
      return null;
      }

      /**
     * plgVmOnEditOrderLineBE
     * This method is fired when editing the order line details in the backend.
     * It can be used to add line specific package codes
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     * @author Oscar van Eijk
     *
      public function plgVmOnEditOrderLineBEPayment(  $_orderId, $_lineId) {
      return null;
      }

      /**
     * This method is fired when showing the order details in the frontend, for every orderline.
     * It can be used to display line specific package codes, e.g. with a link to external tracking and
     * tracing systems
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     * @author Oscar van Eijk
     *
      public function plgVmOnShowOrderLineFE(  $_orderId, $_lineId) {
      return null;
      }

      /**
     * This event is fired when the  method notifies you when an event occurs that affects the order.
     * Typically,  the events  represents for payment authorizations, Fraud Management Filter actions and other actions,
     * such as refunds, disputes, and chargebacks.
     *
     * NOTE for Plugin developers:
     *  If the plugin is NOT actually executed (not the selected payment method), this method must return NULL
     *
     * @param $return_context: it was given and sent in the payment form. The notification should return it back.
     * Used to know which cart should be emptied, in case it is still in the session.
     * @param int $virtuemart_order_id : payment  order id
     * @param char $new_status : new_status for this order id.
     * @return mixed Null when this method was not selected, otherwise the true or false
     *
     * @author Valerie Isaksen
     *
     *
      public function plgVmOnPaymentNotification() {
      return null;
      }
	*/
	function plgVmOnPaymentNotification() {
		
		header("Status: 200 OK");
		if (!class_exists('VirtueMartModelOrders'))
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		$pagdigital_data = $_REQUEST;
		
		
		if (!isset($pagdigital_data['id_transacao'])) {
			return;
		}		
		$order_number = $pagdigital_data['id_pedido'];
		$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
		//$this->logInfo('plgVmOnPaymentNotification: virtuemart_order_id  found ' . $virtuemart_order_id, 'message');

		if (!$virtuemart_order_id) {
			return;
		}
		$vendorId = 0;
		$payment = $this->getDataByOrderId($virtuemart_order_id);
		if($payment->payment_name == '') {
			return false;
		}
		$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		//$this->_debug = $method->debug;
		if (!$payment) {
			$this->logInfo('getDataByOrderId payment not found: exit ', 'ERROR');
			return null;
		}
		$this->logInfo('pagdigital_data ' . implode('   ', $pagdigital_data), 'message');

		// get all know columns of the table
		$db = JFactory::getDBO();
		$query = 'SHOW COLUMNS FROM `' . $this->_tablename . '` ';
		$db->setQuery($query);
		$columns = $db->loadResultArray(0);
		$post_msg = '';
		foreach ($pagdigital_data as $key => $value) {
			$post_msg .= $key . "=" . $value . "<br />";
			$table_key = 'pagdigital_response_' . $key;
			if (in_array($table_key, $columns)) {
			$response_fields[$table_key] = $value;
			}
		}

		//$response_fields[$this->_tablepkey] = $this->_getTablepkeyValue($virtuemart_order_id);
		//$response_fields['payment_name'] = $this->renderPluginName($method);
		$response_fields['payment_name'] = $payment->payment_name;
		//$response_fields['paypalresponse_raw'] = $post_msg;
		//$return_context = $pagdigital_data['custom'];
		$response_fields['order_number'] = $order_number;
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
	//$preload=true   preload the data here too preserve not updated data
		//$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);

		/*
		$error_msg = $this->_processIPN($pagdigital_data, $method);
		$this->logInfo('process IPN ' . $error_msg, 'message');		
		if (!(empty($error_msg) )) {
			$new_status = $method->status_canceled;
			$this->logInfo('process IPN ' . $error_msg . ' ' . $new_status, 'ERROR');
		} else {
			$this->logInfo('process IPN OK', 'message');
		}*/
			/*
			 * https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_html_IPNandPDTVariables
			 * The status of the payment:
			 * Canceled_Reversal: A reversal has been canceled. For example, you won a dispute with the customer, and the funds for the transaction that was reversed have been returned to you.
			 * Completed: The payment has been completed, and the funds have been added successfully to your account balance.
			 * Created: A German ELV payment is made using Express Checkout.
			 * Denied: You denied the payment. This happens only if the payment was previously pending because of possible reasons described for the pending_reason variable or the Fraud_Management_Filters_x variable.
			 * Expired: This authorization has expired and cannot be captured.
			 * Failed: The payment has failed. This happens only if the payment was made from your customer’s bank account.
			 * Pending: The payment is pending. See pending_reason for more information.
			 * Refunded: You refunded the payment.
			 * Reversed: A payment was reversed due to a chargeback or other type of reversal. The funds have been removed from your account balance and returned to the buyer. The reason for the reversal is specified in the ReasonCode element.
			 * Processed: A payment has been accepted.
			 * Voided: This authorization has been voided.
			 *
			 */
			if (empty($pagdigital_data['cod_status']) || ($pagdigital_data['cod_status'] != '0' && $pagdigital_data['cod_status'] != '1' && $pagdigital_data['cod_status'] != '2')) {
			//return false;
			}
			$pagdigital_status = $pagdigital_data['cod_status'];
			if ($pagdigital_status == 1) {
				$new_status = $method->status_aprovado;
			} elseif ($pagdigital_status == 2) {
				$new_status = $method->status_cancelado;	
			} else {
				$new_status = $method->status_aguardando;
			}

		$this->logInfo('plgVmOnPaymentNotification return new_status:' . $new_status, 'message');

		
		

		if ($virtuemart_order_id) {
			// send the email only if payment has been accepted
			if (!class_exists('VirtueMartModelOrders'))
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			$modelOrder = new VirtueMartModelOrders();
			$orderitems = $modelOrder->getOrder($virtuemart_order_id);
			$nb_history = count($orderitems['history']);
			$order['order_status'] = $new_status;
			$order['virtuemart_order_id'] = $virtuemart_order_id;
			$order['comments'] = 'O status do seu pedido '.$order_number.' no Pagamento Digital foi atualizado: '.utf8_encode($pagdigital_data['status']);
			//JText::sprintf('VMPAYMENT_PAYPAL_PAYMENT_CONFIRMED', $order_number);
			if ($nb_history == 1) {
			$order['comments'] .= "<br />" . JText::sprintf('VMPAYMENT_PAYPAL_EMAIL_SENT');
			$order['customer_notified'] = 0;
			} else {
			$order['customer_notified'] = 1;
			}
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
			if ($nb_history == 1) {
			if (!class_exists('shopFunctionsF'))
				require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
			shopFunctionsF::sentOrderConfirmedEmail($orderitems);
			$this->logInfo('Notification, sentOrderConfirmedEmail ' . $order_number. ' '. $new_status, 'message');
			}
		}
		//// remove vmcart
		$this->emptyCart($return_context);
    }
	
      /**
     * plgVmOnPaymentResponseReceived
     * This event is fired when the  method returns to the shop after the transaction
     *
     *  the method itself should send in the URL the parameters needed
     * NOTE for Plugin developers:
     *  If the plugin is NOT actually executed (not the selected payment method), this method must return NULL
     *
     * @param int $virtuemart_order_id : should return the virtuemart_order_id
     * @param text $html: the html to display
     * @return mixed Null when this method was not selected, otherwise the true or false
     *
     * @author Valerie Isaksen
     *
     *
      function plgVmOnPaymentResponseReceived(, &$virtuemart_order_id, &$html) {
      return null;
      }
     */
	 // retorno da transação para o pedido específico
	 function plgVmOnPaymentResponseReceived(&$html) {

		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);

		$vendorId = 0;
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
			return false;
		}
		if (!class_exists('VirtueMartCart'))
				require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		$payment_data = JRequest::get('post');
		$payment_name = $this->renderPluginName($method);
		$html = $this->_getPaymentResponseHtml($payment_data, $payment_name);

		if (!empty($payment_data)) {
			vmdebug('plgVmOnPaymentResponseReceived', $payment_data);
			$order_number = $payment_data['invoice'];
			$return_context = $payment_data['custom'];
			if (!class_exists('VirtueMartModelOrders'))
			require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );

			$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
			$payment_name = $this->renderPluginName($method);
			$html = $this->_getPaymentResponseHtml($payment_data, $payment_name);

			if ($virtuemart_order_id) {
				// send the email ONLY if payment has been accepted
				if (!class_exists('VirtueMartModelOrders'))
					require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );

				$modelOrder = new VirtueMartModelOrders();
				$orderitems = $modelOrder->getOrder($virtuemart_order_id);
				$nb_history = count($orderitems['history']);
				//vmdebug('history', $orderitems);
				if (!class_exists('shopFunctionsF'))
					require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
				if ($nb_history == 1) {
					if (!class_exists('shopFunctionsF'))
					require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
					shopFunctionsF::sentOrderConfirmedEmail($orderitems);
					$this->logInfo('plgVmOnPaymentResponseReceived, sentOrderConfirmedEmail ' . $order_number, 'message');
					$order['order_status'] = $orderitems['items'][$nb_history - 1]->order_status;
					$order['virtuemart_order_id'] = $virtuemart_order_id;
					$order['customer_notified'] = 0;
					$order['comments'] = JText::sprintf('VMPAYMENT_PAYPAL_EMAIL_SENT');
					$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
				}
			}
		}
		$cart = VirtueMartCart::getCart();
		//We delete the old stuff
		// get the correct cart / session
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		return true;
		}
		 
}

// No closing tag
