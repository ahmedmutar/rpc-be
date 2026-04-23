<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class bookingsBreeding extends Model
{
    protected $table = "bookingsBreedings";

    protected $dates = ['created_at', 'deletedAt'];

    protected $guarded = ['id'];

    protected $fillable = [
        'bookingId',
        'stambum',
        'healthClearance',
        'additionalInfo',
        'userId',
        'userUpdateId'
    ];
}
