<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_number',
        'slug',
        'customer_id',
        'project',
        'type',
        'date',
        'amount',
        'discount',
        'subtotal',
        'ppn',
        'grand_total',
        'notes',
        'employee_id',
        'current_status',
        'status', // Added status to fillable
        'review'
    ];

    protected $casts = [
        'status' => 'array' // Cast status as array
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function detailQuotations()
    {
        return $this->hasMany(DetailQuotation::class);
    }

    public function purchaseOrder()
    {
        return $this->hasOne(PurchaseOrder::class);
    }

    public function workOrder()
    {
        return $this->hasOne(WorkOrder::class);
    }
}
