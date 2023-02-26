<?php

beforeEach(function () {
    $_SERVER['SERVER_PORT'] = 80;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:80';

//    $options = [
//        'allowed_hosts' => [
//            'localhost',
//            '127.0.0.1',
//        ]
//    ];

    $this->steamLogin = new \kanalumaddela\SteamLogin\SteamLogin();
});

test('test types', function () {
    $this->assertIsBool($this->steamLogin->isDebug());

    // $this->options
    $options = $this->steamLogin->getOptions();
    $this->assertIsArray($options);
    $this->assertIsString($options['method']);
    $this->assertIsInt($options['timeout']);
    $this->assertIsBool($options['steam_universe']);
    $this->assertIsBool($options['retrieve_info']);
    $this->assertIsBool($options['automatic_redirect']);
    $this->assertIsBool($options['automatic_validation']);
    $this->assertIsArray($this->steamLogin->getAllowedHosts());

    $this->assertIsArray($options['session']);
    $this->assertIsBool($options['session']['enable']);
    $this->assertIsString($options['session']['key']);

    // $this->sites[]
    $this->assertIsArray($this->steamLogin->getSite());
    $this->assertIsBool($this->steamLogin->isSecure());
    $this->assertIsInt($this->steamLogin->getPort());
    $this->assertIsString($this->steamLogin->getDomain());
    $this->assertIsString($this->steamLogin->getPath());
    $this->assertIsString($this->steamLogin->getHome());

    $this->assertNull($this->steamLogin->getApiKey());
    $this->assertNull($this->steamLogin->getApiResponse());
    $this->assertNull($this->steamLogin->getOpenIdResponse());
    $this->assertIsString($this->steamLogin->getReturnTo());
    $this->assertIsString($this->steamLogin->getLoginUrl());
    $this->assertIsString($this->steamLogin->getRedirectTo());
});

test('stuff', function () {
    $this->assertEquals($_SERVER['SERVER_PORT'], $this->steamLogin->getPort());

    $domain = 'localhost';

    $this->steamLogin->setDomain($domain);
    $this->assertEquals($domain, $this->steamLogin->getDomain());
});