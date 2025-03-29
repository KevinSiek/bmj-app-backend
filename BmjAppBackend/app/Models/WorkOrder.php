<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    /** @use HasFactory<\Database\Factories\QuotationFactory> */
    use HasFactory;

    protected $fillable = [
        'id_quotation', 'no_wo', 'received_by', 'expexted_day', 'expected_start_date', 'expected_end_date', 'compiled_by', 'start_date', 'end_date', 'job_descriptions', 'work_peformed_by', 'approved_by', 'additional_components'
    ];


    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'id_quotation');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }





}
