<?php

namespace kanalumaddela\SteamLogin;

use Exception;
use RuntimeException;

class SteamLogin
{
    /**
     * Steam OpenID URL.
     *
     * @var string
     */
    const OPENID_STEAM = 'https://steamcommunity.com/openid/login';

    /**
     * OpenID Specs.
     *
     * @var string
     */
    const OPENID_SPECS = 'http://specs.openid.net/auth/2.0';

    /**
     * Steam API GetPlayerSummaries.
     *
     * @var string
     */
    const STEAM_API = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=%s&steamids=%s';

    /**
     * Steam Profile URL using 64 bit steamid.
     *
     * @var string
     */
    const STEAM_PROFILE = 'https://steamcommunity.com/profiles/%s';

    /**
     * Steam Profile URL using custom URL.
     *
     * @var string
     */
    const STEAM_PROFILE_ID = 'https://steamcommunity.com/id/%s';

    /**
     * Site Info.
     *
     * @var \stdClass
     */
    public $site;

    /**
     * Steam API key.
     *
     * @var string
     */
    private $apiKey;

    /**
     * Login URL.
     *
     * @var string
     */
    private $loginURL;

    /**
     * Player Object.
     *
     * @var \stdClass
     */
    private $player;

    /**
     * URL or path to redirect player to after successfully validating.
     *
     * @var string
     */
    private $redirect_to;

    /**
     * Method of retrieving player's info.
     *
     * @var string
     */
    private $method;

    /**
     * API URL with key filled in.
     *
     * @var string
     */
    protected static $apiURL = '';

    /**
     * personastates.
     */
    protected static $personastates = [
        'Offline',
        'Online',
        'Busy',
        'Away',
        'Snooze',
        'Looking to trade',
        'Looking to play',
    ];

    /**
     * Options.
     *
     * @var array
     */
    protected $options = [
        'debug'          => false,
        'return'         => '',
        'method'         => 'xml',
        'api_key'        => null,
        'timeout'        => 5,
        'steam_universe' => false,
        'session'        => [
            'enable'    => true,
            'name'      => 'SteamLogin',
            'lifetime'  => 0,
            'path'      => '',
            'existing'  => false,
            'http_only' => true,
        ],
    ];

    /**
     * Steam Login respond from OpenID.
     *
     * @var mixed
     */
    protected $loginResponse;

    /**
     * Construct SteamAuth instance.
     *
     * @param array $options
     *
     * @throws Exception
     */
    public function __construct(array $options = [])
    {
        if (isset($_GET['openid_error'])) {
            throw new Exception('OpenID Error: '.$_GET['openid_error']);
        }

        $this->site = new \stdClass();
        $this->site->port = (int) $_SERVER['SERVER_PORT'];
        $this->site->secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $this->site->port === 443 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' : false);

        $this->site->domain = strtok($_SERVER['HTTP_HOST'], ':');

        $this->site->path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $this->site->home = ($this->site->secure ? 'https://' : 'http://').$this->site->domain.($this->site->port !== 80 && !$this->site->secure ? ':'.$this->site->port : '').(basename($_SERVER['SCRIPT_NAME']) != 'index.php' ? $_SERVER['SCRIPT_NAME'] : $this->site->path);

        $this->options['return'] = $this->site->home;
        $this->options['session']['path'] = $this->site->path;

        $this->options = array_replace_recursive($this->options, $options);

        $this->method = $this->options['method'];
        $this->apiKey = $this->options['api_key'];
        unset($this->options['api_key']);

        self::$apiURL = sprintf(self::STEAM_API, $this->apiKey, '%s');

        if ($this->options['session']['enable']) {
            if (session_status() == PHP_SESSION_NONE && !$this->options['session']['existing']) {
                session_set_cookie_params($this->options['session']['lifetime'], $this->options['session']['path'], $this->site->domain, $this->site->secure, $this->options['session']['http_only']);
                session_name($this->options['session']['name']);
                session_start();
            }
        }

        $this->loginURL = $this->createLoginURL($this->options['return']);

        $this->redirect_to = isset($_GET['openid_return_to']) ? $_GET['openid_return_to'] : $this->options['return'];

        $this->player = new \stdClass();

        if ($this->method == 'api') {
            if (empty($this->apiKey)) {
                if ($this->options['debug']) {
                    throw new Exception('Steam API key not given');
                }
                $this->method = 'xml';
            }
        }

        if (self::validRequest()) {
            $valid = $this->validate();

            if (!$valid) {
                throw new Exception('Steam login failed, try again');
            }
        }
    }

