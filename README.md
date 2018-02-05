# steam-login

### Installation
We use composer bois, but I'm too lazy to put this on Packagist
```
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/kanalumaddela/steam-login"
        }
    ],
    "require": {
        "kanalumaddela/steam-login": "dev-master"
    }
}
```

### Usage
Be sure to add `use kanalumaddela\SteamAuth;` in your project.

`SteamAuth::loginUrl($return)` - generates a login with an **optional** return url, if `$return` is not specified, it'll use the current request.

---

### Validation

`$player = $steamauth->player` - player details

## Docs

`SteamLogin::button($type)` - returns the image url for the sign in through steam button  

`small` - ![](https://steamcommunity-a.akamaihd.net/public/images/signinthroughsteam/sits_01.png)
 
`large` - ![](https://steamcommunity-a.akamaihd.net/public/images/signinthroughsteam/sits_02.png)

**Bolded** - XML method only  
*Italicized* - API method only

| var                      | description           | example |
| :---                     | :---                  | ---: |
| $player->steamid         | 64 bit steamid        | 76561198152390718 |
| $player->steamid2        | 32 bit steamid        | STEAM_0:0:96062495 |
| $player->steamid3        | SteamID3              | [U:1:192124990] |
| $player->name            | name                  | kanalumaddela |
| $player->realName        | real name             | Sam |
| $player->playerState     | status                | Online/Offline |
| $player->stateMessage    | status message        | Online/Offline <br> **Last Online/In Game <game>** <br> *Busy/Away/Snooze/Looking to <trade/play>* |
| $player->privacyState    | profile privacy       | Private <br> **Friendsonly** |
| $player->visibilityState | visibility state      | <1/2/3> |
| $player->avatarSmall     | small avatar          | avatar url <br> **cdn.akamai.steamstatic.com** (http) <br> *steamcdn-a.akamaihd.net* (https) |
| $player->avatarMedium    | medium avatar         | ^ |
| $player->avatarLarge     | large avatar          | ^ |
| $player->joined          | date of joining steam | January 1st, 2018 (format is consistent XML method) |
---

### Example
```
<?php

session_start();
require_once __DIR__'/vendor/autoload.php';
use kanalumaddela\SteamAuth\SteamAuth;

echo '<a href="?login">login w steam</a><br><br>';

if ($_SERVER['QUERY_STRING'] == 'login') {
	header('Location: '.SteamAuth::loginUrl());
}

$options = [
	'timeout' => 30,
	'method' => 'api',
	'api_key' => 'hehe you wish'
];

// init instance
$steamauth = new SteamAuth($options);

if ($steamauth->validate()) {
    $user = $steamauth->player;
	if ($user->steamid) { // if steamid is null, validation failed
		$_SESSION = (array)$user; // convert object to array to be saved into session
	}
	header('Location: '.$_GET['openid_return_to']); // redirect 
}

echo '<pre>';
var_dump($_SESSION);

```
