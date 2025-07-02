<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'type',
        'current_status',
        'notes',
        'work_order_number',
        'delivery_order_date',
        'received_by',
        'picked_by',
        'ship_mode',
        'order_type',
        'delivery',
        'npwp',
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }
}
