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

`SteamAuth::validRequest()` - checks if the URL has the required parameters to validate the post steam login

`$player = new SteamAuth($options)` - SteamAuth instance

#### Properties

**Bolded** - XML method only
*Italicized* - API method only

| var | description | example |
| :------- | :--- | ---: |
| $player->steamid | 64 bit steamid | 76561198152390718 |
| $player->name | name | kanalumaddela |
| $player->realName | real name | Sam |
| $player->playerState | status | Online/Offline |
| $player->stateMessage | status message | Online/Offline **Last Online/In Game <game>** *Busy/Away/Snooze/Looking to <trade/play>* |
| $player->privacyState | profile privacy | Private **Friendsonly** |
| $player->visibilityState | visibility state | <1/2/3> |
| $player->avatarSmall | small avatar | image from (non-https)**cdn.akamai.steamstatic.com** / (https)*steamcdn-a.akamaihd.net*|
| $player->avatarMedium | medium avatar | ^ |
| $player->avatarLarge | large avatar | ^ |
| $player->joined | date of joining steam | January 1st, 2018 (format is consistent with XML method) |

### Example
```
<?php

require_once 'vendor/autoload.php';

use kanalumaddela\SteamAuth\SteamAuth;

echo '<a href="?login">test steam auth</a><br><br>';

if ($_SERVER['QUERY_STRING'] == 'login') {
	header('Location: '.SteamAuth::loginUrl());
}

$options = [
	'timeout' => 30,
	'method' => 'api',
	'api_key' => 'hehe you wish'
];

if (SteamAuth::validRequest()) {
	$user = new SteamAuth($options);
	if ($user->steamid) {
		$_SESSION = (array)$user;
	}
	header('Location: '.$_GET['openid_return_to']);
}

echo '<pre>';
var_dump($_SESSION);

```
