<?php
/**
 * @copyright  Copyright (c) 2013 PayU
 * @license	http://opensource.org/licenses/LGPL-3.0  Open Software License (LGPL 3.0)
 *
 * http://www.payu.com
 * http://openpayu.com
 * http://twitter.com/openpayu
 *
 */


if (!defined('_PS_VERSION_'))
	exit;

include_once(_PS_MODULE_DIR_.'payu/sdk/openpayu.php');

class PayU extends PaymentModule
{
	/**
	 * PayU - payment statuses
	 *
	 * @var string
	 */
	const PAYMENT_STATUS_NEW = 'PAYMENT_STATUS_NEW';
	const PAYMENT_STATUS_CANCEL = 'PAYMENT_STATUS_CANCEL';
	const PAYMENT_STATUS_REJECT = 'PAYMENT_STATUS_REJECT';
	const PAYMENT_STATUS_INIT = 'PAYMENT_STATUS_INIT';
	const PAYMENT_STATUS_SENT = 'PAYMENT_STATUS_SENT';
	const PAYMENT_STATUS_NOAUTH = 'PAYMENT_STATUS_NOAUTH';
	const PAYMENT_STATUS_REJECT_DONE = 'PAYMENT_STATUS_REJECT_DONE';
	const PAYMENT_STATUS_END = 'PAYMENT_STATUS_END';
	const PAYMENT_STATUS_ERROR = 'PAYMENT_STATUS_ERROR';

	/**
	 * PayU - order statuses
	 *
	 * @var string
	 */
	const ORDER_STATUS_PENDING = 'ORDER_STATUS_PENDING';
	const ORDER_STATUS_SENT = 'ORDER_STATUS_SENT';
	const ORDER_STATUS_COMPLETE = 'ORDER_STATUS_COMPLETE';
	const ORDER_STATUS_CANCEL = 'ORDER_STATUS_CANCEL';
	const ORDER_STATUS_REJECT = 'ORDER_STATUS_REJECT';

	const BUSINESS_PARTNER_TYPE_EPAYMENT = 'epayment';
	const BUSINESS_PARTNER_TYPE_PLATNOSCI = 'platnosci';

	public $cart = null;
	public $id_cart = null;
	private $order = null;
	public $id_session = '';
	public $id_order = null;

	/**
	 *
	 */
	public function __construct()
	{
		$this->name = 'payu';
		$this->tab = 'payments_gateways';
		$this->version = '2.0.0';
		$this->author = 'PayU';
		$this->need_instance = 0;
		$this->ps_versions_compliancy = array('min' => '1.4.4', 'max' => '1.6');

		$this->currencies = true;
		$this->currencies_mode = 'radio';

		parent::__construct();

		$this->displayName = $this->display_name = $this->l('PayU');
		$this->description = $this->l('Accepts payments by PayU');

		$this->confirm_uninstall = $this->l('Are you sure you want to uninstall? You will lose all your settings!');

		if (version_compare(_PS_VERSION_, '1.5', 'lt'))
			require(_PS_MODULE_DIR_.$this->name.'/backward_compatibility/backward.php');

		$this->initializeOpenPayU();
	}

	/**
	 * @return bool
	 */
	public function install()
	{
		return (
			function_exists('curl_version') &&
				parent::install() &&
				in_array('curl', get_loaded_extensions()) &&
				$this->createInitialDbTable() &&
				$this->createPaymentTable() &&
				$this->registerHook('leftColumn') &&
				$this->registerHook('rightColumn') &&
				$this->registerHook('header') &&
				$this->registerHook('payment') &&
				$this->registerHook('paymentReturn') &&
				(version_compare(_PS_VERSION_, '1.5', 'ge') || $this->registerHook('shoppingCartExtra')) &&
				(version_compare(_PS_VERSION_, '1.5', 'lt') || $this->registerHook('shoppingCart')) &&
				$this->registerHook('backOfficeHeader') &&
				$this->registerHook('adminOrder') &&
				$this->registerHook('updateOrderStatus') &&
				Configuration::updateValue('PAYU_ENVIRONMENT', 'sandbox') &&
				Configuration::updateValue('PAYU_SANDBOX_POS_ID', '') &&
				Configuration::updateValue('PAYU_SANDBOX_POS_AUTH_KEY', '') &&
				Configuration::updateValue('PAYU_SANDBOX_CLIENT_SECRET', '') &&
				Configuration::updateValue('PAYU_SANDBOX_SIGNATURE_KEY', '') &&
				Configuration::updateValue('PAYU_POS_ID', '') &&
				Configuration::updateValue('PAYU_POS_AUTH_KEY', '') &&
				Configuration::updateValue('PAYU_CLIENT_SECRET', '') &&
				Configuration::updateValue('PAYU_SIGNATURE_KEY', '') &&
				Configuration::updateValue('PAYU_EPAYMENT_MERCHANT', '') &&
				Configuration::updateValue('PAYU_EPAYMENT_SECRET_KEY', '') &&
				Configuration::updateValue('PAYU_EPAYMENT_IPN', '1') &&
				Configuration::updateValue('PAYU_EPAYMENT_IDN', '1') &&
				Configuration::updateValue('PAYU_EPAYMENT_IRN', '1') &&
				Configuration::updateValue('PAYU_SELF_RETURN', 1) &&
				Configuration::updateValue('PAYU_VALIDITY_TIME', 1440) &&
				Configuration::updateValue('PAYU_ONE_STEP_CHECKOUT', 1) &&
				Configuration::updateValue('PAYU_PAYMENT_STATUS_PENDING', $this->addNewOrderState('PAYU_PAYMENT_STATUS_PENDING',
					array('en' => 'PayU payment started', 'pl' => 'Płatność PayU rozpoczęta', 'ro' => 'PayU payment started',
						'ru' => 'PayU payment started', 'ua' => 'PayU payment started', 'hu' => 'PayU payment started',
						'tr' => 'PayU payment started'))) &&
				Configuration::updateValue('PAYU_PAYMENT_STATUS_SENT', $this->addNewOrderState('PAYMENT_PAYU_AWAITING_STATE',
					array('en' => 'PayU payment awaits for reception', 'pl' => 'Płatność PayU oczekuje na odbiór',
						'ro' => 'PayU payment awaits for reception', 'ru' => 'PayU payment awaits for reception',
						'ua' => 'PayU payment awaits for reception', 'hu' => 'PayU payment awaits for reception',
						'tr' => 'PayU payment awaits for reception'))) &&
				Configuration::updateValue('PAYU_PAYMENT_STATUS_COMPLETED', 2) &&
				Configuration::updateValue('PAYU_PAYMENT_STATUS_CANCELED', 6) &&
				Configuration::updateValue('PAYU_PAYMENT_STATUS_REJECTED', 7) &&
				Configuration::updateValue('PAYU_PAYMENT_STATUS_DELIVERED', 5)
		);
	}

	/**
	 * @return bool
	 */
	public function uninstall()
	{
		if (!parent::uninstall() ||
			!Configuration::deleteByName('PAYU_NAME') ||
			!Configuration::deleteByName('PAYU_ENVIRONMENT') ||
			!Configuration::deleteByName('PAYU_SANDBOX_POS_ID') ||
			!Configuration::deleteByName('PAYU_SANDBOX_POS_AUTH_KEY') ||
			!Configuration::deleteByName('PAYU_SANDBOX_CLIENT_SECRET') ||
			!Configuration::deleteByName('PAYU_SANDBOX_SIGNATURE_KEY') ||
			!Configuration::deleteByName('PAYU_POS_ID') ||
			!Configuration::deleteByName('PAYU_POS_AUTH_KEY') ||
			!Configuration::deleteByName('PAYU_CLIENT_SECRET') ||
			!Configuration::deleteByName('PAYU_SIGNATURE_KEY') ||
			!Configuration::deleteByName('PAYU_EPAYMENT_MERCHANT') ||
			!Configuration::deleteByName('PAYU_EPAYMENT_SECRET_KEY') ||
			!Configuration::deleteByName('PAYU_EPAYMENT_IPN') ||
			!Configuration::deleteByName('PAYU_EPAYMENT_IDN') ||
			!Configuration::deleteByName('PAYU_EPAYMENT_IRN') ||
			!Configuration::deleteByName('PAYU_SELF_RETURN') ||
			!Configuration::deleteByName('PAYU_VALIDITY_TIME') ||
			!Configuration::deleteByName('PAYU_ONE_STEP_CHECKOUT') ||
			!Configuration::deleteByName('PAYU_PAYMENT_STATUS_PENDING') ||
			!Configuration::deleteByName('PAYU_PAYMENT_STATUS_SENT') ||
			!Configuration::deleteByName('PAYU_PAYMENT_STATUS_COMPLETED') ||
			!Configuration::deleteByName('PAYU_PAYMENT_STATUS_CANCELED') ||
			!Configuration::deleteByName('PAYU_PAYMENT_STATUS_REJECTED') ||
			!Configuration::deleteByName('PAYU_PAYMENT_STATUS_DELIVERED') ||
			!Configuration::deleteByName('PAYU_PAYMENT_ADVERT') ||
			!Configuration::deleteByName('PAYU_PAYMENT_BUTTON'))
			return false;

		return true;
	}

