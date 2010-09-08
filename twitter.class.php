<?php

require_once dirname(__FILE__) . '/OAuth.php';


/**
 * Twitter for PHP - library for sending messages to Twitter and receiving status updates.
 *
 * @author     David Grudl
 * @copyright  Copyright (c) 2008 David Grudl
 * @license    New BSD License
 * @link       http://phpfashion.com/
 * @see        http://apiwiki.twitter.com/Twitter-API-Documentation
 * @version    2.0
 */
class Twitter
{
	/**#@+ Timeline {@link Twitter::load()} */
	const ME = 1;
	const ME_AND_FRIENDS = 2;
	const REPLIES = 3;
	const ALL = 4;
	const RETWEETS = 128; // include retweets?
	/**#@-*/

	/**#@+ Output format {@link Twitter::load()} */
	const XML = 0;
	const JSON = 16;
	const RSS = 32;
	const ATOM = 48;
	/**#@-*/

	/** @var int */
	public static $cacheExpire = 1800; // 30 min

	/** @var string */
	public static $cacheDir;

	/** @var OAuthSignatureMethod */
	private $signatureMethod;

	/** @var OAuthConsumer */
	private $consumer;

	/** @var OAuthConsumer */
	private $token;



	/**
	 * Creates object using consumer and access keys.
	 * @param  string  consumer key
	 * @param  string  app secret
	 * @param  string  optional access token
	 * @param  string  optinal access token secret
	 * @throws TwitterException when CURL extension is not loaded
	 * @throws TwitterAuthException to signalize individual authorization steps
	 */
	public function __construct($consumerKey = NULL, $consumerSecret = NULL, $accessToken = NULL, $accessTokenSecret = NULL)
	{
		if (!extension_loaded('curl')) {
			throw new TwitterException('PHP extension CURL is not loaded.');
		}

		$this->signatureMethod = new OAuthSignatureMethod_HMAC_SHA1();
		$this->consumer = new OAuthConsumer($consumerKey, $consumerSecret);
		$this->token = new OAuthConsumer($accessToken, $accessTokenSecret);
	}



	/**
	 * Tests if user credentials are valid.
	 * @return boolean
	 * @throws TwitterException
	 */
	public function authenticate()
	{
		try {
			$xml = $this->httpRequest('http://twitter.com/account/verify_credentials.xml');
			return !empty($xml->id);

		} catch (TwitterException $e) {
			if ($e->getCode() === 401) {
				return FALSE;
			}
			throw $e;
		}
	}



	/**
	 * Sends message to the Twitter.
	 * @param string   message encoded in UTF-8
	 * @return mixed   ID on success or FALSE on failure
	 * @throws TwitterException
	 */
	public function send($message)
	{
		if (iconv_strlen($message, 'UTF-8') > 140) {
			$message = preg_replace_callback('#https?://\S+[^:);,.!?\s]#', array($this, 'shortenUrl'), $message);
		}

		$xml = $this->httpRequest(
			'https://twitter.com/statuses/update.xml',
			array('status' => $message)
		);
		return $xml->id ? (string) $xml->id : FALSE;
	}



	/**
	 * Returns the most recent statuses.
	 * @param  int    timeline (ME | ME_AND_FRIENDS | REPLIES | ALL) and optional (RETWEETS) or format (XML | JSON | RSS | ATOM)
	 * @param  int    number of statuses to retrieve
	 * @param  int    page of results to retrieve
	 * @return mixed
	 * @throws TwitterException
	 */
	public function load($flags = self::ME, $count = 20, $page = 1)
	{
		static $timelines = array(self::ME => 'user_timeline', self::ME_AND_FRIENDS => 'friends_timeline', self::REPLIES => 'mentions', self::ALL => 'public_timeline');

		if (!is_int($flags)) { // back compatibility
			$flags = $flags ? self::ME_AND_FRIENDS : self::ME;

		} elseif (!isset($timelines[$flags & 0x0F])) {
			throw new InvalidArgumentException;
		}

		return $this->cachedHttpRequest("http://twitter.com/statuses/" . $timelines[$flags & 0x0F] . '.' . self::getFormat($flags), array(
			'count' => $count,
			'page' => $page,
			'include_rts' => $flags & self::RETWEETS ? 1 : 0,
		));
	}



	/**
	 * Returns information of a given user.
	 * @param  string name
	 * @param  int    format (XML | JSON)
	 * @return mixed
	 * @throws TwitterException
	 */
	public function loadUserInfo($user, $flags = self::XML)
	{
		return $this->cachedHttpRequest('http://twitter.com/users/show.' . self::getFormat($flags), array('screen_name' => $user));
	}



