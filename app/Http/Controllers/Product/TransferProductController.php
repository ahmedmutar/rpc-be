<?php

namespace App\Http\Controllers\Product;

use App\Exports\Product\ProductTransferReport;
use App\Models\ProductClinic;
use App\Models\ProductClinicLocation;
use App\Models\Product;
use App\Models\productBatch;
use App\Models\ProductBatches;
use App\Models\ProductCoreCategories;
use App\Models\ProductCustomerGroups;
use App\Models\ProductLocation;
use App\Models\ProductLocations;
use App\Models\ProductPriceLocations;
use App\Models\ProductQuantitiess;
use App\Models\ProductReminders;
use App\Models\Products;
use App\Models\ProductTransfer;
use App\Models\productTransferDetails;
use App\Models\productTransferReceiveImages;
use App\Models\productTransferSentImages;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Validator;
use DB;
use Excel;
use Illuminate\Support\Str;
use File;

class TransferProductController
{
    public function transferProductNumber()
    {
        $findData = ProductTransfer::whereDate('created_at', Carbon::today())->count();

        $number = "";

        if ($findData == 0) {
            $number = Carbon::today();
            $number = 'RPC-TRF-' . $number->format('Ymd') . str_pad(0 + 1, 5, 0, STR_PAD_LEFT);
        } else {
            $number = Carbon::today();
            $number = 'RPC-TRF-' . $number->format('Ymd') . str_pad($findData + 1, 5, 0, STR_PAD_LEFT);
        }

        return response()->json($number, 200);
    }

