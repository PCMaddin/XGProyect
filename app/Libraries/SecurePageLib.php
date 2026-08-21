<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Legacy request-input sanitiser: run once during the legacy bootstrap, it
 * walks the superglobals and neutralises `script` and HTML entities. Native
 * requests go through Laravel's own input handling and never reach this.
 *
 * @SuppressWarnings("PHPMD.Superglobals")
 */
class SecurePageLib
{
    private static ?self $instance = null;

    public function __construct()
    {
        $_GET = array_map([$this, 'validate'], $_GET);
        $_POST = array_map([$this, 'validate'], $_POST);
        $_REQUEST = array_map([$this, 'validate'], $_REQUEST);
        $_SERVER = array_map([$this, 'validate'], $_SERVER);
        $_COOKIE = array_map([$this, 'validate'], $_COOKIE);
    }

    private function validate(mixed $value): mixed
    {
        if (is_array($value)) {
            // Recurse while keeping the keys — the legacy version renumbered
            // nested arrays, which silently corrupted nested form input.
            foreach ($value as $key => $entry) {
                $value[$key] = $this->validate($entry);
            }

            return $value;
        }

        $value = str_ireplace('script', 'blocked', is_scalar($value) ? (string) $value : '');

        return htmlentities($value, ENT_QUOTES, 'UTF-8', false);
    }

    public static function run(): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
    }
}
