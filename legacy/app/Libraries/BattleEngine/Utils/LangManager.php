<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\BattleEngine\Utils;

class LangManager
{
    private ?Lang $impl = null;
    private static ?LangManager $instance = null;

    public function setImplementation(Lang $implementation): void
    {
        $this->impl = $implementation;
    }

    public static function getInstance(): self
    {
        if (empty(self::$instance)) {
            self::$instance = new LangManager();
        }
        return self::$instance;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        $callback = [$this->impl, $name];
        if (empty($this->impl) || !is_callable($callback)) {
            if (empty($arguments)) {
                return $name;
            }
            return $arguments[0];
        }
        return call_user_func_array($callback, $arguments);
    }

    public function implementationExist(): bool
    {
        return !empty($this->impl);
    }
}
