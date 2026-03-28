<?php

namespace App\Models\Customer;

use App\Models\Location\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends Model
{
    use HasFactory;

    protected $table = "customer";

    protected $dates = ['joinDate', 'birthDate', 'created_at', 'updated_at'];

    protected $guarded = ['id'];

    public $timestamps = true;

    protected $fillable = [
        'memberNo',
        'firstName',
        'middleName',
        'lastName',
        'nickName',
        'gender',
        'titleCustomerId',
        'customerGroupId',
        'locationId',
        'notes',
        'colorType',
        'joinDate',
        'typeId',
        'numberId',
        'occupationId',
        'birthDate',
        'referenceCustomerId',
        'isReminderBooking',
        'isReminderPayment',
        'lastTransaction',
        'isDeleted',
        'deletedBy',
        'deletedAt',
        'createdBy',
        'userUpdateId',
        'created_at',
        'updated_at'
    ];

    public function location()
    {
        return $this->belongsTo(Location::class, 'locationId');
    }
}
