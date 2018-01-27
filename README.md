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


`$SteamAuth->validate()` - validates the request and returns a user's steamid (64 bit)
`$SteamAuth->userInfo()` - retrieves a user's info via XML from their profile

### Example
```
<?php

use kanalumaddela\SteamAuth;


```
