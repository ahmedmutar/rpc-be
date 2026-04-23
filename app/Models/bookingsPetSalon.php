<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class bookingsPetSalon extends Model
{
    protected $table = "bookingsPetSalons";

    protected $dates = ['created_at', 'deletedAt'];

    protected $guarded = ['id'];

    protected $fillable = [
        'bookingId',
        'furCondition',
        'skinSensitivity',
        'additionalInfo',
        'userId',
        'userUpdateId'
    ];
}
