<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BranchSparepart extends Pivot
{
    protected $table = 'branch_spareparts';

    protected $fillable = [
        'branch_id',
        'sparepart_id',
        'quantity',
    ];

    public $incrementing = true;
    public $timestamps = true;

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }
}
