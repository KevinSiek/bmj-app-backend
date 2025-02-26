<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Accesses extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeFactory> */
    use HasFactory;

        protected $fillable = [
        'access'
    ];

    public function detailAccesses()
    {
        return $this->hasMany(DetailAccesses::class);
    }

}
