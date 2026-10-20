<?php

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if (defined('PAYMENT_NOTIFICATION')) {
    // Called by Hubtel (callback) or by the customer browser inside the iframe (return, cancel)
    $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;
    $token = isset($_REQUEST['token']) ? (string) $_REQUEST['token'] : '';

    if (empty($order_id)) {
        exit;
    }

    $order_info = fn_hubtel_get_order_by_token($order_id, $token);

    if (empty($order_info)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden';
        exit;
    }

    $reference = isset($order_info['payment_info']['hubtel_client_reference'])
        ? (string) $order_info['payment_info']['hubtel_client_reference']
        : '';

    if ($mode == 'callback') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : array();

        $data = array();
        if (isset($body['Data']) && is_array($body['Data'])) {
            $data = $body['Data'];
        } elseif (isset($body['data']) && is_array($body['data'])) {
            $data = $body['data'];
        }

        $callback_reference = '';
        if (isset($data['ClientReference'])) {
            $callback_reference = (string) $data['ClientReference'];
        } elseif (isset($data['clientReference'])) {
            $callback_reference = (string) $data['clientReference'];
        } elseif (isset($body['ClientReference'])) {
            $callback_reference = (string) $body['ClientReference'];
        } elseif (isset($body['clientReference'])) {
            $callback_reference = (string) $body['clientReference'];
        }

        $code = '';
        if (isset($body['ResponseCode'])) {
            $code = (string) $body['ResponseCode'];
        } elseif (isset($body['responseCode'])) {
            $code = (string) $body['responseCode'];
        }

        // Never trust the callback alone. fn_hubtel_process_order checks the status with Hubtel.
        if ($callback_reference !== '') {
            fn_hubtel_process_order($order_id, $callback_reference, $code);
        }

        header('HTTP/1.1 200 OK');
        header('Content-Type: text/plain');
        echo 'OK';
        exit;
    }

    if ($mode == 'return') {
        if ($reference !== '') {
            $result = fn_hubtel_process_order($order_id, $reference);

            if ($result == 'pending' || $result == 'mismatch') {
                // Keep the order open. The callback or the cron check will confirm it.
                $current_order = fn_get_order_info($order_id);
                if (!empty($current_order) && $current_order['status'] !== 'F' && $current_order['status'] !== 'P') {
                    fn_finish_payment($order_id, array(
                        'order_status' => 'O',
                        'reason_text' => __('hubtel_waiting_for_confirmation'),
                    ));
                }
            }
        }

        // We are inside the iframe here. Move the whole window to the store.
        fn_hubtel_breakout(fn_hubtel_finish_url($order_id, $token));
    }

    if ($mode == 'cancel') {
        if ($order_info['status'] !== 'P') {
            fn_finish_payment($order_id, array(
                'order_status' => 'F',
                'reason_text' => __('hubtel_customer_cancelled'),
            ));
        }

        fn_hubtel_breakout(fn_hubtel_finish_url($order_id, $token));
    }

    exit;
}

// Start of payment: runs when the customer places the order
$params = isset($processor_data['processor_params']) && is_array($processor_data['processor_params'])
    ? $processor_data['processor_params']
    : array();
$order_id = (int) $order_id;

if (empty($params['client_id']) || empty($params['client_secret']) || empty($params['merchant_account_number'])) {
    fn_set_notification('E', __('error'), __('hubtel_credentials_missing'));
    fn_finish_payment($order_id, array(
        'order_status' => 'F',
        'reason_text' => __('hubtel_credentials_missing'),
    ));
    fn_order_placement_routines('route', $order_id);
    exit;
}

$amount = fn_hubtel_to_ghs($order_info['total']);

if ($amount === false || $amount <= 0) {
    fn_set_notification('E', __('error'), __('hubtel_ghs_currency_required'));
    fn_finish_payment($order_id, array(
        'order_status' => 'F',
        'reason_text' => __('hubtel_ghs_currency_required'),
    ));
    fn_order_placement_routines('route', $order_id);
    exit;
}

// Unique reference, max 32 characters. Format: o<order_id>x<random>
$client_reference = 'o' . $order_id . 'x' . substr(md5(uniqid('', true)), 0, 12);

