<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeFactory> */
    use HasFactory;

        protected $fillable = [
        'fullname', 'role', 'username', 'password', 'temp_password', 'temp_pass_already_use'
    ];

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function proformaInvoices()
    {
        return $this->hasMany(ProformaInvoice::class);
    }
    public function detailAccesses()
    {
        return $this->hasMany(DetailAccesses::class);
    }
    public function workOrder()
    {
        return $this->hasMany(WorkOrder::class);
    }

}