    /**
     * Set the API key manually.
     *
     * @param string $key
     */
    public function setKey($key)
    {
        $this->apiKey = $key;
        self::$apiURL = sprintf(self::STEAM_API, $this->apiKey);
    }

    /**
     * Return login URL.
     *
     * @return string
     */
    public function getLoginURL()
    {
        return $this->loginURL;
    }

    /**
     * Return API method.
     *
     * @return string
     */
    public function getMethod()
    {
        return $this->options['method'];
    }

    /**
     * Convert a player's steamid and get their profile info.
     *
     * @param bool $info choose whether or not to retrieve their profile info
     *
     * @throws Exception
     *
     * @return \stdClass
     */
    public function getPlayer($info = false)
    {
        $this->player = self::convert($this->player->steamid, $this->options['steam_universe']);
        if ($info) {
            $this->player = (object) array_merge((array) $this->player, (array) self::userInfo($this->player->steamid, $this->method));
        }

        return $this->player;
    }

    /**
     * Alias function for getPlayer(true).
     *
     * @throws Exception
     *
     * @return \stdClass
     */
    public function getPlayerInfo()
    {
        return $this->getPlayer(true);
    }

    /**
     * Redirect user to steam.
     */
    public function login()
    {
        unset($_SESSION['SteamLogin']);

        return self::redirect($this->loginURL);
    }

    /**
     * Logout user.
     */
    public function logout()
    {
        unset($_SESSION['SteamLogin']);

        return self::redirect($this->site->home);
    }

    /**
     * Return the URL or <img> of Steam Login buttons.
     *
     * @param string $type
     * @param bool   $img
     *
     * @return string
     */
    public static function button($type = 'small', $img = false)
    {
        return ($img == true ? '<img src="' : '').'https://steamcommunity-a.akamaihd.net/public/images/signinthroughsteam/sits_0'.($type == 'small' ? 1 : 2).'.png'.($img == true ? '" />' : '');
    }

    /**
     * Convert the player's 64 bit steamid.
     *
     * @param $steamid
     * @param bool $steam_universe
     *
     * @return \stdClass
     */
    public static function convert($steamid, $steam_universe = false)
    {
        $object = new \stdClass();
        $object->steamid = $steamid;

        $x = ($steamid >> 56) & 0xFF;
        $y = $steam_universe ? $steamid & 1 : 0;
        $z = ($steamid >> 1) & 0x7FFFFFF;

        $object->steamid2 = "STEAM_$x:$y:$z";
        $object->steamid3 = '[U:1:'.($z * 2 + $y).']';

        return $object;
    }

    /**
     * Get and returns a player's information via Steam profile XML or API.
     *
     * @param $steamid
     * @param string $method
     * @param bool   $debug
     *
     * @throws Exception
     *
     * @return \stdClass
     */
    public static function userInfo($steamid, $method = 'xml', $debug = false)
    {
        if ($steamid instanceof \stdClass) {
            $info = $steamid;
            $steamid = $steamid->steamid;
        } else {
            $info = self::convert($steamid);
        }

        switch ($method) {
            case 'api':
                $response = self::curl(sprintf(self::$apiURL, $steamid));
                $data = json_decode($response);
                $data = isset($data->response->players[0]) ? $data->response->players[0] : [];

                $length = count((array) $data);

                if ($length > 0) {
                    $info->name = $data->personaname;
                    $info->realName = !empty($data->realname) ? $data->realname : null;
                    $info->playerState = $data->personastate != 0 ? 'Online' : 'Offline';
                    $info->privacyState = ($data->communityvisibilitystate == 1 || $data->communityvisibilitystate == 2) ? 'Private' : 'Public';
                    $info->stateMessage = isset(self::$personastates[$data->personastate]) ? self::$personastates[$data->personastate] : $data->personastate;
                    $info->visibilityState = $data->communityvisibilitystate;
                    $info->avatarSmall = $data->avatar;
                    $info->avatarMedium = $data->avatarmedium;
                    $info->avatarLarge = $data->avatarfull;
                    $info->joined = isset($data->timecreated) ? $data->timecreated : null;
                } else {
                    if ($debug) {
                        throw new Exception('No valid API data please look into the response: '.$response);
                    }
                }
                break;
            case 'xml':
                $data = simplexml_load_string(self::curl(sprintf(self::STEAM_PROFILE.'/?xml=1', $steamid)), 'SimpleXMLElement', LIBXML_NOCDATA);

                if ($data !== false && !isset($data->error)) {
                    $info->name = (string) $data->steamID;
                    $info->realName = !empty($data->realName) ? $data->realName : null;
                    $info->playerState = ucfirst($data->onlineState);
                    $info->privacyState = ($data->privacyState == 'friendsonly' || $data->privacyState == 'private') ? 'Private' : 'Public';
                    $info->stateMessage = (string) $data->stateMessage;
                    $info->visibilityState = (int) $data->visibilityState;
                    $info->avatarSmall = (string) $data->avatarIcon;
                    $info->avatarMedium = (string) $data->avatarMedium;
                    $info->avatarLarge = (string) $data->avatarFull;
                    $info->joined = isset($data->memberSince) ? strtotime($data->memberSince) : null;
                } else {
                    if ($debug) {
                        throw new Exception('No XML data please look into this: '.(isset($data['error']) ? $data['error'] : ''));
                    }
                }
                break;
            default:
                break;
        }

        return $info;
    }

