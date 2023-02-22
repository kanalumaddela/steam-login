<?php

namespace kanalumaddela\SteamLogin;

use Exception;
use InvalidArgumentException;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use RuntimeException;
use SimpleXMLElement;

class SteamLogin
{
    /**
     * Steam OpenID URL.
     *
     * @var string
     */
    protected const OPENID_STEAM = 'https://steamcommunity.com/openid/login';

    /**
     * OpenID Specs.
     *
     * @var string
     */
    protected const OPENID_SPECS = 'http://specs.openid.net/auth/2.0';

    /**
     * Steam API GetPlayerSummaries.
     *
     * @var string
     */
    public const STEAM_API_PLAYER_SUMMARY = 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=%s&steamids=%s';

    /**
     * Steam Profile URL using 64 bit steamid.
     *
     * @var string
     */
    public const STEAM_PROFILE = 'https://steamcommunity.com/profiles/%s';

    /**
     * personastates.
     */
    protected static array $personaStates = [
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
     * debug: enable debug mode and throw more exceptions
     * method: api method for retrieving player info
     *
     * @var array
     */
    protected array $options = [
        'debug'                => false,
        'method'               => 'xml',
        'api_key'              => null,
        'timeout'              => 5,
        'steam_universe'       => false,
        'retrieve_info'        => false,
        'automatic_validation' => true,
        'automatic_redirect'   => false,
        'session'              => [
            'enable' => true,
            'key'    => 'SteamLogin',
        ],
    ];

    /**
     * openid.return_to.
     *
     * @var string
     */
    protected string $returnTo;

    /**
     * OpenID response.
     *
     * @var mixed
     */
    protected mixed $openIdResponse = null;

    /**
     * Steam API key.
     *
     * @var string|null
     */
    protected string|null $apiKey = null;

    /**
     * Response from either Steam API / XML profile data.
     *
     * @var mixed
     */
    protected mixed $apiResponse = null;

    /**
     * Steam user.
     *
     * @var array
     */
    protected array $player = [];

    protected array $site = [];

    /**
     * OpenID login URL.
     *
     * @var string
     */
    protected string|null $loginUrl = null;

    /**
     * URL to redirect to after logging in.
     *
     * @var string
     */
    protected string $redirectTo;

    public function __construct(array $options = [])
    {
        if (isset($_GET['openid_error'])) {
            throw new RuntimeException('OpenID Error: '.$_GET['openid_error']);
        }

        $options['allowed_hosts'] ??= [
            'localhost',
            '127.0.0.1',
        ];

//        if (empty($options['allowed_hosts'])) {
//            throw new InvalidArgumentException('options.allowed_hosts is empty / not defined');
//        }

        $this->options = \array_replace_recursive($this->options, $options);

        $this->setSite($this->options['site'] ?? []);
        $this->setupApi();
        $this->setupReturnRedirect();
        $this->buildLoginUrl();

        unset($this->options['api_key'], $this->options['site']);

        if ($this->options['session']['enable'] && \session_status() === PHP_SESSION_NONE) {
            \session_start();
        }

        if ($this->options['automatic_validation'] && static::isValidOpenIdRequest()) {
            $valid = $this->validate();

            if (!$valid) {
                throw new RuntimeException('Steam login failed, please try again');
            }

            if ($this->options['retrieve_info']) {
                $this->player = \array_merge($this->player, $this->userInfo($this->player['steamid'], $this->options['method']));
            }

            if ($this->options['session']['enable']) {
                $_SESSION += [$this->options['session']['key'] => $this->player];
            }

            if ($this->options['automatic_redirect']) {
                static::redirect($this->getRedirectTo());
            }
        }
    }

    #[NoReturn]
    public function login(): void
    {
        $this->redirectToSteam();
    }

    #[NoReturn]
    public function redirectToSteam(): void
    {
        static::redirect($this->getLoginUrl());
    }

    #[NoReturn]
    public function logout(bool $destroySession = false, string $redirect = null): void
    {
        unset($_SESSION[$this->options['session']['key']]);

        if ($destroySession) {
            \session_destroy();
        }

        static::redirect($redirect ?? $this->getHome());
    }

    /**
     * Get a user's profile info.
     *
     * @throws InvalidArgumentException|RuntimeException|JsonException
     */
    public function userInfo(string $steamid, string $method = 'xml', bool $addConverted = false): array
    {
        if ($method === 'api' && empty($this->apiKey)) {
            throw new InvalidArgumentException('apiKey is not set, cannot use API method.');
        }

        $info = [];

        if (!empty($this->player['steamid']) && $this->player['steamid'] === $steamid) {
            $info = $this->player;
        }

        if ($addConverted) {
            $info = static::convert($steamid, $this->options['steam_universe']);
        }

        $curlOptions = [
            CURLOPT_TIMEOUT        => $this->options['timeout'],
            CURLOPT_RETURNTRANSFER => true,
        ];

        try {
            if ($method === 'api') {
                $response = $this->setApiResponse(static::curl(\sprintf(static::STEAM_API_PLAYER_SUMMARY, $this->getApiKey(), $steamid), $curlOptions))->getApiResponse();

                $data = \json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                $data = $data['response']['players'][0] ?? [];

                if (\count($data) === 0) {
                    throw new RuntimeException('No valid API data please look into the response: '.$response);
                }

                $info['name'] = $data['personaname'] ?? null;
                $info['realName'] = $data['realname'] ?? null;
                $info['playerState'] = ($data['personastate'] ?? 0) !== 0 ? 'Online' : 'Offline';
                $data['communityVisibilityState'] = $data['communityvisibilitystate'] ??= 1;
                $info['privacyState'] = $data['communityvisibilitystate'] === 1 || $data['communityvisibilitystate'] === 2 ? 'Private' : 'Public';
                $info['isPrivate'] = $info['privacyState'] === 'Private';
                $data['personaState'] = $data['personastate'] ??= null;
                $info['stateMessage'] = static::$personaStates[$data['personastate']] ?? $data['personastate'];
                $info['visibilityState'] = $data['communityvisibilitystate'] ?? null;
                $info['avatars'] = [
                    'small'  => $data['avatar'] ?? null,
                    'medium' => $data['avatarmedium'] ?? null,
                    'large'  => $data['avatarfull'] ?? null,
                ];
                $info['avatarSmall'] = $info['avatars']['small'];
                $info['avatarMedium'] = $info['avatars']['medium'];
                $info['avatarLarge'] = $info['avatars']['large'];

                $info['joined'] = $data['timecreated'] ?? null;
                $info['profileUrl'] = $data['profileurl'] ?? null;
                $info['profileDataUrl'] = 'https://steamcommunity.com/profiles/'.$steamid.'?xml=1';
            } else {
                $response = $this->setApiResponse(static::curl(\sprintf(static::STEAM_PROFILE.'?xml=1', $steamid), $curlOptions))->getApiResponse();

                $data = \simplexml_load_string($response, SimpleXMLElement::class, LIBXML_NOCDATA);

                if ($data !== false && !isset($data->error)) {
                    $info['name'] = (string) $data->steamID;
                    $info['realName'] = !empty($data->realName) ? $data->realName : null;
                    $info['playerState'] = \ucfirst($data->onlineState);
                    $info['privacyState'] = ($data->privacyState == 'friendsonly' || $data->privacyState == 'private') ? 'Private' : 'Public';
                    $info['isPrivate'] = $info['privacyState'] === 'Private';
                    $info['stateMessage'] = (string) $data->stateMessage;
                    $info['visibilityState'] = (int) $data->visibilityState;
                    $info['avatars'] = [
                        'small'  => (string) $data->avatarIcon,
                        'medium' => (string) $data->avatarMedium,
                        'large'  => (string) $data->avatarFull,
                    ];
                    $info['avatarSmall'] = $info['avatars']['small'];
                    $info['avatarMedium'] = $info['avatars']['medium'];
                    $info['avatarLarge'] = $info['avatars']['large'];
                    $info['joined'] = isset($data->memberSince) ? \strtotime($data->memberSince) : null;
                    $info['profileUrl'] = (string) ($data->customURL ?? 'https://steamcommunity.com/profiles/'.$steamid);
                    $info['profileDataUrl'] = 'https://steamcommunity.com/profiles/'.$steamid.'?xml=1';
                } else {
                    throw new RuntimeException('No XML data please look into this: '.($data['error'] ?? ''));
                }
            }
        } catch (Exception $e) {
            if ($this->isDebug()) {
                throw $e;
            }
        }

        return $info;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Setup required site parameters.
     *
     * @param array $site
     *
     * @return $this
     */
    public function setSite(array $site = []): static
    {
        $http_host = \strtok(static::getServer('HTTP_HOST'), ':');
        $site['domain'] = $site['domain'] ?? $http_host !== false ? $http_host : 'localhost';

        if (!\in_array($site['domain'], $this->options['allowed_hosts'], true)) {
            throw new InvalidArgumentException($site['domain'].' is not set in options.allowed_hosts');
        }

        $this->setPort($site['port'] ?? static::getServer('SERVER_PORT', 80));

        $is_https = (!empty($_SERVER['HTTPS']) && static::getServer('HTTPS') !== 'off') || $this->getPort() === 443 || static::getServer('HTTP_X_FORWARDED_PROTO') === 'https';

        $this->setSecure($site['secure'] ?? $is_https)
            ->setDomain($site['domain'])
            ->setPath($site['path'] ?? \parse_url(static::getServer('REQUEST_URI'), PHP_URL_PATH))
            ->setHost(($this->isSecure() ? 'https://' : 'http://').$this->site['domain'].(!$this->isSecure() && $this->site['port'] !== 80 ? ':'.$this->site['port'] : ''))
            ->setHome($this->getHost().$this->getPath());

        return $this;
    }

    /**
     * @return array
     */
    public function getSite(): array
    {
        return $this->site;
    }

    /**
     * Check if debug is enabled.
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->options['debug'] ?? false;
    }

    /**
     * Set debug mode.
     *
     * @param bool $debug
     *
     * @return $this
     */
    public function setDebug(bool $debug): static
    {
        $this->options['debug'] = $debug;

        return $this;
    }

    public function getAllowedHosts(): array
    {
        return $this->options['allowed_hosts'];
    }

    public function setAllowedHosts(array $hosts): static
    {
        $filtered = [];

        foreach ($hosts as $host) {
            if (\filter_var($host, FILTER_VALIDATE_DOMAIN)) {
                throw new InvalidArgumentException($host.' is not a valid host');
            }

            $filtered[] = $host;
        }

        $this->options['allowed_hosts'] = $filtered;

        return $this;
    }

    /**
     * Get the Steam API key.
     *
     * @return string|null
     */
    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * Set the Steam API key.
     *
     * @param string $apiKey
     *
     * @return SteamLogin
     */
    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Get the response from either the Steam API or XML profile data.
     *
     * @return mixed
     */
    public function getApiResponse(): mixed
    {
        return $this->apiResponse;
    }

    /**
     * @param mixed $apiResponse
     *
     * @return SteamLogin
     */
    protected function setApiResponse(mixed $apiResponse): static
    {
        $this->apiResponse = $apiResponse;

        return $this;
    }

    /**
     * Get openid.return_to parameter.
     *
     * @return string
     */
    public function getReturnTo(): string
    {
        return $this->returnTo;
    }

    /**
     * Set openid.return_to parameter.
     *
     * @param string $url
     * @param bool   $skipValidation
     *
     * @return $this
     */
    public function setReturnTo(string $url, bool $skipValidation = false): static
    {
        if (!$skipValidation) {
            static::validateUrl($url);

            if (!\in_array($domain = \parse_url($url, PHP_URL_HOST), $this->options['allowed_hosts'], true)) {
                throw new InvalidArgumentException($domain.' is not set in options.allowed_hosts');
            }
        }

        $this->returnTo = $url;

        return $this->buildLoginUrl();
    }

    /**
     * @return mixed
     */
    public function getOpenIdResponse(): mixed
    {
        return $this->openIdResponse;
    }

    /**
     * @param mixed $openIdResponse
     *
     * @return SteamLogin
     */
    protected function setOpenIdResponse(mixed $openIdResponse): static
    {
        $this->openIdResponse = $openIdResponse;

        return $this;
    }

    public function buildLoginUrl(): static
    {
        $params = [
            'openid.ns'         => static::OPENID_SPECS,
            'openid.mode'       => 'checkid_setup',
            'openid.return_to'  => $this->getReturnTo(),
            'openid.realm'      => $this->getHost(),
            'openid.identity'   => static::OPENID_SPECS.'/identifier_select',
            'openid.claimed_id' => static::OPENID_SPECS.'/identifier_select',
        ];

        $this->setLoginUrl(static::OPENID_STEAM.'?'.\http_build_query($params));

        return $this;
    }

    public function loginButtonLink(string $type = 'small'): string
    {
        return '<a href="'.$this->getLoginURL().'">'.static::button($type, true).'</a>';
    }

    /**
     * @return string
     */
    public function getLoginUrl(): string
    {
        return $this->loginUrl;
    }

    /**
     * @param string $loginUrl
     */
    protected function setLoginUrl(string $loginUrl): void
    {
        $this->loginUrl = $loginUrl;
    }

    public function setSecure(bool $secure): static
    {
        $this->site['secure'] = $secure;

        return $this;
    }

    public function isSecure(): bool
    {
        return $this->site['secure'];
    }

    public function getDomain()
    {
        return $this->site['domain'];
    }

    public function setDomain(string $domain): static
    {
        $this->site['domain'] = $domain;

        return $this;
    }

    public function getPort(): int
    {
        return $this->site['port'];
    }

    public function setPort(int $port = 80): static
    {
        $this->site['port'] = $port;

        return $this;
    }

    public function getHost()
    {
        return $this->site['host'];
    }

    public function setHost(string $host): static
    {
        $this->site['host'] = $host;

        return $this;
    }

    /**
     * Absolute URL path to the website.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->site['path'];
    }

    /**
     * Set the absolute URL path to the website.
     *
     * @param string $path
     *
     * @return $this
     */
    public function setPath(string $path): static
    {
        $this->site['path'] = \rtrim($path, '/');

        return $this;
    }

    public function getHome(): string
    {
        return $this->site['home'];
    }

    public function setHome(string $url): static
    {
        static::validateUrl($url);

        $this->site['home'] = $url;

        return $this;
    }

    public function getRedirectTo(): string
    {
        return $this->redirectTo;
    }

    public function setRedirectTo(string $url): static
    {
        static::validateUrl($url);

        $this->redirectTo = $url;

        return $this;
    }

    protected function setupApi(): void
    {
        if (!empty($this->options['api_key'])) {
            $this->setApiKey($this->options['api_key']);
            $this->options['method'] = 'api';
        } elseif ($this->options['method'] === 'api') {
            if ($this->isDebug()) {
                throw new InvalidArgumentException('Steam API key not given and method is set to "api"');
            }

            $this->options['method'] = 'xml';
        }
    }

    protected function setupReturnRedirect(): void
    {
        $returnTo = $this->options['return_to'] ?? $this->getHome();

        if ($returnTo[0] === '/') {
            $returnTo = $this->getHome().$returnTo;
            $this->setReturnTo($returnTo, true);
        } else {
            $this->setReturnTo($returnTo);
        }

        $redirectTo = $this->options['redirect_to'] ?? static::getQuery('redirect_to');

        if (empty($redirectTo)) {
            $redirectTo = $this->getHome();
        }

        if ($redirectTo[0] === '/') {
            $redirectTo = $this->getHome().$redirectTo;
        }

        $this->setRedirectTo($redirectTo);
    }

    /**
     * @return bool
     *
     * @see SteamLogin::validateOpenId()
     */
    public function validate(): bool
    {
        return $this->validateOpenId();
    }

    /**
     * Validate OpenID query parameters.
     *
     * @return bool
     */
    protected function validateOpenId(): bool
    {
        try {
            $params = [
                'openid.assoc_handle' => static::getQuery('openid_assoc_handle'),
                'openid.signed'       => static::getQuery('openid_signed'),
                'openid.sig'          => static::getQuery('openid_sig'),
                'openid.ns'           => static::OPENID_SPECS,
            ];

            foreach (\explode(',', $params['openid.signed']) as $param) {
                if ($param === 'signed') {
                    continue;
                }

                $params['openid.'.$param] = static::getQuery('openid_'.\str_replace('.', '_', $param));
            }

            $params['openid.mode'] = 'check_authentication';

            $data = \http_build_query($params);

            $result = $this->setOpenIdResponse(static::curl(static::OPENID_STEAM, [
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_POST           => 1,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->options['timeout'],
                CURLOPT_HTTPHEADER     => [
                    'Accept-language: en',
                    'Content-type: application/x-www-form-urlencoded',
                    'Content-Length: '.\strlen($data),
                ],
            ]))->getOpenIdResponse();

            \preg_match('#^https?://steamcommunity.com/openid/id/(\d{17,25})#', static::getQuery('openid_claimed_id'), $matches);

            $steamid = \preg_match("#is_valid\s*:\s*true#i", $result) === 1 && \is_numeric($matches[1]) ? $matches[1] : null;

            if (!$steamid) {
                throw new RuntimeException('Validation failed, try again. Steam OpenID Response: '.$this->getOpenIdResponse());
            }

            $this->player = static::convert($steamid, $this->options['steam_universe']);

            return true;
        } catch (RuntimeException $e) {
            if ($this->isDebug()) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Take a given 64 bit steamid and return also steamid2 and steamid3 representations.
     *
     * @param string $steamid
     * @param bool   $steamUniverse
     *
     * @return string[]
     */
    public static function convert(string $steamid, bool $steamUniverse = false): array
    {
        $x = ($steamid >> 56) & 0xFF;
        $y = $steamUniverse ? $steamid & 1 : 0;
        $z = ($steamid >> 1) & 0x7FFFFFF;

        $steamid2 = "STEAM_$x:$y:$z";
        $steamid3 = '[U:1:'.($z * 2 + $y).']';

        return [
            'steamid'  => $steamid,
            'steamid2' => $steamid2,
            'steamid3' => $steamid3,
        ];
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
        $buttons = [
            'small_classic'        => 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/steamworks_docs/english/sits_small.png',
            'large_classic'        => 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/steamworks_docs/english/sits_large_noborder.png',
            'large_classic_border' => 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/steamworks_docs/english/sits_large_border.png',
            'small'                => 'https://community.cloudflare.steamstatic.com/public/images/signinthroughsteam/sits_01.png',
            'large'                => 'https://community.cloudflare.steamstatic.com/public/images/signinthroughsteam/sits_02.png',
        ];

        return ($img === true ? '<img src="' : '').($buttons[$type] ?? $buttons['small']).($img === true ? '" alt="Sign in through Steam" />' : '');
    }

    /**
     * Getter for $_SERVER.
     *
     * @param string $key
     * @param $default
     *
     * @return mixed|null
     */
    protected static function getServer(string $key, $default = null): mixed
    {
        return $_SERVER[$key] ?? $default;
    }

    /**
     * Getter for $_GET.
     *
     * @param string $key
     * @param $default
     *
     * @return mixed|null
     */
    protected static function getQuery(string $key, $default = null): mixed
    {
        return $_GET[$key] ?? $default;
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
        return \filter_var($url, FILTER_VALIDATE_URL);
    }

    protected static function validateUrl(string $url): void
    {
        if (!static::isUrl($url)) {
            throw new InvalidArgumentException($url.' is not a valid URL!');
        }
    }

    /**
     * Check the current request matches post Steam login is valid.
     *
     * @return bool
     */
    protected static function isValidOpenIdRequest(): bool
    {
        return !empty($_GET['openid_assoc_handle']) && !empty($_GET['openid_claimed_id']) && !empty($_GET['openid_sig']) && !empty($_GET['openid_signed']);
    }

    /**
     * Redirect to a given URL.
     *
     * @param string $url
     *
     * @return void
     */
    #[NoReturn]
    protected static function redirect(string $url): void
    {
        \header('Location: '.$url);
        exit();
    }

    protected static function curl(string $url, array $options = []): bool|string
    {
        $curl = \curl_init($url);

        foreach ($options as $option => $value) {
            \curl_setopt($curl, $option, $value);
        }

        $data = \curl_exec($curl);
        \curl_close($curl);

        return $data;
    }
}
