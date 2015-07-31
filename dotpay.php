<?php

class plgHikashoppaymentDotpay extends hikashopPaymentPlugin
{
	protected $autoloadLanguage = true;

	var $accepted_currencies = array( "PLN","EUR", "USD", "GBP", "JPY","CZK", "SEK" );
	var $multiple = true;
	var $name = 'dotpay';

	var $pluginConfig = array(
		'identifier' => array("Id",'input'),
		'pin' => array("Pin",'input'),
		'dotpay_mode' => array('Dotpay mode', 'list',array(
			'test' => 'Test',
			'prodution' => 'Production'
		)),
		'invalid_status' => array('INVALID_STATUS', 'orderstatus'),
		'pending_status' => array('PENDING_STATUS', 'orderstatus'),
		'verified_status' => array('VERIFIED_STATUS', 'orderstatus')
	);

	const API_VERSION = 'dev';
	const PAYMENT_TYPE = '0';
	const ADDRESS_TYPE = 'billing_address';
	const DOTPAY_URL = 'https://ssl.dotpay.pl/';
	const DOTPAY_TEST_URL = 'https://ssl.dotpay.pl/test_payment/';

	public function __construct(&$subject, $config)
	{
		$this->pluginConfig['notify_url'][2] = HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment='.$this->name.'&tmpl=component';
		return parent::__construct($subject, $config);
	}


	/**
	 * This method is internal hikashop method check out:
	 * http://www.hikashop.com/support/documentation/62-hikashop-developer-documentation.html#payment
	 *
	 * Is triggered after customer place an order
	 *
	 * @param $order
	 * @param $methods
	 * @param $method_id
	 * @return bool
	 */
	public function onAfterOrderConfirm(&$order,&$methods,$method_id)
	{
		parent::onAfterOrderConfirm($order,$methods,$method_id);

		if (!$this->isPaymentParametersValidate()){
			return false;
		}

		$this->vars = $this->prepareVarsArray($order);

		return $this->showPage('end');
	}

	public function getDotpayUrl()
	{
		if($this->payment_params->dotpay_mode == 'production'){
			return self::DOTPAY_URL;
		}
		return self::DOTPAY_TEST_URL;
	}


	/**
	 *
	 * This method is internal hikashop method check out:
	 * http://www.hikashop.com/support/documentation/62-hikashop-developer-documentation.html#payment
	 *
	 * Is triggered before customer place an order and here it use to validate
	 *
	 * @param $order
	 * @param $do
	 * @return bool
	 */
	public function onBeforeOrderCreate(&$order,&$do){
		if(parent::onBeforeOrderCreate($order, $do) === true)
			return true;

		if (!$this->isPaymentParametersValidate()){
			$do = false;
		}
	}

	/**
	 * This is internal hikashop method check out
	 * http://www.hikashop.com/support/documentation/62-hikashop-developer-documentation.html#payment
	 *
	 * It set default values when you create new payment method in admin
	 *
	 * @param $element
	 */
	public function getPaymentDefaultValues(&$element)
	{
		$element->payment_name='Dotpay';
		$element->payment_description='dotpay';
		$element->payment_images='dotpay';
		$element->payment_params->address_type="billing";
		$element->payment_params->invalid_status='cancelled';
		$element->payment_params->verified_status='confirmed';
	}

	/**
	 *  This is internal hikashop method check out
	 * http://www.hikashop.com/support/documentation/62-hikashop-developer-documentation.html#payment
	 *
	 *  It is triggered when payment provider send back notification to shop.
	 *  This method is responsible for recive request, validete and if  everything is correct it change order status
	 *
	 * @param $statuses
	 * @return bool|string
	 */
	public function onPaymentNotification(&$statuses)
	{
		$vars = $this->createVarsFromRequest();
		$orderId = (int)@$vars['control'];

		$orderDb = $this->loadOrderRelatedData($orderId);

		if(!$this->isNotificationValidated($vars, $orderDb) || $vars['operation_status'] == 'rejected'){
			$this->modifyOrder($orderId, $this->payment_params->invalid_status, true, true);
			return false;
		}
		if( $vars['operation_status'] == 'completed'){
			$this->modifyOrder($orderId, $this->payment_params->verified_status, true, true);
			return 'OK';
		}else{
			$this->modifyOrder($orderId, $this->payment_params->pending_status, true, true);
		}
	}

	/**
	 * This method prepare array which is send to dotpay based on order, payment configuration
	 * and customer address
	 *
	 * @param $order
	 * @return array
	 */
	private function prepareVarsArray($order)
	{
		$returnUrl =  HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id='.$order->order_id . $this->url_itemid;
		$address = $this->getAddress($order);
		$vars = array(
			'id' => $this->payment_params->identifier,
			'amount' => $this->getValidAmount($order),
			'currency' => $this->currency->currency_code,
			'control' => (string)$order->order_id,
			'description' => $order->order_number,
			'lang' => $this->getLanguage(),
			'URL' => $returnUrl,
			'URLC' => $this->pluginConfig['notify_url'][2],
			'type' => self::PAYMENT_TYPE,
			'api_version' => self::API_VERSION,
			// below additional optional parameters
			'firstname' => $address->address_firstname,
			'lastname'	=> $address->address_lastname,
			'email'	=> $order->customer->email,
			'street' => $address->address_street,
			'city'	=> $address->address_city,
			'postcode' => $address->address_post_code,
			'phone' => $address->address_telephone,
			'country' => $address->address_country->zone_name,
		);
		return $vars;
	}

