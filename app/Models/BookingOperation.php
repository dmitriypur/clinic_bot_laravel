<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingOperation extends Model
{
    protected $fillable = [
        'client_id', 'operation_key', 'onec_request_id', 'request_hash',
        'branch_id', 'doctor_id', 'cabinet_id', 'starts_at', 'ends_at',
        'status', 'result_code', 'result_http_status', 'application_id',
    ];

    public function reject(string $code, int $httpStatus): void
    {
        $this->update(['status' => 'rejected', 'result_code' => $code, 'result_http_status' => $httpStatus]);
    }
}
