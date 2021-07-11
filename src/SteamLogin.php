<?php

namespace kanalumaddela\SteamLogin;

use function array_merge;
use function array_replace_recursive;
use function basename;
use function count;
use function curl_close;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use Exception;
use function explode;
use function filter_var;
use function header;
use function http_build_query;
use function is_null;
use function is_numeric;
use function json_decode;
use function parse_url;
use function preg_match;
use RuntimeException;
use function session_name;
use function session_set_cookie_params;
use function session_start;
use function session_status;
use function sprintf;
use stdClass;
use function str_replace;
use function strlen;
use function strtok;
use function strtotime;

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
     * API URL with key filled in.
     *
     * @var string
     */
    protected static string $apiURL = '';

    /**
     * personastates.
     */
    protected static array $personastates = [
        'Offline',
        'Online',
        'Busy',
        'Away',
        'Snooze',
        'Looking to trade',
        'Looking to play',
    ];

    /**
     * Site Info.
     *
     * @var \stdClass
     */
    public stdClass $site;

    /**
     * Steam API key.
     *
     * @var string
     */
    protected string $apiKey;

    /**
     * Login URL.
     *
     * @var string
     */
    protected string $loginURL;

    /**
     * Player Object.
     *
     * @var \stdClass
     */
    protected stdClass $player;

    /**
     * URL or path to redirect player to after successfully validating.
     *
     * @var string
     */
    protected string $redirect_to;

    /**
     * Method of retrieving player's info.
     *
     * @var string
     */
    protected string $method;

    /**
     * Options.
     *
     * @var array
     */
    protected array $options = [
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

        $this->site = new stdClass();
        $this->site->port = (int) $_SERVER['SERVER_PORT'];
        $this->site->secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $this->site->port === 443 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

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

        $this->redirect_to = $_GET['openid_return_to'] ?? $this->options['return'];

        $this->player = new stdClass();

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
     * Build Steam Login URL.
     *
     * @param string|null $return
     *
     * @throws RuntimeException if $return is not valid url
     *
     * @return string
     */
    protected function createLoginURL(string $return = null): string
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

    /**
     * Validate a URL.
     *
     * @param string $url
     *
     * @return bool
     */
    protected static function isUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * Checks if request post Steam Login is valid.
     *
     * @return bool
     */
    public static function validRequest(): bool
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
    protected function validate(): bool
    {
        try {
            $params = [
                'openid.assoc_handle' => $_GET['openid_assoc_handle'],
                'openid.signed'       => $_GET['openid_signed'],
                'openid.sig'          => $_GET['openid_sig'],
                'openid.ns'           => self::OPENID_SPECS,
            ];

            foreach (explode(',', $params['openid.signed']) as $param) {
                if ($param === 'signed') {
                    continue;
                }

                $params['openid.'.$param] = $_GET['openid_'.str_replace('.', '_', $param)];
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
            $steamid = preg_match("#is_valid\s*:\s*true#i", $result) === 1 ? (is_numeric($matches[1]) ? $matches[1] : null) : null;

            if (!$steamid) {
                throw new Exception('Validation failed, try again. Response: '.$this->loginResponse);
            }

            $this->player->steamid = $steamid;
        } catch (Exception $e) {
            if ($this->options['debug']) {
                throw $e;
            }

            return false;
        }

        if ($this->options['session']['enable']) {
            $this->player = (object) array_merge((array) self::convert($steamid, $this->options['steam_universe']), (array) self::userInfo($steamid, $this->method));

            $_SESSION = $_SESSION + ['SteamLogin' => (array) $this->player];

            self::redirect($this->redirect_to);
        }

        return true;
    }

    /**
     * Convert the player's 64 bit steamid.
     *
     * @param      $steamid
     * @param bool $steam_universe
     *
     * @return \stdClass
     */
    public static function convert($steamid, bool $steam_universe = false): stdClass
    {
        $object = new stdClass();
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
     * @param        $steamid
     * @param string $method
     * @param bool   $debug
     *
     * @throws Exception
     *
     * @return \stdClass
     */
    public static function userInfo($steamid, string $method = 'xml', bool $debug = false): stdClass
    {
        if ($steamid instanceof stdClass) {
            $info = $steamid;
            $steamid = $steamid->steamid;
        } else {
            $info = self::convert($steamid);
        }

        switch ($method) {
            case 'api':
                $response = self::curl(sprintf(self::$apiURL, $steamid));
                $data = json_decode($response);
                $data = $data->response->players[0] ?? [];

                $length = count((array) $data);

                if ($length > 0) {
                    $info->name = $data->personaname;
                    $info->realName = !empty($data->realname) ? $data->realname : null;
                    $info->playerState = $data->personastate != 0 ? 'Online' : 'Offline';
                    $info->privacyState = ($data->communityvisibilitystate == 1 || $data->communityvisibilitystate == 2) ? 'Private' : 'Public';
                    $info->stateMessage = self::$personastates[$data->personastate] ?? $data->personastate;
                    $info->visibilityState = $data->communityvisibilitystate;
                    $info->avatarSmall = $data->avatar;
                    $info->avatarMedium = $data->avatarmedium;
                    $info->avatarLarge = $data->avatarfull;
                    $info->joined = $data->timecreated ?? null;
                    $info->profileUrl = $data->profileurl;
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
                    $info->profileUrl = (string) $data->customURL ?? 'https://steamcommunity.com/profiles/'.$steamid;
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
     * Basic cURL GET.
     *
     * @param string $url
     *
     * @return string
     */
    protected static function curl(string $url): string
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
     * Redirect user.
     *
     * @param string $url
     */
    protected static function redirect(string $url)
    {
        header('Location: '.$url);
    }

    /**
     * Return the URL or <img> of Steam Login buttons.
     *
     * @param string $type
     * @param bool   $img
     *
     * @return string
     */
    public static function button(string $type = 'small', bool $img = false): string
    {
        return ($img == true ? '<img src="' : '').'https://steamcommunity-a.akamaihd.net/public/images/signinthroughsteam/sits_0'.($type == 'small' ? 1 : 2).'.png'.($img == true ? '" />' : '');
    }

    /**
     * Set the API key manually.
     *
     * @param string $key
     *
     * @return \kanalumaddela\SteamLogin\SteamLogin
     */
    public function setKey(string $key): SteamLogin
    {
        $this->apiKey = $key;
        self::$apiURL = sprintf(self::STEAM_API, $this->apiKey);

        return $this;
    }

    /**
     * Return login URL.
     *
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->loginURL;
    }

    /**
     * Return API method.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->options['method'];
    }

    /**
     * @param string $method
     *
     * @return \kanalumaddela\SteamLogin\SteamLogin
     */
    public function setMethod(string $method): SteamLogin
    {
        $this->method = $method;

        return $this;
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
     * Convert a player's steamid and get their profile info.
     *
     * @param bool $info choose whether or not to retrieve their profile info
     *
     * @throws Exception
     *
     * @return \stdClass
     */
    public function getPlayer(bool $info = false)
    {
        $this->player = self::convert($this->player->steamid, $this->options['steam_universe']);
        if ($info) {
            $this->player = (object) array_merge((array) $this->player, (array) self::userInfo($this->player->steamid, $this->method));
        }

        return $this->player;
    }

    /**
     * Redirect user to steam.
     */
    public function login()
    {
        unset($_SESSION['SteamLogin']);

        self::redirect($this->loginURL);
    }

    /**
     * Logout user.
     */
    public function logout()
    {
        unset($_SESSION['SteamLogin']);

        self::redirect($this->site->home);
    }
}
