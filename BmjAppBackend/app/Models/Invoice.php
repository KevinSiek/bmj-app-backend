<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'id_pi', 'invoice_number', 'invoice_date', 'term_of_pay', 'employee_id'
    ];

    public function proformaInvoice()
    {
        return $this->belongsTo(ProformaInvoice::class, 'id_pi');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
