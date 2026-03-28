<?php

/**
 * Write code on Method
 *
 * @return response()
 */

use App\Models\Customer\Customer;
use App\Models\ProductLog;
use App\Models\Transaction;
use App\Models\ProductSellLog;
use App\Models\recentActivity;
use App\Models\TransactionLog;
use App\Models\ProductClinicLog;
use App\Models\productRestockLog;
use App\Models\ProductTransferLog;
use App\Models\TransactionBreeding;
use App\Models\TransactionPetHotel;
use App\Models\TransactionPetClinic;
use App\Models\TransactionPetshopLog;
use App\Models\TransactionBreedingLog;
use App\Models\TransactionPetHotelLog;
use App\Models\TransactionPetClinicLog;
use App\Models\transactionpetsalon;
use App\Models\transactionPetSalonLog;
use Carbon\Carbon;

if (!function_exists('adminAccess')) {
    function adminAccess($id)
    {
        $status = false;

        $user = DB::table('users as u')
            ->leftjoin('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select('u.id', 'ur.roleName')
            ->where('u.id', '=', $id)
            ->first();

        if ($user->roleName == "Administrator" || $user->roleName == "Manager") {
            $status = true;
        }

        return $status;
    }
}

if (!function_exists('officeAccess')) {
    function officeAccess($id)
    {

        $user = DB::table('users as u')
            ->leftjoin('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select('u.id', 'ur.roleName')
            ->where('u.id', '=', $id)
            ->first();

        if ($user->roleName != "Office") {
            return false;
        } else {
            return true;
        }
    }
}


if (!function_exists('managerAccess')) {
    function managerAccess($id)
    {
        $user = DB::table('users as u')
            ->leftjoin('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select('u.id', 'ur.roleName')
            ->where('u.id', '=', $id)
            ->first();

        if ($user->roleName != "Manager") {
            return false;
        } else {
            return true;
        }
    }
}

if (!function_exists('staffAccess')) {
    function staffAccess($id)
    {
        $user = DB::table('users as u')
            ->leftjoin('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select('u.id', 'ur.roleName')
            ->where('u.id', '=', $id)
            ->first();

        if ($user->roleName != "Staff") {
            return false;
        } else {
            return true;
        }
    }
}

if (!function_exists('customerAccess')) {
    function customerAccess($id)
    {
        $user = DB::table('users as u')
            ->leftjoin('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select('u.id', 'ur.roleName')
            ->where('u.id', '=', $id)
            ->first();

        if ($user->roleName != "Customer") {
            return false;
        } else {
            return true;
        }
    }
}

if (!function_exists('intershipAccess')) {
    function intershipAccess($id)
    {
        $user = DB::table('users as u')
            ->leftjoin('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select('u.id', 'ur.roleName')
            ->where('u.id', '=', $id)
            ->first();

        if ($user->roleName != "Intership") {
            return false;
        } else {
            return true;
        }
    }
}

if (!function_exists('role')) {
    function role($id)
    {
        $user = DB::table('users as u')
            ->leftjoin('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select('u.id', 'ur.roleName')
            ->where('u.id', '=', $id)
            ->first();

        return $user->roleName;
    }
}



if (!function_exists('roleStaffLeave')) {
    function roleStaffLeave($id)
    {
        $user = DB::table('users as u')
            ->leftjoin('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select('u.id', 'ur.roleName')
            ->where('u.id', '=', $id)
            ->first();


        if ($user->roleName == "Administrator" || $user->roleName == "Office") {
            return 1;
        } else {

            return 2;
        }
    }
}


if (!function_exists('convertTrueFalse')) {
    function convertTrueFalse($value)
    {
        if ($value == 'true' || $value == 'TRUE') {
            return 1;
        } elseif ($value == 'false' || $value == 'FALSE') {
            return 0;
        }
    }
}

if (!function_exists('productSellLog')) {
    function productSellLog($productId, $transaction, $remark, $quantity, $balance, $userId)
    {
        ProductLog::create([
            'productId' => $productId,
            'transaction' => $transaction,
            'remark' => $remark,
            'quantity' => $quantity,
            'balance' => $balance,
            'userId' => $userId,
        ]);
    }
}

if (!function_exists('productClinicLog')) {
    function productClinicLog($productId, $transaction, $remark, $quantity, $balance, $userId)
    {
        ProductLog::create([
            'productId' => $productId,
            'transaction' => $transaction,
            'remark' => $remark,
            'quantity' => $quantity,
            'balance' => $balance,
            'userId' => $userId,
        ]);
    }
}

if (!function_exists('productRestockLog')) {
    function productRestockLog($productRestockId, $event, $detail, $userId)
    {
        productRestockLog::create([
            'productRestockId' => $productRestockId,
            'event' => $event,
            'details' => $detail,
            'userId' => $userId,
        ]);
    }
}

if (!function_exists('productTransferLog')) {
    function productTransferLog($productTransferId, $event, $detail, $userId)
    {
        productTransferLog::create([
            'productTransferId' => $productTransferId,
            'event' => $event,
            'details' => $detail,
            'userId' => $userId,
        ]);
    }
}

// if (!function_exists('transactionLog')) {
//     function transactionLog($transactionId, $activity, $remark, $userId)
//     {
//         TransactionLog::create([
//             'transactionId' => $transactionId,
//             'activity' => $activity,
//             'remark' => $remark,
//             'userId' => $userId,
//         ]);
//     }
// }

if (!function_exists('transactionPetClinicLog')) {
    function transactionPetClinicLog($transactionId, $activity, $remark, $userId)
    {
        TransactionPetClinicLog::create([
            'transactionId' => $transactionId,
            'activity' => $activity,
            'remark' => $remark,
            'userId' => $userId,
        ]);
    }
}


if (!function_exists('transactionPetHotelLog')) {
    function transactionPetHotelLog($transactionId, $activity, $remark, $userId)
    {
        TransactionPetHotelLog::create([
            'transactionId' => $transactionId,
            'activity' => $activity,
            'remark' => $remark,
            'userId' => $userId,
        ]);
    }
}

if (!function_exists('transactionBreedingLog')) {
    function transactionBreedingLog($transactionId, $activity, $remark, $userId)
    {
        TransactionBreedingLog::create([
            'transactionId' => $transactionId,
            'activity' => $activity,
            'remark' => $remark,
            'userId' => $userId,
        ]);
    }
}

if (!function_exists('transactionPetSalonLog')) {
    function transactionPetSalonLog($transactionId, $activity, $remark, $userId)
    {
        transactionPetSalonLog::create([
            'transactionId' => $transactionId,
            'activity' => $activity,
            'remark' => $remark,
            'userId' => $userId,
        ]);
    }
}

if (!function_exists('statusTransactionPetClinic')) {
    function statusTransactionPetClinic($transactionId, $status, $doctorId)
    {
        if ($status == 'Cek Kondisi Pet') {
            TransactionPetClinic::where('id', '=', $transactionId)
                ->update([
                    'status' => $status,
                    'doctorId' => $doctorId,
                ]);
            return;
        } else {
            TransactionPetClinic::where('id', '=', $transactionId)
                ->update([
                    'status' => $status,
                ]);
        }
    }
}

if (!function_exists('updateLastTransaction')) {
    function updateLastTransaction($customerId)
    {
        Customer::where('id', '=', $customerId)
            ->update([
                'lastTransaction' => Carbon::now(),
            ]);
        return;
    }
}

if (!function_exists('transactionPetshopLog')) {
    function transactionPetshopLog($transactionId, $activity, $remark, $userId)
    {
        TransactionPetshopLog::create([
            'transactionId' => $transactionId,
            'activity' => $activity,
            'remark' => $remark,
            'userId' => $userId,
        ]);
    }
}


if (!function_exists('statusTransaction')) {
    function statusTransaction($transactionId, $status)
    {
        TransactionPetClinic::where('id', '=', $transactionId)
            ->update([
                'status' => $status,
            ]);
    }
}

if (!function_exists('statusTransactionPetHotel')) {
    function statusTransactionPetHotel($transactionId, $status, $doctorId)
    {
        if ($status == 'Cek Kondisi Pet') {
            TransactionPetHotel::where('id', '=', $transactionId)
                ->update([
                    'status' => $status,
                    'doctorId' => $doctorId,
                ]);
        } else {
            TransactionPetHotel::where('id', '=', $transactionId)
                ->update([
                    'status' => $status,
                ]);
        }
    }
}

if (!function_exists('statusTransactionBreeding')) {
    function statusTransactionBreeding($transactionId, $status, $doctorId)
    {

        if ($status == 'Cek Kondisi Pet') {
            TransactionBreeding::where('id', '=', $transactionId)
                ->update([
                    'status' => $status,
                    'doctorId' => $doctorId,
                ]);
        } else {
            TransactionBreeding::where('id', '=', $transactionId)
                ->update([
                    'status' => $status,
                ]);
        }
    }
}

if (!function_exists('statusTransactionPetSalon')) {
    function statusTransactionPetSalon($transactionId, $status, $doctorId)
    {
        if ($status == 'Cek Kondisi Pet') {
            transactionpetsalon::where('id', '=', $transactionId)
                ->update([
                    'status' => $status,
                    'doctorId' => $doctorId,
                ]);
        } else {
            transactionpetsalon::where('id', '=', $transactionId)
                ->update([
                    'status' => $status,
                ]);
        }
    }
}

if (!function_exists('recentActivities')) {
    function recentActivities($module, $event, $detail, $userId)
    {
        recentActivity::create([
            'module' => $module,
            'event' => $event,
            'detail' => $detail,
            'userId' => $userId,
        ]);
    }
}

if (!function_exists('responseInvalid')) {
    function responseInvalid($errors)
    {
        return response()->json([
            'message' => $errors,
            'errors' => 'The given data was invalid.',
        ], 422);
    }
}

if (!function_exists('responseCreate')) {
    function responseCreate()
    {
        return response()->json(
            [
                'message' => 'Add Data Successful!',
            ],
            200
        );
    }
}

if (!function_exists('responseUpdate')) {
    function responseUpdate()
    {
        return response()->json([
            'message' => 'Update Data Successful',
        ], 200);
    }
}

if (!function_exists('responseDelete')) {
    function responseDelete()
    {
        return response()->json([
            'message' => 'Delete Data Successful',
        ], 200);
    }
}

if (!function_exists('responseIndex')) {
    function responseIndex($paging, $data)
    {
        return response()->json([
            'totalPagination' => $paging,
            'data' => $data
        ], 200);
    }
}

if (!function_exists('responseList')) {
    function responseList($data)
    {
        return response()->json($data, 200);
    }
}

if (!function_exists('paginateData')) {
    function paginateData($query,  $request)
    {
        $itemPerPage = $request->rowPerPage;
        // dd($request->all());
        $totalPaging = 0;
        $count_data = 0;

        if ($itemPerPage == 0) {
            $data = $query->get();
            $count_data = $query->count();
        } else {
            $page = $request->goToPage;

            $offset = ($page - 1) * $itemPerPage;
            $count_data = $query->count();
            $count_result = $count_data - $offset;
            if ($count_result < 0) {
                $data = $query->offset(0)->limit($itemPerPage)->get();
            } else {
                $data = $query->offset($offset)->limit($itemPerPage)->get();
            }
            $totalPaging = $count_data / $itemPerPage;
        }


        return collect([
            'totalPagination' => ceil($totalPaging),
            'totalData' => $count_data,
            'data' => $data
        ]);
    }
}
if (!function_exists('responseSuccess')) {
    function responseSuccess($data = [], $msg = 'Insert Data Successful!')
    {
        return response()->json(
            [
                'data' => $data,
                'message' => $msg,
            ],
            200
        );
    }
}
if (!function_exists('responseErrorValidation')) {
    function responseErrorValidation($errors, $msg = 'The given data was invalid.')
    {
        return response()->json(
            [
                'message' => $msg,
                'errors' => $errors,
            ],
            422
        );
    }
}
if (!function_exists('responseError')) {
    function responseError($errors, $msg = 'The given data was invalid.')
    {
        return response()->json(
            [
                'message' => $msg,
                'errors' => $errors,
            ],
            500
        );
    }
}

if (!function_exists('responseUnauthorize')) {
    function responseUnauthorize($errors = 'User Access not Authorize!', $msg = 'The given data was invalid.')
    {
        return response()->json(
            [
                'message' => $msg,
                'errors' => [$errors],
            ],
            403
        );
    }
}

if (!function_exists('checkAccessIndex')) {
    function checkAccessIndex($identify, $roleId)
    {
        $menuId = 0;

        $data = DB::table('grandChildrenMenuGroups as gc')
            ->select('gc.id', 'gc.identify')
            ->where('gc.identify', 'like', '%' . $identify . '%')
            ->first();

        if ($data) {
            $menuId = $data->id;
        }

        $res = true;

        $data = DB::table('accessControl as ac')
            ->where('ac.menuListId', '=', $menuId)
            ->where('ac.roleId', '=', $roleId)
            ->first();

        if ($data->accessTypeId == 3) {
            $res = false;
        }

        return $res;
    }
}

if (!function_exists('checkAccessModify')) {
    function checkAccessModify($identify, $roleId)
    {
        $menuId = 0;

        $data = DB::table('grandChildrenMenuGroups as gc')
            ->select('gc.id', 'gc.identify')
            ->where('gc.identify', 'like', '%' . $identify . '%')
            ->first();

        if ($data) {
            $menuId = $data->id;
        }

        $res = true;

        $data = DB::table('accessControl as ac')
            ->where('ac.menuListId', '=', $menuId)
            ->where('ac.roleId', '=', $roleId)
            ->first();

        if ($data->accessTypeId == 1 || $data->accessTypeId == 3) {
            $res = false;
        }

        return $res;
    }
}

if (!function_exists('checkAccessDelete')) {
    function checkAccessDelete($identify, $roleId)
    {
        $menuId = 0;

        $data = DB::table('grandChildrenMenuGroups as gc')
            ->select('gc.id', 'gc.identify')
            ->where('gc.identify', 'like', '%' . $identify . '%')
            ->first();

        if ($data) {
            $menuId = $data->id;
        }

        $res = true;

        $data = DB::table('accessControl as ac')
            ->where('ac.menuListId', '=', $menuId)
            ->where('ac.roleId', '=', $roleId)
            ->first();

        if ($data->accessTypeId == 1 || $data->accessTypeId == 3 || $data->accessTypeId == 2) {
            $res = false;
        }

        return $res;
    }
}

function updateDiffStock($locationId, $productId)
{
    $productLoc = DB::table('productLocations')
        ->where('locationId', $locationId)
        ->where('productId', $productId)
        ->first();

    if ($productLoc) {
        $newDiffStock = $productLoc->inStock - $productLoc->lowStock;
        DB::table('productLocations')
            ->where('locationId', $locationId)
            ->where('productId', $productId)
            ->update(['diffStock' => $newDiffStock]);
    }
}

function recentActivity($userId, $module, $event, $detail)
{
    recentActivity::create([
        'userId' => $userId,
        'module' => $module,
        'event' => $event,
        'details' => $detail,
    ]);
}
