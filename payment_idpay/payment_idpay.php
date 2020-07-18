<?php
/**
 * IDPay payment plugin
 *
 * @developer     JMDMahdi, meysamrazmi, vispa
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

    /**
     * Processes the payment form
     * and returns HTML to be displayed to the user
     * generally with a success/failed message
     *
     * @param $data     array       form post data
     * @return string   HTML to display
     */
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
            $phone = !empty( $orderinfo->billing_phone_1 ) ? $orderinfo->billing_phone_1 : '';
        }

        // Load order
        F0FTable::addIncludePath( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
        $orderpayment = F0FTable::getInstance( 'Order', 'J2StoreTable' )
                                ->getClone();
        $orderpayment->load( $data['orderpayment_id'] );

        if ( $vars->api_key == NULL || $vars->api_key == '' )
        {
            $msg         = "لطفا تنظیمات درگاه IDPay را بررسی کنید.";
            $vars->error = $msg;
            $orderpayment->add_history( $msg );
            $orderpayment->store();

            return $this->_getLayout( 'prepayment', $vars );
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
                $msg         = "واحد پول انتخاب شده پشتیبانی نمی شود.";
                $vars->error = $msg;
                $orderpayment->add_history( $msg );
                $orderpayment->store();

                return $this->_getLayout( 'prepayment', $vars );
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
                $msg         = sprintf( 'خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->error_code, $result->error_message );
                $vars->error = $msg;
                $orderpayment->add_history( $msg );
                $orderpayment->store();

                return $this->_getLayout( 'prepayment', $vars );
            }

            // Save transaction id
            $orderpayment->transaction_id = $result->id;
            $orderpayment->store();

            $vars->idpay = $result->link;
            return $this->_getLayout( 'prepayment', $vars );
        }
    }

    function _postPayment( $data ) {
        $app      = JFactory::getApplication();
        $jinput   = $app->input;
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

        if ( $orderpayment->get( 'transaction_status' ) == 'Processed' || $orderpayment->get( 'transaction_status' ) == 'Confirmed' )
        {
            $app->enqueueMessage( 'وضعیت این تراکنش قبلا در حالت پرداخت شده بوده است.', 'Message' );

            return;
        }

        // Save transaction details based on posted data.
        $payment_details           = new JObject();
        $payment_details->status   = $status;
        $payment_details->track_id = $track_id;
        $payment_details->id       = $id;
        $payment_details->order_id = $order_id;
        $payment_details->amount   = $amount;
        $payment_details->card_no  = $card_no;
        $payment_details->date     = $date;

        $orderpayment->transaction_details = json_encode( $payment_details );
        $orderpayment->store();

        if ( $status != 10 )
        {
            $orderpayment->add_history( 'Error Code : ' . $status . ' - Error Message : ' . $this->getStatus($status) . ' - IDPay Track ID : ' . $track_id . ' - Payer card no: ' . $card_no );
            $app->enqueueMessage( $this->idpay_get_filled_message( $track_id, $order_id, 'failed_massage' ), 'Error' );
            // Set transaction status to 'Failed'
            $orderpayment->update_status(3);
            $orderpayment->store();

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
            $orderpayment->add_history( $msg );
            // Set transaction status to 'Failed'
            $orderpayment->update_status(3);
            $orderpayment->store();

            return;
        }

        $verify_status   = empty( $result->status ) ? NULL : $result->status;
        $verify_order_id = empty( $result->order_id ) ? NULL : $result->order_id;
        $verify_track_id = empty( $result->track_id ) ? NULL : $result->track_id;
        $verify_amount   = empty( $result->amount ) ? NULL : $result->amount;
        $verify_card_no  = empty( $result->payment->card_no ) ? NULL : $result->payment->card_no;

        // Update transaction details
        $orderpayment->transaction_details = json_encode( $result );

        if ( empty( $verify_status ) || empty( $verify_track_id ) || empty( $verify_amount ) || $verify_status < 100 )
        {

            $msg = $this->idpay_get_filled_message( $verify_track_id, $verify_order_id, 'failed_massage' );
            $orderpayment->add_history( 'Error Code : ' . $verify_status . ' - Error Message : ' . $this->getStatus($verify_status) . ' - IDPay Track ID : ' . $verify_track_id . ' - Payer card no: ' . $verify_card_no );
            $app->enqueueMessage( $msg, 'Error' );
            // Set transaction status to 'Failed'
            $orderpayment->update_status(3);
            $orderpayment->store();

            return;
        }
        else
        { // Payment is successful.
            $msg = $this->idpay_get_filled_message( $verify_track_id, $verify_order_id, 'success_massage' );
            // Set transaction status to 'PROCESSED'
            $orderpayment->transaction_status = JText::_( 'J2STORE_PROCESSED' );
            $app->enqueueMessage( $msg, 'message' );
            $orderpayment->add_history( 'Remote Status : ' . $verify_status . ' - IDPay Track ID : ' . $verify_track_id . ' - Payer card no: ' . $verify_card_no );

            if ( $orderpayment->store() )
            {
                $orderpayment->payment_complete();
                $orderpayment->empty_cart();
            }
        }
    }

    public function idpay_get_filled_message( $track_id, $order_id, $type ) {
        return str_replace( [ "{track_id}", "{order_id}" ], [
            $track_id,
            $order_id,
        ], $this->params->get( $type, '' ) );
    }

    public function getStatus($status_code){
        switch ($status_code){
            case 1:
                return 'پرداخت انجام نشده است';
                break;
            case 2:
                return 'پرداخت ناموفق بوده است';
                break;
            case 3:
                return 'خطا رخ داده است';
                break;
            case 4:
                return 'بلوکه شده';
                break;
            case 5:
                return 'برگشت به پرداخت کننده';
                break;
            case 6:
                return 'برگشت خورده سیستمی';
                break;
            case 7:
                return 'انصراف از پرداخت';
                break;
            case 8:
                return 'به درگاه پرداخت منتقل شد';
                break;
            case 10:
                return 'در انتظار تایید پرداخت';
                break;
            case 100:
                return 'پرداخت تایید شده است';
                break;
            case 101:
                return 'پرداخت قبلا تایید شده است';
                break;
            case 200:
                return 'به دریافت کننده واریز شد';
                break;
        }
    }
}
