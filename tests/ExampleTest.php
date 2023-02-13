<?php

beforeEach(function () {
    $_SERVER['SERVER_PORT'] = 80;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:80';

    $this->steamLogin = new \kanalumaddela\SteamLogin\SteamLogin();
});

test('my shit works', function () {
    expect(true)->toBeTrue();
});
