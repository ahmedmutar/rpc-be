<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class paymentMethodFinance extends Model
{
    protected $table = "paymentMethodFinances";

    protected $dates = ['created_at', 'deletedAt'];

    protected $guarded = ['id'];

    protected $fillable = [
        'paymentMethod',
        'userId',
        'userUpdateId'
    ];
}
