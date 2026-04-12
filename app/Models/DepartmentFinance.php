<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepartmentFinance extends Model
{
    protected $table = "departmentFinances";

    protected $dates = ['created_at', 'deletedAt'];

    protected $guarded = ['id'];

    protected $fillable = [
        'departmentName',
        'userId',
        'userUpdateId'
    ];
}
