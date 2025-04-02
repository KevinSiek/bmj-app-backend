<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailBackOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'back_order_id', 'sparepart_id', 'number_delivery_order', 'number_back_order'
    ];

    public function backOrder()
    {
        return $this->belongsTo(BackOrder::class);
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }
}