// Token is part of the callback, return and cancel URLs, and of the iframe page URL
$checkout_token = bin2hex(random_bytes(32));
$url_query = 'payment=hubtel&order_id=' . $order_id . '&token=' . $checkout_token;

$description = trim(preg_replace('/[^A-Za-z0-9 ]/', '', 'Order ' . $order_id));

$payload = array(
    'totalAmount' => $amount,
    'description' => $description,
    'callbackUrl' => fn_url('payment_notification.callback?' . $url_query, AREA, 'https'),
    'returnUrl' => fn_url('payment_notification.return?' . $url_query, AREA, 'https'),
    'cancellationUrl' => fn_url('payment_notification.cancel?' . $url_query, AREA, 'https'),
    'merchantAccountNumber' => trim($params['merchant_account_number']),
    'clientReference' => $client_reference,
);

if (!empty($order_info['firstname']) || !empty($order_info['lastname'])) {
    $payload['payeeName'] = trim($order_info['firstname'] . ' ' . $order_info['lastname']);
}
if (!empty($order_info['phone'])) {
    $payload['payeeMobileNumber'] = $order_info['phone'];
}
if (!empty($order_info['email'])) {
    $payload['payeeEmail'] = $order_info['email'];
}

$result = fn_hubtel_initiate_checkout($params, $payload);

$res_code = isset($result['responseCode']) ? (string) $result['responseCode'] : (isset($result['code']) ? (string) $result['code'] : (isset($result['ResponseCode']) ? (string) $result['ResponseCode'] : ''));
if ($res_code !== '' && ctype_digit($res_code) && strlen($res_code) < 4) {
    $res_code = str_pad($res_code, 4, '0', STR_PAD_LEFT);
}

$checkout_data = (isset($result['data']) && is_array($result['data']))
    ? $result['data']
    : ((isset($result['Data']) && is_array($result['Data'])) ? $result['Data'] : $result);

// Onsite flow: checkoutDirectUrl is the link meant for paying on your own page (iframe).
$checkout_url = '';
foreach (array('checkoutDirectUrl', 'CheckoutDirectUrl', 'checkoutUrl', 'CheckoutUrl', 'checkout_url') as $url_key) {
    if (!empty($checkout_data[$url_key])) {
        $checkout_url = (string) $checkout_data[$url_key];
        break;
    }
}

if ($checkout_url === '' || ($res_code !== '' && $res_code !== '0000')) {
    $reason = $res_code !== '' ? fn_hubtel_response_message($res_code) : __('hubtel_initiate_failed');
    fn_hubtel_log('Initiate failed for order ' . $order_id . ': ' . json_encode($result));
    fn_set_notification('E', __('error'), $reason);

    fn_finish_payment($order_id, array(
        'order_status' => 'F',
        'reason_text' => $reason,
    ));
    fn_order_placement_routines('route', $order_id);
    exit;
}

$checkout_id = isset($checkout_data['checkoutId']) ? (string) $checkout_data['checkoutId'] : (isset($checkout_data['CheckoutId']) ? (string) $checkout_data['CheckoutId'] : '');

fn_update_order_payment_info($order_id, array(
    'hubtel_client_reference' => $client_reference,
    'hubtel_checkout_id' => $checkout_id,
    'hubtel_checkout_url' => $checkout_url,
    'hubtel_checkout_token' => $checkout_token,
));

$checkout_page_url = fn_url(
    'hubtel.checkout?order_id=' . $order_id . '&token=' . rawurlencode($checkout_token),
    'C',
    'https'
);

$query = 'order_id=' . $order_id . '&token=' . $checkout_token;

if (defined('AJAX_REQUEST')) {
    // No page to write into. Send the browser to the checkout page of the add-on.
    Tygh::$app['ajax']->assign('force_redirection', $checkout_page_url);
    exit;
}

// Same idea as the Paystack processor: write the pay screen into the "placing order" page.
echo fn_hubtel_render_overlay(array(
    'checkout_url' => $checkout_url,
    'status_url' => fn_url('hubtel.status?' . $query, 'C', 'https'),
    'finish_url' => fn_hubtel_finish_url($order_id, $checkout_token),
    'cancel_url' => fn_url('payment_notification.cancel?' . $url_query, 'C', 'https'),
    'order_id' => $order_id,
    'amount' => $amount,
));

echo '</body></html>';
exit;