	/**
	 * Destroys status.
	 * @param  int    id of status to be destroyed
	 * @return mixed
	 * @throws TwitterException
	 */
	public function destroy($id)
	{
		$xml = $this->httpRequest("http://twitter.com/statuses/destroy/$id.xml", array('id' => $id));
		return $xml->id ? (string) $xml->id : FALSE;
	}



	/**
	 * Returns tweets that match a specified query.
	 * @param  string   query
	 * @param  int      format (JSON | ATOM)
	 * @return mixed
	 * @throws TwitterException
	 */
	public function search($query, $flags = self::JSON)
	{
		return $this->httpRequest(
			'http://search.twitter.com/search.' . self::getFormat($flags),
			array('q' => $query)
		)->results;
	}



	/**
	 * Process HTTP request.
	 * @param  string  URL
	 * @param  string  HTTP method
	 * @param  array   data
	 * @return mixed
	 * @throws TwitterException
	 */
	private function httpRequest($url, $data = NULL, $method = 'POST')
	{
		$request = OAuthRequest::from_consumer_and_token($this->consumer, $this->token, $method, $url, $data);
		$request->sign_request($this->signatureMethod, $this->consumer, $this->token);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_HEADER, FALSE);
		curl_setopt($curl, CURLOPT_TIMEOUT, 20);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array('Expect:'));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE); // no echo, just return result
		curl_setopt($curl, CURLOPT_USERAGENT, 'Twitter for PHP');
		if ($method === 'POST') {
			curl_setopt($curl, CURLOPT_POST, TRUE);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $request->to_postdata());
			curl_setopt($curl, CURLOPT_URL, $request->get_normalized_http_url());
		} else {
			curl_setopt($curl, CURLOPT_URL, $request->to_url());
		}

		$result = curl_exec($curl);
		if (curl_errno($curl)) {
			throw new TwitterException('Server error: ' . curl_error($curl));
		}

		$type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
		if (strpos($type, 'xml')) {
			$payload = @simplexml_load_string($result); // intentionally @

		} elseif (strpos($type, 'json')) {
			$payload = @json_decode($result); // intentionally @
		}

		if (empty($payload)) {
			throw new TwitterException('Invalid server response');
		}

		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if ($code >= 400) {
			throw new TwitterException(isset($payload->error) ? $payload->error : "Server error #$code", $code);
		}

		return $payload;
	}



	/**
	 * Cached HTTP request.
	 * @param  string  URL
	 * @return mixed
	 */
	private function cachedHttpRequest($url, $data)
	{
		if (!self::$cacheDir) {
			return $this->httpRequest($url, $data, 'GET');
		}

		$cacheFile = self::$cacheDir . '/twitter.' . md5($url);
		$cache = @file_get_contents($cacheFile); // intentionally @
		$cache = strncmp($cache, '<', 1) ? @json_decode($cache) : @simplexml_load_string($cache); // intentionally @
		if ($cache && @filemtime($cacheFile) + self::$cacheExpire > time()) { // intentionally @
			return $cache;
		}

		try {
			$payload = $this->httpRequest($url, $data, 'GET');
			file_put_contents($cacheFile, $payload instanceof SimpleXMLElement ? $payload->asXml() : json_encode($payload));
			return $payload;

		} catch (TwitterException $e) {
			if ($cache) {
				return $cache;
			}
			throw $e;
		}
	}



	/**
	 * Shortens URL using http://is.gd API.
	 * @param  array
	 * @return string
	 */
	private function shortenUrl($m)
	{
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, 'http://is.gd/api.php?longurl=' . urlencode($m[0]));
		curl_setopt($curl, CURLOPT_HEADER, FALSE);
		curl_setopt($curl, CURLOPT_TIMEOUT, 20);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		$result = curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		return curl_errno($curl) || $code >= 400 ? $m[0] : $result;
	}



	private static function getFormat($flag)
	{
		static $formats = array(self::XML => 'xml', self::JSON => 'json', self::RSS => 'rss', self::ATOM => 'atom');
		$flag = $flag & 0x30;
		if (isset($formats[$flag])) {
			return $formats[$flag];
		} else {
			throw new InvalidArgumentException('Invalid format');
		}
	}

}



/**
 * An exception generated by Twitter.
 */
class TwitterException extends Exception
{
}