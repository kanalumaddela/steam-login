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
	 * Steam API GetPlayerSummaries
	 *
	 * @var string
	 */
	const STEAM_API = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=%s&steamids=%s';

	/**
	 * Steam Profile XML
	 *
	 * @var string
	 */
	const STEAM_XML = 'http://steamcommunity.com/profiles/%s/?xml=1';

	/**
	 * User's SteamID (64-bit)
	 *
	 * @var int
	 */
	public $steamid;

	/**
	 * User's Steam Info
	 *
	 * @var mixed
	 */
	public $info;

	/**
	 * Steam validation timeout
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Method of retrieving player's info
	 *
	 * @var string
	 */
	private $method;

	/**
	 * Steam API key used to retrieve player's info
	 *
	 * @var	string
	 */
	private $api_key;

	/**
	 * Construct SteamAuth instance
	 *
	 * @param array $options
	 * @throws Exception
	 */
	public function __construct(array $options)
	{
		$this->timeout = $options['timeout'] ?? 15;
		$this->method = $options['method'] ?? 'xml';
		if ($this->method == 'api') {
			if (empty($options['api_key'])) {
				throw new Exception('Steam API key not given');
			}
			$this->api_key = $options['api_key'];
		}
		if (self::validRequest()) {
			$this->validate($this->timeout);
			$this->userInfo($this->method);
		}
	}

	/**
	 * Build Steam Login URL
	 *
	 * @param string $return
	 * @throws Exception if $return is not valid url
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
	 * @throws Exception if steamid is null
	 * @return int|null
	 */
	private function validate($timeout)
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

		if (is_null($steamid)) {
			throw new Exception('Steam Auth failed or timed out');
		}

		return $steamid;
	}

	/**
	 * Get player's information via Steam profile XML.
	 *
	 */
	private function userInfo($method) {
		$this->info = new \stdClass();
		if (!is_null($this->steamid)) {
			switch ($method) {
				case 'xml':
					$info = simplexml_load_string(file_get_contents('http://steamcommunity.com/profiles/'.$this->steamid.'/?xml=1'),'SimpleXMLElement',LIBXML_NOCDATA);
					$this->info->name = (string)$info->steamID;
					$this->info->realName = (string)$info->realname;
					$this->info->playerState = ucfirst((string)$info->onlineState);
					$this->info->stateMessage = (string)$info->stateMessage;
					$this->info->privacyState = ucfirst((string)$info->privacyState);
					$this->info->visibilityState = (int)$info->visibilityState;
					$this->info->avatarSmall = (string)$info->avatarIcon;
					$this->info->avatarMedium = (string)$info->avatarMedium;
					$this->info->avatarFull =(string) $info->avatarFull;
					$this->info->profileURL = (string)$info->customURL ?? null;
					$this->info->joined = (string)$info->memberSince;
					$this->info->summary = (string)$info->summary;
					break;
				case 'api':
					$info = json_decode(file_get_contents(sprintf(self::STEAM_API, $this->api_key, $this->steamid)));
					$info = $info->response->players[0];
					switch ($info->personastate) {
						case 0:
							$info->personastate = 'Offline';
							break;
						case 1:
							$info->personastate = 'Online';
							break;
						case 2:
							$info->personastate = 'Busy';
							break;
						case 3:
							$info->personastate = 'Away';
							break;
						case 4:
							$info->personastate = 'Snooze';
							break;
						case 5:
							$info->personastate = 'Looking to trade';
							break;
						case 6:
							$info->personastate = 'Looking to play';
							break;
					}
					$this->info->name = $info->personaname;
					$this->info->realName = $info->realname ?? '';
					$this->info->playerState = $info->personastate;
					$this->info->stateMessage = $info->personastate;
					$this->info->privacyState = $info->communityvisibilitystate == 1 ? 'Private' : 'Public';
					$this->info->visibilityState = $info->communityvisibilitystate;
					$this->info->avatarSmall = $info->avatar;
					$this->info->avatarMedium = $info->avatarmedium;
					$this->info->avatarFull = $info->avatarfull;
					$this->info->profileURL = $info->profileurl;
					$this->info->joined = isset($info->timecreated) ? date('F jS, Y', $info->timecreated) : null;
					break;
				default:
					break;
			}
		}
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