	/**
	 * @param $state
	 * @param $names
	 *
	 * @return bool
	 */
	private function addNewOrderState($state, $names)
	{
		if (Validate::isInt(Configuration::get($state)) ^ (Validate::isLoadedObject($order_state = new OrderState(Configuration::get($state)))))
		{
			$order_state = new OrderState();

			if (!empty($names))
			{
				foreach ($names as $code => $name)
					$order_state->name[Language::getIdByIso($code)] = $name;
			}

			$order_state->send_email = false;
			$order_state->invoice = false;
			$order_state->unremovable = false;
			$order_state->color = '#00AEEF';

			if (!$order_state->add() ||
				!Configuration::updateValue($state, $order_state->id) || !Configuration::updateValue($state, $order_state->id))
				return false;

			copy(_PS_MODULE_DIR_.$this->name.'/logo.gif', _PS_IMG_DIR_.'os/'.$order_state->id.'.gif');

			return $order_state->id;
		}

		return false;
	}

	/**
	 *
	 */
	protected function initializeOpenPayU()
	{
		if (Configuration::get('PAYU_ENVIRONMENT') == 'sandbox')
		{
			OpenPayUConfiguration::setEnvironment('sandbox');
			OpenPayUConfiguration::setMerchantPosid(Configuration::get('PAYU_SANDBOX_POS_ID'));
			OpenPayUConfiguration::setPosAuthkey(Configuration::get('PAYU_SANDBOX_POS_AUTH_KEY'));
			OpenPayUConfiguration::setClientId(Configuration::get('PAYU_SANDBOX_POS_ID'));
			OpenPayUConfiguration::setClientSecret(Configuration::get('PAYU_SANDBOX_CLIENT_SECRET'));
			OpenPayUConfiguration::setSignatureKey(Configuration::get('PAYU_SANDBOX_SIGNATURE_KEY'));
		} else {
			OpenPayUConfiguration::setEnvironment('secure');
			OpenPayUConfiguration::setMerchantPosid(Configuration::get('PAYU_POS_ID'));
			OpenPayUConfiguration::setPosAuthkey(Configuration::get('PAYU_POS_AUTH_KEY'));
			OpenPayUConfiguration::setClientId(Configuration::get('PAYU_POS_ID'));
			OpenPayUConfiguration::setClientSecret(Configuration::get('PAYU_CLIENT_SECRET'));
			OpenPayUConfiguration::setSignatureKey(Configuration::get('PAYU_SIGNATURE_KEY'));
		}
	}

	/**
	 * @return bool
	 */
	private function createInitialDbTable()
	{
		return Db::getInstance()->Execute('CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'order_payu_payments` (
					`id_payu_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
					`id_order` INT(10) UNSIGNED NOT NULL,
					`id_cart` INT(10) UNSIGNED NOT NULL,
					`id_session` varchar(64) NOT NULL,
					`status` varchar(64) NOT NULL,
					`create_at` datetime,
					`update_at` datetime
				)');
	}


