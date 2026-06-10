<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Borrow extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrow_number',
        'branch_id',
        'employee_id',
        'borrower_name',
        'current_status',
        'status',
        'notes',
    ];

    protected $casts = [
        'status' => 'array',
    ];

    public function detailBorrows()
    {
        return $this->hasMany(DetailBorrow::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
