<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailBorrow extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrow_id',
        'sparepart_id',
        'quantity',
        'quantity_return',
    ];

    public function borrow()
    {
        return $this->belongsTo(Borrow::class);
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }
}
