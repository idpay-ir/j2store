<?php
/**
 * IDPay payment plugin
 *
 * @developer     JMDMahdi
 * @publisher     IDPay
 * @package       J2Store
 * @subpackage    payment
 * @copyright (C) 2018 IDPay
 * @license       http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * http://idpay.ir
 */
defined( '_JEXEC' ) or die( 'Restricted access' );

require_once( JPATH_ADMINISTRATOR . '/components/com_j2store/library/plugins/payment.php' );

class plgJ2StorePayment_idpay extends J2StorePaymentPlugin {

    var $_element = 'payment_idpay';

    function __construct( & $subject, $config ) {
        parent::__construct( $subject, $config );
        $this->loadLanguage( 'com_j2store', JPATH_ADMINISTRATOR );
    }


    function onJ2StoreCalculateFees( $order ) {
        $payment_method = $order->get_payment_method();

        if ( $payment_method == $this->_element )
        {
            $total             = $order->order_subtotal + $order->order_shipping + $order->order_shipping_tax;
            $surcharge         = 0;
            $surcharge_percent = $this->params->get( 'surcharge_percent', 0 );
            $surcharge_fixed   = $this->params->get( 'surcharge_fixed', 0 );
            if ( ( float ) $surcharge_percent > 0 || ( float ) $surcharge_fixed > 0 )
            {
                // percentage
                if ( ( float ) $surcharge_percent > 0 )
                {
                    $surcharge += ( $total * ( float ) $surcharge_percent ) / 100;
                }

                if ( ( float ) $surcharge_fixed > 0 )
                {
                    $surcharge += ( float ) $surcharge_fixed;
                }

                $name         = $this->params->get( 'surcharge_name', JText::_( 'J2STORE_CART_SURCHARGE' ) );
                $tax_class_id = $this->params->get( 'surcharge_tax_class_id', '' );
                $taxable      = FALSE;
                if ( $tax_class_id && $tax_class_id > 0 )
                {
                    $taxable = TRUE;
                }
                if ( $surcharge > 0 )
                {
                    $order->add_fee( $name, round( $surcharge, 2 ), $taxable, $tax_class_id );
                }
            }
        }
    }

    function _prePayment( $data ) {
        $app                       = JFactory::getApplication();
        $vars                      = new JObject();
        $vars->order_id            = $data['order_id'];
        $vars->orderpayment_id     = $data['orderpayment_id'];
        $vars->orderpayment_amount = $data['orderpayment_amount'];
        $vars->orderpayment_type   = $this->_element;
        $vars->button_text         = $this->params->get( 'button_text', 'J2STORE_PLACE_ORDER' );
        $vars->display_name        = 'IDPay';
        $vars->api_key             = $this->params->get( 'api_key', '' );
        $vars->sandbox             = $this->params->get( 'sandbox', '' );

        // Customer information
        $orderinfo = F0FTable::getInstance( 'Orderinfo', 'J2StoreTable' )
                             ->getClone();
        $orderinfo->load( [ 'order_id' => $data['order_id'] ] );

        $name        = $orderinfo->billing_first_name . ' ' . $orderinfo->billing_last_name;
        $all_billing = $orderinfo->all_billing;
        $all_billing = json_decode( $all_billing );
        $mail        = $all_billing->email->value;
        $phone       = $orderinfo->billing_phone_2;

        if ( empty( $phone ) )
        {
            $phone = $orderinfo->billing_phone_1;
        }

        if ( $vars->api_key == NULL || $vars->api_key == '' )
        {
            $link = JRoute::_( JURI::root() . "index.php?option=com_j2store" );
            $app->redirect( $link, '<h2>لطفا تنظیمات درگاه IDPay را بررسی کنید</h2>', $msgType = 'Error' );
        }
        else
        {
            $api_key = $vars->api_key;
            $sandbox = $vars->sandbox == 'no' ? 'false' : 'true';

            $amount   = round( $vars->orderpayment_amount, 0 );
            $desc     = 'پرداخت سفارش شماره: ' . $vars->order_id;
            $callback = JRoute::_( JURI::root() . "index.php?option=com_j2store&view=checkout" ) . '&orderpayment_type=' . $vars->orderpayment_type . '&task=confirmPayment';

            if ( empty( $amount ) )
            {
                $msg  = "واحد پول انتخاب شده پشتیبانی نمی شود.";
                $link = JRoute::_( "index.php?option=com_j2store" );
                $app->redirect( $link, '<h2>' . $msg . '</h2>', $msgType = 'Error' );
            }

            $data = [
                'order_id' => $data['orderpayment_id'],
                'amount'   => $amount,
                'name'     => $name,
                'phone'    => $phone,
                'mail'     => $mail,
                'desc'     => $desc,
                'callback' => $callback,
            ];

            $ch = curl_init( 'https://api.idpay.ir/v1.1/payment' );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY:' . $api_key,
                'X-SANDBOX:' . $sandbox,
            ] );

