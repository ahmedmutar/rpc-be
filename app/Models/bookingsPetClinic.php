<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class bookingsPetClinic extends Model
{
    protected $table = "bookingsPetClinics";

    protected $dates = ['created_at', 'deletedAt'];

    protected $guarded = ['id'];

    protected $fillable = [
        'bookingId',
        'consultationType',
        'drugAllergy',
        'additionalInfo',
        'userId',
        'userUpdateId'
    ];
}
