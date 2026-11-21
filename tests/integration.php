<?php
/** Real WordPress/WooCommerce database regression checks. Run only on a disposable site. */
if (!defined('WP_CLI') || !WP_CLI || !getenv('CRJ_TESTING')) { throw new RuntimeException('Disposable test site and CRJ_TESTING=1 required'); }
$GLOBALS['crj_passed'] = 0;
function crj_test($condition, $message) {
 $passed =& $GLOBALS['crj_passed'];
 if (!$condition) { throw new RuntimeException('FAIL: '.$message); }
 ++$passed; echo "PASS: $message\n";
}
function crj_reject(callable $run, $message) {
 try { $run(); } catch (Throwable $e) { crj_test(true, $message); return; }
 crj_test(false, $message);
}
function crj_test_sign(array $fields) {
 unset($fields['pp_SecureHash']); $signed=[];
 foreach ($fields as $k=>$v) if (preg_match('/^pp_[A-Za-z0-9_]+$/D',$k) && strcasecmp($k,'pp_SecureHash')!==0 && trim((string)$v)!=='') $signed[$k]=(string)$v;
 ksort($signed,SORT_STRING);
 $fields['pp_SecureHash']=strtoupper(hash_hmac('sha256','test-integrity&'.implode('&',$signed),'test-integrity'));
 return $fields;
}
function crj_test_order() {
 $o=wc_create_order(); $o->set_currency('PKR'); $o->set_payment_method('crj_card'); $o->set_total('12.34');
 $ref='CR'.$o->get_id().'X'.strtoupper(bin2hex(random_bytes(5)));
 foreach (['_crj_environment'=>'sandbox','_crj_ref'=>$ref,'_crj_amount'=>'1234','_crj_merchant'=>'test-merchant'] as $k=>$v) $o->update_meta_data($k,$v);
 $a=['request_ref'=>$ref,'provider_ref'=>'','type'=>'unknown','status'=>'INITIATED','created'=>current_time('mysql')];
 $o->update_meta_data('_crj_attempts',[$a]); $o->save(); crj_test(crj_ledger_store($o,$a),'durable attempt stored');
 return [$o,crj_test_sign(['pp_TxnRefNo'=>$ref,'pp_BillReference'=>'ORDER-'.$o->get_id(),'pp_Amount'=>'1234','pp_MerchantID'=>'test-merchant','pp_TxnCurrency'=>'PKR','pp_ResponseCode'=>'000'])];
}
update_option('woocommerce_crj_card_settings',['enabled'=>'yes','environment'=>'sandbox','merchant_id'=>'test-merchant','merchant_password'=>'test-password','integrity_salt'=>'test-integrity']);
update_option('woocommerce_currency','PKR'); $_SERVER['HTTPS']='on';
if (!WC()->session) WC()->initialize_session();
crj_test(crj_minor_amount('12.34')==='1234','decimal to minor units');
foreach (['0','-1','1e2','1.001','99999999999999'] as $v) crj_reject(fn()=>crj_minor_amount($v),'invalid amount rejected: '.$v);
crj_test(crj_parse_callback('pp_Amount=1234&pp_ResponseCode=000','application/x-www-form-urlencoded')['pp_ResponseCode']==='000','form preserves leading zeros');
crj_test(crj_parse_callback('{"pp_Amount":"1234"}','application/json')['pp_Amount']==='1234','JSON accepted');
foreach ([['a=1&a=2','application/x-www-form-urlencoded'],['pp_Amount=1&pp_amount=2','application/x-www-form-urlencoded'],['PP_Amount=1','application/x-www-form-urlencoded'],['{"a":1,"a":2}','application/json'],['{"a":{"b":1}}','application/json'],['[]','application/json'],['a=1','text/plain'],[str_repeat('a',32769),'application/json'],['a[]=1','application/x-www-form-urlencoded']] as [$raw,$mime]) crj_reject(fn()=>crj_parse_callback($raw,$mime),'ambiguous/malformed callback rejected');
$encrypted=crj_protect_secret('private-secret');
crj_test($encrypted!=='private-secret' && crj_unprotect_secret($encrypted)==='private-secret','authenticated encryption roundtrip');
$raw=base64_decode(substr($encrypted,8)); $raw[20]=chr(ord($raw[20])^1);
crj_test(crj_unprotect_secret('crjenc1:'.base64_encode($raw))==='','tampered ciphertext fails closed');
global $wpdb;
$stored=$wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='woocommerce_crj_card_settings'");
crj_test(!str_contains($stored,'test-password') && !str_contains($stored,'test-integrity'),'credentials encrypted in database');
$details=crj_signed_provider_details(['pp_AuthCode'=>'123','PP_ResponseMessage'=>'unsigned','pp_Password'=>'secret']);
crj_test(($details['authorization_code']??'')==='123' && !isset($details['response_message']) && !isset($details['pp_Password']),'detail allowlist excludes unsigned aliases and secrets');
$details=crj_signed_provider_details(['pp_AuthCode'=>''],['authorization_code'=>'old']);
crj_test($details['authorization_code']==='old','empty details preserve previous verified value');
[$o,$payload]=crj_test_order();
$bad=$payload; $bad['pp_Amount']='1'; crj_reject(fn()=>CRJ_Orchestrator_Gateway::process_callback_data($bad),'forged signature rejected');
foreach (['pp_Amount'=>'1','pp_MerchantID'=>'other','pp_TxnCurrency'=>'USD','pp_TxnRefNo'=>'ARBITRARY123456','pp_BillReference'=>'ORDER-99999999'] as $k=>$v) {
 $bad=$payload; $bad[$k]=$v; $bad=crj_test_sign($bad); crj_reject(fn()=>CRJ_Orchestrator_Gateway::process_callback_data($bad),'signed mismatch rejected: '.$k);
}
$bad=$payload;$bad['PP_AuthCode']='unsigned';crj_reject(fn()=>CRJ_Orchestrator_Gateway::process_callback_data($bad),'unsigned provider detail alias rejected before settlement');
crj_reject(fn()=>CRJ_Orchestrator_Gateway::handle_return_request('','application/json','GET'),'return endpoint requires POST');
crj_test(!wc_get_order($o->get_id())->is_paid(),'rejected callbacks leave order unpaid');
$r=CRJ_Orchestrator_Gateway::process_callback_data($payload);
crj_test($r['report']['result']==='payment_completed' && wc_get_order($o->get_id())->is_paid(),'verified callback completes real WooCommerce order');
$r=CRJ_Orchestrator_Gateway::process_callback_data($payload);
crj_test($r['report']['result']==='duplicate_ignored','exact replay is idempotent');
$noise=$payload;$noise['unsigned_noise']='attacker-controlled';$r=CRJ_Orchestrator_Gateway::process_callback_data($noise);
crj_test($r['report']['result']==='duplicate_ignored','unsigned noise cannot bypass replay fingerprint');
$noise=$payload;$noise['pp_SecureHash']=strtolower($noise['pp_SecureHash']);$r=CRJ_Orchestrator_Gateway::process_callback_data($noise);
crj_test($r['report']['result']==='duplicate_ignored','hash casing cannot bypass replay fingerprint');
$bad=$payload;$bad['pp_ResponseCode']='999';$bad=crj_test_sign($bad);
crj_reject(fn()=>CRJ_Orchestrator_Gateway::process_callback_data($bad),'late failure cannot downgrade paid order');
$paid=wc_get_order($o->get_id());$attempts=$paid->get_meta('_crj_attempts');$ref='CR'.$paid->get_id().'X'.strtoupper(bin2hex(random_bytes(5)));
$a=['request_ref'=>$ref,'provider_ref'=>'','status'=>'INITIATED','type'=>'unknown'];$attempts[]=$a;$paid->update_meta_data('_crj_attempts',$attempts);$paid->save();crj_ledger_store($paid,$a);
$bad=$payload;$bad['pp_TxnRefNo']=$ref;$bad=crj_test_sign($bad);
crj_reject(fn()=>CRJ_Orchestrator_Gateway::process_callback_data($bad),'second attempt cannot be recorded as completed on paid order');
crj_test($wpdb->get_var($wpdb->prepare('SELECT txn_status FROM %i WHERE request_ref=%s',crj_ledger_table(),$ref))==='INITIATED','second attempt ledger remains unsettled');
crj_test(!crj_can_view_return($paid),'unrelated browser cannot view private order');
$paid->set_customer_id(1);$paid->save();wp_set_current_user(1);
crj_test(crj_can_view_return($paid),'owning account may view order');
$url=crj_return_continuation_url($paid);parse_str(wp_parse_url($url,PHP_URL_QUERY),$q);
crj_test(crj_authorized_return_order($q)?->get_id()===$paid->get_id(),'signed continuation allows owner');
wp_set_current_user(0);crj_test(crj_authorized_return_order($q)===null,'continuation alone does not authorize disclosure');
$q['crj_return_signature']=str_repeat('0',64);crj_test(crj_authorized_return_order($q)===null,'tampered continuation rejected');
foreach (['124','999'] as $code) { [$unpaid,$p]=crj_test_order();$p['pp_ResponseCode']=$code;$p=crj_test_sign($p);CRJ_Orchestrator_Gateway::process_callback_data($p);crj_test(!wc_get_order($unpaid->get_id())->is_paid(),'non-success code does not settle: '.$code); }
crj_test(crj_payment_awaits_confirmation('999') && !crj_payment_awaits_confirmation('156'),'ambiguous code 999 and confirmed decline 156 are distinguished');
crj_test(crj_payment_awaits_confirmation('999', "A confirmer sends the short message 'N' to cancel a transaction."),'cancellation instructions do not settle or release an unconfirmed payment');
crj_test(crj_provider_transaction_date('20261120233701') > crj_provider_transaction_date('20261120233659'),'transaction timestamps sort chronologically');
crj_test(crj_provider_transaction_date('20260230000000') === null,'invalid provider date does not sort as a later valid date');
[$phone,$response]=crj_test_order();
$response['pp_ResponseCode']='999';
CRJ_Orchestrator_Gateway::process_callback_data(crj_test_sign($response));
$phone=wc_get_order($phone->get_id());
crj_test(crj_order_awaits_confirmation($phone) && !$phone->needs_payment(),'unconfirmed wallet return waits without requesting another payment');
$response['pp_ResponseCode']='000';
CRJ_Orchestrator_Gateway::process_callback_data(crj_test_sign($response));
$phone=wc_get_order($phone->get_id());
crj_test(!crj_order_awaits_confirmation($phone) && $phone->is_paid(),'verified confirmation ends waiting and allows receipt');
[$held,$pending]=crj_test_order();
$pending['pp_ResponseCode']='157';
CRJ_Orchestrator_Gateway::process_callback_data(crj_test_sign($pending));
$held=wc_get_order($held->get_id());
crj_test($held->has_status('on-hold') && !$held->needs_payment(),'verified pending return prevents repeat payment');
$pending['pp_ResponseCode']='xxxx';
$pending['pp_TxnRefNo'].='a1';
CRJ_Orchestrator_Gateway::process_callback_data(crj_test_sign($pending));
$held=wc_get_order($held->get_id());
crj_test($held->is_paid() && $held->get_transaction_id()===$pending['pp_TxnRefNo'],'signed completion can reconcile an unsettled attempt with a provider suffix');
[$declined,$response]=crj_test_order();
$response['pp_ResponseCode']='156';
$original_ref=$response['pp_TxnRefNo'];
$response['pp_TxnRefNo'].='a1';
CRJ_Orchestrator_Gateway::process_callback_data(crj_test_sign($response));
$declined=wc_get_order($declined->get_id());
crj_test($declined->has_status('failed') && crj_checkout_return_url($declined)===$declined->get_checkout_payment_url(),'verified wallet decline opens the same order payment form');
crj_test(crj_attempt_return_code($declined,$original_ref)==='156' && crj_attempt_return_code($declined,'other-reference')==='','history links signed response to the exact attempt only');
crj_test(crj_payment_awaits_confirmation('405') && !crj_payment_awaits_confirmation('405','Insufficient funds'),'card retry requires a definitive decline reason');
[$card,$card_response]=crj_test_order();
$card_response['pp_ResponseCode']='405';
$card_response['pp_TxnType']='MPAY';
CRJ_Orchestrator_Gateway::process_callback_data(crj_test_sign($card_response));
$card_response['pp_ResponseCode']='000';
unset($card_response['pp_TxnType']);
CRJ_Orchestrator_Gateway::process_callback_data(crj_test_sign($card_response));
$card=wc_get_order($card->get_id());
crj_test($card->is_paid() && $card->get_meta('_crj_provider_txn_type')==='MPAY','verified card type survives a later confirmation that omits it');
[$changed,$p]=crj_test_order();$changed->set_total('99');$changed->save();crj_reject(fn()=>CRJ_Orchestrator_Gateway::process_callback_data($p),'changed current total cannot be settled');
[$changedCurrency,$p]=crj_test_order();$changedCurrency->set_currency('USD');$changedCurrency->save();
crj_reject(fn()=>CRJ_Orchestrator_Gateway::process_callback_data($p),'changed current order currency cannot be settled');
$gateway=new CRJ_Orchestrator_Gateway();
$literal='Pa%41ss<literal>\\"quoted';
foreach(['merchant_password','live_merchant_password'] as $field) crj_test($gateway->get_field_value($field,$gateway->form_fields[$field],['woocommerce_crj_card_'.$field=>wp_slash($literal)])===$literal,'merchant password preserves literal characters: '.$field);
crj_test($gateway->validate_merchant_password_field('merchant_password','')==='test-password','empty merchant password preserves stored credential');
crj_test($gateway->validate_merchant_password_field('merchant_password',null)==='test-password','omitted merchant password preserves stored credential');
crj_reject(fn()=>$gateway->validate_merchant_password_field('merchant_password',[]),'non-string merchant password rejected');
$valid=new ReflectionMethod($gateway,'valid_link');$valid->setAccessible(true);
crj_test($valid->invoke(null,'https://onlinepayments.jazzcash.com.pk/pay/token'),'allowlisted provider link accepted');
foreach (['http://onlinepayments.jazzcash.com.pk/pay/x','https://evil.example/pay/x','https://onlinepayments.jazzcash.com.pk@evil.example/pay/x','https://onlinepayments.jazzcash.com.pk:443/pay/x','https://onlinepayments.jazzcash.com.pk/%252e%252e/private','https://onlinepayments.jazzcash.com.pk/pay/x#secret'] as $link) crj_test(!$valid->invoke(null,$link),'unsafe hosted link rejected');
// Never contact provider in tests. Simulate its documented response shape.
$mock=static function($pre,$args,$url) { if (!str_contains($url,'payment-orchestrator')) return $pre; crj_test($args['sslverify']===true && $args['redirection']===0,'provider transport keeps TLS verification and refuses redirects');return ['headers'=>[],'body'=>wp_json_encode(['Success'=>true,'Data'=>['Link'=>'https://onlinepayments.jazzcash.com.pk/pay/test']]),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]]; };
add_filter('pre_http_request',$mock,10,3);
$checkout=wc_create_order();$checkout->set_payment_method('crj_card');$checkout->set_currency('PKR');$checkout->set_total('12.34');$checkout->save();
crj_test($gateway->process_payment($checkout->get_id())['result']==='success','mocked provider creates durable payment attempt');
remove_filter('pre_http_request',$mock,10);
$settings=get_option('woocommerce_crj_card_settings');$settings['environment']='live';update_option('woocommerce_crj_card_settings',$settings);
crj_test(!(new CRJ_Orchestrator_Gateway())->is_available(),'unverified live mode stays disabled');
// A provider-history response is authoritative only for the pending order's exact verified attempt.
[$wallet,$wallet_response]=crj_test_order();
$wallet_attempts=$wallet->get_meta('_crj_attempts');
$wallet_ref=$wallet_response['pp_TxnRefNo'];
$wallet_attempts[0]=array_merge($wallet_attempts[0],['environment'=>'live','merchant_id'=>'test-live-merchant','provider_ref'=>$wallet_ref,'event_hash'=>'trusted-fixture','type'=>'wallet']);
$wallet->update_meta_data('_crj_attempts',$wallet_attempts);
$wallet_row=['pp_TxnRefNo'=>$wallet_ref.'a1','pp_BillReference'=>'ORDER-'.$wallet->get_id(),'pp_TxnCurrency'=>'PKR','pp_Amount'=>'1234','pp_TxnType'=>'MWALLET','pp_Status'=>'xxxx','pp_ResponseCode'=>'000'];
$hosted_parent=array_merge($wallet_row,['pp_TxnRefNo'=>$wallet_ref,'pp_Status'=>'129','pp_ResponseCode'=>'129']);
crj_test(crj_phone_history_result($wallet,[$hosted_parent],'test-live-merchant')===[],'dropped hosted parent alone cannot release a wallet order');
crj_test(crj_phone_history_result($wallet,[$hosted_parent,$wallet_row],'test-live-merchant')['pp_TxnRefNo']===$wallet_ref.'a1','matching paid wallet child is selected');
crj_test(crj_phone_history_result($wallet,[$wallet_row],'wrong-merchant')===[],'wallet history requires the original merchant');
crj_test(crj_phone_history_result($wallet,[array_merge($wallet_row,['pp_Amount'=>'10'])],'test-live-merchant')===[],'wallet history requires the original amount');
$refund_row=array_merge($wallet_row,['pp_TxnRefNo'=>'CRTEST'.strtoupper(bin2hex(random_bytes(8))),'pp_BillReference'=>'EXTERNAL-TEST']);
$refund_snapshot=crj_direct_refund_snapshot($refund_row);
$refund_key=crj_direct_refund_key('test-live-merchant',$refund_snapshot['ref']);
$refund_settings=['live_merchant_id'=>'test-live-merchant','live_merchant_password'=>'test-live-password','live_integrity_salt'=>'test-live-salt'];
$refund_calls=0;
$mock_refund=static function($pre,$args,$url) use($refund_row,&$refund_calls) {
 if (str_contains($url,'/shopify/inquiry')) {
  return ['headers'=>[],'body'=>wp_json_encode(['pp_ResponseCode'=>'000','pp_Items'=>[$refund_row]]),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
 }
 if (str_contains($url,'/shopify/refund')) {
  $body=json_decode($args['body'],true);
  crj_test($args['sslverify'] && $body['pp_TxnRefNo']===$refund_row['pp_TxnRefNo'] && $body['pp_Amount']==='100','refund dispatch matches provider reference and paisa amount over TLS');
  ++$refund_calls;
  return ['headers'=>[],'body'=>wp_json_encode(['pp_ResponseCode'=>'000','pp_ResponseMessage'=>'Synthetic refund confirmed']),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
 }
 return $pre;
};
$provider_reject=static function($pre,$args,$url) {
 if (!str_contains($url,'/shopify/refund')) return $pre;
 return ['headers'=>[],'body'=>wp_json_encode(['pp_ResponseCode'=>'999','pp_ResponseMessage'=>'Merchant MPIN not found.']),'response'=>['code'=>200,'message'=>'OK'],'cookies'=>[]];
};
add_filter('pre_http_request',$mock_refund,10,3);
add_filter('pre_http_request',$provider_reject,11,3);
$refund_details=[];
crj_test(crj_send_direct_refund($refund_key,$refund_settings,$refund_snapshot,1,'1.00',$refund_details)==='merchant_mpin'
 && get_option($refund_key,'')==='' && get_option($refund_key.'_last_result')['code']==='999','definitive JazzCash rejection stays visible and permits a retry');
remove_filter('pre_http_request',$provider_reject,11);
$refund_calls=0;
$legacy_failure=['code'=>'999','message'=>'Sorry! Your transaction has failed. Please try again later.'];
crj_test(crj_refund_was_rejected(['pp_ResponseCode'=>'999','pp_ResponseMessage'=>$legacy_failure['message']],'999')
 && !crj_refund_was_rejected(['pp_ResponseCode'=>'999','pp_ResponseMessage'=>'Unknown outcome'],'999'),'refund 999 retries only the explicit JazzCash refusal');
update_option($refund_key,'dispatching',false);
update_option($refund_key.'_last_result',$legacy_failure,false);

crj_test(crj_send_direct_refund($refund_key,$refund_settings,$refund_snapshot,1,'1.00')==='direct_success','legacy refused refund retries after a fresh JazzCash lookup and records provider success');
crj_test(crj_send_direct_refund($refund_key,$refund_settings,$refund_snapshot,1,'1.00')==='direct_duplicate' && $refund_calls===1,'repeat refund does not dispatch again');
remove_filter('pre_http_request',$mock_refund,10);
delete_option($refund_key);
$record=['id'=>1,'order_id'=>0,'provider_ref'=>'','request_ref'=>'=CMD()','provider_txn_type'=>'','instrument'=>'unknown','response_code'=>'000','provider_details'=>wp_json_encode(['response_message'=>"\t=CMD()"]),'amount'=>'1'];
$cells=array_combine(array_keys(crj_transaction_csv_columns()),crj_transaction_csv_cells($record));
crj_test(str_starts_with($cells['request_ref'],"'") && str_starts_with($cells['response_message'],"'"),'CSV formula injection neutralized');
crj_test($cells['response_code']==='="000"','only fixed numeric response-code expression retained');
// Keyset pagination, including search OR grouping, cannot repeat rows across pages.
for($i=0;$i<505;$i++) $wpdb->insert(crj_ledger_table(),['order_id'=>0,'request_ref'=>'PAGETEST'.wp_generate_password(24,false,false),'amount'=>'1','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
$page=crj_ledger_export_page('PAGETEST',PHP_INT_MAX);$next=crj_ledger_export_page('PAGETEST',(int)end($page)['id']);
crj_test(count($page)===500 && count($next)===5 && !array_intersect(array_column($page,'id'),array_column($next,'id')),'filtered export pagination has no duplicate/missing rows');
$wpdb->query("DELETE FROM {$wpdb->prefix}crj_transactions WHERE request_ref LIKE 'PAGETEST%'");
$lock='crj_'.substr(hash('sha256',$wpdb->prefix.'|integration-lock'),0,60);
crj_security_lock('integration-lock');
$other=new mysqli(DB_HOST,DB_USER,DB_PASSWORD,DB_NAME);
$res=$other->query("SELECT GET_LOCK('".$other->real_escape_string($lock)."',0) AS acquired")->fetch_assoc();
crj_test((string)$res['acquired']==='0','independent database connection cannot acquire held payment lock');
$other->close();
crj_security_lock('test-lock-loss');
$lost='crj_'.substr(hash('sha256',$wpdb->prefix.'|test-lock-loss'),0,60);
$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lost));
crj_reject(fn()=>crj_security_lock('test-lock-loss'),'lost/reconnected database lock fails closed');
echo 'RESULT: '.$GLOBALS['crj_passed']." passed; 0 failed\n";