	/**
	 * @return mixed
	 */
	private function createPaymentTable()
	{
		return Db::getInstance()->Execute('
			CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'payu_payments` (
				`id_payu_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
				`id_order` INT UNSIGNED NOT NULL,
				`id_payu_transaction` INT UNSIGNED NOT NULL,
				`payu_amount` double,
				`payu_currency` varchar(3),
				`amount` double,
				`currency` varchar(3),
				`create_at` datetime,
				`update_at` datetime,
				 KEY `id_order` (`id_order`)
			);
		');
	}

	/**
	 * @return string
	 */
	public function getContent()
	{
		$output = null;
		$errors = array();

		if (Tools::isSubmit('submit'.$this->name))
		{
			if (!Configuration::updateValue('PAYU_ENVIRONMENT', Tools::getValue('PAYU_ENVIRONMENT')) ||
				!Configuration::updateValue('PAYU_PAYMENT_PLATFORM', Tools::getValue('PAYU_PAYMENT_PLATFORM')) ||
				!Configuration::updateValue('PAYU_SELF_RETURN', (int)Tools::getValue('PAYU_SELF_RETURN')) ||
				!Configuration::updateValue('PAYU_VALIDITY_TIME', Tools::getValue('PAYU_VALIDITY_TIME')) ||
				!Configuration::updateValue('PAYU_ONE_STEP_CHECKOUT', (int)Tools::getValue('PAYU_ONE_STEP_CHECKOUT')) ||
				!Configuration::updateValue('PAYU_SANDBOX_POS_ID', Tools::getValue('PAYU_SANDBOX_POS_ID')) ||
				!Configuration::updateValue('PAYU_SANDBOX_POS_AUTH_KEY', Tools::getValue('PAYU_SANDBOX_POS_AUTH_KEY')) ||
				!Configuration::updateValue('PAYU_SANDBOX_CLIENT_ID', Tools::getValue('PAYU_SANDBOX_CLIENT_ID')) ||
				!Configuration::updateValue('PAYU_SANDBOX_CLIENT_SECRET', Tools::getValue('PAYU_SANDBOX_CLIENT_SECRET')) ||
				!Configuration::updateValue('PAYU_SANDBOX_SIGNATURE_KEY', Tools::getValue('PAYU_SANDBOX_SIGNATURE_KEY')) ||
				!Configuration::updateValue('PAYU_POS_ID', Tools::getValue('PAYU_POS_ID')) ||
				!Configuration::updateValue('PAYU_POS_AUTH_KEY', Tools::getValue('PAYU_POS_AUTH_KEY')) ||
				!Configuration::updateValue('PAYU_CLIENT_ID', Tools::getValue('PAYU_CLIENT_ID')) ||
				!Configuration::updateValue('PAYU_CLIENT_SECRET', Tools::getValue('PAYU_CLIENT_SECRET')) ||
				!Configuration::updateValue('PAYU_SIGNATURE_KEY', Tools::getValue('PAYU_SIGNATURE_KEY')) ||
				!Configuration::updateValue('PAYU_PAYMENT_STATUS_PENDING', (int)Tools::getValue('PAYU_PAYMENT_STATUS_PENDING')) ||
				!Configuration::updateValue('PAYU_PAYMENT_STATUS_SENT', (int)Tools::getValue('PAYU_PAYMENT_STATUS_SENT')) ||
				!Configuration::updateValue('PAYU_PAYMENT_STATUS_COMPLETED', (int)Tools::getValue('PAYU_PAYMENT_STATUS_COMPLETED')) ||
				!Configuration::updateValue('PAYU_PAYMENT_STATUS_CANCELED', (int)Tools::getValue('PAYU_PAYMENT_STATUS_CANCELED')) ||
				!Configuration::updateValue('PAYU_PAYMENT_STATUS_REJECTED', (int)Tools::getValue('PAYU_PAYMENT_STATUS_REJECTED')) ||
				!Configuration::updateValue('PAYU_PAYMENT_STATUS_DELIVERED', (int)Tools::getValue('PAYU_PAYMENT_STATUS_DELIVERED')) ||
				!Configuration::updateValue('PAYU_PAYMENT_BUTTON', Tools::getValue('PAYU_PAYMENT_BUTTON')) ||
				!Configuration::updateValue('PAYU_PAYMENT_ADVERT', Tools::getValue('PAYU_PAYMENT_ADVERT')) ||

				!Configuration::updateValue('PAYU_EPAYMENT_MERCHANT', Tools::getValue('PAYU_EPAYMENT_MERCHANT')) ||
				!Configuration::updateValue('PAYU_EPAYMENT_SECRET_KEY', Tools::getValue('PAYU_EPAYMENT_SECRET_KEY')) ||
				!Configuration::updateValue('PAYU_EPAYMENT_IPN', (int)Tools::getValue('PAYU_EPAYMENT_IPN')) ||
				!Configuration::updateValue('PAYU_EPAYMENT_IDN', (int)Tools::getValue('PAYU_EPAYMENT_IDN')) ||
				!Configuration::updateValue('PAYU_EPAYMENT_IRN', (int)Tools::getValue('PAYU_EPAYMENT_IRN')))
				$errors[] = $this->l('Can not save configuration');

			if (!empty($errors))
				foreach ($errors as $error) $output .= $this->displayError($error);
			else
				$output .= $this->displayConfirmation($this->l('Settings updated'));
		}

		return $output.$this->displayForm();
	}

	/**
	 * @return mixed
	 */
	public function displayForm()
	{
		// Get default Language
		$default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
		$lang_iso_code = Language::getIsoById($default_lang);

		$media = $this->getMediaResourcesList($lang_iso_code);

		// Load current value
		$this->context->smarty->assign(array(
			'PAYU_PAYMENT_PLATFORM_EPAYMENT' => self::BUSINESS_PARTNER_TYPE_EPAYMENT,
			'PAYU_PAYMENT_PLATFORM_PLATNOSCI' => self::BUSINESS_PARTNER_TYPE_PLATNOSCI,
			'PAYU_PAYMENT_PLATFORM' => Configuration::get('PAYU_PAYMENT_PLATFORM'),
			'PAYU_PAYMENT_PLATFORM_OPTIONS' => $this->getBusinessPartnersList(),
			'PAYU_ENVIRONMENT' => Configuration::get('PAYU_ENVIRONMENT'),
			'PAYU_ENVIRONMENT_OPTIONS' => array(
				array(
					'id' => 'sandbox',
					'name' => $this->l('Yes')
				),
				array(
					'id' => 'secure',
					'name' => $this->l('No')
				)
			),
			'PAYU_SELF_RETURN' => Configuration::get('PAYU_SELF_RETURN'),
			'PAYU_SELF_RETURN_OPTIONS' => array(
				array(
					'id' => '1',
					'name' => $this->l('Yes')
				),
				array(
					'id' => '0',
					'name' => $this->l('No')
				)
			),
			'PAYU_VALIDITY_TIME' => Configuration::get('PAYU_VALIDITY_TIME'),
			'PAYU_VALIDITY_TIME_OPTIONS' => $this->getValidityTimeList(),
			'PAYU_ONE_STEP_CHECKOUT' => Configuration::get('PAYU_ONE_STEP_CHECKOUT'),
			'PAYU_ONE_STEP_CHECKOUT_OPTIONS' => array(
				array(
					'id' => '1',
					'name' => $this->l('Yes')
				),
				array(
					'id' => '0',
					'name' => $this->l('No')
				)
			),
			'PAYU_SANDBOX_POS_ID' => Configuration::get('PAYU_SANDBOX_POS_ID'),
			'PAYU_SANDBOX_POS_AUTH_KEY' => Configuration::get('PAYU_SANDBOX_POS_AUTH_KEY'),
			'PAYU_SANDBOX_CLIENT_ID' => Configuration::get('PAYU_SANDBOX_CLIENT_ID'),
			'PAYU_SANDBOX_CLIENT_SECRET' => Configuration::get('PAYU_SANDBOX_CLIENT_SECRET'),
			'PAYU_SANDBOX_SIGNATURE_KEY' => Configuration::get('PAYU_SANDBOX_SIGNATURE_KEY'),
			'PAYU_POS_ID' => Configuration::get('PAYU_POS_ID'),
			'PAYU_POS_AUTH_KEY' => Configuration::get('PAYU_POS_AUTH_KEY'),
			'PAYU_CLIENT_ID' => Configuration::get('PAYU_CLIENT_ID'),
			'PAYU_CLIENT_SECRET' => Configuration::get('PAYU_CLIENT_SECRET'),
			'PAYU_SIGNATURE_KEY' => Configuration::get('PAYU_SIGNATURE_KEY'),
			'PAYU_EPAYMENT_MERCHANT' => Configuration::get('PAYU_EPAYMENT_MERCHANT'),
			'PAYU_EPAYMENT_SECRET_KEY' => Configuration::get('PAYU_EPAYMENT_SECRET_KEY'),
			'PAYU_EPAYMENT_IPN' => Configuration::get('PAYU_EPAYMENT_IPN'),
			'PAYU_EPAYMENT_IPN_URL' => $this->getModuleAddress(true, true).'ipn.php',
			'PAYU_EPAYMENT_IPN_OPTIONS' => array(
				array(
					'id' => '1',
					'name' => $this->l('Enabled')
				),
				array(
					'id' => '0',
					'name' => $this->l('Disabled')
				)
			),
			'PAYU_EPAYMENT_IDN' => Configuration::get('PAYU_EPAYMENT_IDN'),
			'PAYU_EPAYMENT_IDN_OPTIONS' => array(
				array(
					'id' => '1',
					'name' => $this->l('Enabled')
				),
				array(
					'id' => '0',
					'name' => $this->l('Disabled')
				)
			),
			'PAYU_EPAYMENT_IRN' => Configuration::get('PAYU_EPAYMENT_IRN'),
			'PAYU_EPAYMENT_IRN_OPTIONS' => array(
				array(
					'id' => '1',
					'name' => $this->l('Enabled')
				),
				array(
					'id' => '0',
					'name' => $this->l('Disabled')
				)
			),
			'PAYU_PAYMENT_STATES_OPTIONS' => $this->getStatesList(),
			'PAYU_PAYMENT_STATUS_PENDING' => Configuration::get('PAYU_PAYMENT_STATUS_PENDING'),
			'PAYU_PAYMENT_STATUS_SENT' => Configuration::get('PAYU_PAYMENT_STATUS_SENT'),
			'PAYU_PAYMENT_STATUS_COMPLETED' => Configuration::get('PAYU_PAYMENT_STATUS_COMPLETED'),
			'PAYU_PAYMENT_STATUS_CANCELED' => Configuration::get('PAYU_PAYMENT_STATUS_CANCELED'),
			'PAYU_PAYMENT_STATUS_REJECTED' => Configuration::get('PAYU_PAYMENT_STATUS_REJECTED'),
			'PAYU_PAYMENT_STATUS_DELIVERED' => Configuration::get('PAYU_PAYMENT_STATUS_DELIVERED'),
			'PAYU_PAYMENT_BUTTON' => Configuration::get('PAYU_PAYMENT_BUTTON'),
			'PAYU_PAYMENT_BUTTON_OPTIONS' => $this->getMediaButtonsResourcesList($media),
			'PAYU_PAYMENT_ADVERT' => Configuration::get('PAYU_PAYMENT_ADVERT'),
			'PAYU_PAYMENT_ADVERT_OPTIONS' => $this->getMediaAdvertsResourcesList($media)
		));

		return $this->hookBackOfficeHeader().$this->fetchTemplate('/views/templates/admin/office.tpl');
	}

	/**
	 * @param $name
	 *
	 * @return mixed
	 */
	public function fetchTemplate($name)
	{
		if (version_compare(_PS_VERSION_, '1.4', 'lt'))
			$this->context->smarty->currentTemplate = $name;
		elseif (version_compare(_PS_VERSION_, '1.5', 'lt'))
		{
			$views = 'views/templates/';
			if (@filemtime(dirname(__FILE__).'/'.$name))
				return $this->display(__FILE__, $name);
			elseif (@filemtime(dirname(__FILE__).'/'.$views.'hook/'.$name))
				return $this->display(__FILE__, $views.'hook/'.$name);
			elseif (@filemtime(dirname(__FILE__).'/'.$views.'front/'.$name))
				return $this->display(__FILE__, $views.'front/'.$name);
			elseif (@filemtime(dirname(__FILE__).'/'.$views.'admin/'.$name))
				return $this->display(__FILE__, $views.'admin/'.$name);
		}

		return $this->display(__FILE__, $name);
	}

	/**
	 * @return string
	 */
	public function hookBackOfficeHeader()
	{
		$this->context->controller->addCSS(_MODULE_DIR_.$this->name.'/css/payu.css');

		$default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
		$lang_iso_code = Language::getIsoById($default_lang);

		$media = $this->getMediaResourcesList($lang_iso_code);

		$output = '<script type="text/javascript">var business_platforms = '.json_encode($this->getBusinessPartnersPayU($media)).';</script>';

		$vieworder = Tools::getValue('vieworder');
		$id_order = Tools::getValue('id_order');

		if (false !== $vieworder && false !== $id_order && $this->getBusinessPartnerSetting('type') === self::BUSINESS_PARTNER_TYPE_EPAYMENT)
		{
			$order = new Order($id_order);

			if (version_compare(_PS_VERSION_, '1.5', 'lt'))
			{
				$order_state = OrderHistory::getLastOrderState($id_order);
				$order_state_id = $order_state->id;
			} else
				$order_state_id = $order->current_state;

			if ($order->module = 'payu' &&
					($order_state_id == Configuration::get('PAYU_PAYMENT_STATUS_COMPLETED') || $order_state_id == Configuration::get('PAYU_PAYMENT_STATUS_DELIVERED') || $order_state_id == Configuration::get('PAYU_PAYMENT_STATUS_REJECTED')))
			{
				$refundable = true;
				$this->context->smarty->assign('payu_refund_full_amount', $order->total_paid);
				$this->context->smarty->assign('payu_refund_currency', $order->id_currency);
			} else
				$refundable = false;

		} else
			$refundable = false;

		$refund_type = Tools::getValue('payu_refund_type');
		$refund_amount = $refund_type === 'full' ? $order->total_paid : (float)Tools::getValue('payu_refund_amount');

		$this->context->smarty->assign('payu_refund_amount', $refund_amount);
		$this->context->smarty->assign('payu_refund_type', $refund_type);
		$this->context->smarty->assign('show_refund', $refundable);

		$errors = array();

		if (empty($errors) && $refundable && Tools::getValue('submitPayuRefund'))
		{ //  refund form is submitted

			if ($refund_amount > $order->total_paid)
				$errors[] = $this->l('The refund amount you entered is greater than paid amount.');

            $payu_trans = $this->getPayuTransaction($id_order);

			$ref_no = 0;
			if (version_compare(_PS_VERSION_, '1.5', 'lt')) {

                $ref_no = $payu_trans['id_payu_transaction'];
            } else {
				foreach ($order->getOrderPaymentCollection() as $payment)
					$ref_no = $payment->transaction_id;
			}

			if (empty($errors))
			{
				$currency = Currency::getCurrency($order->id_currency);

                if ($currency['iso_code'] != $payu_trans['currency'] && $payu_trans['payu_amount'] > 0) {
                    $refund_amount *= $payu_trans['payu_amount'] / $payu_trans['amount'];
                    $refund_curreny = $payu_trans['payu_currency'];
                } else {
                    $refund_curreny = $currency['iso_code'];
                }

				$irn = new PayuIRN(Configuration::get('PAYU_EPAYMENT_MERCHANT'), Configuration::get('PAYU_EPAYMENT_SECRET_KEY'));
				$irn->setQueryUrl($this->getBusinessPartnerSetting('irn_url'));
				$irn->setPayuReference($ref_no);
				$irn->setOrderAmount($payu_trans['payu_amount']);
				$irn->setRefundAmount($refund_amount);
				$irn->setOrderCurrency($refund_curreny);

				$irn_response = $irn->processRequest();
				
				if (!isset($irn_response['RESPONSE_CODE']) || 1 != $irn_response['RESPONSE_CODE']) {
					$error = isset($irn_response['RESPONSE_MSG'])?$irn_response['RESPONSE_MSG']:(is_string($irn_response)?strip_tags($irn_response):'unknown');
					$errors[] = $this->l('Refund error: ').$error;
				}

				if (empty($errors))
				{   //  change order status
					// Create new OrderHistory
					$history = new OrderHistory();
					$history->id_order = (int)$id_order;
					$history->id_employee = (int)$this->context->employee->id;

					$use_existings_payment = false;
					/*if (!$order->hasInvoice())
						$use_existings_payment = true;*/
					$history->changeIdOrderState(Configuration::get('PAYU_PAYMENT_STATUS_REJECTED'), $id_order, $use_existings_payment);
					$history->addWithemail(true, array());

					if (version_compare(_PS_VERSION_, '1.5', 'lt'))
						Tools::redirectAdmin('index.php?tab=AdminOrders&vieworder&id_order='.$id_order.'&token='.$_GET['token']);
					else
						Tools::redirectAdmin('index.php?controller=AdminOrders&vieworder&id_order='.$id_order.'&token='.$_GET['token']);
				}
			}
		}

		$this->context->smarty->assign('payu_refund_errors', $errors);

		return $output.$this->fetchTemplate('/views/templates/admin/header.tpl');
	}

	/**
	 * @param $lang_iso_code
	 * @return stdObject
	 */
	public function getMediaResourcesList($lang_iso_code)
	{
		$media_resources = $this->mediaOpenPayU($lang_iso_code);

		return $media_resources;
	}

	/**
	 * @param $media_resources
	 * @return array|null
	 */
	public function getMediaButtonsResourcesList($media_resources)
	{
		$list = array();

		if (empty($media_resources->buttons))
			return null;

		foreach ($media_resources->buttons as $button)
			$list[] = array('id' => $button, 'name' => $button);

		return $list;
	}

	/**
	 * @param $media_resources
	 * @return array|null
	 */
	public function getMediaAdvertsResourcesList($media_resources)
	{
		$list = array();

		if (empty($media_resources->adverts))
			return null;

		foreach ($media_resources->adverts as $group)
		{
			foreach($group as $advert)
				$list[] = array('id' => $advert, 'name' => $advert);
		}

		return $list;
	}

	/**
	 * Return PayU business partners
	 *
	 * @return array
	 */
	private function getBusinessPartnersPayU()
	{
		$business_partners = $this->jsonOpenPayU('business_partners');
		$business_partners = json_decode(json_encode($business_partners), true);

		if (empty($business_partners))
			$business_partners = array(
				'payu_pl' => array(
					'name' => 'PayU Poland - PayU',
					'type' => self::BUSINESS_PARTNER_TYPE_PLATNOSCI,
				),
				'payu_ro_epayment' => array(
					'name' => 'PayU Romania - ePayment',
					'type' => self::BUSINESS_PARTNER_TYPE_EPAYMENT,
					'lu_url' => 'https://secure.epayment.ro/order/lu.php',
					'idn_url' => 'https://secure.epayment.ro/order/idn.php',
					'irn_url' => 'https://secure.epayment.ro/order/irn.php'
				),
				'payu_ru_epayment' => array(
					'name' => 'PayU Russia - ePayment',
					'type' => self::BUSINESS_PARTNER_TYPE_EPAYMENT,
					'lu_url' => 'https://secure.payu.ru/order/lu.php',
					'idn_url' => 'https://secure.payu.ru/order/idn.php',
					'irn_url' => 'https://secure.payu.ru/order/irn.php'
				),
				'payu_ua_epayment' => array(
					'name' => 'PayU Ukraine - ePayment',
					'type' => self::BUSINESS_PARTNER_TYPE_EPAYMENT,
					'lu_url' => 'https://secure.payu.ua/order/lu.php',
					'idn_url' => 'https://secure.payu.ua/order/idn.php',
					'irn_url' => 'https://secure.payu.ua/order/irn.php'
				),
				'payu_tr_epayment' => array(
					'name' => 'PayU Turkey - ePayment',
					'type' => self::BUSINESS_PARTNER_TYPE_EPAYMENT,
					'lu_url' => 'https://secure.payu.com.tr/order/lu.php',
					'idn_url' => 'https://secure.payu.com.tr/order/idn.php',
					'irn_url' => 'https://secure.payu.com.tr/order/irn.php'
				),
				'payu_hu_epayment' => array(
					'name' => 'PayU Hungary - ePayment',
					'type' => self::BUSINESS_PARTNER_TYPE_EPAYMENT,
					'lu_url' => 'https://secure.payu.hu/order/lu.php',
					'idn_url' => 'https://secure.payu.hu/order/idn.php',
					'irn_url' => 'https://secure.payu.hu/order/irn.php'
				),
			);

		return $business_partners;
	}


	/**
	 * @param string $setting_name
	 * @param null|string $business_partner
	 * @return null|string
	 */
	public function getBusinessPartnerSetting($setting_name, $business_partner = null)
	{
		$business_partner = $business_partner === null ? Configuration::get('PAYU_PAYMENT_PLATFORM'): $business_partner;
		$settings = $this->getBusinessPartnersPayU();
		return isset($settings[$business_partner][$setting_name]) ? $settings[$business_partner][$setting_name] : null;
	}

	/**
	 * @return array
	 */
	private function getBusinessPartnersList()
	{
		$list = array();
		$business_partners_area_list = $this->getBusinessPartnersPayU();

		if (empty($business_partners_area_list))
			return array();

		foreach($business_partners_area_list as $id_area_partner => $partner)
			$list[] = array('id' => $id_area_partner, 'name' => $partner['name'], 'type' => $partner['type']);

		return $list;
	}


	/**
	 * @return mixed
	 */
	public function hookDisplayRightColumn()
	{
		return $this->hookDisplayLeftColumn();
	}

	/**
	 * @return mixed
	 */
	public function hookDisplayLeftColumn()
	{
		$img = Configuration::get('PAYU_PAYMENT_ADVERT');

		if (Configuration::get('PS_SSL_ENABLED'))
			$img = str_replace('http://', 'https://', $img);

		$this->context->smarty->assign('image', $img);

		return $this->fetchTemplate('/views/templates/hook/advertisement.tpl');
	}

	/**
	 *
	 */
	public function hookDisplayHeader()
	{
		$this->context->controller->addCSS($this->_path.'css/payu.css', 'all');
	}

	/**
	 * @return mixed
	 */
	public function hookPayment()
	{
		$img = Configuration::get('PAYU_PAYMENT_BUTTON');

		if (Configuration::get('PS_SSL_ENABLED'))
			$img = str_replace('http://', 'https://', $img);

		if (version_compare(_PS_VERSION_, '1.5', 'lt'))
			$link = $this->getModuleAddress().'backward_compatibility/payment.php';
		else
			$link = $this->context->link->getModuleLink('payu', 'payment');

		$this->context->smarty->assign(array('image' => $img, 'actionUrl' => $link));

		return $this->fetchTemplate('/views/templates/hook/payment.tpl');
	}

	/**
	 * @return mixed
	 */
	public function hookShoppingCartExtra()
	{
		return $this->hookShoppingCart();
	}

	/**
	 * @return mixed
	 */
	public function hookShoppingCart()
	{
		$img = Configuration::get('PAYU_PAYMENT_BUTTON');

		$this->context->smarty->assign(array('image' => $img));

		return $this->fetchTemplate('/views/templates/hook/cart.tpl');
	}

	/**
	 * @return null|string
	 */
	public function hookAdminOrder()
	{
		$output = '';
		$this->id_order = Tools::getValue('id_order');

		$order_payment = $this->getOrderPaymentByOrderId($this->id_order);

		if (!(int)Configuration::get('PAYU_SELF_RETURN') &&
			!($this->getBusinessPartnerSetting('type') == self::BUSINESS_PARTNER_TYPE_PLATNOSCI) &&
			!($order_payment['status'] == self::PAYMENT_STATUS_END))
			return null;

		$this->id_session = $order_payment['id_session'];

		if (Tools::isSubmit('submitpayustatus') && $this->id_session && $order_payment['status'] == self::PAYMENT_STATUS_SENT)
		{
			if (trim(Tools::getValue('PAYU_PAYMENT_STATUS')) &&
				$this->sendPaymentUpdate(trim(Tools::getValue('PAYU_PAYMENT_STATUS'))))
				$output .= $this->displayConfirmation($this->l('Update status request has been sent'));
			else
				$output .= $this->displayError($this->l('Update status request has not been completed correctly.'));
		}

		$this->context->smarty->assign(array(
			'PAYU_PAYMENT_STATUS_OPTIONS' => $this->getPaymentAcceptanceStatusesList(),
			'PAYU_PAYMENT_STATUS' => $order_payment['status']
		));

		return $output.$this->fetchTemplate('/views/templates/admin/status.tpl');
	}

	/**
	 * @param $status
	 * @return bool
	 */
	private function sendPaymentUpdate($status)
	{
		if (!empty($status) && !empty($this->id_session))
		{
			if ($status == self::ORDER_STATUS_CANCEL)
				$result = OpenPayUOrder::cancel($this->id_session, false);
			elseif ($status == self::ORDER_STATUS_COMPLETE)
				$result = OpenPayUOrder::updateStatus($this->id_session, $status, false);

			if ($result->getSuccess())
			{
				$this->updateOrderData();
				return true;
			} else {
				Logger::addLog($this->displayName.' '.trim($result->getError().' '.$result->getMessage().' '.$this->id_session), 1);
				return false;
			}
		}

		return false;
	}

	/**
	 * Hook display on payment return
	 *
	 * @return string Content
	 */
	public function paymentReturn()
	{
		global $smarty;

		$errorval = (int)Tools::getValue('error', 0);

		if ($errorval != 0)
			$smarty->assign(array('errormessage' => ''));

		return $this->fetchTemplate('/views/templates/front/', 'payment_return');
	}

	/**
	 * Return PayU json data
	 *
	 * @param string|null $section Section to return from json, or null to return all
	 * @param string $lang language ISO code
	 * @return stdClass
	 */
	private function jsonOpenPayU($section = null, $lang = 'en')
	{
		static $cache = array();

		if (!isset($cache[$lang]))
		{
			$url = 'http://openpayu.com/'.trim($lang).'/goods/json';

			$c = curl_init();
			curl_setopt($c, CURLOPT_URL, $url);
			curl_setopt($c, CURLOPT_POST, 0);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
			$content = curl_exec($c);
			$curl_info = curl_getinfo($c);
			curl_close($c);

			if ($curl_info['http_code'] != 200 && $lang != 'en')
				return $this->mediaOpenPayU('en');

			$cache[$lang] = Tools::jsonDecode($content);
		}

		return $section === null ? $cache[$lang] : (isset($cache[$lang]->$section) ? $cache[$lang]->$section : null);
	}

	/**
	 * Return PayU media data from json
	 * 
	 * @param string $lang
	 * @return stdClass
	 */
	private function mediaOpenPayU($lang = 'en')
	{
		return $this->jsonOpenPayU('media', $lang);
	}

	/**
	 * Convert to amount
	 *
	 * @param $value
	 * @return int
	 */
	private function toAmount($value)
	{
		return (int)($value * 100);
	}

	/**
	 * Convert to decimal
	 *
	 * @param $val
	 * @return float
	 */
	private function toDecimal($val)
	{
		return ($val / 100);
	}

	/**
	 * @return array
	 */
	private function getValidityTimeList()
	{
		return array(
			array(
				'id' => '1440',
				'name' => '1440 min (24h)'
			),
			array(
				'id' => '720',
				'name' => '720 min (12h)'
			),
			array(
				'id' => '360',
				'name' => '360 min (6h)'
			),
			array(
				'id' => '60',
				'name' => '60 min (1h)'
			),
			array(
				'id' => '30',
				'name' => '30 min'
			)
		);
	}

	/**
	 * @return array
	 */
	private function getPaymentAcceptanceStatusesList()
	{
		return $list = array(
			array('id' => self::ORDER_STATUS_COMPLETE, 'name' => 'Payment accepted'),
			array('id' => self::ORDER_STATUS_CANCEL, 'name' => 'Payment rejected')
		);
	}

	/**
	 * @return array|null
	 */
	public function getStatesList()
	{
		$states = OrderState::getOrderStates($this->context->language->id);
		$list = array();

		if (empty($states))
			return null;

		foreach ($states as $state) $list[] = array('id' => $state['id_order_state'], 'name' => $state['name']);

		return $list;
	}

	/**
	 * @param bool $http
	 * @param bool $entities
	 * @return string
	 */
	public function getModuleAddress($http = true, $entities = true)
	{
		return $this->getShopDomainAddress($http, $entities).(__PS_BASE_URI__.'modules/'.$this->name.'/');
	}

	/**
	 * @param bool $http
	 * @param bool $entities
	 * @return string
	 */
	public static function getShopDomainAddress($http = false, $entities = false)
	{
		if (method_exists('Tools', 'getShopDomainSsl'))
			return Tools::getShopDomainSsl($http, $entities);
		else {
			if (!($domain = Configuration::get('PS_SHOP_DOMAIN_SSL')))
				$domain = Tools::getHttpHost();

			if ($entities)
				$domain = htmlspecialchars($domain, ENT_COMPAT, 'UTF-8');

			if ($http)
				$domain = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').$domain;

			return $domain;
		}
	}

	/**
	 * @return array
	 */
	public function orderCreateRequest()
	{
		$currency = Currency::getCurrency($this->cart->id_currency);
		$return_array = array();

		$items = array();
		$total = 0;

		$cart_products = $this->cart->getProducts();

		if ($this->cart->isVirtualCart())
			$order_type = 'VIRTUAL';
		else
			$order_type = 'MATERIAL';

		foreach ($cart_products as $product)
		{
			$tax = explode('.', $product['rate']);
			$price_wt = $this->toAmount($product['price_wt']);
			$price = $this->toAmount($product['price']);
			$total += $this->toAmount($product['total_wt']);

			$item = array(
				'Quantity' => (int)$product['quantity'],
				'Product' => array(
					'Name' => $product['name'],
					'UnitPrice' => array(
						'Gross' => $price_wt,
						'Net' => $price,
						'Tax' => ($price_wt - $price)
					)
				)
			);

			if (!empty($tax[0]))
				$item['Product']['UnitPrice']['TaxRate'] = $tax[0];

			$item['Product']['UnitPrice']['CurrencyCode'] = $currency['iso_code'];

			$items[]['ShoppingCartItem'] = $item;
		}

		$carriers_list = $this->getCarriersListForCart($this->cart);

		$shipping_cost = array(
			'CountryCode' => Tools::strtoupper(Configuration::get('PS_LOCALE_COUNTRY')),
			'ShipToOtherCountry' => 'true',
			'ShippingCostList' => $carriers_list
		);

		if ($this->toAmount($this->cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING)) < $total)
		{
			$grand_total = $total;
			$discount_total = $total - $this->toAmount($this->cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING));
		} else {
			$grand_total = $this->toAmount($this->cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING));
			$discount_total = 0;
		}

		$shopping_cart = array(
			'GrandTotal' => $grand_total,
			'DiscountTotal' => $discount_total,
			'CurrencyCode' => $currency['iso_code'],
			'ShoppingCartItems' => $items
		);

		$order = array(
			'MerchantPosId' => OpenPayUConfiguration::getMerchantPosid(),
			'SessionId' => $_SESSION['sessionId'],
			'OrderUrl' => $this->context->link->getPageLink('guest-tracking.php', true),
			'OrderCreateDate' => date('c'),
			'ValidityTime' => (int)Configuration::get('PAYU_VALIDITY_TIME'),
			'InvoiceDisabled' => (int)Configuration::get('PS_INVOICE') ? 'false' : 'true',
			'OrderDescription' => $this->l('Order for cart: ').$this->cart->id.$this->l(' from the store: ').Configuration::get('PS_SHOP_NAME'),
			'MerchantAuthorizationKey' => OpenPayUConfiguration::getPosAuthkey(),
			'OrderType' => $order_type,
			'ShoppingCart' => $shopping_cart
		);

		if (version_compare(_PS_VERSION_, '1.5', 'gt'))
		{
			$order_complete_link = $this->context->link->getModuleLink('payu', 'success');
			$order_notify_link = $this->context->link->getModuleLink('payu', 'notification');
			$order_cancel_link = $this->context->link->getPageLink('order.php', true);
			$order_shipping_link = $this->context->link->getModuleLink('payu', 'shipping');
		} else {
			$link = new Link();
			$order_complete_link = $this->getModuleAddress().'backward_compatibility/success.php';
			$order_notify_link = $this->getModuleAddress().'backward_compatibility/notification.php';
			$order_cancel_link = $link->getPageLink(__PS_BASE_URI__.'order.php');
			$order_shipping_link = $this->getModuleAddress().'backward_compatibility/shipping.php';
		}

		$ocr = array(
			'ReqId' => md5(rand()),
			'CustomerIp' => (
			($_SERVER['REMOTE_ADDR'] == '::1' || $_SERVER['REMOTE_ADDR'] == '::' ||
				!preg_match('/^((?:25[0-5]|2[0-4][0-9]|[01]?[0-9]?[0-9]).){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9]?[0-9])$/m', $_SERVER['REMOTE_ADDR'])) ? '127.0.0.1' : $_SERVER['REMOTE_ADDR']
			),
			'NotifyUrl' => $order_notify_link,
			'OrderCancelUrl' => $order_cancel_link,
			'OrderCompleteUrl' => $order_complete_link.'?id_cart='.$this->cart->id.'&id_payu_session='.$_SESSION['sessionId'],
			'Order' => $order,
			'ShippingCost' => array(
				'AvailableShippingCost' => $shipping_cost,
				'ShippingCostsUpdateUrl' => $order_shipping_link.'?id_cart='.$this->cart->id
			)
		);

		if (!empty($this->cart->id_customer))
		{
			$customer = new Customer((int)$this->cart->id_customer);

			if ($customer->email)
			{
				$customer_sheet = array(
					'Email' => $customer->email,
					'FirstName' => $customer->firstname,
					'LastName' => $customer->lastname
				);

				if (!empty($this->cart->id_address_delivery))
				{
					$address = new Address((int)$this->cart->id_address_delivery);
					$country = new Country((int)$address->id_country);

					if (empty($address->phone))
						$customer_sheet['Phone'] = $address->phone_mobile;
					else
						$customer_sheet['Phone'] = $address->phone;

					$customer_sheet['Shipping'] = array(
						'Street' => $address->address1,
						'PostalCode' => $address->postcode,
						'City' => $address->city,
						'CountryCode' => Tools::strtoupper($country->iso_code),
						'AddressType' => 'SHIPPING',
						'RecipientName' => trim($address->firstname.' '.$address->lastname),
						'RecipientPhone' => $address->phone ? $address->phone : $address->phone_mobile,
						'RecipientEmail' => $customer->email
					);
				}

				if (!empty($this->cart->id_address_invoice) && Configuration::get('PS_INVOICE'))
				{
					$address = new Address((int)$this->cart->id_address_invoice);
					$country = new Country((int)$address->id_country);

					$customer_sheet['Invoice'] = array(
						'Street' => $address->address1,
						'PostalCode' => $address->postcode,
						'City' => $address->city,
						'CountryCode' => Tools::strtoupper($country->iso_code),
						'AddressType' => 'BILLING',
						'RecipientName' => trim($address->firstname.' '.$address->lastname),
						'TIN' => $address->vat_number
					);
				}

				$ocr['Customer'] = $customer_sheet;
			}
		}

		try
		{
			$result = OpenPayUOrder::create($ocr);

			if ($result->getSuccess())
			{
				$token = OpenPayUOAuthenticate::accessTokenByClientCredentials();

				$return_array = array(
					'summaryUrl' => OpenPayUConfiguration::getSummaryUrl(),
					'sessionId' => $_SESSION['sessionId'],
					'oauthToken' => $token->getAccessToken(),
					'langCode' => Tools::strtolower(Language::getIsoById($this->cart->id_lang))
				);
			} else {
				Logger::addLog($this->display_name.' '.trim($result->getError().' '.$result->getMessage().' '
					.$_SESSION['sessionId']), 1);
			}
		}
		catch(Exception $e){
			Logger::addLog($this->display_name.' '.trim($e->getCode().' '.$e->getMessage().' '.$_SESSION['sessionId']), 1);
		}


		return $return_array;
	}

	/**
	 * @param CartCore $cart
	 * @return string
	 */
	public function getLuForm(CartCore $cart)
	{
		$merchant_id = Configuration::get('PAYU_EPAYMENT_MERCHANT');
		$secret_key = Configuration::get('PAYU_EPAYMENT_SECRET_KEY');
		$live_update = new PayuLu($merchant_id, $secret_key);
		$url = $this->getBusinessPartnerSetting('lu_url');
		$live_update->setQueryUrl($url);

		$this->validateOrder($cart->id, (int)Configuration::get('PAYU_PAYMENT_STATUS_PENDING'),
			$cart->getOrderTotal(true, Cart::BOTH), $this->display_name, null,
			null, (int)$cart->id_currency, false, $cart->secure_key,
			Context::getContext()->shop->id ? new Shop((int)Context::getContext()->shop->id) : null
		);
		$this->current_order = $this->currentOrder;
		$this->current_order_reference = $this->currentOrderReference;

		$live_update->setBackRef($this->getModuleAddress().'payment_return.php?order_ref='.$this->current_order);

		if (version_compare(_PS_VERSION_, '1.5', 'lt'))
		{
			$internal_reference = '#'.str_pad($this->current_order, 6, '0', STR_PAD_LEFT);
			$order_ref = $this->current_order.'|'.str_pad($this->current_order, 6, '0', STR_PAD_LEFT);
            $order_id = $this->current_order;
		}
		else
		{
			$internal_reference = $this->currentOrderReference;
			$order_ref = $this->currentOrder.'|'.$this->currentOrderReference;
            $order_id = $this->currentOrder;
		}

		$live_update->setOrderRef($order_ref);

		$currency = Currency::getCurrency($cart->id_currency);
		$default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
		$lang_iso_code = Language::getIsoById($default_lang);
		$live_update->setPaymentCurrency($currency['iso_code']);
		$live_update->setLanguage(Tools::strtoupper($lang_iso_code));

		$payu_product = new PayuProduct();
		$payu_product->setName('Payment for order '.$internal_reference);
		$payu_product->setCode($internal_reference);
		$payu_product->setPrice($cart->getOrderTotal(true, Cart::BOTH));
		$payu_product->setTax(0);
		$payu_product->setQuantity(1);

		$live_update->addProduct($payu_product);

		if (!empty($cart->id_customer))
		{
			$customer = new Customer((int)$cart->id_customer);
			if ($customer->email)
			{
				if (!empty($cart->id_address_invoice) && Configuration::get('PS_INVOICE'))
				{
					$address = new Address((int)$cart->id_address_invoice);
					$country = new Country((int)$address->id_country);

					$billing = new PayuAddress();
					$billing->setFirstName($address->firstname);
					$billing->setLastName($address->lastname);
					$billing->setEmail($customer->email);
					$billing->setPhone(!$address->phone ? $address->phone_mobile : $address->phone);
					$billing->setAddress($address->address1);
					$billing->setAddress2($address->address2);
					$billing->setZipCode($address->postcode);
					$billing->setCity($address->city);
					$billing->setCountryCode(Tools::strtoupper($country->iso_code));

					$live_update->setBillingAddress($billing);
				}

				if (!empty($cart->id_address_delivery))
				{
					$address = new Address((int)$cart->id_address_delivery);
					$country = new Country((int)$address->id_country);

					$delivery = new PayuAddress();
					$delivery->setFirstName($address->firstname);
					$delivery->setLastName($address->lastname);
					$delivery->setEmail($customer->email);
					$delivery->setPhone(!$address->phone ? $address->phone_mobile : $address->phone);
					$delivery->setAddress($address->address1);
					$delivery->setAddress2($address->address2);
					$delivery->setZipCode($address->postcode);
					$delivery->setCity($address->city);
					$delivery->setCountryCode(Tools::strtoupper($country->iso_code));
					$live_update->setDeliveryAddress($delivery);
				}
			}
		}

		$luForm = $live_update->renderPaymentForm(null);

        $this->savePayuTransaction($order_id, $cart->getOrderTotal(true, Cart::BOTH), Currency::getCurrency($cart->id_currency));

		return $luForm;
	}

	/**
	 * @return array
	 */
	public function getCarriersListForCart()
	{
		$carrier_list = array();

		$currency = Currency::getCurrency($this->cart->id_currency);
		$country_code = Tools::strtoupper(Configuration::get('PS_LOCALE_COUNTRY'));
		$country = new Country(Country::getByIso($country_code));
		$cart_products = $this->cart->getProducts();
		$free_shipping = false;

		// turned off for 1.4
		if (version_compare(_PS_VERSION_, '1.5', 'gt'))
		{
			foreach ($this->cart->getCartRules() as $rule)
			{
				if ($rule['free_shipping'])
				{
					$free_shipping = true;
					break;
				}
			}
		}

		if ($this->cart->id_carrier > 0)
		{
			$selected_carrier = new Carrier($this->cart->id_carrier);
			$shipping_method = $selected_carrier->getShippingMethod();

			if ($free_shipping == false)
			{
				if (version_compare(_PS_VERSION_, '1.5', 'lt'))
				{
					$price = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $this->cart->getOrderShippingCost((int)$this->cart->id_carrier, true, $country, $cart_products));
					$price_tax_exc = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $this->cart->getOrderShippingCost((int)$this->cart->id_carrier, false, $country, $cart_products));
				}
				else
				{
					$price = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $this->cart->getPackageShippingCost((int)$this->cart->id_carrier, true, $country, $cart_products));
					$price_tax_exc = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $this->cart->getPackageShippingCost((int)$this->cart->id_carrier, false, $country, $cart_products));
				}

				$tax_amount = $price - $price_tax_exc;
			} else {
				$price = 0;
				$price_tax_exc = 0;
				$tax_amount = 0;
			}

			if ((int)$selected_carrier->active == 1)
			{
				$carrier_list[0]['ShippingCost'] = array(
					'Type' => $selected_carrier->name.' ('.$selected_carrier->id.')',
					'CountryCode' => $country_code,
					'Price' => array(
						'Gross' => $this->toAmount($price),
						'Net' => $this->toAmount($price_tax_exc),
						'Tax' => $this->toAmount($tax_amount)
					)
				);

				if (!empty($tax_rate))
					$carrier_list[0]['ShippingCost']['Price']['TaxRate'] = $tax_rate;

				$carrier_list[0]['ShippingCost']['Price']['CurrencyCode'] = $currency['iso_code'];
			}
		} else {
			$i = 0;
			if ((int)$this->context->cookie->id_customer > 0)
			{
				$customer = new Customer((int)$this->context->cookie->id_customer);
				$address = new Address((int)$this->cart->id_address_delivery);
				$id_zone = Address::getZoneById((int)$address->id);
				$carriers = Carrier::getCarriersForOrder($id_zone, $customer->getGroups());
			} else
				$carriers = Carrier::getCarriers((int)$this->cart->id_lang, true);

			if ($carriers)
			{
				foreach ($carriers as $carrier)
				{
					$c = new Carrier((int)$carrier['id_carrier']);

					$shipping_method = $c->getShippingMethod();

					if ($free_shipping == false)
					{
						if (version_compare(_PS_VERSION_, '1.5', 'lt'))
						{
							$price = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $this->cart->getOrderShippingCost((int)$carrier['id_carrier'], true, $country, $cart_products));
							$price_tax_exc = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $this->cart->getOrderShippingCost((int)$carrier['id_carrier'], false, $country, $cart_products));
						}
						else
						{
							$price = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $this->cart->getPackageShippingCost((int)$carrier['id_carrier'], true, $country, $cart_products));
							$price_tax_exc = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $this->cart->getPackageShippingCost((int)$carrier['id_carrier'], false, $country, $cart_products));
						}
						$tax_amount = $price - $price_tax_exc;
					} else {
						$price = 0;
						$price_tax_exc = 0;
						$tax_amount = 0;
					}

					if ($carrier['id_carrier'] != $this->cart->id_carrier)
					{
						if ((int)$carrier['active'] == 1)
						{
							$carrier_list[$i]['ShippingCost'] = array(
								'Type' => $carrier['name'].' ('.$carrier['id_carrier'].')',
								'CountryCode' => $country_code,
								'Price' => array(
									'Gross' => $this->toAmount($price),
									'Net' => $this->toAmount($price_tax_exc),
									'Tax' => $this->toAmount($tax_amount)
								)
							);

							if (!empty($tax_rate))
								$carrier_list[$i]['ShippingCost']['Price']['TaxRate'] = $tax_rate;

							$carrier_list[$i]['ShippingCost']['Price']['CurrencyCode'] = $currency['iso_code'];

							$i++;
						}
					}
				}
			}
		}

		return $carrier_list;
	}

	/**
	 * @param $id_session
	 * @return bool|int
	 */
	public function getOrderIdBySessionId($id_session)
	{
		$result = Db::getInstance()->getRow('
			SELECT `id_order` FROM `'._DB_PREFIX_.'order_payu_payments`
			WHERE `id_session`="'.addslashes($id_session).'"');

		if ($result)
			return (int)($result['id_order']);
		else
			return false;
	}

	/**
	 * @param $id_order
	 * @return bool
	 */
	public function getOrderPaymentByOrderId($id_order)
	{
		$result = Db::getInstance()->getRow('
			SELECT * FROM `'._DB_PREFIX_.'order_payu_payments`
			WHERE `id_order`="'.addslashes($id_order).'"');

		if ($result)
			return $result;

		return false;
	}

	/**
	 * @param $id_session
	 * @return bool
	 */
	public function getOrderPaymentBySessionId($id_session)
	{
		$result = Db::getInstance()->getRow('
			SELECT * FROM `'._DB_PREFIX_.'order_payu_payments`
			WHERE `id_session`="'.addslashes($id_session).'"');

		if ($result)
			return $result;

		return false;
	}

	/**
	 * @param $status
	 * @return bool
	 */
	public function updateOrderPaymentStatusBySessionId($status)
	{
		return $result = Db::getInstance()->execute('
			UPDATE `'._DB_PREFIX_.'order_payu_payments`
			SET id_order = "'.(int)$this->id_order.'", status = "'.addslashes($status).'", update_at = NOW()
			WHERE `id_session`="'.addslashes($this->id_session).'"');
	}

	/**
	 * @param string $status
	 * @return mixed
	 */
	public function addOrderSessionId($status = '')
	{
		if (Db::getInstance()->execute('
			INSERT INTO `'._DB_PREFIX_.'order_payu_payments` (`id_order`, `id_cart`, `id_session`,  `status`,  `create_at`)
				VALUES ("'.(int)$this->id_order.'", "'.(int)$this->id_cart.'",  "'.$this->id_session.'",   "'.addslashes($status).'", NOW())'))
			return (int)Db::getInstance()->Insert_ID();

		return false;
	}

	/**
	 * @param $iso_country_code
	 * @return null|string
	 */
	public function shippingCostRetrieveRequest($iso_country_code)
	{
		if ($iso_country_code)
		{
			$cart = new Cart($this->id_cart);

			if ($id_country = Country::getByIso($iso_country_code))
			{
				if ($id_zone = Country::getIdZone($id_country))
				{
					$carriers = Carrier::getCarriersForOrder($id_zone);
					$currency = Currency::getCurrency($cart->id_currency);
					if ($carriers)
					{
						$carrier_list = array();
						foreach ($carriers as $carrier)
						{
							$c = new Carrier((int)$carrier['id_carrier']);
							$shipping_method = $c->getShippingMethod();

							$price = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $cart->getOrderShippingCost((int)$carrier['id_carrier']));
							$price_tax_exc = ($shipping_method == Carrier::SHIPPING_METHOD_FREE ? 0 : $cart->getOrderShippingCost((int)$carrier['id_carrier'], false));

							$carrier_list[]['ShippingCost'] = array(
								'Type' => $carrier['name'].' ('.$carrier['id_carrier'].')',
								'CountryCode' => Tools::strtoupper($iso_country_code),
								'Price' => array(
									'Gross' => $this->toAmount($price),
									'Net' => $this->toAmount($price_tax_exc),
									'Tax' => $this->toAmount($price) - $this->toAmount($price_tax_exc),
									'CurrencyCode' => Tools::strtoupper($currency['iso_code'])
								)
							);
						}

						$shipping_cost = array(
							'CountryCode' => Tools::strtoupper($iso_country_code),
							'ShipToOtherCountry' => 'true',
							'ShippingCostList' => $carrier_list
						);
						$xml = OpenPayU::buildShippingCostRetrieveResponse($shipping_cost, $this->id_request, $iso_country_code);
						return $xml;
					} else
						Logger::addLog('carrier by id_zone is undefined');
				}
			}
		}

		return null;
	}

	/**
	 * @param $status
	 * @return bool
	 */
	private function updateOrderState($status)
	{
		if (!empty($this->order->id))
		{
			if (version_compare(_PS_VERSION_, '1.5', 'lt'))
			{
				$order_state = OrderHistory::getLastOrderState($this->order->id);
				$order_state_id = $order_state->id;
			} else
				$order_state_id = $this->order->current_state;

			$history = new OrderHistory();
			$history->id_order = $this->order->id;
			$history->date_add = date('Y-m-d H:i:s');

			switch($status)
			{
				case self::PAYMENT_STATUS_END :
					if ($order_state_id != (int)Configuration::get('PAYU_PAYMENT_STATUS_COMPLETED'))
					{
						$history->changeIdOrderState(Configuration::get('PAYU_PAYMENT_STATUS_COMPLETED'), $this->order->id);
						$history->addWithemail(true);
					}
					break;
				case self::PAYMENT_STATUS_CANCEL :
					if ($order_state_id != (int)Configuration::get('PAYU_PAYMENT_STATUS_CANCELED'))
					{
						$history->changeIdOrderState(Configuration::get('PAYU_PAYMENT_STATUS_CANCELED'), $this->order->id);
						$history->addWithemail(true);
					}
					break;
				case self::PAYMENT_STATUS_REJECT :
					if ($order_state_id != (int)Configuration::get('PAYU_PAYMENT_STATUS_REJECTED'))
					{
						$history->changeIdOrderState(Configuration::get('PAYU_PAYMENT_STATUS_REJECTED'), $this->order->id);
						$history->addWithemail(true);
					}
					break;
				case self::PAYMENT_STATUS_SENT :
					if ($order_state_id != (int)Configuration::get('PAYU_PAYMENT_STATUS_SENT'))
					{
						$history->changeIdOrderState(Configuration::get('PAYU_PAYMENT_STATUS_SENT'), $this->order->id);
						$history->addWithemail(false);
					}
					break;
			}

			return $this->updateOrderPaymentStatusBySessionId($status);
		}

		return false;
	}

	/**
	 *
	 */
	public function updateOrderData()
	{
		if (empty($this->id_session))
			Logger::addLog($this->display_name.' '.$this->l('Can not get order information - id_session is empty'), 1);

		$result = OpenPayUOrder::retrieve($this->id_session);
		if ($result->getSuccess())
		{
			$response = $result->getResponse();

			$payu_order = $response['OpenPayU']['OrderDomainResponse']['OrderRetrieveResponse'];

			$payu_order_customer = isset($payu_order['CustomerRecord']) ? $payu_order['CustomerRecord'] : array();
			$payu_order_shipping = isset($payu_order['Shipping']) ? $payu_order['Shipping'] : array();
			$payu_order_invoice = isset($payu_order['Invoice']) ? $payu_order['Invoice'] : array();

			if (!empty($this->id_order))
			{
				$this->order = new Order($this->id_order);

				if (isset($payu_order_shipping['ShippingType']))
				{
					preg_match_all("'([0-9]+)'si", trim($payu_order_shipping['ShippingType'], ')'), $carrier);
					$carrier_id = ($carrier[0][count($carrier[0]) - 1]);

					if (!empty($carrier_id))
					{
						$this->order->id_carrier = $carrier_id;

						$id_order_carrier = Db::getInstance()->getValue('
						SELECT `id_order_carrier`
						FROM `'._DB_PREFIX_.'order_carrier`
						WHERE `id_order` = '.(int)$this->id_order.'
						AND (`id_order_invoice` IS NULL OR `id_order_invoice` = 0)');

						if ($id_order_carrier)
						{
							$shipping_cost_tax_excl = $this->toDecimal((int)$payu_order_shipping['ShippingCost']['Net']);
							$shipping_cost_tax_incl = $this->toDecimal((int)$payu_order_shipping['ShippingCost']['Gross']);

							$order_carrier = new OrderCarrier($id_order_carrier);
							$order_carrier->id_carrier = (int)$this->order->id_carrier;
							$order_carrier->shipping_cost_tax_excl = $shipping_cost_tax_excl;
							$order_carrier->shipping_cost_tax_incl = $shipping_cost_tax_incl;
							$order_carrier->update();

							$this->order->total_shipping = $order_carrier->shipping_cost_tax_incl;
							$this->order->total_shipping_tax_incl = $order_carrier->shipping_cost_tax_incl;
							$this->order->total_shipping_tax_excl = $order_carrier->shipping_cost_tax_excl;

							if ((isset($payu_order['PaidAmount']) && $payu_order['OrderStatus'] == self::ORDER_STATUS_COMPLETE && $payu_order['PaymentStatus'] == 'PAYMENT_STATUS_END') && (int)$this->order->total_paid_real == 0)
							{
								$this->order->total_paid = $this->order->total_products_wt + $this->order->total_shipping_tax_incl;
								$this->order->total_paid_tax_incl = $this->order->total_paid;
								$this->order->total_paid_tax_excl = $this->order->total_products + $this->order->total_shipping_tax_excl;
							}
						}
					}
				}

				// Delivery address add
				if (isset($payu_order_shipping['Address']))
				{
					if (isset($payu_order_customer['Phone']) && !empty($payu_order_customer['Phone']))
						$payu_order_shipping['Address']['Phone'] = $payu_order_customer['Phone'];

					$payu_order_shipping_address = $payu_order_shipping['Address'];
					$new_delivery_address_id = $this->addNewAddress($payu_order_shipping_address);

					if (!empty($new_delivery_address_id))
						$this->order->id_address_delivery = $new_delivery_address_id;
				}

				// Invoice address add
				if (isset($payu_order_invoice['Billing']))
				{
					if (isset($payu_order_customer['Phone']) && !empty($payu_order_customer['Phone']))
						$payu_order_invoice['Billing']['Phone'] = $payu_order_customer['Phone'];

					$payu_order_invoice_address = $payu_order_invoice['Billing'];
					$new_invoice_address_id = $this->addNewAddress($payu_order_invoice_address);

					if (!empty($new_invoice_address_id))
						$this->order->id_address_invoice = $new_invoice_address_id;
				}

				$this->order->update();

				// Update order state
				$this->updateOrderState(isset($payu_order['PaymentStatus']) ? $payu_order['PaymentStatus'] : null);
			}
		}
	}

	/**
	 * @param $address
	 *
	 * @return int
	 */
	private function checkIsAddressExists($address)
	{
		$check_address = Db::getInstance()->executeS('
			SELECT `id_address`
			FROM `'._DB_PREFIX_.'address`
			WHERE `id_customer` = "'.(int)$address->id_customer.'"
			AND `id_country` = "'.(int)$address->id_country.'"
			AND `company` = "'.addslashes($address->company).'"
			AND `lastname` = "'.addslashes($address->lastname).'"
			AND `firstname` = "'.addslashes($address->firstname).'"
			AND `address1` = "'.addslashes($address->address1).'"
			AND `address2` = "'.addslashes($address->address2).'"
			AND `postcode` = "'.addslashes($address->postcode).'"
			AND `city` = "'.addslashes($address->city).'"
			AND `phone` = "'.addslashes($address->phone).'"
			AND `phone_mobile` = "'.addslashes($address->phone_mobile).'"
			AND `vat_number` = "'.addslashes($address->vat_number).'"
			ORDER BY id_address DESC LIMIT 0,1
		');

		$id_address = array_shift($check_address);

		return (int)$id_address['id_address'];
	}

	/**
	 * @param $address
	 * @return mixed
	 */
	private function addNewAddress($address)
	{
		if ((int)Country::getByIso($address['CountryCode']))
			$address_country_id = Country::getByIso($address['CountryCode']);
		else
			$address_country_id = Configuration::get('PS_COUNTRY_DEFAULT');

		$shipping_recipient_name = explode(' ', $address['RecipientName']);

		$new_address = new Address();
		$new_address->id_customer = (int)$this->order->id_customer;
		$new_address->id_country = (int)$address_country_id;
		$new_address->id_state = 0;
		$new_address->alias = $this->l('PayU address').': '.$this->order->id.' '.time();
		$new_address->firstname = $shipping_recipient_name[0];
		$new_address->lastname = $shipping_recipient_name[1];

		$street = $address['Street'];
		if (isset($address['HouseNumber']))
			$street .= ' '.$address['HouseNumber'];
		if (isset($address['ApartmentNumber']))
			$street .= '/'.$address['ApartmentNumber'];

		$new_address->address1 = trim($street);
		$new_address->postcode = $address['PostalCode'];
		$new_address->city = $address['City'];
		$new_address->phone = $address['Phone'];
		if (isset($address['TIN']))
			$new_address->vat_number = $address['TIN'];
		$new_address->deleted = 0;

		if ($id_address = $this->checkIsAddressExists($new_address))
			return $id_address;

		$new_address->add();
		return $new_address->id;
	}

	/**
	 * Hook action before status change
	 *
	 * @param array $params Parameters
	 */
	public function hookUpdateOrderStatus($params)
	{
		$order_id = $params['id_order'];

		if (!$order_id)
			return;

		if ($this->getBusinessPartnerSetting('type') === self::BUSINESS_PARTNER_TYPE_EPAYMENT)
		{
			if ($params['newOrderStatus']->id == (int)Configuration::get('PAYU_PAYMENT_STATUS_DELIVERED'))
			{
				$order = new Order($order_id);
                $payu_trans = $this->getPayuTransaction($order_id);

				$ref_no = 0;
				if (version_compare(_PS_VERSION_, '1.5', 'lt')) {
                    $ref_no = $payu_trans['id_payu_transaction'];
                }
				else {
					foreach ($order->getOrderPaymentCollection() as $payment)
						$ref_no = $payment->transaction_id;
				}

				$idn = new PayuIDN(Configuration::get('PAYU_EPAYMENT_MERCHANT'), Configuration::get('PAYU_EPAYMENT_SECRET_KEY'));
				$idn->setQueryUrl($this->getBusinessPartnerSetting('idn_url'));
				$idn->setPayuReference($ref_no);
				$idn->setOrderAmount($payu_trans['payu_amount']);
				$idn->setChargeAmount($payu_trans['payu_amount']);
				$idn->setOrderCurrency($payu_trans['payu_currency']);
				$idn_result = $idn->processRequest();

				// check if IDN failed
				if (!isset($idn_result['RESPONSE_CODE']) || 1 != $idn_result['RESPONSE_CODE'])
				{
					if (version_compare(_PS_VERSION_, '1.5', 'lt'))
						Tools::redirectAdmin('index.php?tab=AdminOrders&vieworder&id_order='.$order_id.'&token='.$_GET['token']);
					else
						Tools::redirectAdmin('index.php?controller=AdminOrders&vieworder&id_order='.$order_id.'&token='.$_GET['token']);
				}
			}
		}

		return true;
	}


	/**
	 * @param $server
	 * @return bool
	 */
	public function interpretReturnParameters($server)
	{
		parse_str($server['QUERY_STRING'], $parameters);

		if (!isset($parameters['order_ref']) || !is_numeric($parameters['order_ref']))
			return true;

		// validate signature
		if (true !== PayuSignature::validateSignature($server, Configuration::get('PAYU_EPAYMENT_SECRET_KEY')))
			return false;

		// check if IPN is disabled
		if (!Configuration::get('PAYU_EPAYMENT_IPN'))
		{
			$order_id = (int)$parameters['order_ref'];

			$history = new OrderHistory();
			$history->id_order = $order_id;

			if (isset($_GET['TRS']) && $_GET['TRS'] === 'AUTH')
			{
				// mark order as complete
				$history->changeIdOrderState((int)Configuration::get('PAYU_PAYMENT_STATUS_COMPLETED'), $order_id);
				$history->addWithemail(true);
			}
		}

		return true;
	}

	/**
	 * Interpret instant payment notification
	 *
	 * @param array $params
	 * @return array|bool
	 */
	public function interpretIPN(Array $params)
	{
		$order_id = (int)$params['REFNOEXT'];

		if (empty($order_id))
			return false;

		if ($this->getBusinessPartnerSetting('type') !== self::BUSINESS_PARTNER_TYPE_EPAYMENT)
			return false;

		if ($params['HASH'] != PayuSignature::generateHmac(Configuration::get('PAYU_EPAYMENT_SECRET_KEY'), PayuSignature::signatureString($params, array('HASH'))))
			return false;

		try {
			$history = new OrderHistory();
			$history->id_order = $order_id;

			switch ($params['ORDERSTATUS'])
			{
				case 'PAYMENT_AUTHORIZED':
				case 'PAYMENT_RECEIVED':
					$new_status = (int)Configuration::get('PAYU_PAYMENT_STATUS_COMPLETED');

					$history->changeIdOrderState($new_status, $order_id);
					$history->addWithemail(true);

					$order = new Order($order_id);

					if (version_compare(_PS_VERSION_, '1.5', 'ge'))
					{
						$payment = $order->getOrderPaymentCollection();
						$payments = $payment->getAll();
						$payments[$payment->count() - 1]->transaction_id = $params['REFNO'];
						$payments[$payment->count() - 1]->update();
					}

                    $this->updatePayuTransaction($order_id, (int)$params['REFNO'], $params['IPN_TOTALGENERAL'], $params['CURRENCY']);
					break;
			}

			$date = date('YmdGis');

			$response_params = array(
				$params['IPN_PID'][0],
				$params['IPN_PNAME'][0],
				$params['IPN_DATE'],
				$date
			);

			$hash = PayuSignature::generateHmac(Configuration::get('PAYU_EPAYMENT_SECRET_KEY'), PayuSignature::signatureString($response_params, array('HASH')));

			return array(
				'date' => $date,
				'hash' => $hash,
			);
		} catch (Exception $e){
			Logger::addLog($this->display_name.' '.trim($e->getCode().' '.$e->getMessage().' id_order: '.$order_id), 1);
			return false;
		}
	}

	public function updatePayuTransaction($order_id, $transaction_id, $payu_amount, $payu_currency)
	{
		return Db::getInstance()->Execute('
			UPDATE `'._DB_PREFIX_.'payu_payments`
			    SET
			        id_payu_transaction = '.(int)$transaction_id.',
			        payu_amount = '.(float)$payu_amount.',
			        payu_currency = "'.addslashes($payu_currency).'",
			        update_at = NOW()
			    WHERE id_order = '.(int)$order_id.'
		');

		return true;
	}

    public function savePayuTransaction($order_id, $amount, $currency)
    {
        return Db::getInstance()->Execute('
			INSERT INTO
			    `'._DB_PREFIX_.'payu_payments`
			    SET
			        id_order = '.(int)$order_id.',
			        amount = '.(float)$amount.',
			        currency = "'.addslashes($currency).'",
			        create_at = NOW()
		');

        return true;
    }

	public function getPayuTransaction($order_id)
	{
		return Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.'payu_payments` WHERE `id_order` = '.(int)$order_id.' ORDER BY `update_at` DESC');
	}
}
