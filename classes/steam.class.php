<?php
require_once 'simple_html_dom.php';
/**
 * Steam Trade PHP Class
 * Based on node.js version by Alex7Kom https://github.com/Alex7Kom/node-steam-tradeoffers
 * Created By halipso https://github.com/halipso/php-steam-tradeoffers
 * Reworked By Abolix https://github.com/abolix/php-steam-tradeoffers
 * it's reworked because there is no support in halipso repository and project is outdated
 * 
 */
class SteamTrade
{
	private $webCookies = '';
	private $sessionId = '';
	private $apiKey = '';

	function __construct()
	{
		# code...
	}

	public function setup($sessionId, $webCookies)
	{
		$this->webCookies = $webCookies;
		$this->sessionId = $sessionId;
		$this->getApiKey();
	}

	private function SendRequest($URL,$PostParams = [],$Headers = []) {
	$HeadersArray = ["Cookie:" . $this->webCookies];
	foreach($Headers as $HeaderKey => $HeaderValue) {
		array_push($HeadersArray,$HeaderKey . ': ' . $HeaderValue);
	}
	$ch = curl_init();
	$timeout = 5;
	curl_setopt($ch,CURLOPT_URL,$URL);
	curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
	curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	if($PostParams != []) {
		curl_setopt($ch,CURLOPT_POST, 1); 
		curl_setopt($ch, CURLOPT_POSTFIELDS, $PostParams);
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $HeadersArray);
	$Body = curl_exec($ch);
	$Info = curl_getinfo($ch);
	$Data = ['body' => $Body,'info' => $Info ];
	return $Data;
	}

	public function getApiKey()
	{
		if ($this->apiKey) {
			return;
		}
		$Response = $this->SendRequest('https://steamcommunity.com/dev/apikey');
		if ($Response['info']['http_code'] != 200) {
			die("Error getting apiKey. Code:" . $Response['info']['http_code']);
		}
		$parse = str_get_html($Response['body']);
		if ($parse->find('#mainContents', 0)->find('h2', 0)->plaintext == 'Access Denied') {
			die('Error: Access Denied!');
		}

		if ($parse->find('#bodyContents_ex', 0)->find('h2', 0)->plaintext == 'Your Steam Web API Key') {
			$key = explode(' ', $parse->find('#bodyContents_ex', 0)->find('p', 0)->plaintext)[1];
			$this->apiKey = $key;
			return;
		}

		$PostParams = ['domain' => SITE_URL, 'agreeToTerms' => 'agreed', 'sessionid' => $this->sessionId, 'submit' => 'Register'];
		$Response = $this->SendRequest('https://steamcommunity.com/dev/registerkey',$PostParams);
		$this->getApiKey();
	}

	public function loadMyInventory($options)
	{
		$query = [];

		if ($options['language']) {
			$query['l'] = $options['language'];
		}

		if ($options['tradableOnly']) {
			$query['trading'] = 1;
		}

		$uri = 'https://steamcommunity.com/my/inventory/json/' . $options['appId'] . '/' . $options['contextId'] . '/?' . http_build_query($query);
		return $this->_loadInventory([], $uri, ['json' => TRUE], $options['contextId'], null);
	}

	private function _loadInventory($inventory, $uri, $options, $contextid, $start = null)
	{
		$options['uri'] = $uri;

		if ($start) {
			$options['uri'] = $options['uri'] + '&' + http_build_query(['start' => 'start']);
		}


		$headers = [];
		if (isset($options['headers'])) {
			if ($options['headers']) {
				foreach ($options['headers'] as $key => $value) {
					$headers[$key] = $value;
				}
			}
		}

		$Response = $this->SendRequest($uri);
		if ($Response['info']['http_code'] != 200) {
			die("Error loading inventory. Code:" . $Response['info']['http_code']);
		}

		$Response = json_decode($Response['body']);
		if (!$Response || !$Response->rgInventory || !$Response->rgDescriptions) {
			die('Invalid Response');
		}

		$inventory = array_merge($inventory, array_merge($this->mergeWithDescriptions($Response->rgInventory, $Response->rgDescriptions, $contextid), $this->mergeWithDescriptions($Response->rgCurrency, $Response->rgDescriptions, $contextid)));
		if ($Response->more) {
			return $this->_loadInventory($inventory, $uri, $options, $contextid, $Response->more_start);
		} else {
			return $inventory;
		}
	}

