<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'purchase_order_number',
        'purchase_order_date',
        'payment_due',
        'employee_id',
        'current_status',
        'notes',
        'version'
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function proformaInvoice()
    {
        return $this->hasOne(ProformaInvoice::class);
    }

    public function backOrders()
    {
        return $this->hasOne(BackOrder::class);
    }

    public function workOrder()
    {
        return $this->hasOne(WorkOrder::class);
    }

    public function deliveryOrder()
    {
        return $this->hasOne(DeliveryOrder::class);
    }
}
