<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Buy extends Model
{
    use HasFactory;

    protected $fillable = [
        'buy_number',
        'total_amount',
        'review',
        'current_status',
        'notes',
        'back_order_id',
        'branch_id',
    ];

    public function detailBuys()
    {
        return $this->hasMany(DetailBuy::class);
    }

    public function backOrder()
    {
        return $this->belongsTo(BackOrder::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
