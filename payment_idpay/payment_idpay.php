<?php
/**
 * IDPay payment plugin
 *
 * @developer     JMDMahdi, meysamrazmi, vispa
 * @publisher     IDPay
 * @package       J2Store
 * @subpackage    payment
 * @copyright (C) 2020 IDPay
 * @license       http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * https://idpay.ir
 */
defined( '_JEXEC' ) or die( 'Restricted access' );

require_once( JPATH_ADMINISTRATOR . '/components/com_j2store/library/plugins/payment.php' );

class plgJ2StorePayment_idpay extends J2StorePaymentPlugin {
    /**
     * @var $_element  string  Should always correspond with the plugin's filename,
     *                         forcing it to be unique
     */
    var $_element    = 'payment_idpay';

    function __construct( & $subject, $config ) {
        parent::__construct( $subject, $config );
        $this->loadLanguage( 'com_j2store', JPATH_ADMINISTRATOR );
        $this->loadLanguage('plg_system_payment_idpay', JPATH_ADMINISTRATOR);
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
     * Prepares variables and
     * Renders the form for collecting payment info
     *
     * @return unknown_type
     */
    function _renderForm( $data )
    {
        $user = JFactory::getUser();
        $vars = new JObject();
        $vars->idpay = $this->translate( 'NAME' );
        $html = $this->_getLayout('form', $vars);
        return $html;
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
        $vars->display_name        = $this->translate( 'NAME' );
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
            $msg         = $this->translate('ERROR_CONFIG');
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
            $desc     = $this->translate('PARAMS_DESC') . $vars->order_id;
            $callback = JRoute::_( JURI::root() . "index.php?option=com_j2store&view=checkout" ) . '&orderpayment_type=' . $vars->orderpayment_type . '&task=confirmPayment';

            if ( empty( $amount ) )
            {
                $msg         = $this->translate('ERROR_PRICE');
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
                $msg         = sprintf( $this->translate('ERROR_PAYMENT'), $http_status, $result->error_code, $result->error_message );
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
        $vars     = new JObject();
        $app      = JFactory::getApplication();
        $jinput   = $app->input;
        $status   = !empty( $jinput->post->get( 'status' ) )   ? $jinput->post->get( 'status' )   : ( !empty( $jinput->get->get( 'status' ) )   ? $jinput->get->get( 'status' )   : NULL );
        $track_id = !empty( $jinput->post->get( 'track_id' ) ) ? $jinput->post->get( 'track_id' ) : ( !empty( $jinput->get->get( 'track_id' ) ) ? $jinput->get->get( 'track_id' ) : NULL );
        $id       = !empty( $jinput->post->get( 'id' ) )       ? $jinput->post->get( 'id' )       : ( !empty( $jinput->get->get( 'id' ) )       ? $jinput->get->get( 'id' )       : NULL );
        $order_id = !empty( $jinput->post->get( 'order_id' ) ) ? $jinput->post->get( 'order_id' ) : ( !empty( $jinput->get->get( 'order_id' ) ) ? $jinput->get->get( 'order_id' ) : NULL );
        $amount   = !empty( $jinput->post->get( 'amount' ) )   ? $jinput->post->get( 'amount' )   : NULL;
        $card_no  = !empty( $jinput->post->get( 'card_no' ) )  ? $jinput->post->get( 'card_no' )  : NULL;
        $date     = !empty( $jinput->post->get( 'date' ) )     ? $jinput->post->get( 'date' )     : NULL;

        F0FTable::addIncludePath( JPATH_ADMINISTRATOR . '/components/com_j2store/tables' );
        $orderpayment = F0FTable::getInstance( 'Order', 'J2StoreTable' )
                                ->getClone();

        if ( empty( $id ) || empty( $order_id ) )
        {
            $app->enqueueMessage( $this->translate('ERROR_EMPTY_PARAMS'), 'Error' );
            $vars->message = $this->translate('ERROR_EMPTY_PARAMS');
            return $this->return_result( $vars );
        }

        if ( ! $orderpayment->load( $order_id ) )
        {
            $app->enqueueMessage( $this->translate('ERROR_NOT_FOUND'), 'Error' );
            $vars->message = $this->translate('ERROR_NOT_FOUND');
            return $this->return_result( $vars );
        }

        // Check double spending.
        if ( $orderpayment->transaction_id != $id )
        {
            $app->enqueueMessage( $this->translate('ERROR_WRONG_PARAMS'), 'Error' );
            $vars->message = $this->translate('ERROR_WRONG_PARAMS');
            return $this->return_result( $vars );
        }

        if ( $orderpayment->get( 'transaction_status' ) == 'Processed' || $orderpayment->get( 'transaction_status' ) == 'Confirmed' )
        {
            $app->enqueueMessage( $this->translate('ERROR_ALREADY_COMPLETED'), 'Message' );
            $vars->message = $this->translate('ERROR_ALREADY_COMPLETED');
            return $this->return_result( $vars );
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
            $orderpayment->add_history( sprintf( $this->translate('ERROR_FAILED'), $this->translate('CODE_' . $status), $status, $track_id ) );

            $msg = $this->idpay_get_filled_message( $track_id, $order_id, 'failed_massage' );
            $app->enqueueMessage( $msg, 'Error' );
            // Set transaction status to 'Failed'
            $orderpayment->update_status(3);
            $orderpayment->store();

            $vars->message = $msg;
            return $this->return_result( $vars );
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
            $msg = sprintf( $this->translate('ERROR_FAILED_VERIFY'), $http_status, $result->error_code, $result->error_message );
            $app->enqueueMessage( $msg, 'Error' );
            $orderpayment->add_history( $msg );
            // Set transaction status to 'Failed'
            $orderpayment->update_status(3);
            $orderpayment->store();

            $vars->message = $msg;
            return $this->return_result( $vars );
        }

        $verify_status   = empty( $result->status ) ? NULL : $result->status;
        $verify_order_id = empty( $result->order_id ) ? NULL : $result->order_id;
        $verify_track_id = empty( $result->track_id ) ? NULL : $result->track_id;
        $verify_amount   = empty( $result->amount ) ? NULL : $result->amount;

        // Update transaction details
        $orderpayment->transaction_details = json_encode( $result );

        if ( empty( $verify_status ) || empty( $verify_track_id ) || empty( $verify_amount ) || $verify_status < 100 )
        {

            $msg = $this->idpay_get_filled_message( $verify_track_id, $verify_order_id, 'failed_massage' );
            $orderpayment->add_history( sprintf( $this->translate('ERROR_FAILED'), $this->translate('CODE_' . $verify_status), $verify_status, $verify_track_id ) );
            $app->enqueueMessage( $msg, 'Error' );
            // Set transaction status to 'Failed'
            $orderpayment->update_status(3);
            $orderpayment->store();

            $vars->message = $msg;
            return $this->return_result( $vars );
        }
        else
        { // Payment is successful.
            $msg = $this->idpay_get_filled_message( $verify_track_id, $verify_order_id, 'success_massage' );
            $vars->message = $msg;
            // Set transaction status to 'PROCESSED'
            $orderpayment->transaction_status = JText::_( 'J2STORE_PROCESSED' );
            $app->enqueueMessage( $msg, 'message' );
            $orderpayment->add_history( $msg );

            if ( $orderpayment->store() )
            {
                $orderpayment->payment_complete();
                $orderpayment->empty_cart();
            }
        }
        return $this->return_result( $vars );
    }

    public function idpay_get_filled_message( $track_id, $order_id, $type ) {
        return str_replace( [ "{track_id}", "{order_id}" ], [
            $track_id,
            $order_id,
        ], $this->params->get( $type, '' ) );
    }

    protected function return_result($vars) {
        return $this->_getLayout( 'postpayment', $vars );
    }

    /**
     * translate plugin language files
     * @param $key
     * @return mixed
     */
    protected function translate($key)
    {
        return JText::_('PLG_J2STORE_IDPAY_' . strtoupper($key));
    }
}
