<?php
declare(strict_types=1);

use D2S\domain\Problem;
use D2S\repositories\Database;
use D2S\services\AlipayPayments;
use D2S\services\AlipayBills;
use D2S\services\AlipayRefunds;
use D2S\services\WechatBills;
use D2S\services\WalletRefunds;
use D2S\services\WechatRefunds;
use D2S\services\WechatRefundReviews;
use D2S\services\WechatProfiles;
use D2S\services\FinancialReviews;
use D2S\services\WechatFinancialReviews;
use D2S\services\RefundReviews;
use D2S\services\Audit;
use D2S\services\HttpRequest;
use D2S\services\PaymentOperations;
use D2S\services\PaymentProfiles;
use D2S\services\SecretBox;
use D2S\services\WebSessions;

$db = Database::fromEnvironment();
$now = time();
$write = $_SERVER['REQUEST_METHOD'] === 'POST';
$owner = HttpRequest::session('admin',new WebSessions($db),$write,$now);
$profiles = new PaymentProfiles($db,SecretBox::fromEnvironment());
$service = new PaymentOperations($db,new AlipayPayments($db,$profiles));
$refunds = new AlipayRefunds($db,$profiles);
$context = Audit::context($_SERVER);
if ($write && $path === '/api/v2/admin/payments/bills/download') {
    $input = HttpRequest::json();
    HttpRequest::strings($input,['bill_date']);
    if (isset($input['order_id']) && !is_string($input['order_id'])) { throw new Problem('order_id_invalid'); }
    if (isset($input['bill_channel']) && !is_string($input['bill_channel'])) { throw new Problem('bill_channel_invalid'); }
    $channel = $input['bill_channel'] ?? 'alipay';
    $bill = match ($channel) {
        'alipay' => (new AlipayBills($db,$profiles))->download($owner['id'],$input['bill_date'],$input['order_id'] ?? null,$context,$now),
        'wechat' => (new WechatBills($db,new WechatProfiles($db,SecretBox::fromEnvironment())))
            ->download($owner['id'],$input['bill_date'],$input['order_id'] ?? null,$context,$now),
        default => throw new Problem('bill_channel_invalid'),
    };
    header('Content-Type: '.($bill['content_type'] ?? 'application/zip'));
    header('Content-Disposition: attachment; filename="'.$bill['filename'].'"');
    header('Content-Length: '.strlen($bill['body']));
    echo $bill['body'];
    exit;
} elseif (!$write && $_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/v2/admin/payments/orders') {
    $page = filter_var($_GET['page'] ?? '1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
    if ($page === false) { throw new Problem('page_invalid'); }
    if (isset($_GET['status']) && !is_string($_GET['status'])) { throw new Problem('order_status_invalid'); }
    $data = $service->orders($owner['id'],$page,$context,$now,$_GET['status'] ?? null);
} elseif (preg_match('#^/api/v2/admin/payments/orders/([a-f0-9]{32})/review$#D',$path,$parts)) {
    $channel = $db->prepare('SELECT payment_method FROM portal_orders WHERE id=?');
    $channel->execute([$parts[1]]);
    $reviews = $channel->fetchColumn() === 'wechat_native'
        ? new WechatFinancialReviews($db,new WechatProfiles($db,SecretBox::fromEnvironment()))
        : new FinancialReviews($db,$profiles);
    if ($write) {
        $input = HttpRequest::json();
        HttpRequest::strings($input,['action','reason','idempotency_key']);
        if (($input['confirm_resolution'] ?? null) !== true) { throw new Problem('payment_review_confirmation_required'); }
        if (isset($input['payment_id']) && !is_string($input['payment_id'])) { throw new Problem('payment_review_input_invalid'); }
        $data = $reviews->resolve($owner['id'],$parts[1],$input['action'],$input['payment_id'] ?? null,$input['reason'],$input['idempotency_key'],$context,$now);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = filter_var($_GET['page'] ?? '1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if ($page === false) { throw new Problem('page_invalid'); }
        $data = $reviews->records($owner['id'],$parts[1],$context,$now,$page);
    } else { throw new Problem('not_found',404); }
} elseif (preg_match('#^/api/v2/admin/payments/orders/([a-f0-9]{32})/wechat-refunds$#D',$path,$parts)) {
    $wechatRefunds = new WechatRefunds($db,new WechatProfiles($db,SecretBox::fromEnvironment()));
    if ($write) {
        $input = HttpRequest::json();
        HttpRequest::strings($input,['payment_id','reason','idempotency_key']);
        if (($input['confirm_refund'] ?? null) !== true) { throw new Problem('refund_confirmation_required'); }
        $data = $wechatRefunds->request($owner['id'],$input['payment_id'],$input['amount'] ?? null,$input['reason'],$input['idempotency_key'],$context,$now,$parts[1]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = filter_var($_GET['page'] ?? '1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if ($page === false) { throw new Problem('page_invalid'); }
        $data = $wechatRefunds->records($owner['id'],$parts[1],$context,$now,$page);
    } else { throw new Problem('not_found',404); }
} elseif ($write && preg_match('#^/api/v2/admin/payments/wechat-refunds/([a-f0-9]{32})/process$#D',$path,$parts)) {
    HttpRequest::json();
    $data = (new WechatRefunds($db,new WechatProfiles($db,SecretBox::fromEnvironment())))->process($parts[1],$context,$now,$owner['id']);
} elseif (preg_match('#^/api/v2/admin/payments/orders/([a-f0-9]{32})/wallet-refunds$#D',$path,$parts)) {
    $walletRefunds = new WalletRefunds($db);
    if ($write) {
        $input = HttpRequest::json();
        HttpRequest::strings($input,['reason','idempotency_key']);
        if (($input['confirm_refund'] ?? null) !== true) { throw new Problem('refund_confirmation_required'); }
        $data = $walletRefunds->request($owner['id'],$parts[1],$input['amount'] ?? null,$input['reason'],$input['idempotency_key'],$context,$now);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = filter_var($_GET['page'] ?? '1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if ($page === false) { throw new Problem('page_invalid'); }
        $data = $walletRefunds->records($owner['id'],$parts[1],$context,$now,$page);
    } else { throw new Problem('not_found',404); }
} elseif (preg_match('#^/api/v2/admin/payments/orders/([a-f0-9]{32})/refunds$#D',$path,$parts)) {
    if ($write) {
        $input = HttpRequest::json();
        HttpRequest::strings($input,['payment_id','reason','idempotency_key']);
        if (($input['confirm_refund'] ?? null) !== true) { throw new Problem('refund_confirmation_required'); }
        $data = $refunds->request($owner['id'],$input['payment_id'],$input['amount'] ?? null,$input['reason'],$input['idempotency_key'],$context,$now,$parts[1]);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = filter_var($_GET['page'] ?? '1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if ($page === false) { throw new Problem('page_invalid'); }
        $data = $refunds->records($owner['id'],$parts[1],$context,$now,$page);
    } else { throw new Problem('not_found',404); }
} elseif (preg_match('#^/api/v2/admin/payments/wechat-refunds/([a-f0-9]{32})/review$#D',$path,$parts)) {
    $review = new WechatRefundReviews($db);
    if ($write) {
        $input = HttpRequest::json();
        HttpRequest::strings($input,['action','reason','idempotency_key']);
        if (($input['confirm_review'] ?? null) !== true) { throw new Problem('refund_review_confirmation_required'); }
        $data = $review->act($owner['id'],$parts[1],$input['action'],$input['reason'],$input['idempotency_key'],$context,$now);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = filter_var($_GET['page'] ?? '1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if ($page === false) { throw new Problem('page_invalid'); }
        $data = $review->records($owner['id'],$parts[1],$context,$now,$page);
    } else { throw new Problem('not_found',404); }
} elseif (preg_match('#^/api/v2/admin/payments/refunds/([a-f0-9]{32})/review$#D',$path,$parts)) {
    $review = new RefundReviews($db);
    if ($write) {
        $input = HttpRequest::json();
        HttpRequest::strings($input,['action','reason','idempotency_key']);
        if (($input['confirm_review'] ?? null) !== true) { throw new Problem('refund_review_confirmation_required'); }
        $data = $review->act($owner['id'],$parts[1],$input['action'],$input['reason'],$input['idempotency_key'],$context,$now);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = filter_var($_GET['page'] ?? '1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
        if ($page === false) { throw new Problem('page_invalid'); }
        $data = $review->records($owner['id'],$parts[1],$context,$now,$page);
    } else { throw new Problem('not_found',404); }
} elseif ($write && preg_match('#^/api/v2/admin/payments/refunds/([a-f0-9]{32})/process$#D',$path,$parts)) {
    HttpRequest::json();
    $data = $refunds->process($parts[1],$context,$now,$owner['id']);
} elseif ($write && preg_match('#^/api/v2/admin/payments/orders/([a-f0-9]{32})/(query|close)$#D',$path,$parts)) {
    $input = HttpRequest::json();
    if ($parts[2] === 'close' && ($input['confirm_close'] ?? null) !== true) { throw new Problem('close_confirmation_required'); }
    $data = $service->operate($owner['id'],$parts[1],$parts[2],$context,$now);
} else { throw new Problem('not_found',404); }
echo json_encode(['success'=>true,'data'=>$data],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
