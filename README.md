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


`$player = new SteamAuth()` - validates and gets player's info, throws an exception if validation fails

`$player->steamid` - the player's steamid if validated successfully
`$player->info->name` - player's name
`$player->info->onlineState` - player's online state
`$player->info->privacyState` - player's profile visibility
`$player->info->stateMessage` - player's stateMessage
`$player->info->visibilityState` - player's visibilityState


`$player = new SteamAuth($timeout)` - validates and gets info user, timeout defaults to 15

| var | description | example |
| :--- | :--- | ---: |
| $player->steamid | player's 64 bit steamid | 76561198152390718 |
| $player->steamid | player's name | kanalumaddela |

### Example
```
<?php

use kanalumaddela\SteamAuth;


```
