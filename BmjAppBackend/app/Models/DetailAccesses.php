<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetailAccesses extends Model
{
    /** @use HasFactory<\Database\Factories\DetailBuyFactory> */
    use HasFactory;

    protected $fillable = [
        'accesses_id', 'id_employee'
    ];

    public function Accesses()
    {
        return $this->belongsTo(Accesses::class, 'accesses_id');
    }

    public function Employee()
    {
        return $this->belongsTo(Employee::class, 'id_employee');
    }
}
