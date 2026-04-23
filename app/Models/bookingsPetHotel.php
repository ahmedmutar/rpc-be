<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class bookingsPetHotel extends Model
{
    protected $table = "bookingsPetHotels";

    protected $dates = ['created_at', 'deletedAt'];

    protected $guarded = ['id'];

    protected $fillable = [
        'bookingId',
        'socializationType',
        'emergencyContactName',
        'inventoryProducts',
        'additionalInfo',
        'userId',
        'userUpdateId'
    ];
}
