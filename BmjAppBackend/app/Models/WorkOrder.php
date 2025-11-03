<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'work_order_number',
        'received_by',
        'expected_days',
        'expected_start_date',
        'expected_end_date',
        'start_date',
        'end_date',
        'current_status',
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
        'peduli_lindungi',
        'execution_time',
        'notes'
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function woUnits()
    {
        return $this->hasMany(WoUnit::class, 'id_wo');
    }
}
