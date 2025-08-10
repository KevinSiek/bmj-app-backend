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
        'down_payment',
        'grand_total',
        'total_amount',
        'is_dp_paid',
        'is_full_paid',
        'total_amount_text',
        'employee_id',
        'notes'
    ];

    protected $casts = [
        'is_dp_paid' => 'boolean',
        'is_full_paid' => 'boolean',
        'proforma_invoice_date' => 'date',
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
        return $this->hasOne(Invoice::class);
    }
}
