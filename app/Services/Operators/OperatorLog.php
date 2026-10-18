<?php

declare(strict_types=1);

namespace App\Services\Operators;

use App\Models\Operator;
use App\Services\Instances\InstanceRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What happened on /operator: sign-ins (also failed ones), and every change
 * to a universe, with who, from where and when.
 */
class OperatorLog
{
    public const LOGIN = 'login';
    public const LOGIN_FAILED = 'login_failed';
    public const CODE_FAILED = 'code_failed';
    public const LOGOUT = 'logout';
    public const CREATED = 'universe_created';
    public const CHANGED = 'universe_changed';
    public const CLOSED = 'universe_closed';
    public const PAYOUT = 'payout_reserved';
    public const RELEASED = 'payout_released';
    public const PASSWORD = 'password_changed';
    public const CODES = 'codes_renewed';
    public const ACCOUNT_CREATED = 'operator_created';
    public const ACCOUNT_RESET = 'operator_reset';
    public const ACCOUNT_DELETED = 'operator_deleted';

    /**
     * @param array<string, mixed> $details
     */
    public function record(?Operator $operator, string $email, string $action, string $ip, ?string $subject = null, array $details = []): void
    {
        $this->table()->insert([
            'operator_id' => $operator?->id,
            'email' => mb_substr($email, 0, 254),
            'action' => $action,
            'subject' => $subject === null ? null : mb_substr($subject, 0, 64),
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => mb_substr($ip, 0, 45),
            'created_at' => time(),
        ]);
    }

    /**
     * @return list<array{email: string, action: string, subject: string, details: string, ip: string, created_at: int}>
     */
    public function latest(int $limit): array
    {
        return array_values($this->table()->orderByDesc('id')->limit($limit)->get()->map(static function (object $row): array {
            $values = get_object_vars($row);

            return [
                'email' => is_string($values['email'] ?? null) ? $values['email'] : '',
                'action' => is_string($values['action'] ?? null) ? $values['action'] : '',
                'subject' => is_string($values['subject'] ?? null) ? $values['subject'] : '',
                'details' => is_string($values['details'] ?? null) ? $values['details'] : '',
                'ip' => is_string($values['ip'] ?? null) ? $values['ip'] : '',
                'created_at' => is_numeric($values['created_at'] ?? null) ? (int) $values['created_at'] : 0,
            ];
        })->all());
    }

    /**
     * Failed sign-ins from an address since a time: a lock beyond the rate limit.
     */
    public function failuresSince(string $ip, int $since): int
    {
        return $this->table()->where('ip', $ip)->whereIn('action', [self::LOGIN_FAILED, self::CODE_FAILED])->where('created_at', '>=', $since)->count();
    }

    private function table(): Builder
    {
        return DB::connection(InstanceRegistry::CONNECTION)->table('operator_events');
    }
}
