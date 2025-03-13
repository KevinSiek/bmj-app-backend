<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    /** @use HasFactory<\Database\Factories\QuotationFactory> */
    use HasFactory;

    protected $fillable = [
        'no', 'slug', 'id_customer', 'project', 'type', 'date', 'amount', 'discount', 'subtotal', 'vat', 'total', 'note', 'employee_id', 'status', 'review'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'id_customer');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function detailQuotations()
    {
        return $this->hasMany(DetailQuotation::class, 'id_quotation');
    }

    public function purchaseOrder()
    {
        return $this->hasOne(PurchaseOrder::class, 'id_quotation');
    }

    public function workOrder()
    {
        return $this->hasOne(WorkOrder::class, 'id_quotation');
    }
}
