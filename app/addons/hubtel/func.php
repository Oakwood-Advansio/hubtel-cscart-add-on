<?php

use Tygh\Http;
use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

const HUBTEL_INITIATE_URL = 'https://payproxyapi.hubtel.com/items/initiate';
const HUBTEL_STATUS_URL = 'https://api-txstatus.hubtel.com/transactions/';

function fn_hubtel_log($message)
{
    error_log('Hubtel: ' . $message);
    if (function_exists('fn_log_event')) {
        fn_log_event('general', 'error', array('message' => 'Hubtel: ' . $message));
    }
}

/**
 * Hubtel response codes from the Online Checkout docs.
 */
function fn_hubtel_response_message($code)
{
    $messages = array(
        '0000' => __('hubtel_payment_confirmed'),
        '0005' => __('hubtel_status_unknown'),
        '2001' => __('hubtel_payment_failed'),
        '4000' => __('hubtel_validation_error'),
        '4070' => __('hubtel_cannot_complete'),
    );

    $code = (string) $code;

    return isset($messages[$code]) ? $messages[$code] : __('hubtel_response_code', array('[code]' => $code));
}

function fn_hubtel_headers(array $params)
{
    $client_id = isset($params['client_id']) ? trim($params['client_id']) : '';
    $client_secret = isset($params['client_secret']) ? trim($params['client_secret']) : '';

    return array(
        'Content-Type: application/json',
        'Accept: application/json',
        'Cache-Control: no-cache',
        'Authorization: Basic ' . base64_encode($client_id . ':' . $client_secret),
    );
}

/**
 * Converts an amount from the store primary currency to GHS.
 * Returns false when GHS is not an active currency in the store.
 */
function fn_hubtel_to_ghs($amount)
{
    $currencies = Registry::get('currencies');

    if (isset($currencies['GHS'])) {
        return round((float) fn_format_price_by_currency($amount, CART_PRIMARY_CURRENCY, 'GHS'), 2);
    }

    if (defined('CART_PRIMARY_CURRENCY') && CART_PRIMARY_CURRENCY === 'GHS') {
        return round((float) $amount, 2);
    }

    return false;
}

function fn_hubtel_initiate_checkout(array $params, array $payload)
{
    $response = Http::post(
        HUBTEL_INITIATE_URL,
        json_encode($payload),
        array('headers' => fn_hubtel_headers($params), 'timeout' => 30)
    );

    $data = json_decode($response, true);

    return is_array($data) ? $data : array();
}

function fn_hubtel_check_status(array $params, $client_reference)
{
    $url = HUBTEL_STATUS_URL . rawurlencode(trim($params['merchant_account_number'])) . '/status';

    $response = Http::get(
        $url,
        array('clientReference' => $client_reference),
        array('headers' => fn_hubtel_headers($params), 'timeout' => 30)
    );

    $data = json_decode($response, true);

    if (!is_array($data)) {
        fn_hubtel_log('Status check gave no valid JSON for ' . $client_reference . ': ' . substr((string) $response, 0, 300));
        return array();
    }

    return $data;
}

/**
 * Checks one order against Hubtel and updates the order.
 *
 * @param int    $order_id
 * @param string $client_reference
 * @param string $callback_code    ResponseCode sent in the Hubtel callback, if any
 *
 * @return string paid|failed|pending|mismatch|unknown|refunded
 */
