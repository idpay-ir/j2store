<?php
/**
 * IDPay payment plugin
 *
 * @developer JMDMahdi
 * @publisher IDPay
 * @package VirtueMart
 * @subpackage payment
 * @copyright (C) 2018 IDPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
defined('_JEXEC') or die('Restricted access');

require_once(JPATH_ADMINISTRATOR . '/components/com_j2store/library/plugins/payment.php');

class plgJ2StorePayment_idpay extends J2StorePaymentPlugin
{
    var $_element = 'payment_idpay';

    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
        $this->loadLanguage('com_j2store', JPATH_ADMINISTRATOR);
    }


    function onJ2StoreCalculateFees($order)
    {
        $payment_method = $order->get_payment_method();

        if ($payment_method == $this->_element) {
            $total = $order->order_subtotal + $order->order_shipping + $order->order_shipping_tax;
            $surcharge = 0;
            $surcharge_percent = $this->params->get('surcharge_percent', 0);
            $surcharge_fixed = $this->params->get('surcharge_fixed', 0);
            if (( float )$surcharge_percent > 0 || ( float )$surcharge_fixed > 0) {
                // percentage
                if (( float )$surcharge_percent > 0) {
                    $surcharge += ($total * ( float )$surcharge_percent) / 100;
                }

                if (( float )$surcharge_fixed > 0) {
                    $surcharge += ( float )$surcharge_fixed;
                }

                $name = $this->params->get('surcharge_name', JText::_('J2STORE_CART_SURCHARGE'));
                $tax_class_id = $this->params->get('surcharge_tax_class_id', '');
                $taxable = false;
                if ($tax_class_id && $tax_class_id > 0)
                    $taxable = true;
                if ($surcharge > 0) {
                    $order->add_fee($name, round($surcharge, 2), $taxable, $tax_class_id);
                }
            }
        }
    }

    function _prePayment($data)
    {
        $app = JFactory::getApplication();
        $vars = new JObject();
        $vars->order_id = $data['order_id'];
        $vars->orderpayment_id = $data['orderpayment_id'];
        $vars->orderpayment_amount = $data['orderpayment_amount'];
        $vars->orderpayment_type = $this->_element;
        $vars->button_text = $this->params->get('button_text', 'J2STORE_PLACE_ORDER');
        $vars->display_name = 'idpay';
        $vars->api_key = $this->params->get('api_key', '');
        $vars->sandbox = $this->params->get('sandbox', '');

        if ($vars->api_key == null || $vars->api_key == '') {
            $link = JRoute::_(JURI::root() . "index.php?option=com_j2store");
            $app->redirect($link, '<h2>لطفا تنظیمات درگاه idpay را بررسی کنید</h2>', $msgType = 'Error');
        } else {
            $api_key = $vars->api_key;
            $sandbox = $vars->sandbox == 'no' ? 'false' : 'true';

            $amount = round($vars->orderpayment_amount, 0);
            $desc = 'پرداخت سفارش شماره: ' . $vars->order_id;
            $callback = JRoute::_(JURI::root() . "index.php?option=com_j2store&view=checkout") . '&orderpayment_type=' . $vars->orderpayment_type . '&task=confirmPayment';

            if (empty($amount)) {
                $msg = "واحد پول انتخاب شده پشتیبانی نمی شود.";
                $link = JRoute::_("index.php?option=com_j2store");
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }

            $data = array(
                'order_id' => $data['orderpayment_id'],
                'amount' => $amount,
                'phone' => '',
                'desc' => $desc,
                'callback' => $callback,
            );

            $ch = curl_init('https://api.idpay.ir/v1/payment');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'X-API-KEY:' . $api_key,
                'X-SANDBOX:' . $sandbox,
            ));

            $result = curl_exec($ch);
            $result = json_decode($result);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
                $link = JRoute::_("index.php?option=com_j2store");
                $app->redirect($link, '<h2>' . sprintf('خطا هنگام ایجاد تراکنش. کد خطا: %s', $http_status) . '</h2>', $msgType = 'Error');
            }

            $vars->idpay = $result->link;
            $html = $this->_getLayout('prepayment', $vars);
            return $html;
        }
    }


    function _postPayment($data)
    {
        $app = JFactory::getApplication();
        $jinput = $app->input;
        $html = '';
        $orderpayment_id = $jinput->post->get('order_id', '0', 'INT');
        F0FTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_j2store/tables');
        $orderpayment = F0FTable::getInstance('Order', 'J2StoreTable')->getClone();
        if ($orderpayment->load($orderpayment_id)) {
            $customer_note = $orderpayment->customer_note;
            if ($orderpayment->j2store_order_id == $orderpayment_id) {
                $pid = $jinput->post->get('id', '', 'STRING');
                $porder_id = $jinput->post->get('order_id', '', 'STRING');
                if (!empty($pid) && !empty($porder_id)) {
					
					$price = $this->params->get('amount', '');
                    $api_key = $this->params->get('api_key', '');
                    $sandbox = $this->params->get('sandbox', '') == 'no' ? 'false' : 'true';

                    $data = array(
                        'id' => $pid,
                        'order_id' => $porder_id,
                    );

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1/payment/inquiry');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'X-API-KEY:' . $api_key,
                        'X-SANDBOX:' . $sandbox,
                    ));

                    $result = curl_exec($ch);
                    $result = json_decode($result);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($http_status != 200) {
                        $msg = sprintf('خطا هنگام بررسی وضعیت تراکنش. کد خطا: %s', $http_status);
                        $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
                        $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                    }

                    $inquiry_status = empty($result->status) ? NULL : $result->status;
                    $inquiry_order_id = empty($result->order_id) ? NULL : $result->order_id;
                    $inquiry_track_id = empty($result->track_id) ? NULL : $result->track_id;
                    $inquiry_amount = empty($result->amount) ? NULL : $result->amount;

                    if (empty($inquiry_status) || empty($inquiry_track_id) || empty($inquiry_amount) || $inquiry_amount != $price || $inquiry_status != 100) {
                        $msg = $this->idpay_get_failed_message($inquiry_track_id, $inquiry_order_id);
                        $link = JRoute::_("index.php?option=com_j2store");
                        $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                    } else {
                        $msg = $this->idpay_get_success_message($inquiry_track_id, $inquiry_order_id);
                        $this->saveStatus($msg, 1, $customer_note, 'ok', $inquiry_track_id, $orderpayment);
                        $app->enqueueMessage($msg, 'message');
                    }
                } else {
                    $msg = 'کاربر از انجام تراکنش منصرف شده است';
                    $link = JRoute::_("index.php?option=com_j2store");
                    $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                }
            } else {
                $msg = 'سفارش پیدا نشد';
                $link = JRoute::_("index.php?option=com_j2store");
                $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
            }
        } else {
            $msg = 'سفارش پیدا نشد';
            $link = JRoute::_("index.php?option=com_j2store");
            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
        }
    }

    public function idpay_get_failed_message($track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->params->get('failed_massage', ''));
    }

    public function idpay_get_success_message($track_id, $order_id)
    {
        return str_replace(["{track_id}", "{order_id}"], [$track_id, $order_id], $this->params->get('success_massage', ''));
    }

    function getPaymentStatus($payment_status)
    {
        $status = '';
        switch ($payment_status) {
            case '1':
                $status = JText::_('J2STORE_CONFIRMED');
                break;
            case '2':
                $status = JText::_('J2STORE_PROCESSED');
                break;
            case '3':
                $status = JText::_('J2STORE_FAILED');
                break;
            case '4':
                $status = JText::_('J2STORE_PENDING');
                break;
            case '5':
                $status = JText::_('J2STORE_INCOMPLETE');
                break;
            default:
                $status = JText::_('J2STORE_PENDING');
                break;
        }
        return $status;
    }

    function saveStatus($msg, $statCode, $customer_note, $emptyCart, $trackingCode, $orderpayment)
    {
        $html = '<br />';
        $html .= '<strong>' . 'idpay' . '</strong>';
        $html .= '<br />';
        if (isset($trackingCode)) {
            $html .= '<br />';
            $html .= $trackingCode . 'شماره پیگری ';
            $html .= '<br />';
        }
        $html .= '<br />' . $msg;
        $orderpayment->customer_note = $customer_note . $html;
        $payment_status = $this->getPaymentStatus($statCode);
        $orderpayment->transaction_status = $payment_status;
        $orderpayment->order_state = $payment_status;
        $orderpayment->order_state_id = $this->params->get('payment_status', $statCode);

        if ($orderpayment->store()) {
            if ($emptyCart == 'ok') {
                $orderpayment->payment_complete();
                $orderpayment->empty_cart();
            }
        } else {
            $errors[] = $orderpayment->getError();
        }

        $vars = new JObject();
        $vars->onafterpayment_text = $msg;
        $html = $this->_getLayout('postpayment', $vars);
        $html .= $this->_displayArticle();
        return $html;
    }

    function getShippingAddress()
    {

        $user = JFactory::getUser();
        $db = JFactory::getDBO();

        $query = "SELECT * FROM #__j2store_addresses WHERE user_id={$user->id}";
        $db->setQuery($query);
        return $db->loadObject();

    }

}
