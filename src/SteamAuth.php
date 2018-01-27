<?php

namespace kanalumaddela\SteamAuth;

use Exception;

class SteamAuth implements SteamAuthInterface
{
	/**
	 * Steam OpenID URL
	 *
	 * @var string
	 */
	const OPENID_STEAM = 'https://steamcommunity.com/openid/login';

	/**
	 * OpenID Specs
	 *
	 * @var	string
	 */
	const OPENID_SPECS = 'http://specs.openid.net/auth/2.0';

	/**
	 * User's SteamID (64-bit)
	 *
	 * @var int
	 */
	private $steamid;

	/**
	 * Build Steam Login URL
	 *
	 * @param string $return
	 * @throws Exception if $return is not valid
	 * @return string
	 */
	public static function loginUrl($return = null)
	{
		if (!is_null($return) && !self::isUrl($return)) {
			throw new Exception('The return URL is not valid');
		}

		$host = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://').$_SERVER['SERVER_NAME'];

		$return = $return ?? ($host.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

		$params = [
			'openid.ns'         => self::OPENID_SPECS,
			'openid.mode'       => 'checkid_setup',
			'openid.return_to'  => $return,
			'openid.realm'      => $host,
			'openid.identity'   => self::OPENID_SPECS.'/identifier_select',
			'openid.claimed_id' => self::OPENID_SPECS.'/identifier_select',
		];

		return self::OPENID_STEAM.'?'.http_build_query($params);
	}

	/**
	 * Checks if request post Steam Login is valid
	 *
	 * @return boolean
	 */
	public static function validRequest()
	{
		return (isset($_GET['openid_assoc_handle']) && isset($_GET['openid_claimed_id']) && isset($_GET['openid_sig']) && isset($_GET['openid_signed']));
	}

	/**
	 * Validate Steam Login
	 *
	 * @param int $timeout
	 * @return int|null
	 */
	public function validate($timeout = 15)
	{
		try {
			$params = [
				'openid.assoc_handle' => $_GET['openid_assoc_handle'],
				'openid.signed'       => $_GET['openid_signed'],
				'openid.sig'          => $_GET['openid_sig'],
				'openid.ns'           => self::OPENID_SPECS,
			];

			$signed = explode(',', $_GET['openid_signed']);

			foreach ($signed as $item) {
				$value = $_GET['openid_' . str_replace('.', '_', $item)];
				$params['openid.' . $item] = get_magic_quotes_gpc() ? stripslashes($value) : $value;
			}

			$params['openid.mode'] = 'check_authentication';

			$data =  http_build_query($params);

			$context = stream_context_create(array(
				'http' => array(
					'method' => 'POST',
					'header' =>
						"Accept-language: en\r\n".
						"Content-type: application/x-www-form-urlencoded\r\n" .
						"Content-Length: " . strlen($data) . "\r\n",
					'content' => $data,
					'timeout' => $timeout
				),
			));

			$result = file_get_contents(self::OPENID_STEAM, false, $context);

			preg_match("#^http://steamcommunity.com/openid/id/([0-9]{17,25})#", $_GET['openid_claimed_id'], $matches);
			$steamid = is_numeric($matches[1]) ? $matches[1] : 0;
			$steamid = preg_match("#is_valid\s*:\s*true#i", $result) == 1 ? $steamid : null;
			$this->steamid = $steamid;
		} catch (Exception $e) {
			$steamid = null;
		}

		return $steamid;
	}

	public function userInfo() {
		if (!is_null($this->steamid)) {
			$info = simplexml_load_string(file_get_contents('http://steamcommunity.com/profiles/'.$this->steamid.'/?xml=1'),'SimpleXMLElement',LIBXML_NOCDATA);
		}
		return $info ?? null;
	}

	/**
	 * Validate a URL
	 *
	 * @param string $url
	 * @return boolean
	 */
	private static function isUrl($url) {
		$valid = false;
		if (filter_var($url, FILTER_VALIDATE_URL)) {
			set_error_handler(function() {});
			$headers = get_headers($url);
			$httpCode = substr($headers[0], 9, 3);
			restore_error_handler();
			$valid = ($httpCode >= 200 && $httpCode <= 400);
		}

		return $valid;
	}
}