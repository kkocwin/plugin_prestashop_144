<?php
/**
 * @copyright  Copyright (c) 2013 PayU
 * @license    http://opensource.org/licenses/LGPL-3.0  Open Software License (LGPL 3.0)
 *
 * http://www.payu.com
 * http://openpayu.com
 * http://twitter.com/openpayu
 *
 */


class PayUPaymentModuleFrontController extends ModuleFrontController
{
	public function initContent()
	{
		parent::initContent();

		$cart = $this->context->cart;
		$products = $cart->getProducts();

		if (empty($products))
			Tools::redirect('index.php?controller=order');

		$this->payu = new PayU();
		$this->payu->cart = $cart;

		$_SESSION['sessionId'] = md5($this->payu->cart->id.rand().rand().rand().rand());

		switch ($this->payu->getBusinessPartnerSetting('type'))
		{
			case PayU::BUSINESS_PARTNER_TYPE_EPAYMENT:
				$result = array('luForm' => $this->payu->getLuForm($cart));
				$template = 'lu-form.tpl';
				break;
			case PayU::BUSINESS_PARTNER_TYPE_PLATNOSCI:
				$result = $this->payu->orderCreateRequest();
				$this->context->smarty->assign(
					array('url_address' => $this->context->link->getModuleLink('payu', 'validation'))
				);
				$template = 'order-summary.tpl';
				break;
			default:
				//  incorrect partner
				break;
		}

		if (!empty($result))
		{
			$this->context->smarty->assign(
				$result + array(
					'id_customer' => $this->context->cookie->id_customer
				)
			);
			$this->setTemplate($template);
		} else {
			$this->context->smarty->assign(
				array(
					'message' => $this->payu->l('An error occurred while processing your order.')
				)
			);
			$this->setTemplate('error.tpl');
		}
	}
}