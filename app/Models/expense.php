<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class expense extends Model
{
    protected $table = "expenses";

    protected $dates = ['created_at', 'deletedAt'];

    protected $guarded = ['id'];

    protected $fillable = [
        'transactionDate',
        'referenceNo',
        'vendorId',
        'locationId',
        'subTotal',
        'tax',
        'pph',
        'grandTotal',
        'categoryId',
        'expenseTypeId',
        'departmentId',
        'paymentStatusId',
        'dueDate',
        'paymentMethodId',
        'description',
        'realImageName',
        'imagePath',
        'statusApproval',
        'userApprovalId',
        'approvalAt',
        'userId',
        'userUpdateId'
    ];
}
