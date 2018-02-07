# steam-login

All in one Steam Authentication library.
 
 Features
   - easy to use
   - quickly get up and running without configuring anything
   - can be used purely for validation or as all in one
   - session management
   - steamid conversions + steamid profile retrieval via 2 different methods
 
 ***Tested on PHP 7, haven't checked if it works on 5.6***

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
    $player = $steamlogin->player;
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
        'name' => 'SteamLogin', // gets converted to snake case e.g. My Site -> My_Site
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