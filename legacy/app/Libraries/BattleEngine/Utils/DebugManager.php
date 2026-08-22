<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\Utils;

/**
 *  OPBE
 *  Copyright (C) 2015  Jstar
 *
 * This file is part of OPBE.
 *
 * OPBE is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OPBE is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with OPBE.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package OPBE
 *
 * @author Jstar <frascafresca@gmail.com>
 * @copyright 2015 Jstar <frascafresca@gmail.com>
 * @license http://www.gnu.org/licenses/ GNU AGPLv3 License
 *
 * @version 6-3-2015
 *
 * @link https://github.com/jstar88/opbe
 *
 * @SuppressWarnings("PHPMD.Superglobals")
 */
class DebugManager
{
    public static function intercept(callable $toIntercept, callable $newFunction): \Closure
    {
        return function () use ($toIntercept, $newFunction) {
            $newFunction();
            return call_user_func_array($toIntercept, func_get_args());
        }
        ;
    }

    /**
     * DebugManager::runDebugged()
     * Return a new function that will run the function given as argument under debug
     */
    public static function runDebugged(callable $func, ?callable $errorHandler = null, ?callable $exceptionHandler = null): \Closure
    {
        if ($errorHandler == null) {
            $errorHandler = [DebugManager::class, 'myErrorHandler'];
        }

        if ($exceptionHandler == null) {
            $exceptionHandler = [DebugManager::class, 'save'];
        }

        return function () use ($func, $errorHandler, $exceptionHandler) {
            set_error_handler($errorHandler);
            set_exception_handler($exceptionHandler);
            $return = call_user_func_array($func, func_get_args());
            restore_exception_handler();
            restore_error_handler();

            return $return;
        }
        ;
    }

    /**
     * DebugManager::myErrorHandler()
     * default error handler function
     */
    public static function myErrorHandler(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $error = '';
        switch ($errno) {
            case E_USER_ERROR:
                $error .= "ERROR [$errno] $errstr" . PHP_EOL;
                break;

            case E_USER_WARNING:
                $error .= "WARNING [$errno] $errstr" . PHP_EOL;
                break;

            case E_USER_NOTICE:
                $error .= "NOTICE [$errno] $errstr" . PHP_EOL;
                break;

            default:
                $error .= "Unknown error type: [$errno] $errstr" . PHP_EOL;
                break;
        }
        $error .= "Error on line $errline in file $errfile";
        $error .= ', PHP ' . PHP_VERSION . ' (' . PHP_OS . ')' . PHP_EOL;
        DebugManager::save($error);
        /* Don't execute PHP internal error handler */
        return true;
    }

    /**
     * DebugManager::save()
     * default exception handler function
     *
     * @param mixed $other
     */
    public static function save(mixed $other): void
    {
        date_default_timezone_set(TIMEZONE);
        $time = date('l jS \of F Y h:i:s A');
        $post = '$_POST =' . var_export($_POST, true);
        $get = '$_GET =' . var_export($_GET, true);
        $output = (string) ob_get_clean();
        $otherStr = (is_object($other) && method_exists($other, '__toString')) || is_scalar($other)
            ? (string) $other
            : print_r($other, true);
        if (!file_exists(OPBEPATH . 'errors')) {
            mkdir(OPBEPATH . 'errors', 0777, true);
        }
        file_put_contents(OPBEPATH . 'errors' . DIRECTORY_SEPARATOR . date('d-m-y__H-i-s') . '.html', $time . PHP_EOL . $otherStr . PHP_EOL . $post . PHP_EOL . $get . PHP_EOL . $output);
        die('An error occurred, we will resolve it soon as possible');
    }
}
