<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'back_order_number',
        'status',
        'employee_id'
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function detailBackOrders()
    {
        return $this->hasMany(DetailBackOrder::class);
    }

    public function buy()
    {
        return $this->hasOne(Buy::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
