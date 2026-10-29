<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Security\Suspicions;
use App\Services\TimingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Signs of multiple accounts, pushing and scripts (xgp:cheat:scan) for the
 * admin to decide on: clear them, or exclude the player from the season's
 * rankings and prizes.
 */
class SuspicionsController extends BaseController
{
    public function __construct(private Suspicions $suspicions, private TimingService $timing)
    {
    }

    public function index(): View
    {
        return view('admin.suspicions', [
            'suspicions' => array_map(fn (array $row): array => [
                'id' => self::int($row['id'] ?? 0),
                'user' => self::text($row['user_name'] ?? '') ?: '#' . self::int($row['user_id'] ?? 0),
                'user_id' => self::int($row['user_id'] ?? 0),
                'other' => self::int($row['other_user'] ?? 0) === 0 ? '' : (self::text($row['other_name'] ?? '') ?: '#' . self::int($row['other_user'] ?? 0)),
                'rank' => self::int($row['user_rank'] ?? 0),
                'kind' => self::text($row['kind'] ?? ''),
                'score' => self::int($row['score'] ?? 0),
                'detail' => self::detail(self::text($row['detail'] ?? '')),
                'status' => self::text($row['status'] ?? ''),
                'note' => self::text($row['note'] ?? ''),
                'updated_at' => $this->timing->formatExtendedDate(self::int($row['updated_at'] ?? 0)),
            ], $this->suspicions->all()),
        ]);
    }

    public function decide(Request $request, int $suspicion): RedirectResponse
    {
        $status = $request->input('status');
        $note = $request->input('note');
        $done = is_string($status) && $this->suspicions->decide($suspicion, $status, is_string($note) ? mb_substr($note, 0, 500) : '', time());

        return redirect()->route('admin.suspicions')->with($done ? 'success' : 'danger', __($done ? 'admin/suspicions.su_saved' : 'admin/suspicions.su_not_saved'));
    }

    /**
     * "resources: 150000, logins: 4" from the stored JSON.
     */
    private static function detail(string $json): string
    {
        $values = json_decode($json, true);

        if (!is_array($values)) {
            return '';
        }

        $parts = [];

        foreach ($values as $key => $value) {
            $parts[] = __('admin/suspicions.su_detail_' . $key) . ': ' . (is_numeric($value) ? number_format((float) $value, 0, ',', '.') : (is_string($value) ? $value : ''));
        }

        return implode(', ', $parts);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
