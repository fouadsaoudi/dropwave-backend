<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppErrorCode extends Model
{
    protected $table = 'whatsapp_error_codes';

    protected $fillable = [
        'code',
        'subcode',
        'category',
        'title',
        'details',
        'possible_reasons',
        'possible_solutions',
        'client_explanation',
        'client_solution',
        'http_status_code',
    ];

    protected $casts = [
        'http_status_code' => 'integer',
    ];
}
