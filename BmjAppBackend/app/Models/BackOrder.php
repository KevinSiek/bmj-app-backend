<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackOrder extends Model
{
    /** @use HasFactory<\Database\Factories\BackOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'id_po', 'number_delivery_order', 'number_back_order', 'status'
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'id_po');
    }

    public function buys()
    {
        return $this->hasMany(Buy::class, 'id_bo');
    }
}
