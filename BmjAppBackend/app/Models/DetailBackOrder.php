<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailBackOrder extends Model
{
    /** @use HasFactory<\Database\Factories\BackOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'id_bo', 'id_spareparts', 'number_delivery_order', 'number_back_order',
    ];
    public function backOrders()
    {
        return $this->belongsTo(BackOrder::class, 'id_bo');
    }
    public function spareparts()
    {
        return $this->belongsTo(Sparepart::class, 'id_spareparts');
    }
}
