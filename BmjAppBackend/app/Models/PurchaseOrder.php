<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    /** @use HasFactory<\Database\Factories\PurchaseOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'id_quotation', 'po_number', 'po_date', 'employee_id'
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'id_quotation');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function proformaInvoices()
    {
        return $this->hasMany(ProformaInvoice::class, 'id_po');
    }

    public function backOrders()
    {
        return $this->hasMany(BackOrder::class, 'id_po');
    }
}
