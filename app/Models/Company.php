<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['smtp'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'settings' => 'array', 'smtp' => 'encrypted:array', 'subscription_until' => 'date'];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function enabled(string $module): bool
    {
        if ($module === 'cash' && in_array('sales', $this->modules ?? [])) {
            return true;
        }

        return array_key_exists($module, config('poseitech.modules')) && in_array($module, $this->modules ?? []);
    }

    public function available(): bool
    {
        return $this->status === 'active' && (! $this->subscription_until || $this->subscription_until->endOfDay()->isFuture());
    }
}
