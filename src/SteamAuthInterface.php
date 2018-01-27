<?php

namespace kanalumaddela\SteamAuth;

interface SteamAuthInterface {
	public static function loginUrl($return);
	public function validate($timeout);
}