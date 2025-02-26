<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProformaInvoice extends Model
{
    /** @use HasFactory<\Database\Factories\ProformaInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'id_po', 'pi_number', 'pi_date', 'advance_payment', 'total', 'total_amount', 'total_amount_text', 'employee_id'
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'id_po');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'id_pi');
    }
}