    public function create(Request $request)
    {
        DB::beginTransaction();
        try {

            $validate = Validator::make($request->all(), [
                'transferNumber' => 'required|string',
                'transferName' => 'required|string',
                'locationId' => 'required|integer',
                'totalItem' => 'required|integer',
                'userIdReceiver' => 'required|integer',
                'productId' => 'required|integer',
                'productType' => 'required|string|in:productSell,productClinic',
                'additionalCost' => 'numeric',
                'remark' => 'nullable|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();

                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }

            $prodDest = null;

            $findData = ProductTransfer::whereDate('created_at', Carbon::today())->count();

            $number = "";

            if ($findData == 0) {
                $number = Carbon::today();
                $number = 'RPC-TRF-' . $number->format('Ymd') . str_pad(0 + 1, 5, 0, STR_PAD_LEFT);
            } else {
                $number = Carbon::today();
                $number = 'RPC-TRF-' . $number->format('Ymd') . str_pad($findData + 1, 5, 0, STR_PAD_LEFT);
            }

            //find product id destination
            // if ($request->productType == 'productSell') {


            // } elseif ($request->productType == 'productClinic') {

            //     $prodOrigin = ProductClinic::find($request->productId);

            //     if ($prodOrigin) {

            //         $prodDest = DB::table('productClinics as pc')
            //             ->join('productClinicLocations as pcl', 'pc.id', 'pcl.productClinicId')
            //             ->select('pc.*', 'pcl.diffStock')
            //             ->where('pcl.locationId', '=', $request->locationId)
            //             ->where('pc.fullName', '=', $prodOrigin->fullName)
            //             ->first();
            //     } else {
            //         return response()->json([
            //             'message' => 'The given data was invalid.',
            //             'errors' => ['Product does not exist!'],
            //         ], 422);
            //     }
            // }

            $prodOrigin = Product::find($request->productId);

            if ($prodOrigin) {

                $prodDest = DB::table('products as ps')
                    ->join('productLocations as psl', 'ps.id', 'psl.productId')
                    ->select('ps.*', 'psl.diffStock')
                    ->where('psl.locationId', '=', $request->locationId)
                    ->where('ps.fullName', '=', $prodOrigin->fullName)
                    ->first();
            } else {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Product does not exist!'],
                ], 422);
            }

            $checkAdminApproval = false;

            if ($prodDest) {

                if ($prodDest->diffStock > 0) {
                    $checkAdminApproval = true;
                }

                $cntNum = DB::table('productTransfers')
                    ->where('status', '!=', 0)
                    ->count();

                if ($cntNum == 0) {
                    $numberId = '#' . str_pad(1, 8, 0, STR_PAD_LEFT);
                } else {
                    $numberId = '#' . str_pad($cntNum + 1, 8, 0, STR_PAD_LEFT);
                }

                $master = ProductTransfer::create([
                    'numberId' => $numberId,
                    'transferNumber' => $number,
                    'transferName' => $request->transferName,
                    'locationIdOrigin' => 0,
                    'locationIdDestination' => 0,
                    'variantProduct' => 1,
                    'totalProduct' => $request->totalItem,
                    'userIdReceiver' => $request->userIdReceiver,
                    'isAdminApproval' => $checkAdminApproval,
                    'status' => 1,
                    'userId' => $request->user()->id,
                ]);

                productTransferLog($master->id, "Created", "Waiting for Approval", $request->user()->id);

                productTransferDetails::create([
                    'productTransferId' => $master->id,
                    'productIdOrigin' => $request->productId,
                    'productIdDestination' => $prodDest->id,
                    'productType' => $request->productType,
                    'remark' => $request->remark,
                    'quantity' => $request->totalItem,
                    'additionalCost' => $request->additionalCost,
                    'isAdminApproval' => $checkAdminApproval,
                    'userId' => $request->user()->id,
                ]);
            } else {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Product Destination does not exist!'],
                ], 422);
            }

            recentActivity(
                $request->user()->id,
                'Product Transfer',
                'Create Transfer',
                'Created product Transfer'
            );

            DB::commit();
            return responseCreate();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function createMultiple(Request $request)
    {
        DB::beginTransaction();

        try {
            $validate = Validator::make($request->all(), [
                'type' => 'required|string|in:draft,final',
                'transferNumber' => 'required|string',
                'transferName' => 'required|string',
                'locationIdOrigin' => 'required|integer',
                'locationIdDestination' => 'required|integer',
                'userIdReceiver' => 'required|integer',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();

                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }

            $datas = json_decode($request->products, true);

            $validate = Validator::make(
                $datas,
                [
                    '*.productId' => 'required|integer',
                    '*.productType' => 'required|string|in:productSell,productClinic',
                    '*.quantity' => 'required|integer',
                    '*.additionalCost' => 'required|numeric',
                    '*.remark' => 'nullable|string',
                ],
                [
                    '*.productId.required' => 'Product Id Should be Required!',
                    '*.productId.integer' => 'Product Id Should be Integer!',

                    '*.productType.required' => 'Product Type Should be Required!',
                    '*.productType.string' => 'Product Type Should be String!',

                    '*.quantity.required' => 'Quantity Should be Required!',
                    '*.quantity.integer' => 'Quantity Should be Integer!',

                    '*.additionalCost.required' => 'Additional Cost Should be Required!',
                    '*.additionalCost.numeric' => 'Additional Cost Should be Numeric!',

                    '*.remark.string' => 'Remark Should be String!',
                ]
            );

            $numberId = '';
            $transferNumber = '';
            $variantProduct = 0;
            $totalProduct = 0;
            $status = 0;

            if ($request->type == 'final') {
                $status = 1;

                $cntNum = DB::table('productTransfers')
                    ->where('status', '!=', 0)
                    ->count();

                if ($cntNum == 0) {
                    $numberId = '#' . str_pad(1, 8, 0, STR_PAD_LEFT);
                } else {
                    $numberId = '#' . str_pad($cntNum + 1, 8, 0, STR_PAD_LEFT);
                }
            } elseif ($request->type == 'draft') {
                $numberId = 'draft';
            }

            $findData = ProductTransfer::whereDate('created_at', Carbon::today())->count();

            if ($findData == 0) {
                $transferNumber = Carbon::today();
                $transferNumber = 'RPC-TRF-' . $transferNumber->format('Ymd') . str_pad(0 + 1, 5, 0, STR_PAD_LEFT);
            } else {
                $transferNumber = Carbon::today();
                $transferNumber = 'RPC-TRF-' . $transferNumber->format('Ymd') . str_pad($findData + 1, 5, 0, STR_PAD_LEFT);
            }

            foreach ($datas as $value) {
                if (!$value['status'] === 'del') {
                    $variantProduct += 1;
                    $totalProduct += $value['quantity'];
                }
            }

            $master = ProductTransfer::create([
                'numberId' => $numberId,
                'transferNumber' => $transferNumber,
                'transferName' => $request->transferName,
                'locationIdOrigin' => $request->locationIdOrigin,
                'locationIdDestination' => $request->locationIdDestination,
                'variantProduct' => $variantProduct,
                'totalProduct' => $totalProduct,
                'userIdReceiver' => $request->userIdReceiver,
                'isAdminApproval' => 0,
                'status' => $status,
                'userId' => $request->user()->id,
            ]);

            if ($numberId == 'draft') {
                productTransferLog($master->id, "Created", "Draft", $request->user()->id);
            } else {
                productTransferLog($master->id, "Created", "Waiting for Approval", $request->user()->id);
            }

            $productIdDestination = 0;
            $adminApprovalMaster = false;

            foreach ($datas as $value) {
                $checkAdminApproval = false;

                $dataProductOr = DB::table('products as ps')
                    ->join('productLocations as psl', 'ps.id', 'psl.productId')
                    ->select('ps.fullName', 'psl.diffStock')
                    ->where('psl.locationId', '=', $request->locationIdOrigin)
                    ->where('ps.id', '=', $value['productId'])
                    ->first();

                if (!$dataProductOr) {
                    return responseInvalid(['Location Origin with Product Id is not exists in our data']);
                }

                $data = DB::table('products as ps')
                    ->join('productLocations as psl', 'ps.id', 'psl.productId')
                    ->select('ps.*')
                    ->where('psl.locationId', '=', $request->locationIdDestination)
                    ->where('ps.fullName', 'like', '%' . $dataProductOr->fullName . '%')
                    ->first();

                if (!$data) {
                    $productIdDestination = 0;
                } else {
                    $productIdDestination = $data->id;
                }

                if ($dataProductOr->diffStock <= 0) {
                    $adminApprovalMaster = true;
                    $checkAdminApproval = true;
                }

                $detail = productTransferDetails::create([
                    'productTransferId' => $master->id,
                    'productIdOrigin' => $value['productId'],
                    'productIdDestination' => $productIdDestination,
                    'productType' => $value['productType'],
                    'quantity' => $value['quantity'],
                    'remark' => $value['remark'],
                    'isAdminApproval' => $checkAdminApproval,
                    'additionalCost' => $value['additionalCost'],
                    'userId' => $request->user()->id,
                ]);

                foreach ($value['images'] as $img) {

                    if ($img['imagePath'] != '') {
                        $image = str_replace('data:image/', '', $img['imagePath']);
                        $image = explode(';base64,', $image);
                        $imageName = Str::random(40) . '.' . $image[0];
                        File::put(public_path('ProductTransferSentImages') . '/' . $imageName, base64_decode($image[1]));

                        productTransferSentImages::create([
                            'productTransferDetailId' => $detail->id,
                            'label' => $img['label'],
                            'realImageName' => $img['originalName'],
                            'imagePath' => '/ProductTransferSentImages' . '/' . $imageName,
                            'userId' => $request->user()->id,
                        ]);
                    }
                }
            }

            if ($adminApprovalMaster == true) {

                $res = ProductTransfer::find($master->id);
                $res->isAdminApproval = $adminApprovalMaster;
                $res->save();
            }

            DB::commit();
            return responseCreate();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function index(Request $request)
    {
        $itemPerPage = $request->rowPerPage;

        $page = $request->goToPage;

        $data = DB::table('productTransfers as pt')
            ->join('users as u', 'pt.userId', 'u.id')
            ->leftjoin('location as lo', 'pt.locationIdOrigin', 'lo.id')
            ->leftjoin('location as ld', 'pt.locationIdDestination', 'ld.id')
            ->select(
                'pt.id as id',
                'pt.numberId',
                'pt.transferNumber',
                'pt.transferName',
                'pt.variantProduct',
                'pt.totalProduct',
                'pt.status',
                'lo.id as locationOriginId',
                'lo.locationName as locationOriginName',
                'ld.id as locationDestinationId',
                'ld.locationName as locationDestinationName',
                'u.id as userId',
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(pt.created_at, '%d/%m/%Y') as createdAt")
            )
            ->where('pt.isDeleted', '=', 0);

        if ($request->locationDestinationId) {
            $data = $data->whereIn('ld.id', $request->locationDestinationId);
        }

        if ($request->type == 'approval') {
            $data = $data->whereIn('pt.status', array(1, 3, 4));

            if (adminAccess($request->user()->id)) {
                $data = $data->where('pt.isAdminApproval', '=', 1);
            }
        }

        if ($request->type == 'history') {
            $data = $data->whereIn('pt.status', array(2, 5));
        }

        if ($request->status) {
            $data = $data->where('pt.status', '=', $request->status);
        }

        if ($request->search) {
            $res = $this->Search($request);
            if ($res) {
                $data = $data->where($res[0], 'like', '%' . $request->search . '%');

                for ($i = 1; $i < count($res); $i++) {

                    $data = $data->orWhere($res[$i], 'like', '%' . $request->search . '%');
                }
            } else {
                $data = [];
                return response()->json([
                    'totalPagination' => 0,
                    'data' => $data
                ], 200);
            }
        }

        if ($request->orderValue) {
            $data = $data->orderBy($request->orderColumn, $request->orderValue);
        }

        $data = $data->orderBy('pt.updated_at', 'desc');

        $offset = ($page - 1) * $itemPerPage;

        $count_data = $data->count();
        $count_result = $count_data - $offset;

        if ($count_result < 0) {
            $data = $data->offset(0)->limit($itemPerPage)->get();
        } else {
            $data = $data->offset($offset)->limit($itemPerPage)->get();
        }

        $totalPaging = $count_data / $itemPerPage;


        return responseIndex(ceil($totalPaging), $data);
    }

    private function search(Request $request)
    {
        $temp_column = null;

        $data = DB::table('productTransfers as pt')
            ->select(
                'pt.numberId'
            )
            ->where('pt.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('pt.numberId', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 'pt.numberId';
        }

        $data = DB::table('productTransfers as pt')
            ->select(
                'pt.transferNumber'
            )
            ->where('pt.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('pt.transferNumber', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 'pt.transferNumber';
        }

        $data = DB::table('productTransfers as pt')
            ->select(
                'pt.transferName'
            )
            ->where('pt.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('pt.transferName', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 'pt.transferName';
        }

        $data = DB::table('productTransfers as pt')
            ->join('users as u', 'pt.userId', 'u.id')
            ->select(
                'u.firstName'
            )
            ->where('pt.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('u.firstName', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 'u.firstName';
        }

        return $temp_column;
    }

    public function detailHistory(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $itemPerPage = $request->rowPerPage;

        $page = $request->goToPage;

        $data = DB::table('productTransferLogs as prl')
            ->join('users as u', 'prl.userId', 'u.id')
            ->select(
                DB::raw("DATE_FORMAT(prl.created_at, '%W, %d %M %Y') as date"),
                DB::raw("DATE_FORMAT(prl.created_at, '%H:%i') as time"),
                'u.firstName as createdBy',
                'prl.details',
                'prl.event'
            )
            ->where('prl.productTransferId', '=', $request->id);

        if ($request->orderValue) {
            $data = $data->orderBy($request->orderColumn, $request->orderValue);
        }

        $data = $data->orderBy('prl.updated_at', 'desc');

        $offset = ($page - 1) * $itemPerPage;

        $count_data = $data->count();
        $count_result = $count_data - $offset;

        if ($count_result < 0) {
            $data = $data->offset(0)->limit($itemPerPage)->get();
        } else {
            $data = $data->offset($offset)->limit($itemPerPage)->get();
        }

        $totalPaging = $count_data / $itemPerPage;

        return responseIndex(ceil($totalPaging), $data);
    }

    public function update(Request $request)
    {
        DB::beginTransaction();

        try {
            $validate = Validator::make($request->all(), [
                'id' => 'required|integer',
                'type' => 'required|string|in:draft,final',
                'transferNumber' => 'required|string',
                'transferName' => 'required|string',
                'locationIdOrigin' => 'required|integer',
                'locationIdDestination' => 'required|integer',
                'userIdReceiver' => 'required|integer',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();

                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }

            $validate = Validator::make(
                $request->products,
                [
                    '*.id' => 'nullable|integer',
                    '*.productId' => 'required|integer',
                    '*.productType' => 'required|string|in:productSell,productClinic',
                    '*.quantity' => 'required|integer',
                    '*.additionalCost' => 'required|numeric',
                    '*.remark' => 'nullable|string',
                ],
                [
                    '*.productId.required' => 'Product Id Should be Required!',
                    '*.productId.integer' => 'Product Id Should be Integer!',

                    '*.id.integer' => 'Detail Transfer Id Should be Integer!',

                    '*.productType.required' => 'Product Type Should be Required!',
                    '*.productType.string' => 'Product Type Should be String!',

                    '*.quantity.required' => 'Quantity Should be Required!',
                    '*.quantity.integer' => 'Quantity Should be Integer!',

                    '*.additionalCost.required' => 'Additional Cost Should be Required!',
                    '*.additionalCost.numeric' => 'Additional Cost Should be Numeric!',

                    '*.remark.string' => 'Remark Should be String!',
                ]
            );

            $numberId = '';
            $variantProduct = 0;
            $totalProduct = 0;
            $status = 0;
            $checkAdminApproval = false;

            if ($request->type == 'final') {
                $status = 1;

                $cntNum = DB::table('productTransfers')
                    ->where('status', '!=', 0)
                    ->count();

                if ($cntNum == 0) {
                    $numberId = '#' . str_pad(1, 8, 0, STR_PAD_LEFT);
                } else {
                    $numberId = '#' . str_pad($cntNum + 1, 8, 0, STR_PAD_LEFT);
                }
            } elseif ($request->type == 'draft') {
                $numberId = 'draft';
            }

            foreach ($request->products as $value) {
                $variantProduct += 1;
                $totalProduct += $value['quantity'];
            }

            $master = ProductTransfer::updateOrCreate(
                ['id' => $request->id],
                [
                    'numberId' => $numberId,
                    'transferNumber' => $request->transferNumber,
                    'transferName' => $request->transferName,
                    'locationIdOrigin' => $request->locationIdOrigin,
                    'locationIdDestination' => $request->locationIdDestination,
                    'variantProduct' => $variantProduct,
                    'totalProduct' => $totalProduct,
                    'userIdReceiver' => $request->userIdReceiver,
                    'isAdminApproval' => 0,
                    'status' => $status,
                    'userId' => $request->user()->id,
                ]
            );

            if ($numberId == 'final') {
                productTransferLog($request->id, "Updated", "Waiting for Approval", $request->user()->id);
            }

            $productIdDestination = 0;
            $adminApprovalMaster = false;

            foreach ($request->products as $value) {

                $checkAdminApproval = false;

                // if ($value['productType'] == 'productSell') {


                // } elseif ($value['productType'] == 'productClinic') {

                //     $dataProductOr = DB::table('productClinics as ps')
                //         ->join('productClinicLocations as psl', 'ps.id', 'psl.productClinicId')
                //         ->select('ps.fullName', 'psl.diffStock')
                //         ->where('psl.locationId', '=', $request->locationIdOrigin)
                //         ->where('ps.id', '=', $value['productId'])
                //         ->first();

                //     if (!$dataProductOr) {
                //         return responseInvalid(['Location Origin with Product Id is not exists in our data']);
                //     }

                //     $data = DB::table('productClinics as ps')
                //         ->join('productClinicLocations as psl', 'ps.id', 'psl.productClinicId')
                //         ->select('ps.*')
                //         ->where('psl.locationId', '=', $request->locationIdDestination)
                //         ->where('ps.fullName', 'like', '%' . $dataProductOr->fullName . '%')
                //         ->first();

                //     if (!$data) {
                //         $productIdDestination = 0;
                //     } else {
                //         $productIdDestination = $data->id;
                //     }

                //     if ($dataProductOr->diffStock <= 0) {
                //         $checkAdminApproval = true;
                //         $adminApprovalMaster = true;
                //     }
                // }

                $dataProductOr = DB::table('products as ps')
                    ->join('productLocations as psl', 'ps.id', 'psl.productId')
                    ->select('ps.fullName', 'psl.diffStock')
                    ->where('psl.locationId', '=', $request->locationIdOrigin)
                    ->where('ps.id', '=', $value['productId'])
                    ->first();

                if (!$dataProductOr) {
                    return responseInvalid(['Location Origin with Product Id is not exists in our data']);
                }

                $data = DB::table('products as ps')
                    ->join('productLocations as psl', 'ps.id', 'psl.productId')
                    ->select('ps.*')
                    ->where('psl.locationId', '=', $request->locationIdDestination)
                    ->where('ps.fullName', 'like', '%' . $dataProductOr->fullName . '%')
                    ->first();

                if (!$data) {
                    $productIdDestination = 0;
                } else {
                    $productIdDestination = $data->id;
                }

                if ($dataProductOr->diffStock <= 0) {
                    $checkAdminApproval = true;
                }

                if ($value['status'] === 'del') {
                    if ($value['id']) {
                        $res = productTransferDetails::find($value['id']);

                        $res->DeletedBy = $request->user()->id;
                        $res->isDeleted = true;
                        $res->DeletedAt = Carbon::now();
                        $res->save();

                        $images = productTransferSentImages::where('productTransferDetailId', '=', $value['id'])->get();

                        // if ($res->productType == 'productSell') {


                        // } elseif ($res->productType == 'productClinic') {

                        //     $prod = ProductClinic::find($res->productIdOrigin);
                        //     productTransferLog($request->id, "Deleted Product in Transfer", "Product " . $prod->fullName . " has already Deleted", $request->user()->id);
                        // }

                        $prod = Products::find($res->productIdOrigin);

                        productTransferLog($request->id, "Deleted Product in Transfer", "Product " . $prod->fullName . " has already Deleted", $request->user()->id);

                        if ($images) {
                            foreach ($images as $vaDetail) {

                                DB::table('productTransferSentImages')
                                    ->where('productTransferDetailId', '=', $vaDetail['id'])
                                    ->update([
                                        'isDeleted' => true,
                                        'DeletedBy' => $request->user()->id,
                                        'DeletedAt' => Carbon::now()
                                    ]);
                            }
                        }
                    }
                } else {

                    $detail = productTransferDetails::find($value['id']);

                    $tmp_cost = $detail->additionalCost;
                    $tmp_qty = $detail->quantity;
                    $tmp_remark = $detail->remark;

                    $detail->productTransferId = $master->id;
                    $detail->productIdOrigin = $value['productId'];
                    $detail->productIdDestination = $productIdDestination;
                    $detail->productType = $value['productType'];
                    $detail->quantity = $value['quantity'];
                    $detail->remark = $value['remark'];
                    $detail->isAdminApproval = $checkAdminApproval;
                    $detail->additionalCost = $value['additionalCost'];
                    $detail->userId = $request->user()->id;
                    $detail->save();

                    $check = false;

                    if ($detail->wasChanged('quantity') || $detail->wasChanged('remark') || ($detail->additionalCost != $tmp_cost)) {
                        $check = true;
                    }

                    if ($check == true) {
                        $textQuantity = "";
                        $textRemark = "";
                        $textAdditionalCost = "";

                        // if ($value['productType'] == 'productSell') {


                        // } elseif ($value['productType'] == 'productClinic') {

                        //     $prod = ProductClinic::find($value['productId']);

                        //     if ($detail->wasChanged('quantity')) {
                        //         $textQuantity = "Quantity Product " . $prod->fullName . " change from " . $tmp_qty  . " to " . $value['quantity'];
                        //         productTransferLog($request->id, "Update in " . $prod->fullName, $textQuantity, $request->user()->id);
                        //     }

                        //     if ($detail->additionalCost != $tmp_cost) {
                        //         $textAdditionalCost = "Additional Cost Product " . $prod->fullName . " change from " . $tmp_cost  . " to " . $value['additionalCost'];
                        //         productTransferLog($request->id, "Update in " . $prod->fullName, $textAdditionalCost, $request->user()->id);
                        //     }

                        //     if ($detail->wasChanged('remark')) {
                        //         $textRemark = "Remark Product " . $prod->fullName . " change from " . $tmp_remark . " to " .  $value['remark'];
                        //         productTransferLog($request->id, "Update in " . $prod->fullName, $textRemark, $request->user()->id);
                        //     }
                        // }

                        $prod = Products::find($value['productId']);

                        if ($detail->wasChanged('quantity')) {
                            $textQuantity = "Quantity Product " . $prod->fullName . " change from " . $tmp_qty  . " to " . $value['quantity'];
                            productTransferLog($request->id, "Update in " . $prod->fullName, $textQuantity, $request->user()->id);
                        }

                        if ($detail->additionalCost != $tmp_cost) {
                            $textAdditionalCost = "Additional Cost Product " . $prod->fullName . " change from " . $tmp_cost  . " to " . $value['additionalCost'];
                            productTransferLog($request->id, "Update in " . $prod->fullName, $textAdditionalCost, $request->user()->id);
                        }

                        if ($detail->wasChanged('remark')) {
                            $textRemark = "Remark Product " . $prod->fullName . " change from " . $tmp_remark . " to " .  $value['remark'];
                            productTransferLog($request->id, "Update in " . $prod->fullName, $textRemark, $request->user()->id);
                        }
                    }

                    if (is_null($value['id']) || empty($value['id'])) {
                        // if ($value['productType'] == 'productSell') {


                        // } elseif ($value['productType'] == 'productClinic') {

                        //     $prod = ProductClinic::find($value['productId']);

                        //     productTransferLog($request->id, "Add Product " . $prod->fullName, "Product " . $prod->fullName . " has already added", $request->user()->id);
                        // }

                        $prod = Products::find($value['productId']);

                        productTransferLog($request->id, "Add Product " . $prod->fullName, "Product " . $prod->fullName . " has already added", $request->user()->id);
                    }
                }

                if (is_null($value['id'])) {

                    foreach ($value['images'] as $img) {

                        if ($img['imagePath'] != '') {
                            $image = str_replace('data:image/', '', $img['imagePath']);
                            $image = explode(';base64,', $image);
                            $imageName = Str::random(40) . '.' . $image[0];
                            File::put(public_path('ProductTransferSentImages') . '/' . $imageName, base64_decode($image[1]));

                            productTransferSentImages::create([
                                'productTransferDetailId' => $detail->id,
                                'label' => $img['label'],
                                'realImageName' => $img['originalName'],
                                'imagePath' => '/ProductTransferSentImages' . '/' . $imageName,
                                'userId' => $request->user()->id,
                            ]);
                        }
                    }
                }
            }

            if ($adminApprovalMaster == true) {

                $res = ProductTransfer::find($master->id);
                $res->isAdminApproval = $adminApprovalMaster;
                $res->save();
            }

            recentActivity(
                $request->user()->id,
                'Product Transfer',
                'Update Transfer',
                'Updated product Transfer'
            );

            DB::commit();
            return responseUpdate();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function delete(Request $request)
    {
        DB::beginTransaction();
        try {
            $validate = Validator::make(
                $request->all(),
                [
                    'id.*' => 'required|integer',
                ],
                [
                    'id.*.required' => 'Id Should be Required!',
                    'id.*.integer' => 'Id Should be Integer!',
                ]
            );

            if ($validate->fails()) {
                $errors = $validate->errors()->all();

                return responseInvalid($errors);
            }

            if (adminAccess($request->user()->id)) {

                $tmp_num = '';

                foreach ($request->id as $va) {

                    $res = ProductTransfer::find($va);

                    if (!$res) {

                        return responseInvalid(['There is any Data not found!']);
                    }

                    if ($res->status == 5) {
                        $tmp_num = $tmp_num . (string) $res->numberId . ', ';
                    }
                }

                if ($tmp_num != '') {
                    return responseInvalid(['Transfer with ID Number ' . rtrim($tmp_num, ', ') . ' cannot be deleted. Becasue has already received!']);
                }
            } else {

                $tmp_num = '';

                foreach ($request->id as $va) {
                    $res = ProductTransfer::find($va);

                    if (!$res) {

                        return responseInvalid(['There is any Data not found!']);
                    }

                    if ($res->status != 0) {
                        $tmp_num = $tmp_num . (string) $res->numberId . ', ';
                    }
                }

                if ($tmp_num != '') {
                    return responseInvalid(['Transfer with ID Number ' . rtrim($tmp_num, ', ') . ' cannot be deleted. Becasue has already submited, has already sent, or has already received!']);
                }
            }

            foreach ($request->id as $va) {
                $res = ProductTransfer::find($va);

                $res->DeletedBy = $request->user()->id;
                $res->isDeleted = true;
                $res->DeletedAt = Carbon::now();
                $res->save();

                $detail = productTransferDetails::where('productTransferId', '=', $va)->get();

                foreach ($detail as $vaDetail) {

                    DB::table('productTransferSentImages')
                        ->where('productTransferDetailId', '=', $vaDetail['id'])
                        ->update([
                            'isDeleted' => true,
                            'DeletedBy' => $request->user()->id,
                            'DeletedAt' => Carbon::now()
                        ]);
                }

                productTransferDetails::updateOrCreate(
                    ['productTransferId' => $va],
                    [
                        'isDeleted' => true,
                        'DeletedBy' => $request->user()->id,
                        'DeletedAt' => Carbon::now()
                    ]
                );
            }

            recentActivity(
                $request->user()->id,
                'Product Transfer',
                'Delete Transfer',
                'Deleted product Transfer'
            );

            DB::commit();
            return responseDelete();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function detail(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $findData = ProductTransfer::find($request->id);

        if ($findData) {

            if ($request->type == 'edit') {

                $data = DB::table('productTransfers as pt')
                    ->leftJoin('location as lo', 'pt.locationIdOrigin', 'lo.id')
                    ->leftJoin('location as ld', 'pt.locationIdDestination', 'ld.id')
                    ->join('users as u', 'pt.userId', 'u.id')
                    ->join('users as ur', 'pt.userIdReceiver', 'ur.id')
                    ->select(
                        'pt.id',
                        'pt.transferNumber',
                        DB::raw("DATE_FORMAT(pt.created_at, '%d/%m/%Y %H:%i:%s') as transferDate"),
                        'pt.transferName',
                        'lo.id as locationOriginId',
                        'lo.locationName as locationOriginName',
                        'ld.id as locationDestinationId',
                        'ld.locationName as locationDestinationName',
                        'ur.id as userIdReceiver',
                        DB::raw("IFNULL(ur.firstName,'') as receivedBy"),
                    )
                    ->where('pt.id', '=', $request->id)
                    ->first();

                $detail = productTransferDetails::where('productTransferId', '=', $request->id)->get();

                $datas = [];
                foreach ($detail as $value) {
                    $prd = DB::table('products as ps')
                        ->join('productTransferDetails as ptd', 'ps.id', 'ptd.productIdOrigin')
                        ->select(
                            'ptd.id',
                            'ps.id as productId',
                            'ps.fullName',
                            DB::raw("TRIM(ptd.additionalCost)+0 as additionalCost"),
                            'ptd.remark',
                            'ptd.productType',
                            'ptd.quantity',
                        )
                        ->where('ptd.id', '=', $value->id)
                        ->where('ptd.isDeleted', '=', 0)
                        ->first();

                    if ($prd) {
                        $images = DB::table('productTransferSentImages as pti')
                            ->join('productTransferDetails as ptd', 'pti.productTransferDetailId', 'ptd.id')
                            ->select(
                                'pti.realImageName',
                                'pti.label',
                                'pti.imagePath',
                            )
                            ->where('pti.productTransferDetailId', '=', $value->id)
                            ->get();

                        $datas[] = array(
                            'id' => $prd->id,
                            'productId' => $prd->productId,
                            'fullName' => $prd->fullName,
                            'productType' => $prd->productType,
                            'quantity' => $prd->quantity,
                            'remark' => $prd->remark,
                            'additionalCost' => $prd->additionalCost,
                            'images' => $images
                        );
                    }
                }
                $data->detail = $datas;

                return responseList($data);
            } elseif ($request->type == 'receive') {
                # code...
            } else {

                $data = DB::table('productTransfers as pt')
                    ->leftJoin('location as lo', 'pt.locationIdOrigin', 'lo.id')
                    ->leftJoin('location as ld', 'pt.locationIdDestination', 'ld.id')
                    ->join('users as u', 'pt.userId', 'u.id')
                    ->join('users as ur', 'pt.userIdReceiver', 'ur.id')
                    ->select(
                        'pt.numberId',
                        'pt.transferNumber',
                        'pt.transferName',
                        'lo.id as idBranchOrigin',
                        'lo.locationName as branchOrigin',
                        'ld.id as idBranchDestination',
                        'ld.locationName as branchDestination',
                        'ur.id as idUserReceived',
                        DB::raw("IFNULL(ur.firstName,'') as receivedBy"),
                        'u.firstName as createdBy',
                        DB::raw("DATE_FORMAT(pt.created_at, '%d/%m/%Y %H:%i:%s') as transferDate")
                    )
                    ->where('pt.id', '=', $request->id)
                    ->first();

                $detail = productTransferDetails::where('productTransferId', '=', $request->id)->get();
                $datas = [];
                //tinggal gambarnya belom
                foreach ($detail as $value) {
                    $prd = DB::table('products as ps')
                        ->join('productTransferDetails as ptd', 'ps.id', 'ptd.productIdOrigin')
                        ->select(
                            'ptd.id',
                            'ps.fullName',
                            DB::raw("TRIM(ptd.additionalCost)+0 as additionalCost"),
                            'ptd.remark',
                            'ptd.productType',
                            'ptd.quantity',
                        )
                        ->where('ptd.id', '=', $value->id)
                        ->first();

                    $images = DB::table('productTransferSentImages as pti')
                        ->join('productTransferDetails as ptd', 'pti.productTransferDetailId', 'ptd.id')
                        ->select(
                            'pti.realImageName',
                            'pti.label',
                            'pti.imagePath',
                        )
                        ->where('pti.productTransferDetailId', '=', $value->id)
                        ->where('pti.isDeleted', '=', 0)
                        ->get();

                    $datas[] = array(
                        'id' => $prd->id,
                        'fullName' => $prd->fullName,
                        'productType' => $prd->productType,
                        'quantity' => $prd->quantity,
                        'remark' => $prd->remark,
                        'additionalCost' => $prd->additionalCost,
                        'images' => $images
                    );
                }

                $data->detail = $datas;
                return responseList($data);
            }
        } else {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['Data does not exist!'],
            ], 422);
        }
    }

    public function export(Request $request)
    {
        $tmp = "";
        $fileName = "";
        $date = Carbon::now()->format('d-m-y');
        $role = role($request->user()->id);
        $locations = $request->locationDestinationId;
        $status = $request->status;
        $statusName = "";

        if (!is_null($locations)) {

            $location = DB::table('location')
                ->select('locationName')
                ->whereIn('id', $locations)
                ->get();

            if ($location) {

                foreach ($location as $key) {
                    $tmp = $tmp . (string) $key->locationName . ",";
                }
            }
            $tmp = rtrim($tmp, ", ");
        }

        if ($status === 0) {
            $statusName = "Draft";
        } elseif ($status === 1) {
            $statusName = "Waiting for Approval";
        } elseif ($status === 2) {
            $statusName = "Rejected";
        } elseif ($status === 3) {
            $statusName = "Approved";
        } elseif ($status === 4) {
            $statusName = "Product Sent";
        } elseif ($status === 5) {
            $statusName = "Product Received";
        }

        if ($tmp == "") {
            if ($statusName == "") {
                $fileName = "Rekap Produk Transfer " . $date . ".xlsx";
            } else {
                $fileName = "Rekap Produk Transfer " . $statusName . " " . $date . ".xlsx";
            }
        } else {
            if ($statusName == "") {
                $fileName = "Rekap Produk Transfer " . $tmp . " " . $date . ".xlsx";
            } else {
                $fileName = "Rekap Produk Transfer " . $statusName . " " . $tmp . " " . $date . ".xlsx";
            }
        }

        return Excel::download(
            new ProductTransferReport(
                $request->orderValue,
                $request->orderColumn,
                $request->locationDestinationId,
                $request->status
            ),
            $fileName
        );
    }

    private function validationApproval(Request $request)
    {
        $role = role($request->user()->id);

        if ($role != 'Administrator' && $role != 'Office') {

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['Acccess Denied!'],
            ], 422);
        }

        $product = ProductTransfer::find($request->id);

        if (!$product) {

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['Data not exist!'],
            ], 422);
        }
    }

    public function approval(Request $request)
    {
        DB::beginTransaction();
        try {

            $validate = Validator::make($request->all(), [
                'productTransferId' => 'required|integer',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();

                return responseInvalid([$errors]);
            }

            $isAdmin = false;

            if (adminAccess($request->user()->id)) {
                $isAdmin = true;
            }

            $find = ProductTransfer::find($request->productTransferId);

            if (!$find) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Data Transfer Product not found!'],
                ], 422);
            } elseif ($find->numberId == 'draft') {
                return responseInvalid(['Transfer Product currenlty still in draft!']);
            }

            if ($request->isAcceptedAll == '1') {

                $detail = productTransferDetails::where('productTransferId', '=', $request->productTransferId)->get();

                foreach ($detail as $value) {

                    $detail2 = productTransferDetails::find($value['id']);

                    if ($isAdmin) {

                        if ($detail2->isAdminApproval == 1) {
                            $detail2->isApprovedAdmin = 1;
                            $detail2->userIdAdmin = $request->user()->id;
                            $detail2->adminApprovedAt = Carbon::now();
                        }
                    } else {
                        $detail2->isApprovedOffice = 1;
                        $detail2->userIdOffice = $request->user()->id;
                        $detail2->officeApprovedAt = Carbon::now();
                    }

                    $detail2->accepted = $detail2->quantity;
                    $detail2->updated_at = Carbon::now();
                    $detail2->save();
                }

                $checkAdminApproval = DB::table('productTransferDetails')
                    ->where('productTransferId', '=', $request->productTransferId)
                    ->where('isAdminApproval', '=', 1)
                    ->get();

                if ($checkAdminApproval) {

                    $adminApproved = DB::table('productTransferDetails')
                        ->where('productTransferId', '=', $request->productTransferId)
                        ->where('isApprovedAdmin', '=', 1)
                        ->get();

                    if (count($checkAdminApproval) == count($adminApproved)) {
                        $find->status = 3;
                        $find->updated_at = Carbon::now();
                        $find->userUpdateId = $request->user()->id;
                        $find->save();
                    }
                } else {
                    $find->status = 3;
                    $find->updated_at = Carbon::now();
                    $find->userUpdateId = $request->user()->id;
                    $find->save();
                }
            } elseif ($request->isRejectedAll == '1') {
                $find->status = 2;
                $find->updated_at = Carbon::now();
                $find->userUpdateId = $request->user()->id;
                $find->save();

                $detail = productTransferDetails::where('productTransferId', '=', $request->productTransferId)->get();

                foreach ($detail as $value) {

                    $detail2 = productTransferDetails::find($value['id']);

                    if ($isAdmin) {
                        $detail2->isApprovedAdmin = 2;
                        $detail2->userIdAdmin = $request->user()->id;
                        $detail2->adminApprovedAt = Carbon::now();
                        $detail2->reasonAdmin = $request->reasonRejectAll;
                    } else {
                        $detail2->isApprovedOffice = 2;
                        $detail2->userIdOffice = $request->user()->id;
                        $detail2->officeApprovedAt = Carbon::now();
                        $detail2->reasonOffice = $request->reasonRejectAll;
                    }

                    $detail2->rejected = $detail2->quantity;
                    $detail2->updated_at = Carbon::now();
                    $detail2->save();
                }
            } else {

                $datas = json_decode($request->productTransfers, true);

                $validate = Validator::make(
                    $datas,
                    [
                        '*.productTransferDetailId' => 'required|integer',
                        '*.transferQuantity' => 'required|integer',
                        '*.accepted' => 'required|integer',
                        '*.rejected' => 'required|integer',
                    ],
                    [
                        '*.productTransferDetailId.required' => 'Product Transfer Detail Id Should be Required!',
                        '*.productTransferDetailId.integer' => 'Product Transfer Detail Id Should be Integer!',
                        '*.transferQuantity.required' => 'Transfer Quantity Should be Required!',
                        '*.transferQuantity.integer' => 'Transfer Quantity Should be Integer!',
                        '*.accepted.required' => 'Accepeted Should be Required!',
                        '*.accepted.integer' => 'Accepeted Should be Integer!',
                        '*.rejected.required' => 'Rejected Should be Required!',
                        '*.rejected.integer' => 'Rejected Should be Integer!',
                    ]
                );

                if ($validate->fails()) {
                    $errors = $validate->errors()->first();

                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => [$errors],
                    ], 422);
                }

                foreach ($datas as $value) {

                    $validateProductTransfer = productTransferDetails::where('id', '=', $value['productTransferDetailId'])
                        ->where('productTransferId', '=', $request->productTransferId)->first();

                    if (!$validateProductTransfer) {

                        return responseInvalid(['Data Product Transfer with Product Transfer Detail are not valid!']);
                    }

                    $findQuantity = productTransferDetails::find($value['productTransferDetailId']);

                    if (!$findQuantity) {

                        return responseInvalid(['Data not found!']);
                    }

                    if ($findQuantity->quantity != $value['transferQuantity']) {
                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' => ['Quantity Transfer Product not same with system!'],
                        ], 422);
                    }

                    $totalApproval = $value['accepted'] + $value['rejected'];

                    if ($totalApproval != $value['transferQuantity']) {
                        return response()->json([
                            'message' => 'The given data was invalid.',
                            'errors' => ['Total data approval and reject are not same with total Transfer Product!'],
                        ], 422);
                    }

                    if ($isAdmin) {
                        if ($value['rejected'] == $value['transferQuantity']) {

                            if ($value['reasonReject'] == '') {
                                return responseInvalid(['Reason Reject must be filled!']);
                            }

                            $findQuantity->isApprovedAdmin = 2;
                            $findQuantity->userIdAdmin = $request->user()->id;
                            $findQuantity->adminApprovedAt = Carbon::now();
                            $findQuantity->reasonAdmin = $value['reasonReject'];

                            $findQuantity->rejected = $value['rejected'];
                            $findQuantity->updated_at = Carbon::now();
                            $findQuantity->save();
                        } else if ($value['rejected'] > 0) {

                            if ($value['reasonReject'] == '') {
                                return responseInvalid(['Reason Reject must be filled!']);
                            }

                            $findQuantity->isApprovedAdmin = 1;
                            $findQuantity->userIdAdmin = $request->user()->id;
                            $findQuantity->adminApprovedAt = Carbon::now();
                            $findQuantity->reasonAdmin = $value['reasonReject'];

                            $findQuantity->rejected = $value['rejected'];
                            $findQuantity->accepted = $value['accepted'];
                            $findQuantity->updated_at = Carbon::now();
                            $findQuantity->save();
                        } elseif ($value['accepted'] == $value['transferQuantity']) {
                            $findQuantity->isApprovedAdmin = 1;
                            $findQuantity->userIdAdmin = $request->user()->id;
                            $findQuantity->adminApprovedAt = Carbon::now();

                            $findQuantity->accepted = $value['accepted'];
                            $findQuantity->updated_at = Carbon::now();
                            $findQuantity->save();
                        }
                    } else {
                        if ($value['rejected'] == $value['transferQuantity']) {

                            if ($value['reasonReject'] == '') {
                                return responseInvalid(['Reason Reject must be filled!']);
                            }

                            $findQuantity->isApprovedOffice = 2;
                            $findQuantity->userIdOffice = $request->user()->id;
                            $findQuantity->officeApprovedAt = Carbon::now();
                            $findQuantity->reasonOffice = $value['reasonReject'];

                            $findQuantity->rejected = $value['rejected'];
                            $findQuantity->updated_at = Carbon::now();
                            $findQuantity->save();
                        } else if ($value['rejected'] > 0) {

                            if ($value['reasonReject'] == '') {
                                return responseInvalid(['Reason Reject must be filled!']);
                            }

                            $findQuantity->isApprovedOffice = 1;
                            $findQuantity->userIdOffice = $request->user()->id;
                            $findQuantity->officeApprovedAt = Carbon::now();
                            $findQuantity->reasonOffice = $value['reasonReject'];

                            $findQuantity->rejected = $value['rejected'];
                            $findQuantity->accepted = $value['accepted'];
                            $findQuantity->updated_at = Carbon::now();
                            $findQuantity->save();
                        } elseif ($value['accepted'] == $value['transferQuantity']) {
                            $findQuantity->isApprovedOffice = 1;
                            $findQuantity->userIdOffice = $request->user()->id;
                            $findQuantity->officeApprovedAt = Carbon::now();

                            $findQuantity->accepted = $value['accepted'];
                            $findQuantity->updated_at = Carbon::now();
                            $findQuantity->save();
                        }
                    }
                }

                $prodTransfer = ProductTransfer::find($request->productTransferId);

                $findDetailAdmin = DB::table('productTransferDetails')
                    ->where('productTransferId', '=', $request->productTransferId)
                    ->where('isAdminApproval', '=', 1)
                    ->get();

                if (count($findDetailAdmin) > 0) {
                    $findAdminApproval = DB::table('productTransferDetails')
                        ->where('productTransferId', '=', $request->productTransferId)
                        ->where('isApprovedAdmin', '=', 1)
                        ->get();

                    if (count($findDetailAdmin) == count($findAdminApproval)) {
                        $prodTransfer->status = 3;
                        $prodTransfer->updated_at = Carbon::now();
                    }
                } else {
                    $findOfficeApproval = DB::table('productTransferDetails')
                        ->where('productTransferId', '=', $request->productTransferId)
                        ->where('isApprovedOffice', '=', 1)
                        ->get();

                    if (count($findOfficeApproval) > 0) {
                        $prodTransfer->status = 3;
                        $prodTransfer->updated_at = Carbon::now();
                    }
                }

                $prodTransfer->save();
            }

            DB::commit();
            return responseUpdate();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function sentReceiver(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'productTransferId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();

            return responseInvalid([$errors]);
        }

        $prod = ProductTransfer::find($request->productTransferId);

        if ($prod->status != 3) {
            return responseInvalid(['Only accepted Transfer can be sent to Receiver!']);
        }

        $prod->status = 4;
        $prod->save();

        return responseUpdate();
    }

    public function receive(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'isFinished' => 'required|integer|in:0,1',
            'productTransferId' => 'required|integer',
        ]);

        if ($validate->fails()) {

            $errors = $validate->errors()->all();
            return responseInvalid([$errors]);
        }

        $master = ProductTransfer::find($request->productTransferId);

        if (!$master) {
            return responseInvalid(['Product Transfer are not found!']);
        }

        $datas = json_decode($request->productTranfers, true);

        $validate = Validator::make(
            $datas,
            [
                '*.productTransferDetailId' => 'required|integer',
                '*.accepted' => 'required|integer',
                '*.received' => 'required|integer',
                '*.canceled' => 'required|integer',
                '*.expiredDate' => 'required|date',
                '*.reference' => 'required|string|max:255',
            ],
            [
                '*.productTransferDetailId.required' => 'Product Transfer Detail Id Should be Required!',
                '*.productTransferDetailId.integer' => 'Product Transfer Detail Id Should be Integer!',
                '*.received.required' => 'Received Should be Required!',
                '*.received.integer' => 'Received Should be Integer!',
                '*.accepted.required' => 'Accepted Should be Required!',
                '*.accepted.integer' => 'Accepted Should be Integer!',
                '*.expiredDate.required' => 'Expired Date Should be Required!',
                '*.expiredDate.date' => 'Expired Date Should be Date!',
                '*.reference.required' => 'Reference Should be Required!',
                '*.reference.string' => 'Reference Should be String!',
            ]
        );

        if ($validate->fails()) {
            $errors = $validate->errors()->first();

            return responseInvalid([$errors]);
        }

        foreach ($datas as $value) {

            $dtl = productTransferDetails::find($value['productTransferDetailId']);

            if ($value['accepted'] != ($value['received'] + $value['canceled'])) {
                // if ($dtl->productType == 'productSell') {

                // } elseif ($dtl->productType == 'productClinic') {
                //     $find = ProductClinic::find($dtl->productIdOrigin);
                // }

                $find = Products::find($dtl->productIdOrigin);

                return responseInvalid(['Total accepted not same with received and canceled at product ' . $find->fullName]);
            }

            if ($dtl->accepted != $value['accepted']) {
                // if ($dtl->productType == 'productSell') {

                // } elseif ($dtl->productType == 'productClinic') {
                //     $find = ProductClinic::find($dtl->productIdOrigin);
                // }

                $find = Products::find($dtl->productIdOrigin);
                return responseInvalid(['Total accepted not same with system at product ' . $find->fullName]);
            }
        }

        foreach ($datas as $value) {
            $dtl = productTransferDetails::find($value['productTransferDetailId']);

            $dtl->received = $value['received'];
            $dtl->canceled = $value['canceled'];
            $dtl->reasonCancel = $value['reasonCancel'];
            $dtl->userId = $request->user()->id;
            $dtl->updated_at = Carbon::now();
            $dtl->save();

            $img = $value['imagePath'];

            list($type, $img) = explode(';', $img);
            list(, $img)      = explode(',', $img);
            $img = base64_decode($img);

            $image = str_replace('data:image/', '', $value['imagePath']);
            $image = explode(';base64,', $image);
            $imageName = Str::random(40) . '.' . $image[0];

            file_put_contents(public_path() . '/ProductTransferReceiveImages/' . $imageName, $img);

            productTransferReceiveImages::create(
                [
                    'productTransferDetailId' => $value['productTransferDetailId'],
                    'realImageName' => $value['originalName'],
                    'imagePath' => '/ProductTransferReceiveImages' . '/' . $imageName,
                    'label' => '',
                    'userId' => $request->user()->id,
                ]
            );

            if ($request->isFinished) {

                $detail = productTransferDetails::find($value['productTransferDetailId']);

                // if ($detail->productType == 'productSell') {

                // } elseif ($detail->productType == 'productClinic') {


                // }
                $product = Products::find($detail->productIdOrigin);
                $LocOrig = ProductLocations::where('productId', '=', $detail->productIdOrigin)
                    ->first();

                $LocDest = ProductLocations::where('productId', '=', $detail->productIdDestination)
                    ->first();

                if (!$LocDest) {

                    $newProduct = $product->replicate();
                    $newProduct->created_at = Carbon::now();
                    $newProduct->updated_at = Carbon::now();
                    $newProduct->userId = $request->user()->id;
                    $newProduct->save();

                    $categories = ProductCoreCategories::where('productId', '=', $detail->productIdOrigin)->get();

                    foreach ($categories as $res) {

                        $category = ProductCoreCategories::find($res['id']);

                        if ($category) {
                            $newCategory = $category->replicate();
                            $newCategory->productId = $newProduct->id;
                            $newCategory->created_at = Carbon::now();
                            $newCategory->updated_at = Carbon::now();
                            $newCategory->userId = $request->user()->id;
                            $newCategory->save();
                        }
                    }

                    $prodLoc = ProductLocations::find($LocOrig->id);

                    $newProdLoc = $prodLoc->replicate();
                    $newProdLoc->productId = $newProduct->id;
                    $newProdLoc->locationId = $newProduct->id;
                    $newProdLoc->inStock = $value['received'];
                    $newProdLoc->diffStock = $value['received'] - $prodLoc->lowStock;
                    $newProdLoc->userId = $request->user()->id;
                    $newProdLoc->created_at = Carbon::now();
                    $newProdLoc->updated_at = Carbon::now();
                    $newProdLoc->save();

                    productClinicLog($newProduct->id, "Create New Item", "", $value['received'], $value['received'], $request->user()->id);

                    if ($product->pricingStatus == "CustomerGroups") {

                        $productCustomerGroups = ProductCustomerGroups::where('productId', '=', $detail->productIdOrigin)->get();

                        foreach ($productCustomerGroups as $res) {

                            $prod = ProductCustomerGroups::find($res['id']);

                            if ($prod) {
                                $newProduct = $prod->replicate();
                                $newProduct->productId = $newProduct->id;
                                $newProduct->created_at = Carbon::now();
                                $newProduct->updated_at = Carbon::now();
                                $newProduct->userId = $request->user()->id;
                                $newProduct->save();
                            }
                        }
                    }

                    if ($product->pricingStatus == "PriceLocations") {

                        $prodPriceLoc = ProductPriceLocations::where('productId', '=', $detail->productIdOrigin)->get();

                        foreach ($prodPriceLoc as $res) {

                            $prodLoc = ProductPriceLocations::find($res['id']);

                            if ($prodLoc) {
                                $newProductLoc = $prodLoc->replicate();
                                $newProductLoc->productId = $newProduct->id;
                                $newProductLoc->created_at = Carbon::now();
                                $newProductLoc->updated_at = Carbon::now();
                                $newProductLoc->userId = $request->user()->id;
                                $newProductLoc->save();
                            }
                        }
                    }

                    if ($product->pricingStatus == "Quantities") {

                        $prodQty = ProductQuantitiess::where('productId', '=', $detail->productIdOrigin)->get();

                        foreach ($prodQty as $res) {

                            $prodQty = ProductQuantitiess::find($res['id']);

                            if ($prodQty) {
                                $newProductQty = $prodQty->replicate();
                                $newProductQty->productId = $newProduct->id;
                                $newProductQty->created_at = Carbon::now();
                                $newProductQty->updated_at = Carbon::now();
                                $newProductQty->userId = $request->user()->id;
                                $newProductQty->save();
                            }
                        }
                    }

                    $prodReminder = ProductReminders::where('productId', '=', $detail->productIdOrigin)->get();

                    foreach ($prodReminder as $res) {

                        $prodReminder = ProductReminder::find($res['id']);

                        if ($prodReminder) {
                            $newProductReminder = $prodReminder->replicate();
                            $newProductReminder->productId = $newProduct->id;
                            $newProductReminder->created_at = Carbon::now();
                            $newProductReminder->updated_at = Carbon::now();
                            $newProductReminder->userId = $request->user()->id;
                            $newProductReminder->save();
                        }
                    }

                    $oldProdLoc = ProductLocations::where('productId', '=', $detail->productIdOrigin)->first();

                    $instock = $oldProdLoc->inStock;
                    $lowstock = $oldProdLoc->lowStock;

                    $oldProdLoc->inStock = $instock - $value['received'];
                    $oldProdLoc->diffStock = ($instock - $value['received']) - $lowstock;
                    $oldProdLoc->updated_at = Carbon::now();
                    $oldProdLoc->save();

                    $product->updated_at = Carbon::now();
                    $product->save();
                    ProductClinicLog($detail->productIdOrigin, 'Transfer Product', 'Product Decrease', $value['received'], $instock - $value['received'], $request->user()->id);
                } else {

                    $instock = $LocDest->inStock;
                    $lowstock = $LocDest->lowStock;

                    $LocDest->inStock = $instock + $value['received'];
                    $LocDest->diffStock = ($instock + $value['received']) - $lowstock;
                    $LocDest->updated_at = Carbon::now();
                    $LocDest->save();
                    ProductClinicLog($detail->productIdDestination, 'Transfer Product', 'Product Increase', $value['received'], $instock + $value['received'], $request->user()->id);


                    $instock = $LocOrig->inStock;
                    $lowstock = $LocOrig->lowStock;

                    $LocOrig->inStock = $instock - $value['received'];
                    $LocOrig->diffStock = ($instock - $value['received']) - $lowstock;
                    $LocOrig->updated_at = Carbon::now();
                    $LocOrig->save();
                    ProductClinicLog($detail->productIdOrigin, 'Transfer Product', 'Product Decrease', $value['received'], $instock - $value['received'], $request->user()->id);
                }

                $findBatch = ProductBatches::where('productId', '=', $detail->productIdDestination)->count();

                $number = "";

                if ($findBatch == 0) {
                    $number = Carbon::today();
                    $number = 'BC-' . $number->format('Ymd') . str_pad(0 + 1, 5, 0, STR_PAD_LEFT);
                } else {
                    $number = Carbon::today();
                    $number = 'BC-' . $number->format('Ymd') . str_pad($findBatch + 1, 5, 0, STR_PAD_LEFT);
                }

                ProductBatches::create([
                    'batchNumber' => $number,
                    'productId' => $detail->productIdDestination,
                    'productRestockId' => 0,
                    'productTransferId' => $master->id,
                    'productTransferDetailId' => $request->productTransferDetailId,
                    'transferNumber' => $master->transferNumber,
                    'productRestockDetailId' => 0,
                    'purchaseRequestNumber' => '',
                    'purchaseOrderNumber' => '',
                    'expiredDate' => $value['expiredDate'],
                    'sku' => $value['sku'],
                    'userId' => $request->user()->id,
                ]);
            }
        }

        return responseUpdate();
    }

    function productListWithTwoBranch(Request $request)
    {
        //proses:
        // tidak ada di cabang destination
        // jika memang ada, maka akan melakukan pencarian berdasarkan full name dengan fungsi like, jadi tidak akan membuat produk baru di cabang destination.
        $validate = Validator::make($request->all(), [
            'productType' => 'required|string|in:productSell,productClinic',
            'branchOrigin' => 'required|integer',
        ]);

        if ($validate->fails()) {

            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        // if ($request->productType == 'productSell') {

        // } else if ($request->productType == 'productClinic') {
        //     $data = DB::table('productClinicLocations as psl')
        //         ->join('productClinics as ps', 'psl.productClinicId', 'ps.id')
        //         ->select('ps.id', 'ps.fullName')
        //         ->where('psl.locationId', '=', $request->branchOrigin)
        //         ->get();
        // }

        $data = DB::table('productLocations as psl')
            ->join('products as ps', 'psl.productId', 'ps.id')
            ->select('ps.id', 'ps.fullName')
            ->where('psl.locationId', '=', $request->branchOrigin)
            ->get();

        return response()->json($data, 200);
    }
}