	private function mergeWithDescriptions($items, $descriptions, $contextid)
	{
		$descriptions = (array) $descriptions;
		$n_items = [];
		foreach ($items as $key => $item) {
			$description = (array) $descriptions[$item->classid . '_' . ($item->instanceid ? $item->instanceid : 0)];
			$item = (array) $item;
			foreach ($description as $k => $v) {
				$item[$k] = $description[$k];
			}
			// add contextid because Steam is retarded
			$item['contextid'] = $contextid;
			$n_items[] = $item;
		}
		return $n_items;
	}

	private function toAccountID($id)
	{
		if (preg_match('/^STEAM_/', $id)) {
			$split = explode(':', $id);
			return $split[2] * 2 + $split[1];
		} elseif (preg_match('/^765/', $id) && strlen($id) > 15) {
			return bcsub($id, '76561197960265728');
		} else {
			return $id;
		}
	}

	private function toSteamID($id)
	{
		if (preg_match('/^STEAM_/', $id)) {
			$parts = explode(':', $id);
			return bcadd(bcadd(bcmul($parts[2], '2'), '76561197960265728'), $parts[1]);
		} elseif (is_numeric($id) && strlen($id) < 16) {
			return bcadd($id, '76561197960265728');
		} else {
			return $id;
		}
	}

	public function loadPartnerInventory($options) # TODO : Needs work
	{

		$form = [
			'sessionid' => $this->sessionId,
			'partner' => $options['partnerSteamId'],
			'appid' => $options['appId'],
			'contextid' => $options['contextId']
		];

		if ($options['language']) {
			$form['l'] = $options['language'];
		}

		$offer = 'new';
		if ($options['tradeOfferId']) {
			$offer = $options->tradeOfferId;
		}

		$uri = 'https://steamcommunity.com/tradeoffer/' . $offer . '/partnerinventory/?' . http_build_query($form);

		return $this->_loadInventory(
			[],
			$uri,
			[
				'json' => TRUE,
				'headers' => [
					'referer' => 'https://steamcommunity.com/tradeoffer/' . $offer . '/?partner=' . $this->toAccountID($options['partnerSteamId'])
				]
			],
			$options['contextId'],
			null
		);
	}

	public function makeOffer($options)
	{

		$tradeoffer = [
			'newversion' => TRUE,
			'version' => 2,
			'me' => ['assets' => [$options['itemsFromMe']], 'currency' => [], 'ready' => FALSE],
			'them' => ['assets' => [$options['itemsFromThem']], 'currency' => [], 'ready' => FALSE]
		];

		$formFields = [
			'serverid' => 1,
			'sessionid' => $this->sessionId,
			'partner' => $options['partnerSteamId'] ? $options['partnerSteamId'] : $this->toSteamID($options['partnerAccountId']),
			'tradeoffermessage' => $options['message'] ? $options['message'] : '',
			'json_tradeoffer' => json_encode($tradeoffer),
			'captcha' => ''
		];

		$query = [
			'partner' => $options['partnerAccountId'] ? $options['partnerAccountId'] : $this->toAccountID($options['partnerSteamId'])
		];

		if ($options['accessToken']) {
			$formFields['trade_offer_create_params'] = json_encode(['trade_offer_access_token' => $options['accessToken']]);
			$query['token'] = $options['accessToken'];
		}

		$referer = '';
		if ($options['counteredTradeOffer']) {
			$formFields['tradeofferid_countered'] = $options['counteredTradeOffer'];
			$referer = 'https://steamcommunity.com/tradeoffer/' . $options['counteredTradeOffer'] . '/';
		} else {
			$referer = 'https://steamcommunity.com/tradeoffer/new/?' . http_build_query($query);
		}

		$headers['referer'] = $referer; # TODO : Add Header
		$Response = $this->SendRequest('hhttps://steamcommunity.com/tradeoffer/new/send',$formFields);

		if ($Response['info']['http_code'] != 200) {
			echo JsonResult(0, 'Error In Sending Trade Offer , Error Code :' . $Response['info']['http_code']); // TODO : Change
			exit;
		}

		$body = $Response['body'];
		if ($body && isset($body->strError)) {
			echo JsonResult(0, 'Error making offer: ' . $body->strError);
			exit;
		}

		return $body;
	}

