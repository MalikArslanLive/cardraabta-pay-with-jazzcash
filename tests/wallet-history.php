<?php
require __DIR__ . '/integration.php';
$settings=get_option('woocommerce_crj_card_settings');
$settings['live_merchant_id']='test-live-merchant';
$settings['live_merchant_password']='test-live-password';
$settings['live_integrity_salt']='test-integrity';
update_option('woocommerce_crj_card_settings',$settings);
$lookup=new ReflectionMethod(CRJ_Orchestrator_Gateway::class,'reconcile_phone_history');
foreach (['paid','dropped','cancelled'] as $expected) {
 [$order,$response]=crj_test_order();
 $attempts=$order->get_meta('_crj_attempts');
 $attempts[0]['environment']='live';
 $attempts[0]['merchant_id']='test-live-merchant';
 $order->update_meta_data('_crj_attempts',$attempts);
 $order->update_meta_data('_crj_environment','live');
 $order->update_meta_data('_crj_merchant','test-live-merchant');
 $order->save();
 $request=$response['pp_TxnRefNo'];
 $response['pp_TxnRefNo']=$request.'a1';
 $response['pp_MerchantID']='test-live-merchant';
 $response['pp_TxnType']='MWALLET';
 $response['pp_ResponseCode']='999';
 $waiting=CRJ_Orchestrator_Gateway::process_callback_data(crj_test_sign($response))['order'];
 crj_test(crj_needs_wallet_history($waiting) && !$waiting->needs_payment() && crj_checkout_return_url($waiting)===$waiting->get_checkout_order_received_url() && wp_next_scheduled('crj_check_wallet_history',[$order->get_id(),$request,1]),'synthetic Live return stays unpaid with duplicate payment blocked');
 $row=$response;
 $row['pp_ResponseCode']=['paid'=>'000','dropped'=>'129','cancelled'=>'8900'][$expected];
 $row['pp_Status']=$expected==='paid'?'xxxx':$row['pp_ResponseCode'];
 $mock=static function($pre,$args,$url) use($row) {
  if (!str_contains($url,'/shopify/inquiry')) return $pre;
  return ['headers'=>[],'body'=>wp_json_encode(['pp_ResponseCode'=>'000','pp_Items'=>[$row]]),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
 };
 add_filter('pre_http_request',$mock,10,3);
 $settled=$lookup->invoke(null,$waiting);
 remove_filter('pre_http_request',$mock,10);
 crj_test(!crj_needs_wallet_history($settled),'matched terminal history leaves wait state: '.$expected);
 if ($expected==='paid') {
  crj_test($settled->is_paid() && $settled->get_transaction_id()===$row['pp_TxnRefNo'] && crj_checkout_return_url($settled)===$settled->get_checkout_order_received_url(),'verified history confirms payment and opens receipt');
 } else {
  crj_test(!$settled->is_paid() && $settled->has_status('failed') && crj_checkout_return_url($settled)===$settled->get_checkout_order_received_url(),'verified terminal failure opens the order receipt: '.$expected);
 }
}
echo "WALLET RESULT: 9 passed; 0 failed\n";
