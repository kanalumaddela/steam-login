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
     * Steam API key used to retrieve player's info.
     *
     * @var string
     */
    private $api_key;

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
        'api_key'        => '',
        'timeout'        => 5,
        'steam_universe' => false,
        'session'        => [
            'enable'   => true,
            'name'     => 'SteamLogin',
            'lifetime' => 0,
            'path'     => '',
        ],
    ];

    /**
     * Construct SteamAuth instance.
     *
     * @param array $options
     * @param bool  $suppress
     *
     * @throws Exception
     */
    public function __construct(array $options = [], $suppress = false)
    {
        $this->site = new \stdClass();
        $this->site->secure = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' : isset($_SERVER['HTTPS']);
        $this->site->host = ($this->site->secure ? 'https://' : 'http://').$_SERVER['SERVER_NAME'];
        $this->site->path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $this->site->home = $this->site->host.(basename($_SERVER['PHP_SELF']) != 'index.php' ? $_SERVER['PHP_SELF'] : $this->site->path);

        $this->options['return'] = $this->site->home;
        $this->options['session']['path'] = $this->site->path;

        $this->options = array_replace_recursive($this->options, $options);

        $this->method = $this->options['method'];
        $this->api_key = $this->options['api_key'];
        unset($this->options['api_key']);

        if ($this->options['session']['enable']) {
            if (session_status() == PHP_SESSION_NONE) {
                session_set_cookie_params($this->options['session']['lifetime'], $this->options['session']['path'], $_SERVER['SERVER_NAME'], $this->site->secure, true);
                session_start();
            }
        }

        $this->loginURL = $this->createLoginURL($this->options['return']);

        $this->redirect_to = isset($_GET['openid_return_to']) ? $_GET['openid_return_to'] : $this->options['return'];

        $this->player = new \stdClass();

        if ($this->method == 'api') {
            if (empty($this->api_key)) {
                if ($this->options['debug']) {
                    throw new Exception('Steam API key not given');
                }
                $this->method = 'xml';
            }
        }

        if (self::validRequest()) {
            $this->validate();
        }
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
     * Return login URL.
     *
     * @return string
     */
    public function getLoginURL()
    {
        return $this->loginURL;
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
        $this->convert();
        if ($info) {
            $this->userInfo();
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
     * Checks if request post Steam Login is valid.
     *
     * @return bool
     */
    public static function validRequest()
    {
        return isset($_GET['openid_assoc_handle']) && isset($_GET['openid_claimed_id']) && isset($_GET['openid_sig']) && isset($_GET['openid_signed']);
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

            $result = curl_exec($curl);
            curl_close($curl);

            preg_match('#^https?://steamcommunity.com/openid/id/([0-9]{17,25})#', $_GET['openid_claimed_id'], $matches);
            $steamid = is_numeric($matches[1]) ? $matches[1] : 0;
            $steamid = preg_match("#is_valid\s*:\s*true#i", $result) == 1 ? $steamid : null;
            $this->player->steamid = $steamid;
        } catch (Exception $e) {
            $steamid = null;
            if (is_null($steamid) && $this->options['debug']) {
                throw $e;
            }

            return false;
        }

        if ($this->options['session']['enable']) {
            $this->userInfo();

            return self::redirect($this->redirect_to);
        }

        return true;
    }

    /**
     * Convert the player's 64 bit steamid.
     *
     * @return void
     */
    private function convert()
    {
        $x = ($this->player->steamid >> 56) & 0xFF;
        $y = $this->options['steam_universe'] ? $this->player->steamid & 1 : 0;
        $z = ($this->player->steamid >> 1) & 0x7FFFFFF;

        $this->player->steamid2 = "STEAM_$x:$y:$z";
        $this->player->steamid3 = '[U:1:'.($z * 2 + $y).']';
    }

    /**
     * Get and set player's information via Steam profile XML or API.
     *
     * @throws Exception
     */
    private function userInfo()
    {
        if (!isset($this->player->steamid2)) {
            $this->convert();
        }

        switch ($this->method) {
            case 'api':
                $data = json_decode(self::curl(sprintf(self::STEAM_API, $this->api_key, $this->player->steamid)));
                $data = isset($data->response->players[0]) ? $data->response->players[0] : [];

                $length = count((array) $data);

                if ($length > 0) {
                    $this->player->name = $data->personaname;
                    $this->player->realName = !empty($data->realname) ? $data->realname : null;
                    $this->player->playerState = $data->personastate != 0 ? 'Online' : 'Offline';
                    $this->player->privacyState = ($data->communityvisibilitystate == 1 || $data->communityvisibilitystate == 2) ? 'Private' : 'Public';
                    $this->player->stateMessage = isset(self::$personastates[$data->personastate]) ? self::$personastates[$data->personastate] : $data->personastate;
                    $this->player->visibilityState = $data->communityvisibilitystate;
                    $this->player->avatarSmall = $data->avatar;
                    $this->player->avatarMedium = $data->avatarmedium;
                    $this->player->avatarLarge = $data->avatarfull;
                    $this->player->joined = isset($data->timecreated) ? $data->timecreated : null;
                }
                break;
            case 'xml':
                $data = simplexml_load_string(self::curl(sprintf(self::STEAM_PROFILE.'/?xml=1', $this->player->steamid)), 'SimpleXMLElement', LIBXML_NOCDATA);

                if ($data !== false && !isset($data->error)) {
                    $this->player->name = (string) $data->steamID;
                    $this->player->realName = !empty($data->realName) ? $data->realName : null;
                    $this->player->playerState = ucfirst($data->onlineState);
                    $this->player->privacyState = ($data->privacyState == 'friendsonly' || $data->privacyState == 'private') ? 'Private' : 'Public';
                    $this->player->stateMessage = (string) $data->stateMessage;
                    $this->player->visibilityState = (int) $data->visibilityState;
                    $this->player->avatarSmall = (string) $data->avatarIcon;
                    $this->player->avatarMedium = (string) $data->avatarMedium;
                    $this->player->avatarLarge = (string) $data->avatarFull;
                    $this->player->joined = isset($data->memberSince) ? strtotime($data->memberSince) : null;
                } else {
                    if ($this->options['debug']) {
                        throw new Exception('No XML data please look into this: '.(isset($data['error']) ? $data['error'] : ''));
                    }
                }
                break;
            default:
                break;
        }

        if ($this->options['session']['enable']) {
            $_SESSION = $_SESSION + ['SteamLogin' => (array) $this->player];
        }
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
            'openid.realm'      => $this->site->host,
            'openid.identity'   => self::OPENID_SPECS.'/identifier_select',
            'openid.claimed_id' => self::OPENID_SPECS.'/identifier_select',
        ];

        return self::OPENID_STEAM.'?'.http_build_query($params);
    }
}
