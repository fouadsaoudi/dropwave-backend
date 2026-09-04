<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToTenant;

class OptOut extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id',
        'phone_number',
        'opted_out_at',
        'source',
    ];

    protected $casts = [
        'opted_out_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
