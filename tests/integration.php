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
crj_test(crj_return_destination($q)===$paid->get_checkout_order_received_url(),'signed continuation allows owner');
wp_set_current_user(0);crj_test(crj_return_destination($q)==='','continuation alone does not authorize disclosure');
$q['crj_return_signature']=str_repeat('0',64);crj_test(crj_return_destination($q)==='','tampered continuation rejected');
foreach (['124','999'] as $code) { [$unpaid,$p]=crj_test_order();$p['pp_ResponseCode']=$code;$p=crj_test_sign($p);CRJ_Orchestrator_Gateway::process_callback_data($p);crj_test(!wc_get_order($unpaid->get_id())->is_paid(),'non-success code does not settle: '.$code); }
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
