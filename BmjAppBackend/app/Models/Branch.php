<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
    ];

    public function spareparts()
    {
        return $this->belongsToMany(Sparepart::class, 'branch_spareparts')
            ->using(BranchSparepart::class)
            ->withPivot(['quantity'])
            ->withTimestamps();
    }

    public function sparepartStocks()
    {
        return $this->hasMany(BranchSparepart::class);
    }
}
