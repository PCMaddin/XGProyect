<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dark matter bought with real money (see App\Services\Premium\DarkMatterOrders).
 *
 * @property int $id
 * @property int $user_id
 * @property string $package
 * @property int $dark_matter
 * @property int $amount in the smallest unit of the currency (cents)
 * @property string $currency lower case, as Stripe writes it (eur)
 * @property string $provider
 * @property string|null $provider_session
 * @property string|null $provider_payment
 * @property string $status
 * @property int $created_at
 * @property int|null $paid_at
 * @property int|null $refunded_at
 */
class DarkMatterOrder extends Model
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const EXPIRED = 'expired';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';

    /**
     * @var string
     */
    protected $table = 'dark_matter_orders';

    /**
     * The game keeps unix seconds, not Laravel's timestamps.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'package',
        'dark_matter',
        'amount',
        'currency',
        'provider',
        'provider_session',
        'provider_payment',
        'status',
        'created_at',
        'paid_at',
        'refunded_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'dark_matter' => 'int',
        'amount' => 'int',
        'created_at' => 'int',
        'paid_at' => 'int',
        'refunded_at' => 'int',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