	public function getOffers($options)
	{
		$offers = $this->doAPICall(
			[
				'method' => 'GetTradeOffers/v1',
				'params' => $options
			]
		);

		$offers = json_decode(mb_convert_encoding($offers, 'UTF-8', 'UTF-8'), 1);

		if ($offers['response']['trade_offers_received']) {
			foreach ($offers['response']['trade_offers_received'] as $key => $value) {
				$offers['response']['trade_offers_received'][$key]['steamid_other'] = $this->toSteamID($value['accountid_other']);
			}
		}

		if ($offers['response']['trade_offers_sent']) {
			foreach ($offers['response']['trade_offers_sent'] as $key => $value) {
				$offers['response']['trade_offers_sent'][$key]['steamid_other'] = $this->toSteamID($value['accountid_other']);
			}
		}

		return $offers;
	}

	public function getOffer($options)
	{
		$offer = $this->doAPICall(
			[
				'method' => 'GetTradeOffer/v1',
				'params' => $options
			]
		);

		$offer = json_decode(mb_convert_encoding($offer, 'UTF-8', 'UTF-8'), 1);

		if (isset($offer['response']['offer'])) {
			$offer['response']['offer']['steamid_other'] = $this->toSteamId($offer['response']['offer']['accountid_other']);
		}

		return $offer;
	}

	private function doAPICall($options)
	{
		$uri = 'https://api.steampowered.com/IEconService/' . $options['method'] . '/?key=' . $this->apiKey . (isset($options['post']) ? '' : ('&' . http_build_query($options['params'])));

		$body = null;
		if (isset($options['post'])) {
			$body = $options['params'];
		}

		if(isset($options['post'])) {
			$Response = $this->SendRequest($uri,['Test' => null]);
		}else {
			$Response = $this->SendRequest($uri);
		}

		if ($Response['info']['http_code'] != 200) {
			die('Error doing API call. Server response code: ' . $Response['info']['http_code']);
		}

		if (!$Response->raw_body) { # TODO : Check This
			die('Error doing API call. Invalid response.');
		}

		return $Response->raw_body;
	}

	public function declineOffer($options)
	{
		return $this->doAPICall(
			[
				'method' => 'DeclineTradeOffer/v1',
				'params' => ['tradeofferid' => $options['tradeOfferId']],
				'post' => 1
			]
		);
	}

	public function cancelOffer($options)
	{
		return $this->doAPICall(
			[
				'method' => 'CancelTradeOffer/v1',
				'params' => ['tradeofferid' => $options['tradeOfferId']],
				'post' => 1
			]
		);
	}

	public function acceptOffer($options)
	{

		if (!$options['tradeOfferId']) {
			die('No options');
		}

		$form = [
			'sessionid' => $this->sessionId,
			'serverid' => 1,
			'tradeofferid' => $options['tradeOfferId']
		];

		$referer = 'https://steamcommunity.com/tradeoffer/' . $options['tradeOfferId'] . '/';

		$headers = [];
		$headers['referer'] = $referer;
		$Response = $this->SendRequest('https://steamcommunity.com/tradeoffer/' . $options['tradeOfferId'] . '/accept',[],$headers);

		if ($Response['info']['http_code'] != 200) {
			die('Error accepting offer. Server response code: ' . $Response['info']['http_code']);
		}

		$body = $Response['body'];

		if ($body && $body->strError) {
			die('Error accepting offer: ' . $body->strError);
		}

		return $body;
	}

	public function getOfferToken()
	{
		$Response = $this->SendRequest('https://steamcommunity.com/my/tradeoffers/privacy');
		if ($Response['info']['http_code'] != 200) {
			die('Error retrieving offer token. Server response code: ' . $Response['info']['http_code']);
		}

		$body = str_get_html($Response['body']);

		if (!$body) {
			die('Error retrieving offer token. Invalid response.');
		}

		$offerUrl = $body->find('#trade_offer_access_url', 0)->value;
		return explode('=', $offerUrl)[2];
	}

	public function getItems($options)
	{
		$Response = $this->SendRequest('https://steamcommunity.com/trade/' . $options['tradeId'] . '/receipt/');

		if ($Response['info']['http_code'] != 200) {
			die('Error get items. Server response code: ' . $Response['info']['http_code']);
		}

		$body = $Response['body'];

		preg_match('/(var oItem;[\s\S]*)<\/script>/', $body, $matches);

		if (!$matches) {
			die('Error get items: no session');
		}

		$temp = str_replace(["\r", "\n"], "", $matches[1]);

		$items = [];

		preg_match_all('/oItem = {(.*?)};/', $temp, $matches);
		foreach ($matches[0] as $key => $value) {
			$value = rtrim(str_replace('oItem = ', '', $value), ';');
			$items[] = json_decode($value, 1);
		}

		return $items;
	}
}