function fn_hubtel_process_order($order_id, $client_reference, $callback_code = '')
{
    $order_info = fn_get_order_info($order_id);

    if (empty($order_info)) {
        return 'unknown';
    }

    if ($order_info['status'] === 'P') {
        return 'paid';
    }

    $processor_data = fn_get_processor_data($order_info['payment_id']);

    if (empty($processor_data['processor_params'])) {
        return 'unknown';
    }

    $params = $processor_data['processor_params'];
    $saved_reference = isset($order_info['payment_info']['hubtel_client_reference'])
        ? $order_info['payment_info']['hubtel_client_reference']
        : '';

    if ($saved_reference === '' || $saved_reference !== $client_reference) {
        fn_hubtel_log('Reference mismatch for order ' . $order_id);
        return 'unknown';
    }

    $result = fn_hubtel_check_status($params, $client_reference);
    $data = array();
    if (isset($result['data']) && is_array($result['data'])) {
        $data = $result['data'];
    } elseif (isset($result['Data']) && is_array($result['Data'])) {
        $data = $result['Data'];
    }

    $status = '';
    if (isset($data['status'])) {
        $status = strtolower((string) $data['status']);
    } elseif (isset($data['Status'])) {
        $status = strtolower((string) $data['Status']);
    }

    if ($status === 'paid' || $status === 'completed' || $status === 'success') {
        $expected = fn_hubtel_to_ghs($order_info['total']);
        $amount = 0.0;
        if (isset($data['amount'])) {
            $amount = (float) $data['amount'];
        } elseif (isset($data['Amount'])) {
            $amount = (float) $data['Amount'];
        }

        if ($expected === false || abs($amount - $expected) > 0.01) {
            fn_hubtel_log('Amount mismatch for order ' . $order_id . ': paid ' . $amount . ', expected ' . var_export($expected, true));
            return 'mismatch';
        }

        $transaction_id = '';
        if (isset($data['transactionId'])) {
            $transaction_id = (string) $data['transactionId'];
        } elseif (isset($data['TransactionId'])) {
            $transaction_id = (string) $data['TransactionId'];
        }

        $network_reference = '';
        if (isset($data['externalTransactionId'])) {
            $network_reference = (string) $data['externalTransactionId'];
        } elseif (isset($data['ExternalTransactionId'])) {
            $network_reference = (string) $data['ExternalTransactionId'];
        }

        $payment_method = '';
        if (isset($data['paymentMethod'])) {
            $payment_method = (string) $data['paymentMethod'];
        } elseif (isset($data['PaymentMethod'])) {
            $payment_method = (string) $data['PaymentMethod'];
        }

        fn_finish_payment($order_id, array(
            'order_status' => 'P',
            'reason_text' => __('hubtel_payment_confirmed'),
            'transaction_id' => $transaction_id,
            'hubtel_network_reference' => $network_reference,
            'hubtel_payment_method' => $payment_method,
        ));

        return 'paid';
    }

    if ($status === 'refunded') {
        fn_hubtel_log('Order ' . $order_id . ' shows as Refunded at Hubtel. Review it by hand.');
        return 'refunded';
    }

    $callback_code = (string) $callback_code;

    if (in_array($status, array('failed', 'declined', 'cancelled', 'canceled', 'expired'), true)) {
        $reason = $callback_code !== '' && $callback_code !== '0000'
            ? fn_hubtel_response_message($callback_code)
            : __('hubtel_payment_failed');

        fn_finish_payment($order_id, array(
            'order_status' => 'F',
            'reason_text' => $reason,
        ));

        return 'failed';
    }

    if ($callback_code !== '' && $callback_code !== '0000') {
        if ($callback_code === '0005') {
            // State unknown. Do not mark as failed. Hubtel says to contact support.
            fn_hubtel_log('Code 0005 for order ' . $order_id . '. State unknown, contact Hubtel.');
            return 'pending';
        }

        fn_finish_payment($order_id, array(
            'order_status' => 'F',
            'reason_text' => fn_hubtel_response_message($callback_code),
        ));

        return 'failed';
    }

    return 'pending';
}

/**
 * Hubtel asks merchants to check the status when no final status
 * arrives after 5 minutes. Call this from cron.
 */
function fn_hubtel_check_pending_orders()
{
    $order_ids = db_get_fields(
        'SELECT o.order_id FROM ?:orders AS o'
        . ' INNER JOIN ?:payments AS p ON p.payment_id = o.payment_id'
        . ' INNER JOIN ?:payment_processors AS pp ON pp.processor_id = p.processor_id'
        . ' WHERE pp.processor_script = ?s AND o.status IN (?a) AND o.timestamp < ?i AND o.timestamp > ?i',
        'hubtel.php',
        array('N', 'O'),
        TIME - 300,
        TIME - 3 * 86400
    );

    $summary = array();

    foreach ($order_ids as $order_id) {
        $order_info = fn_get_order_info($order_id);
        $reference = isset($order_info['payment_info']['hubtel_client_reference'])
            ? $order_info['payment_info']['hubtel_client_reference']
            : '';

        if ($reference === '') {
            continue;
        }

        $summary[$order_id] = fn_hubtel_process_order($order_id, $reference);
    }

    return $summary;
}

/**
 * Returns the order only when the token is right and the order uses Hubtel.
 * The token is created when checkout starts and sits in the order payment info.
 */
function fn_hubtel_get_order_by_token($order_id, $token)
{
    $order_id = (int) $order_id;
    $token = (string) $token;

    if ($order_id <= 0 || $token === '') {
        return array();
    }

    $order_info = fn_get_order_info($order_id);

    if (empty($order_info)) {
        return array();
    }

    $saved = isset($order_info['payment_info']['hubtel_checkout_token'])
        ? (string) $order_info['payment_info']['hubtel_checkout_token']
        : '';

    if ($saved === '' || !hash_equals($saved, $token)) {
        return array();
    }

    $processor_data = !empty($order_info['payment_id']) ? fn_get_processor_data($order_info['payment_id']) : array();

    if (empty($processor_data['processor_script']) || $processor_data['processor_script'] !== 'hubtel.php') {
        return array();
    }

    return $order_info;
}

function fn_hubtel_finish_url($order_id, $token)
{
    return fn_url('hubtel.finish?order_id=' . (int) $order_id . '&token=' . rawurlencode($token), 'C', 'https');
}

/**
 * Hubtel sends the customer back to our return and cancel URLs inside the iframe.
 * This page moves the whole browser window to the store page instead.
 */
function fn_hubtel_breakout($url)
{
    $js = json_encode((string) $url);
    $safe = htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Hubtel</title></head><body>'
        . '<script>try { window.top.location.href = ' . $js . '; } catch (e) { window.location.href = ' . $js . '; }</script>'
        . '<noscript><a href="' . $safe . '" target="_top">Continue</a></noscript>'
        . '</body></html>';
    exit;
}
