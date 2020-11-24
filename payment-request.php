<?php

require_once('lib/WooppaySoapClient.php');

$client_secret = "ZHV4EKPLFBgyrUpNVeJadEOHDi8x41YY";
$iv = "abcdefghijklmnopqrstuvwx";
$cipher = "aes-128-gcm";
$ivlen = openssl_cipher_iv_length($cipher = "AES-128-CBC");
$tag = 0;


if (isset($_POST["data"])) {


	/**
	 * Functions to decrypt the payment request from Ecwid
	 * @param $app_secret_key
	 * @param $data
	 * @return mixed
	 */
	function getEcwidPayload($app_secret_key, $data)
	{
		$encryption_key = substr($app_secret_key, 0, 16);
		$json_data = aes_128_decrypt($encryption_key, $data);
		$json_decoded = json_decode($json_data, true);
		return $json_decoded;
	}

	/**
	 * @param $key
	 * @param $data
	 * @return false|string
	 */
	function aes_128_decrypt($key, $data)
	{
		$base64_original = str_replace(array('-', '_'), array('+', '/'), $data);
		$decoded = base64_decode($base64_original);
		$iv = substr($decoded, 0, 16);
		$payload = substr($decoded, 16);
		$json = openssl_decrypt($payload, "aes-128-cbc", $key, OPENSSL_RAW_DATA, $iv);
		return $json;
	}

	$ecwid_payload = $_POST['data'];
	$order = getEcwidPayload($client_secret, $ecwid_payload);

	$ciphertext_raw = openssl_encrypt($order['token'], $cipher, $client_secret, $options = 0, $iv, $tag);
	$callbackPayload = base64_encode($ciphertext_raw);
	$callbackUrl = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]" . "?storeId=" . $order['storeId'] . "&orderNumber=" . $order['cart']['order']['orderNumber'] . "&callbackPayload=" . $callbackPayload;

	$paymentParam = array(
		"url_api" => $order['merchantAppSettings']['url_api'],
		"login_api" => $order['merchantAppSettings']['login_api'],
		"password_api" => $order['merchantAppSettings']['password_api'],
		"order_prefix" => $order['merchantAppSettings']['order_prefix'],
		"service_name" => $order['merchantAppSettings']['service_name']
	);

	try {
		$client = new WooppaySoapClient($paymentParam['url_api']);
	} catch (WooppaySoapException $e) {
		echo $e->getMessage();
	}
	$login_request = new CoreLoginRequest();
	$login_request->username = $paymentParam['login_api'];
	$login_request->password = $paymentParam['password_api'];
	try {
		if ($client->login($login_request)) {
			$invoice_request = new CashCreateInvoiceByServiceRequest();
			$invoice_request->referenceId = $paymentParam['order_prefix'] . $order['cart']['order']['orderNumber'];
			$invoice_request->serviceName = $paymentParam['service_name'];
			$invoice_request->backUrl = $order["returnUrl"];
			$invoice_request->requestUrl = $callbackUrl . "&status=PAID";
			$invoice_request->addInfo = 'Оплата заказа #' . $order['cart']['order']['orderNumber'];
			$invoice_request->amount = $order["cart"]["order"]["total"];
			$invoice_request->userEmail = $order["cart"]["order"]["email"];
			$invoice_request->userPhone = $order["cart"]["order"]["billingPerson"]["phone"];
			$invoice_request->deathDate = '';
			$invoice_request->description = '';
			$invoice_request->serviceType = 0;
			$invoice_data = $client->createInvoice($invoice_request);
		}
	} catch (Exception $exception) {
		echo $exception->getMessage();
	}

	$operationUrl = $invoice_data->response->operationUrl;

	echo "<script>window.location = '$operationUrl'</script>";

}
if (isset($_GET["callbackPayload"]) && isset($_GET["status"])) {

	// Set variables
	$client_id = "test-rick-payment-template";
	$c = base64_decode($_GET['callbackPayload']);
	$token = openssl_decrypt($c, $cipher, $client_secret, $options=0, $iv,$tag);
	$storeId = $_GET['storeId'];
	$orderNumber = $_GET['orderNumber'];
	$status = $_GET['status'];
	$returnUrl = "https://app.ecwid.com/custompaymentapps/$storeId?orderId=$orderNumber&clientId=$client_id";

	// Prepare request body for updating the order
	$json = json_encode(array(
		"paymentStatus" => $status,
		"externalTransactionId" => "transaction_".$orderNumber
	));

	// URL used to update the order via Ecwid REST API
	$url = "https://app.ecwid.com/api/v3/$storeId/orders/transaction_$orderNumber?token=$token";

	// Send request to update order
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($json)));
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
	curl_setopt($ch, CURLOPT_POSTFIELDS,$json);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$response = curl_exec($ch);
	curl_close($ch);

	// return customer back to storefront
	echo "<script>window.location = '$returnUrl'</script>";

} else {

	header('HTTP/1.0 403 Forbidden');
	echo 'Access forbidden!';

}


?>