	/**
	 * Return address of customer who place an order
	 *
	 * @param $order
	 * @return mixed
	 */
	private function getAddress($order)
	{
		$addressType = self::ADDRESS_TYPE;
		// below is equivalent to $order->cart->billing_address
		return $order->cart->$addressType;
	}

	/**
	 * Return language of joomla which is set by admin
	 *
	 * @return string
	 */
	private function getLanguage()
	{
		$lang = JFactory::getLanguage();
		return strtolower(substr($lang->get('tag'), 0, 2));
	}

	/**
	 * Check if dotpay parameters from config is not empty
	 *
	 * @return bool
	 */
	private function isPaymentParametersValidate()
	{
		if (!$this->payment_params->identifier || !$this->payment_params->pin){
			$this->app->enqueueMessage('You have to configure an identifier for the Example plugin payment first : check your plugin\'s parameters, on your website backend','error');
			return false;
		}
		return true;
	}

	/**
	 * Return price in correct format (two decimal character like: 10,00)
	 *
	 * @param $order
	 * @return float
	 */
	private function getValidAmount($order)
	{
		return round($order->cart->full_total->prices[0]->price_value_with_tax,2);
	}

	/**
	 * Check if notification is validated, check two parameters:
	 * Signature from dotpay, checkout dotpay documenation and
	 * price from order and returned from dotpay
	 *
	 * @param $vars
	 * @param $orderDb
	 * @return bool
	 */
	private function isNotificationValidated($vars, $orderDb)
	{
		if($_SERVER['REQUEST_METHOD'] != 'POST')
			return false;
		if(!$this->isTrustedIp())
			return false;
		if(!$this->isSignatureMatch($vars))
			return false;
		if(!$this->isPriceMatch($vars, $orderDb))
			return false;
		return true;
	}

	private function isTrustedIp()
	{
		if($_SERVER['REMOTE_ADDR'] == '195.150.9.37' || $_SERVER['REMOTE_ADDR'] == '127.0.0.1' ){
			return true;
		}
		return false;
	}

	/**
	 * It check if price from dotpay and from order are the same
	 *
	 * @param $vars
	 * @param $orderDb
	 * @return bool
	 */
	private function isPriceMatch($vars, $orderDb)
	{
		$price = round($orderDb->order_full_price, 2);
		if(isset($vars['operation_original_amount']) && $vars['operation_original_amount'] == $price){
			return true;
		}
	}

	/**
	 * Check if signature from dotpay is the same like calculate signature
	 *
	 * @param $vars
	 * @return bool
	 *
	 */
	private function isSignatureMatch($vars)
	{
		if(isset($vars['signature']) && $this->calculateSignature($vars) == $vars['signature']){
			return true;
		}
	}

	/**
	 * Calculate sygnature based on dotpay parameters and pin
	 *
	 * @param $vars
	 * @return string
	 */
	private function calculateSignature($vars)
	{
		$string = $this->payment_params->pin .
		(isset($vars['id']) ? $vars['id'] : '').
		(isset($vars['operation_number']) ? $vars['operation_number'] : '').
		(isset($vars['operation_type']) ? $vars['operation_type'] : '').
		(isset($vars['operation_status']) ? $vars['operation_status'] : '').
		(isset($vars['operation_amount']) ? $vars['operation_amount'] : '').
		(isset($vars['operation_currency']) ? $vars['operation_currency'] : '').
		(isset($vars['operation_withdrawal_amount']) ? $vars['operation_withdrawal_amount'] : '').
		(isset($vars['operation_commission_amount']) ? $vars['operation_commission_amount'] : '').
		(isset($vars['operation_original_amount']) ? $vars['operation_original_amount'] : '').
		(isset($vars['operation_original_currency']) ? $vars['operation_original_currency'] : '').
		(isset($vars['operation_datetime']) ? $vars['operation_datetime'] : '').
		(isset($vars['operation_related_number']) ? $vars['operation_related_number'] : '').
		(isset($vars['control']) ? $vars['control'] : '').
		(isset($vars['description']) ? $vars['description'] : '').
		(isset($vars['email']) ? $vars['email'] : '').
		(isset($vars['p_info']) ? $vars['p_info'] : '').
		(isset($vars['p_email']) ? $vars['p_email'] : '').
		(isset($vars['channel']) ? $vars['channel'] : '').
		(isset($vars['channel_country']) ? $vars['channel_country'] : '').
		(isset($vars['geoip_country']) ? $vars['geoip_country'] : '');
		return hash('sha256', $string);
	}

	/**
	 * This method find order based on it id and then assign order parameter to class variables
	 *  check out loadOrderParams and loadOrderData in parent class
	 *
	 * @param $orderId
	 * @return bool|null
	 */
	private function loadOrderRelatedData($orderId)
	{
		$dbOrder = $this->getOrder($orderId);
		$this->loadPaymentParams($dbOrder);
		if(empty($this->payment_params))
			return false;

		$this->loadOrderData($dbOrder);
		return $dbOrder;
	}

	/**
	 * Modify request from dotpay to another format
	 *
	 * @return array
	 */
	private function createVarsFromRequest()
	{
		$vars = array();

		$filter = JFilterInput::getInstance();

		foreach($_REQUEST as $key => $value)
		{
			$key = $filter->clean($key);
			$value = JRequest::getString($key);
			$vars[$key]=$value;
		}
		return $vars;
	}
}
