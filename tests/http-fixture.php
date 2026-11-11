<?php
/** Disposable HTTP concurrency fixture; never shipped in the release ZIP. */
if(!defined('WP_CLI') || !WP_CLI || getenv('CRJ_TESTING')!=='1')throw new RuntimeException('Test site only');
if(!empty($args[0])){
 $order=wc_get_order((int)$args[0]);
 $notes=wc_get_order_notes(['order_id'=>$order->get_id(),'limit'=>100]);
 $success=array_filter($notes,fn($n)=>str_contains($n->content,'signed successful return verified'));
 if(!$order->is_paid() || count($success)!==1)throw new RuntimeException('Concurrent settlement verification failed');
 echo "PASS: concurrent HTTP callbacks settle exactly once\n";return;
}
update_option('woocommerce_crj_card_settings',['enabled'=>'yes','environment'=>'sandbox','merchant_id'=>'test-merchant','merchant_password'=>'test-password','integrity_salt'=>'test-integrity']);
$order=wc_create_order();$order->set_currency('PKR');$order->set_payment_method('crj_card');$order->set_total('12.34');
$ref='CR'.$order->get_id().'X'.strtoupper(bin2hex(random_bytes(5)));
foreach(['_crj_environment'=>'sandbox','_crj_ref'=>$ref,'_crj_amount'=>'1234','_crj_merchant'=>'test-merchant'] as $k=>$v)$order->update_meta_data($k,$v);
$attempt=['request_ref'=>$ref,'provider_ref'=>'','type'=>'unknown','status'=>'INITIATED','created'=>current_time('mysql')];
$order->update_meta_data('_crj_attempts',[$attempt]);$order->save();if(!crj_ledger_store($order,$attempt))throw new RuntimeException('Fixture ledger failure');
$fields=['pp_TxnRefNo'=>$ref,'pp_BillReference'=>'ORDER-'.$order->get_id(),'pp_Amount'=>'1234','pp_MerchantID'=>'test-merchant','pp_TxnCurrency'=>'PKR','pp_ResponseCode'=>'000'];
ksort($fields,SORT_STRING);$fields['pp_SecureHash']=strtoupper(hash_hmac('sha256','test-integrity&'.implode('&',$fields),'test-integrity'));
echo wp_json_encode(['order_id'=>$order->get_id(),'payload'=>$fields]);
