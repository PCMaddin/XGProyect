<?php

declare(strict_types=1);

namespace App\Libraries\Users;

use Xgp\App\Helpers\StringsHelper;

/**
 * Fleet shortcut list, persisted as a JSON string on the user row.
 *
 * The legacy version called die() whenever JSON decoding or validation failed
 * and getById() returned 0 despite an array return type. This one degrades
 * gracefully and normalises every entry to a fixed shape, so callers always
 * receive well-typed records.
 *
 * @phpstan-type Shortcut array{name: string, g: int, s: int, p: int, pt: int}
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Shortcuts
{
    /** @var list<Shortcut> */
    private array $shortcuts = [];

    public function __construct(?string $shortcuts)
    {
        if ($shortcuts !== null && $shortcuts !== '') {
            $this->setShortcuts($shortcuts);
        }
    }

    private function setShortcuts(string $shortcuts): void
    {
        $decoded = json_decode($shortcuts, true);

        if (!is_array($decoded)) {
            return;
        }

        $normalized = [];

        foreach ($decoded as $entry) {
            $record = $this->normalize($entry);

            if ($record !== null) {
                $normalized[] = $record;
            }
        }

        $this->shortcuts = $normalized;
    }

    /**
     * @return list<Shortcut>
     */
    public function addNew(string $name, int $galaxy, int $system, int $planet, int $planetType): array
    {
        if ($name === '' || $galaxy === 0 || $system === 0 || $planet === 0 || $planetType === 0) {
            return $this->shortcuts;
        }

        $this->shortcuts[] = $this->build($name, $galaxy, $system, $planet, $planetType);

        return $this->shortcuts;
    }

    /**
     * @return list<Shortcut>
     */
    public function editById(int $shortcutId, string $name, int $galaxy, int $system, int $planet, int $planetType): array
    {
        if (!isset($this->shortcuts[$this->validateShortcutId($shortcutId)])) {
            return $this->shortcuts;
        }

        $this->shortcuts[$shortcutId] = $this->build($name, $galaxy, $system, $planet, $planetType);

        return $this->shortcuts;
    }

    /**
     * @return list<Shortcut>
     */
    public function deleteById(int $shortcutId): array
    {
        array_splice($this->shortcuts, $this->validateShortcutId($shortcutId), 1);

        return $this->shortcuts;
    }

    /**
     * @return list<Shortcut>
     */
    public function getAllAsArray(): array
    {
        return $this->shortcuts;
    }

    public function getAllAsJsonString(): string
    {
        $json = json_encode($this->shortcuts);

        return $json !== false ? $json : '[]';
    }

    /**
     * @return Shortcut|array{}
     */
    public function getById(int $shortcutId): array
    {
        return $this->shortcuts[$shortcutId] ?? [];
    }

    /**
     * @return Shortcut
     */
    private function build(string $name, int $galaxy, int $system, int $planet, int $planetType): array
    {
        return [
            'name' => StringsHelper::escapeString(strip_tags($name)),
            'g' => $galaxy,
            's' => $system,
            'p' => $planet,
            'pt' => $planetType,
        ];
    }

    /**
     * @return Shortcut|null
     */
    private function normalize(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $name = $entry['name'] ?? '';

        return [
            'name' => is_scalar($name) ? (string) $name : '',
            'g' => $this->toInt($entry['g'] ?? 0),
            's' => $this->toInt($entry['s'] ?? 0),
            'p' => $this->toInt($entry['p'] ?? 0),
            'pt' => $this->toInt($entry['pt'] ?? 0),
        ];
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function validateShortcutId(int $shortcutId): int
    {
        if ($shortcutId < 0) {
            return 0;
        }

        if ($shortcutId > count($this->shortcuts)) {
            return count($this->shortcuts) - 1;
        }

        return $shortcutId;
    }
}