            $result      = curl_exec( $ch );
            $result      = json_decode( $result );
            $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );

            if ( $http_status != 201 || empty( $result ) || empty( $result->id ) || empty( $result->link ) )
            {
                $link = JRoute::_( "index.php?option=com_j2store" );
                $app->redirect( $link, '<h2>' . sprintf( 'خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message ) . '</h2>', $msgType = 'Error' );
            }

            F0FTable::addIncludePath( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
            $orderpayment = F0FTable::getInstance( 'Order', 'J2StoreTable' )
                                    ->getClone();
            // Save transaction id
            $orderpayment->load( $data['order_id'] );
            $orderpayment->transaction_id = $result->id;
            $orderpayment->store();

            $vars->idpay = $result->link;
            $html        = $this->_getLayout( 'prepayment', $vars );

            return $html;
        }
    }


    function _postPayment( $data ) {
        $app    = JFactory::getApplication();
        $jinput = $app->input;

        $status   = empty( $jinput->post->get( 'status' ) ) ? NULL : $jinput->post->get( 'status' );
        $track_id = empty( $jinput->post->get( 'track_id' ) ) ? NULL : $jinput->post->get( 'track_id' );
        $id       = empty( $jinput->post->get( 'id' ) ) ? NULL : $jinput->post->get( 'id' );
        $order_id = empty( $jinput->post->get( 'order_id' ) ) ? NULL : $jinput->post->get( 'order_id' );
        $amount   = empty( $jinput->post->get( 'amount' ) ) ? NULL : $jinput->post->get( 'amount' );
        $card_no  = empty( $jinput->post->get( 'card_no' ) ) ? NULL : $jinput->post->get( 'card_no' );
        $date     = empty( $jinput->post->get( 'date' ) ) ? NULL : $jinput->post->get( 'date' );

        F0FTable::addIncludePath( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
        $orderpayment = F0FTable::getInstance( 'Order', 'J2StoreTable' )
                                ->getClone();

        if ( empty( $id ) || empty( $order_id ) )
        {
            $app->enqueueMessage( 'پارامترهای ورودی خالی است.', 'Error' );

            return;
        }

        if ( ! $orderpayment->load( $order_id ) )
        {
            $app->enqueueMessage( 'سفارش پیدا نشد.', 'Error' );

            return;
        }

        // Check double spending.
        if ( $orderpayment->transaction_id != $id )
        {
            $app->enqueueMessage( 'پارامترهای ورودی با هم مغایرت دارند.', 'Error' );

            return;
        }


        if ( $status != 10 )
        {
            $orderpayment->add_history( 'Remote Status : ' . $status . ' - IDPay Track ID : ' . $track_id . ' - Payer card no: ' . $card_no );
            $app->enqueueMessage( $this->idpay_get_failed_message( $track_id, $order_id ), 'Error' );

            return;
        }

        $api_key = $this->params->get( 'api_key', '' );
        $sandbox = $this->params->get( 'sandbox', '' ) == 'no' ? 'false' : 'true';

        $data = [
            'id'       => $id,
            'order_id' => $order_id,
        ];

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, 'https://api.idpay.ir/v1.1/payment/verify' );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-KEY:' . $api_key,
            'X-SANDBOX:' . $sandbox,
        ] );

        $result      = curl_exec( $ch );
        $result      = json_decode( $result );
        $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( $http_status != 200 )
        {
            $msg = sprintf( 'خطا هنگام بررسی وضعیت تراکنش. وضعیت خطا: %s - کد خطا: %s - پیغام خطا: %s', $http_status, $result->error_code, $result->error_message );
            $app->enqueueMessage( $msg, 'Error' );

            return;
        }

        $verify_status   = empty( $result->status ) ? NULL : $result->status;
        $verify_order_id = empty( $result->order_id ) ? NULL : $result->order_id;
        $verify_track_id = empty( $result->track_id ) ? NULL : $result->track_id;
        $verify_amount   = empty( $result->amount ) ? NULL : $result->amount;
        $verify_card_no  = empty( $result->payment->card_no ) ? NULL : $result->payment->card_no;

        if ( empty( $verify_status ) || empty( $verify_track_id ) || empty( $verify_amount ) || $verify_status < 100 )
        {

            $msg = $this->idpay_get_failed_message( $verify_track_id, $verify_order_id );
            $orderpayment->add_history( 'Remote Status : ' . $verify_status . ' - IDPay Track ID : ' . $verify_track_id . ' - Payer card no: ' . $verify_card_no );
            $app->enqueueMessage( $msg, 'Error' );

            return;
        }
        else
        {
            // Payment is successful.
            $msg = $this->idpay_get_success_message( $verify_track_id, $verify_order_id );
            $this->saveStatus( $orderpayment, 1 );
            $orderpayment->add_history( 'Remote Status : ' . $verify_status . ' - IDPay Track ID : ' . $verify_track_id . ' - Payer card no: ' . $verify_card_no );

            $app->enqueueMessage( $msg, 'message' );
        }


    }

    public function idpay_get_failed_message( $track_id, $order_id ) {
        return str_replace( [ "{track_id}", "{order_id}" ], [
            $track_id,
            $order_id,
        ], $this->params->get( 'failed_massage', '' ) );
    }

    public function idpay_get_success_message( $track_id, $order_id ) {
        return str_replace( [ "{track_id}", "{order_id}" ], [
            $track_id,
            $order_id,
        ], $this->params->get( 'success_massage', '' ) );
    }

    private function getPaymentStatus( $payment_status_code ) {
        $status = '';
        switch ( $payment_status_code )
        {
            case '1':
                $status = JText::_( 'J2STORE_CONFIRMED' );
                break;
            case '2':
                $status = JText::_( 'J2STORE_PROCESSED' );
                break;
            case '3':
                $status = JText::_( 'J2STORE_FAILED' );
                break;
            case '4':
                $status = JText::_( 'J2STORE_PENDING' );
                break;
            case '5':
                $status = JText::_( 'J2STORE_INCOMPLETE' );
                break;
            default:
                $status = JText::_( 'J2STORE_PENDING' );
                break;
        }

        return $status;
    }

    private function saveStatus( $orderpayment, $payment_status_code ) {

        $payment_status                   = $this->getPaymentStatus( $payment_status_code );
        $orderpayment->transaction_status = $payment_status;
        $orderpayment->order_state        = $payment_status;
        $orderpayment->order_state_id     = $this->params->get( 'payment_status', $payment_status_code );

        if ( $orderpayment->store() )
        {
            $orderpayment->payment_complete();
            $orderpayment->empty_cart();
        }
    }
}
