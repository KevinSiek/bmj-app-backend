<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProformaInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'proforma_invoice_number',
        'proforma_invoice_date',
        'advance_payment',
        'grand_total',
        'total_amount',
        'total_amount_text',
        'employee_id'
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