    /**
     * Checks if request post Steam Login is valid.
     *
     * @return bool
     */
    public static function validRequest()
    {
        $valid = isset($_GET['openid_assoc_handle']) && isset($_GET['openid_claimed_id']) && isset($_GET['openid_sig']) && isset($_GET['openid_signed']);

        if ($valid) {
            $valid = !empty($_GET['openid_assoc_handle']) && !empty($_GET['openid_claimed_id']) && !empty($_GET['openid_sig']) && !empty($_GET['openid_signed']);
        }

        return $valid;
    }

    /**
     * Validate Steam Login.
     *
     * @throws RuntimeException if steamid is null
     * @throws Exception
     *
     * @return bool
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
                $value = $_GET['openid_'.str_replace('.', '_', $item)];
                $params['openid.'.$item] = get_magic_quotes_gpc() ? stripslashes($value) : $value;
            }

            $params['openid.mode'] = 'check_authentication';

            $data = http_build_query($params);

            $curl = curl_init(self::OPENID_STEAM);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, (int) $this->options['timeout']);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Accept-language: en',
                'Content-type: application/x-www-form-urlencoded',
                'Content-Length: '.strlen($data),
            ]);

            $this->loginResponse = $result = curl_exec($curl);
            curl_close($curl);

            preg_match('#^https?://steamcommunity.com/openid/id/([0-9]{17,25})#', $_GET['openid_claimed_id'], $matches);
            $steamid = is_numeric($matches[1]) ? $matches[1] : 0;
            $steamid = preg_match("#is_valid\s*:\s*true#i", $result) == 1 ? $steamid : null;

            if (!$steamid) {
                throw new Exception('Validation failed, try again');
            }

            $this->player->steamid = $steamid;
        } catch (Exception $e) {
            $steamid = null;
            if (is_null($steamid) && $this->options['debug']) {
                throw $e;
            }

            return false;
        }

        if ($this->options['session']['enable']) {
            $this->player = (object) array_merge((array) self::convert($steamid, $this->options['steam_universe']), (array) self::userInfo($steamid, $this->method));

            $_SESSION = $_SESSION + ['SteamLogin' => (array) $this->player];

            return self::redirect($this->redirect_to);
        }

        return true;
    }

    /**
     * Validate a URL.
     *
     * @param string $url
     *
     * @return bool
     */
    private static function isUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * Redirect user.
     *
     * @param string $url
     */
    private static function redirect($url)
    {
        return header('Location: '.$url);
    }

    /**
     * Basic cURL GET.
     *
     * @param string $url
     *
     * @return string
     */
    private static function curl($url)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        $data = curl_exec($curl);
        curl_close($curl);

        return $data;
    }

    /**
     * Build Steam Login URL.
     *
     * @param string $return
     *
     * @throws RuntimeException if $return is not valid url
     *
     * @return string
     */
    protected function createLoginURL($return = null)
    {
        if (!is_null($return) && !self::isUrl($return)) {
            throw new RuntimeException('The return URL is not valid');
        }

        $return = !empty($return) ? $return : $this->site->home;

        $params = [
            'openid.ns'         => self::OPENID_SPECS,
            'openid.mode'       => 'checkid_setup',
            'openid.return_to'  => $return,
            'openid.realm'      => ($this->site->secure ? 'https://' : 'http://').$this->site->domain,
            'openid.identity'   => self::OPENID_SPECS.'/identifier_select',
            'openid.claimed_id' => self::OPENID_SPECS.'/identifier_select',
        ];

        return self::OPENID_STEAM.'?'.http_build_query($params);
    }
}
