<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'work_order_number',
        'received_by',
        'expected_days',
        'expected_start_date',
        'expected_end_date',
        'start_date',
        'end_date',
        'job_descriptions',
        'worker',
        'compiled',
        'head_of_service',
        'approver',
        'is_done',
        'spareparts',
        'backup_sparepart',
        'scope',
        'vaccine',
        'apd',
        'peduli_lindungi'
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
