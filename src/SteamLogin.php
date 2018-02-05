<?php

namespace kanalumaddela\SteamLogin;

use Exception;

class SteamLogin implements SteamLoginInterface
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
	 * Steam Profile URL using 64 bit steamid
	 *
	 * @var string
	 */
	const STEAM_PROFILE = 'https://steamcommunity.com/profiles/%s';

	/**
	 * Steam Profile URL using custom URL
	 *
	 * @var string
	 */
	const STEAM_PROFILE_ID = 'https://steamcommunity.com/id/%s';

	/**
	 * Player's SteamID (64-bit)
	 *
	 * @var int
	 */
	public $steamid;

	/**
	 * Player's name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Player's real name
	 *
	 * @var string
	 */
	public $realName;

	/**
	 * Player's status
	 *
	 * @var string
	 */
	public $playerState;

	/**
	 * Player's status message
	 *
	 * @var string
	 */
	public $stateMessage;

	/**
	 * Player's privacy state
	 *
	 * @var string
	 */
	public $privacyState;

	/**
	 * Player's visibility state
	 *
	 * @var int
	 */
	public $visibilityState;

	/**
	 * Player's small avatar
	 *
	 * @var string
	 */
	public $avatarSmall;

	/**
	 * Player's medium avatar
	 *
	 * @var string
	 */
	public $avatarMedium;

	/**
	 * Player's large avatar
	 *
	 * @var string
	 */
	public $avatarLarge;

	/**
	 * Player's profile URL
	 *
	 * @var string
	 */
	public $profileURL;

	/**
	 * Player's account of create
	 *
	 * @var string
	 */
	public $joined;

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
		$this->timeout = isset($options['timeout']) ? ['timeout'] : 15;
		$this->method = isset($options['method']) ? $options['method'] : 'xml';
		if (self::$method == 'api') {
			if (empty($options['api_key'])) {
				throw new Exception('Steam API key not given');
			}
			$this->api_key = $options['api_key'];
		}
		if (self::validRequest()) {
			$this->validate();
			$this->userInfo();
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
	 * @throws Exception if steamid is null
	 * @return int|null
	 */
	private function validate()
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

			$curl = curl_init();
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
			curl_setopt($curl, CURLOPT_POST, 1);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
			curl_setopt($curl, CURLOPT_HTTPHEADER, [
				"Accept-language: en",
				"Content-type: application/x-www-form-urlencoded",
				"Content-Length: " . strlen($data),
			]);

			curl_setopt($curl, CURLOPT_URL, self::OPENID_STEAM);
			$result = curl_exec($curl);
			curl_close($curl);

			preg_match("#^http://steamcommunity.com/openid/id/([0-9]{17,25})#", $_GET['openid_claimed_id'], $matches);
			$steamid = is_numeric($matches[1]) ? $matches[1] : 0;
			$steamid = preg_match("#is_valid\s*:\s*true#i", $result) == 1 ? $steamid : null;
			$this->steamid = $steamid;
		} catch (Exception $e) {
			$steamid = null;
		}

		return $steamid;
	}

	/**
	 * Get and set player's information via Steam profile XML or API
	 */
	private function userInfo()
	{
		if (!is_null($this->steamid)) {
			switch ($this->method) {
				case 'xml':
					$info = simplexml_load_string(self::cURL(sprintf(self::STEAM_PROFILE.'/?xml=1', $this->steamid)),'SimpleXMLElement',LIBXML_NOCDATA);
					$info->customURL = (string)$info->customURL;
					$info->joined = (string)$info->memberSince;

					$this->name = (string)$info->steamID;
					$this->realName = (string)$info->realname;
					$this->playerState = ucfirst((string)$info->onlineState);
					$this->stateMessage = (string)$info->stateMessage;
					$this->privacyState = ucfirst((string)$info->privacyState);
					$this->visibilityState = (int)$info->visibilityState;
					$this->avatarSmall = (string)$info->avatarIcon;
					$this->avatarMedium = (string)$info->avatarMedium;
					$this->avatarLarge =(string) $info->avatarFull;
					$this->profileURL = !empty((string)$info->customURL) ? sprintf(self::STEAM_PROFILE_ID, (string)$info->customURL) : sprintf(self::STEAM_PROFILE, $this->steamid);
					$this->joined = !empty($info->joined) ? $info->joined : null;
					break;
				case 'api':
					$info = json_decode(self::cURL(sprintf(self::STEAM_API, self::$api_key, $this->steamid)));
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
					$this->name = $info->personaname;
					$this->realName =  isset($info->realname) ? $info->realname : null;
					$this->playerState = $info->personastate != 0 ? 'Online' : 'Offline';
					$this->stateMessage = $info->personastate;
					$this->privacyState = ($info->communityvisibilitystate == 1 || $info->communityvisibilitystate == 2) ? 'Private' : 'Public';
					$this->visibilityState = $info->communityvisibilitystate;
					$this->avatarSmall = $info->avatar;
					$this->avatarMedium = $info->avatarmedium;
					$this->avatarLarge = $info->avatarfull;
					$this->profileURL = str_replace('http://', 'https://', $info->profileurl);
					$this->joined = isset($info->timecreated) ? date('F jS, Y', $info->timecreated) : null;
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
	private static function isUrl($url)
	{
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

	/**
	 * Basic cURL GET
	 *
	 * @param string $url
	 * @return string
	 */
	private static function cURL($url)
	{
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_URL, $url);
		$data = curl_exec($curl);
		curl_close($curl);

		return $data;
	}
}