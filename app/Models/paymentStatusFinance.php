<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class paymentStatusFinance extends Model
{
    protected $table = "paymentStatusFinances";

    protected $dates = ['created_at', 'deletedAt'];

    protected $guarded = ['id'];

    protected $fillable = [
        'paymentStatus',
        'userId',
        'userUpdateId'
    ];
}
