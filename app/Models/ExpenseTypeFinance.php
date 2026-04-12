<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseTypeFinance extends Model
{
    protected $table = "expenseTypeFinances";

    protected $dates = ['created_at', 'deletedAt'];

    protected $guarded = ['id'];

    protected $fillable = [
        'expenseType',
        'userId',
        'userUpdateId'
    ];
}
