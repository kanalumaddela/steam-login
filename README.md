# steam-login

[![Packagist](https://img.shields.io/packagist/dt/kanalumaddela/steam-login.svg?style=flat-square)](https://packagist.org/packages/kanalumaddela/steam-login)
[![Packagist version](https://img.shields.io/packagist/v/kanalumaddela/steam-login.svg?style=flat-square)](https://packagist.org/packages/kanalumaddela/steam-login)
[![PHP from Packagist](https://img.shields.io/packagist/php-v/kanalumaddela/steam-login.svg?style=flat-square)]()
[![GitHub stars](https://img.shields.io/github/stars/kanalumaddela/steam-login.svg?style=flat-square)](https://github.com/kanalumaddela/steam-login/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/kanalumaddela/steam-login.svg?style=flat-square)](https://github.com/kanalumaddela/steam-login/network)
[![GitHub issues](https://img.shields.io/github/issues/kanalumaddela/steam-login.svg?style=flat-square)](https://github.com/kanalumaddela/steam-login/issues)
[![GitHub license](https://img.shields.io/github/license/kanalumaddela/steam-login.svg?style=flat-square)](https://github.com/kanalumaddela/steam-login/blob/master/LICENSE)

All in one Steam Authentication library.  
Do you use Laravel? See [laravel-steam-login](https://github.com/kanalumaddela/laravel-steam-login)
 
 Features
   - easy to use
   - quickly get up and running without configuring anything
   - can be used purely for validation or as all in one
   - session management
   - steamid conversions + steamid profile retrieval via 2 different methods
 
```
composer require kanalumaddela/steam-login
```
https://github.com/kanalumaddela/steam-login/wiki/Getting-Started

---

### Example (quick run)

*this example is not setting sessions or redirecting after validation, its purpose is to show users who want to handle that part themselves*

```php
<?php

require_once __DIR__.'/vendor/autoload.php';

use kanalumaddela\SteamLogin\SteamLogin;


// init instance
$steamlogin = new SteamLogin();

// redirect to steam
if ($_SERVER['QUERY_STRING'] == 'login') {
    $steamlogin->login();
}

// logout
if ($_SERVER['QUERY_STRING'] == 'logout') {
    $steamlogin->logout();
}

if (SteamLogin::validRequest()) {
    $player = $steamlogin->getPlayerInfo();
    echo '<pre>';
    print_r($player);
    echo '</pre>';
} else {
    echo '<a href="?login">'.SteamLogin::button('large', true).'</a>';
}
```

---

### Example (with sessions)

*for sessions to be enabled, you must pass the `$options['sessions']` array, you don't have to fill everything in as the lib can do this for you*

```php
<?php

require_once __DIR__.'/vendor/autoload.php';

use kanalumaddela\SteamLogin\SteamLogin;

$options = [
    'method' => 'xml',
    'api_key' => '',
    'timeout' => 15,
    'session' => [
        'name' => 'My Site', // gets converted to snake case e.g. My Site -> My_Site
        'lifetime' => 0,
        'path' => str_replace(basename(__FILE__), '', $_SERVER['PHP_SELF']),
        'secure' => isset($_SERVER["HTTPS"])
    ],
];

// init instance
$steamlogin = new SteamLogin($options);

// redirect to steam
if ($_SERVER['QUERY_STRING'] == 'login') {
    $steamlogin->login();
}

// logout
if ($_SERVER['QUERY_STRING'] == 'logout') {
    $steamlogin->logout();
}

// show login or user info
if (!isset($_SESSION['steamid'])) {
    echo '<a href="?login">'.SteamLogin::button('large', true).'</a>';
} else {
    echo '<a href="?logout">Logout</a>';
    echo '<pre>';
    print_r($_SESSION);
    echo '</pre>';
}
```
