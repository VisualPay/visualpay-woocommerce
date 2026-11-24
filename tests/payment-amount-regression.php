<?php
/** Run with php tests/payment-amount-regression.php. No WordPress installation or network required. */
define('ABSPATH', __DIR__);
function __($text, $domain = '') { return $text; }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value) { return json_encode($value); }
function wc_format_decimal($value, $places = 2) { return number_format((float) $value, $places, '.', ''); }
function apply_filters($name, $value, ...$args) { return $value; }

class WC_Order {
    public $meta = [];
    public $notes = [];
    public $completions = 0;
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function get_id() { return 27; }
    public function get_total() { return 1000; }
    public function get_payment_method() { return 'visualpay'; }
    public function has_status($status) { return in_array('on-hold', (array) $status, true); }
    public function is_paid() { return $this->completions > 0; }
    public function payment_complete($tx) { $this->completions++; }
    public function set_transaction_id($tx) {}
    public function add_order_note($text) { $this->notes[] = $text; }
    public function save() {}
}
class VisualPay_Transaction_Store {
    public static function update_from_status($id, $data) {}
    public static function upsert_from_create($id, $data, $extra) {}
}
require dirname(__DIR__).'/includes/class-visualpay-security.php';
require dirname(__DIR__).'/includes/class-visualpay-order-handler.php';

$checks = 0;
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function invoice($expected = '1000', $usd = '1000', $tolerance = '0', $symbol = 'USDT') {
    $order = new WC_Order;
    VisualPay_Order_Handler::save_create($order, [
        'tracking_code' => 'TRACK27', 'expected_amount' => $expected,
        'usdt_amount' => $usd, 'payment_tolerance_percent' => $tolerance,
        'currency_symbol' => $symbol,
    ]);
    return $order;
}
function confirmation($paid) {
    return ['status' => 'confirmed', 'paid_amount' => $paid, 'tracking_code' => 'TRACK27', 'order_id' => 27];
}

foreach (['1', '0', '-1', null, '', 'invalid', INF, NAN, [], true] as $paid) {
    $order = invoice();
    $status = VisualPay_Order_Handler::apply_status($order, confirmation($paid));
    check(!$order->is_paid() && $status === 'manual_review', 'Invalid/insufficient payment completed an order');
}
foreach (['1000', '1001', '999.95'] as $paid) {
    $order = invoice();
    check(VisualPay_Order_Handler::apply_status($order, confirmation($paid)) === 'confirmed', 'Covered payment rejected');
    check($order->completions === 1, 'Covered payment did not complete');
    VisualPay_Order_Handler::apply_status($order, confirmation($paid));
    check($order->completions === 1, 'Repeated confirmation completed twice');
}
$order = invoice('1000', '1000', '1');
check(VisualPay_Order_Handler::apply_status($order, confirmation('990')) === 'confirmed', 'Snapshot tolerance not honored');
$order = invoice();
$forgedQuote = confirmation('1') + ['expected_amount' => '1', 'usdt_amount' => '1', 'payment_tolerance_percent' => 100];
check(VisualPay_Order_Handler::apply_status($order, $forgedQuote) === 'manual_review', 'Status response changed the locked quote');
$notes = count($order->notes);
VisualPay_Order_Handler::apply_status($order, $forgedQuote);
check(count($order->notes) === $notes, 'Polling duplicated review notes');
check(!VisualPay_Order_Handler::is_payable($order), 'Review invoice allowed a replacement payment');
check(VisualPay_Order_Handler::apply_status($order, confirmation('1000')) === 'confirmed', 'Later sufficient payment could not clear review');
check(!$order->get_meta(VisualPay_Order_Handler::META_REVIEW), 'Resolved review flag was retained');
foreach (['tracking_code' => 'FOREIGN', 'order_id' => 28] as $key => $value) {
    $order = invoice();
    $data = confirmation('1000'); $data[$key] = $value;
    check(VisualPay_Order_Handler::apply_status($order, $data) === 'mismatch' && !$order->is_paid(), 'Foreign confirmation accepted');
}
$order = invoice(); $data = confirmation('1000'); unset($data['tracking_code'], $data['order_id']);
check(VisualPay_Order_Handler::apply_status($order, $data) === 'confirmed', 'Stored tracking binding lost with optional response fields');
$order = invoice(); unset($order->meta[VisualPay_Order_Handler::META_TOLERANCE]);
check(VisualPay_Order_Handler::apply_status($order, confirmation('990') + ['payment_tolerance_percent' => 99]) === 'manual_review', 'Legacy invoice trusted a new tolerance');
$order = invoice('0.01', '1000', 0, 'BTC');
check(VisualPay_Order_Handler::apply_status($order, confirmation('0.00001')) === 'manual_review', 'BTC units compared against USD allowance');
$order = invoice('0.01', '1000', 0, 'BTC');
check(VisualPay_Order_Handler::apply_status($order, confirmation('0.0099995')) === 'confirmed', 'USD floor not converted to token units');
$order = invoice('0.00000001', '1000', 0, 'BTC');
check(VisualPay_Order_Handler::apply_status($order, confirmation('0.000000005')) === 'manual_review', 'Tiny asset underpayment rounded away');
$order = invoice('0.01', '1000', 0, 'BTC'); unset($order->meta[VisualPay_Order_Handler::META_EXPECTED]);
check(VisualPay_Order_Handler::apply_status($order, confirmation('0.01')) === 'manual_review', 'Unknown native quote guessed from USD');
echo "Payment amount regression: $checks checks passed.\n";
