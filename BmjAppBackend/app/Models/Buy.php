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
        'status',
        'notes',
    ];

    public function detailBuys()
    {
        return $this->hasMany(DetailBuy::class);
    }
}
