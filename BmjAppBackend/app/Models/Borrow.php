<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Borrow extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrow_number',
        'branch_id',
        'employee_id',
        'purchase_order_id',
        'sparepart_po_id',
        'current_status',
        'status',
        'notes',
        'return_notes',
        'reject_notes',
    ];

    protected $casts = [
        'status' => 'array',
    ];

    public function detailBorrows()
    {
        return $this->hasMany(DetailBorrow::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    // Spareparts-type PO justifying a returned-short Pinjaman (set at reconciliation).
    public function sparepartPo()
    {
        return $this->belongsTo(PurchaseOrder::class, 'sparepart_po_id');
    }
}
