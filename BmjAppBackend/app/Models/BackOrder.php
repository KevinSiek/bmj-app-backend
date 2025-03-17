<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackOrder extends Model
{
    /** @use HasFactory<\Database\Factories\BackOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'id_po', 'no_bo', 'status'
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'id_po');
    }

    public function detailBackOrders()
    {
        return $this->hasMany(DetailBackOrder::class, 'id_bo');
    }
}
