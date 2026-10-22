<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Instances\InstanceRegistry;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An operator of the server (base database): manages the universes on
 * /operator. Not an account of any universe; two-factor sign-in is always on.
 *
 * @property int         $id
 * @property string      $email
 * @property string      $password
 * @property string|null $two_factor_secret         decrypted on read
 * @property string|null $two_factor_recovery_codes JSON list of SHA-256 hashes
 * @property int|null    $two_factor_confirmed_at
 * @property int|null    $two_factor_last_step
 * @property int|null    $last_login_at
 * @property string|null $last_login_ip
 * @property int         $created_at
 */
class Operator extends Authenticatable
{
    public $timestamps = false;

    /** @var string|null */
    protected $connection = InstanceRegistry::CONNECTION;

    /** @var string */
    protected $table = 'operators';

    /** @var list<string> */
    protected $fillable = ['email', 'password', 'created_at'];

    /** @var list<string> */
    protected $hidden = ['password', 'two_factor_secret', 'two_factor_recovery_codes'];

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'int',
        'password' => 'hashed',
        'two_factor_secret' => 'encrypted',
        'two_factor_confirmed_at' => 'int',
        'two_factor_last_step' => 'int',
        'last_login_at' => 'int',
        'created_at' => 'int',
    ];

    /** no "remember me": every sign-in asks for the code */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null && $this->two_factor_secret !== '';
    }
}
