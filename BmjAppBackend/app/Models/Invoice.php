<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'proforma_invoice_id',
        'invoice_number',
        'invoice_date',
        'term_of_payment',
        'employee_id'
    ];

    public function proformaInvoice()
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
