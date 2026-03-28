<?php

namespace App\Http\Controllers\Transaction;

use DB;
use Validator;
use Carbon\Carbon;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\ProductLocations;
use App\Models\Customer\Customer;
use App\Models\Location\Location;
use App\Models\TransactionPetShop;
use App\Models\Staff\UsersLocation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\TransactionPetShopDetail;

class TransactionPetShopController
{
    public function index(Request $request)
    {
        $itemPerPage = $request->rowPerPage;
        $page = $request->goToPage;

        $data = DB::table('transactionpetshop as tp')
            ->join('customer as c', 'tp.customerId', '=', 'c.id')
            ->join('location as l', 'tp.locationId', '=', 'l.id')
            ->join('users as u', 'tp.userId', '=', 'u.id')
            ->leftJoin('customerGroups as cg', 'c.customerGroupId', '=', 'cg.id')
            ->select(
                'tp.id',
                'tp.registrationNo',
                'tp.locationId',
                'tp.customerId',
                'cg.customerGroup as customerGroup',
                'tp.totalItem',
                'tp.totalUsePromo',
                'tp.totalAmount',
                'c.firstName as customerName',
                'l.locationName',
                'u.firstName as createdBy',
                'tp.created_at as createdAt'
            )
            ->where('tp.isDeleted', '=', 0);

        $roleId = $request->user()->roleId;

        if ($roleId == 1) {
            if ($request->locationId) {
                $data = $data->whereIn('tp.locationId', $request->locationId);
            }
        } else {
            $locations = UsersLocation::where('usersId', $request->user()->id)->pluck('id')->toArray();
            $data = $data->whereIn('tp.locationId', $locations);
        }

        if ($request->customerGroupId) {
            $data = $data->whereIn('c.customerGroupId', $request->customerGroupId);
        }

        if ($request->serviceCategories) {
            $data = $data->whereIn('tp.serviceCategory', $request->serviceCategories);
        }

        if ($request->search) {
            $res = $this->Search($request);
            if ($res) {
                $data = $data->where(function ($query) use ($res, $request) {
                    $query->where($res[0], 'like', '%' . $request->search . '%');
                    for ($i = 1; $i < count($res); $i++) {
                        $query->orWhere($res[$i], 'like', '%' . $request->search . '%');
                    }
                });
            } else {
                return response()->json([
                    'totalPagination' => 0,
                    'data' => []
                ], 200);
            }
        }

        $allowedColumns = [
            'tp.registrationNo',
            'c.firstName',
            'l.locationName',
            'tp.totalAmount',
            'tp.totalItem',
            'tp.totalUsePromo',
            'tp.updated_at'
        ];

        $orderColumn = in_array($request->orderColumn, $allowedColumns) ? $request->orderColumn : 'tp.updated_at';
        $orderValue = in_array(strtolower($request->orderValue), ['asc', 'desc']) ? $request->orderValue : 'desc';

        $data = $data->orderBy(DB::raw($orderColumn), $orderValue);

        $offset = ($page - 1) * $itemPerPage;
        $count_data = $data->count();
        $totalPaging = ceil($count_data / $itemPerPage);

        $data = $data->offset($offset)->limit($itemPerPage)->get();

        return responseIndex($totalPaging, $data);
    }

    private function Search(Request $request)
    {
        $temp_column = [];


        $data = DB::table('transactionpetshop as tp')
            ->select('tp.registrationNo')
            ->where('tp.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('tp.registrationNo', 'like', '%' . $request->search . '%');
        }

        if ($data->exists()) {
            $temp_column[] = 'tp.registrationNo';
        }


        $data = DB::table('transactionpetshop as tp')
            ->join('customer as c', 'c.id', '=', 'tp.customerId')
            ->select('c.firstName')
            ->where('tp.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('c.firstName', 'like', '%' . $request->search . '%');
        }

        if ($data->exists()) {
            $temp_column[] = 'c.firstName';
        }


        $data = DB::table('transactionpetshop as tp')
            ->join('location as l', 'l.id', '=', 'tp.locationId')
            ->select('l.locationName')
            ->where('tp.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('l.locationName', 'like', '%' . $request->search . '%');
        }

        if ($data->exists()) {
            $temp_column[] = 'l.locationName';
        }

        return $temp_column;
    }

    public function create(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'isNewCustomer' => 'required|boolean',
            'locationId' => 'required|integer',
            'serviceCategory' => 'required|string|in:Pet Clinic,Pet Hotel,Pet Salon,Pet Shop,Pacak',
            'paymentMethod' => 'required|integer',
            'productList' => 'nullable|array',
            'productList.*.productId' => 'nullable|integer',
            'productList.*.quantity' => 'integer|min:1',
            'productList.*.price' => 'integer|min:0',
            'productList.*.note' => 'nullable|string',
            'productList.*.promoId' => 'nullable|integer',
            'selectedPromos' => 'nullable|array',
            'selectedPromos.freeItems' => 'nullable|array',
            'selectedPromos.discounts' => 'nullable|array',
            'selectedPromos.bundles' => 'nullable|array',
            'selectedPromos.basedSales' => 'nullable|array',
        ]);

        if ($request->isNewCustomer) {
            $validate->after(function ($validator) use ($request) {
                if (empty($request->customerName)) {
                    $validator->errors()->add('customerName', 'Customer name is required for new customer.');
                }
            });
        } else {
            $validate->after(function ($validator) use ($request) {
                if (empty($request->customerId)) {
                    $validator->errors()->add('customerId', 'Customer ID is required for existing customer.');
                }
            });
        }

        if ($validate->fails()) {
            return responseInvalid($validate->errors()->all());
        }

        DB::beginTransaction();
        try {
            if ($request->isNewCustomer) {
                $cust = DB::table('customer')->insertGetId([
                    'firstName' => $request->customerName,
                    'locationId' => $request->locationId,
                    'typeId' => 0,
                    'memberNo' => '',
                    'gender' => '',
                    'joinDate' => Carbon::now(),
                    'createdBy' => $request->user()->id,
                    'userUpdateId' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $cust = DB::table('customer')
                    ->select('id', 'isDeleted')
                    ->where('id', $request->customerId)
                    ->where('isDeleted', 0)
                    ->first();

                if (!$cust) {
                    return responseInvalid(['Customer not found.']);
                }

                $cust = $cust->id;
            }

            $promoResult = null;
            $promoNotes = [];
            $totalDiscount = 0;

            $locationId = $request->locationId;
            $now = Carbon::now();
            $tahun = $now->format('Y');
            $bulan = $now->format('m');

            $jumlahTransaksi = DB::table('transactionpetshop')
                ->where('locationId', $locationId)
                ->whereYear('created_at', $tahun)
                ->whereMonth('created_at', $bulan)
                ->count();

            $nomorUrut = str_pad($jumlahTransaksi + 1, 4, '0', STR_PAD_LEFT);

            $nomorNota = "INV/PS/{$locationId}/{$tahun}/{$bulan}/{$nomorUrut}";

            $lowStockWarnings = [];
            foreach ($request->productList as $prod) {
                $productLoc = DB::table('productLocations')
                    ->where('locationId', $request->locationId)
                    ->where('productId', $prod['productId'])
                    ->first();

                if (!$productLoc) {
                    return responseInvalid(["Produk ID {$prod['productId']} tidak ditemukan di cabang ini."]);
                }

                $remainingStock = $productLoc->inStock - $prod['quantity'];

                if ($prod['quantity'] > $productLoc->inStock) {
                    return responseInvalid([
                        "Stok produk '{$prod['productId']}' tidak mencukupi. Tersedia: {$productLoc->inStock}, Diminta: {$prod['quantity']}"
                    ]);
                }

                if ($remainingStock < $productLoc->lowStock) {
                    $lowStockWarnings[] = "Stok produk '{$prod['productId']}' akan di bawah batas minimum ({$productLoc->lowStock}). Sisa: {$remainingStock}";
                }
            }

            $discountedProducts = [];
            $discountDetails = [];
            $discountAmount = 0;

            if (!empty($request->selectedPromos['discounts'])) {
                try {
                    $promoResultDiscounts = $this->handleDiscountPromos(
                        $request->locationId,
                        $request->selectedPromos['discounts'],
                        $request->productList
                    );

                    $discountedProducts = $promoResultDiscounts['discountedProducts'];
                    $discountDetails = $promoResultDiscounts['discountDetails'];
                    $discountAmount = $promoResultDiscounts['totalDiscount'];

                    $promoNotes = array_merge($promoNotes, $discountDetails);

                    $totalDiscount += $discountAmount;
                } catch (\Exception $e) {
                    return responseInvalid([$e->getMessage()]);
                }
            }

            $trxCount = DB::table('transactionpetshop')
                ->where('locationId', $request->locationId)
                ->count();
            $regisNo = 'RPC.TRX.' . $request->locationId . '.' . str_pad($trxCount + 1, 8, '0', STR_PAD_LEFT);

            $totalAmount = 0;
            $totalPayment = 0;

            if (!empty($promoResult['purchases'])) {
                $totalAmount = $promoResult['subtotal'] ?? 0;
                $totalPayment = $promoResult['total_payment'] ?? 0;
            } else {
                $totalAmount = 0;
                foreach ($request->productList as $prod) {
                    $totalAmount += $prod['quantity'] * $prod['price'];
                }
                $totalPayment = $totalAmount;
            }


            $totalUsePromo = 0;

            if (!empty($request->selectedPromos)) {
                $totalUsePromo += count($request->selectedPromos['freeItems'] ?? []);
                $totalUsePromo += count($request->selectedPromos['discounts'] ?? []);
                $totalUsePromo += count($request->selectedPromos['bundles'] ?? []);
                $totalUsePromo += count($request->selectedPromos['basedSales'] ?? []);
            }

            $tranId = DB::table('transactionpetshop')->insertGetId([
                'registrationNo' => $regisNo,
                'no_nota' => $nomorNota,
                'locationId' => $request->locationId,
                'customerId' => $cust,
                'note' => $request->notes,
                'paymentMethod' => $request->paymentMethod,
                'userId' => $request->user()->id,
                'totalAmount' => $totalAmount,
                'totalDiscount' => $totalDiscount,
                'totalPayment' => $totalPayment,
                'promoNotes' => !empty($promoNotes) ? json_encode($promoNotes) : null,
                'isPayed' => false,
                'totalUsePromo' => $totalUsePromo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $tran = (object) [
                'id' => $tranId
            ];

            $totalItem = 0;

            $bundleItems = [];

            if (!empty($request->selectedPromos['freeItems'])) {
                try {
                    $promoResultFreeItems = $this->handleFreeItemsPromo(
                        $request->locationId,
                        $request->selectedPromos['freeItems'],
                        $request->user()->id,
                        $tran->id
                    );
                    $freeItems = $promoResultFreeItems['freeItems'];
                    $lowStockWarnings = array_merge($lowStockWarnings, $promoResultFreeItems['lowStockWarnings']);
                } catch (\Exception $e) {
                    return responseInvalid([$e->getMessage()]);
                }
            }

            if (!empty($request->selectedPromos['bundles'])) {
                try {
                    $promoResultBundle = $this->handleBundlePromos(
                        $request->locationId,
                        $request->selectedPromos['bundles'],
                        $request->user()->id,
                        $tran->id
                    );

                    $bundleItems = $promoResultBundle['bundleItems'];
                    $lowStockWarnings = array_merge($lowStockWarnings, $promoResultBundle['lowStockWarnings']);
                    $promoNotes = array_merge($promoNotes, $promoResultBundle['promoNotes'] ?? []);
                    $totalAmount += $promoResultBundle['bundleTotalAmount'] ?? 0;
                    $totalItem += $promoResultBundle['bundleTotalItem'] ?? 0;

                    Log::debug('test', [$totalAmount, $totalItem, $promoResultBundle['bundleTotalAmount']]);
                } catch (\Exception $e) {
                    return responseInvalid([$e->getMessage()]);
                }
            }

            $totalFromProductList = 0;
            foreach ($request->productList as $prod) {
                $totalFromProductList += $prod['quantity'] * $prod['price'];
            }

            if (!empty($request->selectedPromos['basedSales'])) {
                try {
                    $promoResultBasedSales = $this->handleBasedSalesPromo(
                        $request->selectedPromos['basedSales'],
                        $totalFromProductList
                    );

                    $totalDiscount += $promoResultBasedSales['discount'];
                    $totalPayment -= $promoResultBasedSales['discount'];
                    $promoNotes[] = $promoResultBasedSales['note'];
                } catch (\Exception $e) {
                    return responseInvalid([$e->getMessage()]);
                }
            }

            // if (
            //     empty($request->selectedPromos['freeItems']) &&
            //     empty($request->selectedPromos['bundles']) &&
            //     empty($request->selectedPromos['discounts']) &&
            //     (empty($promoResult) || empty($promoResult['purchases']))
            // )

            foreach ($request->productList as $prod) {
                $unitPrice = $prod['price'];
                $quantity = $prod['quantity'];
                $discount = 0;
                $finalPrice = $unitPrice;
                $promoId = $prod['promoId'] ?? null;

                $discountProduct = collect($discountedProducts)->firstWhere('productId', $prod['productId']);
                if ($discountProduct) {
                    $discount = $discountProduct['discount'];
                    $finalPrice = $discountProduct['finalPrice'];
                    $promoId = $discountProduct['promoId'];
                }

                $totalFinalPrice = $quantity * $finalPrice;

                DB::table('transactionpetshopdetail')->insert([
                    'transactionpetshopId' => $tran->id,
                    'productId' => $prod['productId'],
                    'quantity' => $quantity,
                    'price' => $unitPrice,
                    'discount' => $discount,
                    'final_price' => $finalPrice,
                    'total_final_price' => $totalFinalPrice,
                    'promoId' => $promoId,
                    'isDeleted' => false,
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('productLocations')
                    ->where('locationId', $request->locationId)
                    ->where('productId', $prod['productId'])
                    ->decrement('inStock', $quantity);

                DB::table('productLocations')
                    ->where('locationId', $request->locationId)
                    ->where('productId', $prod['productId'])
                    ->decrement('diffStock', $quantity);

                $totalItem += $quantity;
            }

            DB::table('transactionpetshop')
                ->where('id', $tran->id)
                ->update([
                    'totalAmount' => $totalAmount,
                    'totalDiscount' => $totalDiscount,
                    'totalPayment' => $totalPayment,
                    'promoNotes' => !empty($promoNotes) ? json_encode($promoNotes) : null,
                    'totalItem' => $totalItem,
                ]);
            transactionPetshopLog($tran->id, 'New Transaction', '', $request->user()->id);

            updateLastTransaction($cust);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi berhasil dibuat.',
                'lowStockWarnings' => $lowStockWarnings,
                'transactionId' => $tran->id,
                'promoApplied' => $totalUsePromo > 0,
                'totalUsePromo' => $totalUsePromo,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return responseInvalid([$e->getMessage()]);
        }
    }

    public function update(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|integer',
            'isNewCustomer' => 'required|boolean',
            'locationId' => 'required|integer',
            'serviceCategory' => 'required|string|in:Pet Clinic,Pet Hotel,Pet Salon,Pet Shop,Pacak',
            'paymentMethod' => 'required|integer',
            'productList' => 'nullable|array',
            'productList.*.productId' => 'nullable|integer',
            'productList.*.quantity' => 'integer|min:1',
            'productList.*.price' => 'integer|min:0',
            'productList.*.note' => 'nullable|string',
            'productList.*.promoId' => 'nullable|integer',
            'selectedPromos' => 'nullable|array',
            'selectedPromos.freeItems' => 'nullable|array',
            'selectedPromos.discounts' => 'nullable|array',
            'selectedPromos.bundles' => 'nullable|array',
            'selectedPromos.basedSales' => 'nullable|array',
        ]);

        if ($request->isNewCustomer) {
            $validate->after(function ($validator) use ($request) {
                if (empty($request->customerName)) {
                    $validator->errors()->add('customerName', 'Customer name is required for new customer.');
                }
            });
        } else {
            $validate->after(function ($validator) use ($request) {
                if (empty($request->customerId)) {
                    $validator->errors()->add('customerId', 'Customer ID is required for existing customer.');
                }
            });
        }

        if ($validate->fails()) {
            return responseInvalid($validate->errors()->all());
        }

        $existingTransaction = DB::table('transactionpetshop')
            ->where('id', $request->id)
            ->where('isDeleted', 0)
            ->first();

        if (!$existingTransaction) {
            return responseInvalid(['Transaction not found.']);
        }

        DB::beginTransaction();
        try {

            $this->restoreOldTransactionData($request->id);


            if ($request->isNewCustomer) {
                $cust = DB::table('customer')->insertGetId([
                    'firstName' => $request->customerName,
                    'locationId' => $request->locationId,
                    'typeId' => 0,
                    'memberNo' => '',
                    'gender' => '',
                    'joinDate' => Carbon::now(),
                    'createdBy' => $request->user()->id,
                    'userUpdateId' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $cust = DB::table('customer')
                    ->select('id', 'isDeleted')
                    ->where('id', $request->customerId)
                    ->where('isDeleted', 0)
                    ->first();

                if (!$cust) {
                    return responseInvalid(['Customer not found.']);
                }
                $cust = $cust->id;
            }


            $lowStockWarnings = $this->validateStockAvailability($request->locationId, $request->productList, $request->selectedPromos ?? []);


            $totalAmountBeforeDiscount = 0;
            $totalAmountAfterDiscount = 0;
            $totalDiscount = 0;
            $totalItem = 0;
            $promoNotes = [];
            $discountedProducts = [];


            $baseTotal = 0;
            foreach ($request->productList as $prod) {
                $baseTotal += $prod['quantity'] * $prod['price'];
            }


            $basedSalesDiscount = 0;
            if (!empty($request->selectedPromos['basedSales'])) {
                $basedSalesResult = $this->handleBasedSalesPromo($request->selectedPromos['basedSales'], $baseTotal);
                $basedSalesDiscount = $basedSalesResult['discount'];
                $promoNotes[] = $basedSalesResult['note'];
            }


            if (!empty($request->selectedPromos['discounts'])) {
                $discountResult = $this->handleDiscountPromos(
                    $request->locationId,
                    $request->selectedPromos['discounts'],
                    $request->productList
                );
                $discountedProducts = $discountResult['discountedProducts'];
                $totalDiscount += $discountResult['totalDiscount'];
                $promoNotes = array_merge($promoNotes, $discountResult['discountDetails']);
            }


            foreach ($request->productList as $prod) {
                $unitPrice = $prod['price'];
                $quantity = $prod['quantity'];
                $discount = 0;
                $finalPrice = $unitPrice;
                $promoId = $prod['promoId'] ?? null;

                $discountProduct = collect($discountedProducts)->firstWhere('productId', $prod['productId']);
                if ($discountProduct) {
                    $discount = $discountProduct['discount'];
                    $finalPrice = $discountProduct['finalPrice'];
                    $promoId = $discountProduct['promoId'];
                }

                $totalFinalPrice = $quantity * $finalPrice;


                DB::table('transactionpetshopdetail')->insert([
                    'transactionpetshopId' => $request->id,
                    'productId' => $prod['productId'],
                    'quantity' => $quantity,
                    'price' => $unitPrice,
                    'discount' => $discount,
                    'final_price' => $finalPrice,
                    'total_final_price' => $totalFinalPrice,
                    'promoId' => $promoId,
                    'isDeleted' => false,
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);


                $this->updateProductStock($request->locationId, $prod['productId'], $quantity);


                $totalAmountBeforeDiscount += $unitPrice * $quantity;
                $totalAmountAfterDiscount += $finalPrice * $quantity;
                $totalItem += $quantity;
            }


            if (!empty($request->selectedPromos['freeItems'])) {
                $freeItemsResult = $this->handleFreeItemsPromo(
                    $request->locationId,
                    $request->selectedPromos['freeItems'],
                    $request->user()->id,
                    $request->id
                );
                $lowStockWarnings = array_merge($lowStockWarnings, $freeItemsResult['lowStockWarnings']);


                foreach ($freeItemsResult['freeItems'] as $freeItem) {
                    $totalItem += $freeItem->quantityFreeItem;
                }
            }


            if (!empty($request->selectedPromos['bundles'])) {
                $bundleResult = $this->handleBundlePromos(
                    $request->locationId,
                    $request->selectedPromos['bundles'],
                    $request->user()->id,
                    $request->id
                );
                $lowStockWarnings = array_merge($lowStockWarnings, $bundleResult['lowStockWarnings']);
                $promoNotes = array_merge($promoNotes, $bundleResult['promoNotes'] ?? []);
                $totalAmountAfterDiscount += $bundleResult['bundleTotalAmount'] ?? 0;
                $totalItem += $bundleResult['bundleTotalItem'] ?? 0;
            }

            $totalDiscount = $totalAmountBeforeDiscount - $totalAmountAfterDiscount;

            if (!empty($request->selectedPromos['basedSales'])) {
                $basedSalesResult = $this->handleBasedSalesPromo($request->selectedPromos['basedSales'], $baseTotal);
                $totalDiscount += $basedSalesResult['discount'];
                $totalAmountAfterDiscount -= $basedSalesResult['discount'];
                $promoNotes[] = $basedSalesResult['note'];
            }


            $totalPayment = $totalAmountAfterDiscount;
            if ($totalPayment < 0) $totalPayment = 0;

            $totalUsePromo = 0;
            if (!empty($request->selectedPromos)) {
                $totalUsePromo += count($request->selectedPromos['freeItems'] ?? []);
                $totalUsePromo += count($request->selectedPromos['discounts'] ?? []);
                $totalUsePromo += count($request->selectedPromos['bundles'] ?? []);
                $totalUsePromo += count($request->selectedPromos['basedSales'] ?? []);
            }

            DB::table('transactionpetshop')
                ->where('id', $request->id)
                ->update([
                    'locationId' => $request->locationId,
                    'customerId' => $cust,
                    'note' => $request->notes,
                    'paymentMethod' => $request->paymentMethod,
                    'totalAmount' => $totalAmountAfterDiscount,
                    'totalDiscount' => $totalDiscount,
                    'totalPayment' => $totalPayment,
                    'totalItem' => $totalItem,
                    'promoNotes' => !empty($promoNotes) ? json_encode($promoNotes) : null,
                    'totalUsePromo' => $totalUsePromo,
                    'userUpdateId' => $request->user()->id,
                    'updated_at' => now(),
                ]);


            transactionPetshopLog($request->id, 'Transaction Updated', '', $request->user()->id);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Transaksi berhasil diupdate.',
                'lowStockWarnings' => $lowStockWarnings,
                'transactionId' => $request->id,
                'promoApplied' => $totalUsePromo > 0,
                'totalUsePromo' => $totalUsePromo,
                'totalAmount' => $totalAmountAfterDiscount,
                'totalDiscount' => $totalDiscount,
                'totalPayment' => $totalPayment,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return responseInvalid([$e->getMessage()]);
        }
    }

    private function restoreOldTransactionData($transactionId)
    {

        $oldTransaction = DB::table('transactionpetshop')->where('id', $transactionId)->first();


        $oldDetails = DB::table('transactionpetshopdetail')
            ->where('transactionpetshopId', $transactionId)
            ->where('isDeleted', false)
            ->get();


        foreach ($oldDetails as $detail) {
            DB::table('productLocations')
                ->where('locationId', $oldTransaction->locationId)
                ->where('productId', $detail->productId)
                ->increment('inStock', $detail->quantity);

            DB::table('productLocations')
                ->where('locationId', $oldTransaction->locationId)
                ->where('productId', $detail->productId)
                ->increment('diffStock', $detail->quantity);
        }


        $oldPromoIds = DB::table('transactionpetshopdetail')
            ->where('transactionpetshopId', $transactionId)
            ->where('isDeleted', false)
            ->whereNotNull('promoId')
            ->pluck('promoId')
            ->unique();

        foreach ($oldPromoIds as $promoId) {

            DB::table('promotionFreeItems')
                ->where('promoMasterId', $promoId)
                ->increment('totalMaxUsage', 1);


            DB::table('promotionDiscounts')
                ->where('promoMasterId', $promoId)
                ->increment('totalMaxUsage', 1);


            DB::table('promotionBundles')
                ->where('promoMasterId', $promoId)
                ->increment('totalMaxUsage', 1);


            DB::table('promotionBasedSales')
                ->where('promoMasterId', $promoId)
                ->increment('totalMaxUsage', 1);
        }


        DB::table('transactionpetshopdetail')
            ->where('transactionpetshopId', $transactionId)
            ->delete();
    }

    private function validateStockAvailability($locationId, $productList, $selectedPromos)
    {
        $lowStockWarnings = [];

        foreach ($productList as $prod) {
            $productLoc = DB::table('productLocations')
                ->where('locationId', $locationId)
                ->where('productId', $prod['productId'])
                ->first();

            if (!$productLoc) {
                throw new \Exception("Produk ID {$prod['productId']} tidak ditemukan di cabang ini.");
            }

            if ($prod['quantity'] > $productLoc->inStock) {
                throw new \Exception("Stok produk '{$prod['productId']}' tidak mencukupi. Tersedia: {$productLoc->inStock}, Diminta: {$prod['quantity']}");
            }

            $remainingStock = $productLoc->inStock - $prod['quantity'];
            if ($remainingStock < $productLoc->lowStock) {
                $lowStockWarnings[] = "Stok produk '{$prod['productId']}' akan di bawah batas minimum ({$productLoc->lowStock}). Sisa: {$remainingStock}";
            }
        }

        if (!empty($selectedPromos['freeItems'])) {
            $freeItems = DB::table('promotionFreeItems')
                ->join('promotionMasters', 'promotionMasters.id', '=', 'promotionFreeItems.promoMasterId')
                ->whereIn('promotionMasters.id', $selectedPromos['freeItems'])
                ->get();

            foreach ($freeItems as $freeItem) {
                $productLoc = DB::table('productLocations')
                    ->where('locationId', $locationId)
                    ->where('productId', $freeItem->productFreeId)
                    ->first();

                if (!$productLoc || $freeItem->quantityFreeItem > $productLoc->inStock) {
                    throw new \Exception("Stok produk bonus '{$freeItem->productFreeId}' tidak mencukupi.");
                }
            }
        }


        if (!empty($selectedPromos['bundles'])) {
            $bundles = DB::table('promotionBundles')
                ->whereIn('promoMasterId', $selectedPromos['bundles'])
                ->get();

            foreach ($bundles as $bundle) {
                $details = DB::table('promotionBundleDetails')
                    ->where('promoBundleId', $bundle->id)
                    ->get();

                foreach ($details as $detail) {
                    $productLoc = DB::table('productLocations')
                        ->where('locationId', $locationId)
                        ->where('productId', $detail->productId)
                        ->first();

                    if (!$productLoc || $detail->quantity > $productLoc->inStock) {
                        throw new \Exception("Stok produk bundle '{$detail->productId}' tidak mencukupi.");
                    }
                }
            }
        }

        return $lowStockWarnings;
    }

    private function updateProductStock($locationId, $productId, $quantity)
    {
        DB::table('productLocations')
            ->where('locationId', $locationId)
            ->where('productId', $productId)
            ->decrement('inStock', $quantity);

        DB::table('productLocations')
            ->where('locationId', $locationId)
            ->where('productId', $productId)
            ->decrement('diffStock', $quantity);
    }

    private function handleFreeItemsPromo($locationId, $promoIds, $userId, $transactionId)
    {
        $lowStockWarnings = [];
        $freeItems = DB::table('promotionFreeItems')
            ->select('promotionFreeItems.*', 'promotionMasters.name')
            ->join('promotionMasters', 'promotionMasters.id', '=', 'promotionFreeItems.promoMasterId')
            ->whereIn('promotionMasters.id', $promoIds)
            ->get();

        foreach ($freeItems as $freeItem) {
            $productLoc = DB::table('productLocations')
                ->where('locationId', $locationId)
                ->where('productId', $freeItem->productFreeId)
                ->first();

            if (!$productLoc) {
                throw new \Exception("Produk bonus ID {$freeItem->productFreeId} tidak ditemukan di cabang ini.");
            }

            $remainingStock = $productLoc->inStock - $freeItem->quantityFreeItem;

            if ($freeItem->quantityFreeItem > $productLoc->inStock) {
                throw new \Exception("Stok produk bonus '{$freeItem->productFreeId}' tidak mencukupi. Tersedia: {$productLoc->inStock}, Dibutuhkan: {$freeItem->quantityFreeItem}");
            }

            if ($remainingStock < $productLoc->lowStock) {
                $lowStockWarnings[] = "Stok produk bonus '{$freeItem->productFreeId}' akan di bawah batas minimum ({$productLoc->lowStock}). Sisa: {$remainingStock}";
            }

            if ($freeItem->totalMaxUsage <= 0) {
                $promoName = $freeItem->name ?? "Promo tidak dikenal";
                throw new \Exception("Promo '{$promoName}' sudah tidak bisa digunakan (habis).");
            }


            DB::table('promotionFreeItems')
                ->where('id', $freeItem->id)
                ->where('totalMaxUsage', '>', 0)
                ->decrement('totalMaxUsage', 1);

            DB::table('productLocations')
                ->where('locationId', $locationId)
                ->where('productId', $freeItem->productFreeId)
                ->decrement('inStock', $freeItem->quantityFreeItem);

            DB::table('transactionpetshopdetail')->insert([
                'transactionpetshopId' => $transactionId,
                'productId' => $freeItem->productFreeId,
                'quantity' => $freeItem->quantityFreeItem,
                'price' => 0,
                'final_price' => 0,
                'promoId' => $freeItem->promoMasterId,
                'isDeleted' => false,
                'userId' => $userId,
                'userUpdateId' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'freeItems' => $freeItems,
            'lowStockWarnings' => $lowStockWarnings
        ];
    }

    private function handleDiscountPromos($locationId, $discountPromoIds, $productList)
    {
        $discountItems = DB::table('promotionDiscounts')
            ->whereIn('promoMasterId', $discountPromoIds)
            ->get();

        $productIds = collect($productList)->pluck('productId')->unique();
        $productNames = DB::table('products')
            ->whereIn('id', $productIds)
            ->pluck('fullName', 'id')
            ->toArray();

        $discountedProducts = [];
        $discountDetails = [];
        $totalDiscount =  0;

        $usedPromoIds = [];

        foreach ($productList as $prod) {
            foreach ($discountItems as $promo) {
                if ($promo->productId == $prod['productId']) {
                    $unitPrice = $prod['price'];
                    $discount = 0;
                    $finalPrice = $unitPrice;

                    if ($promo->percentOrAmount === 'percent') {
                        $discount = $promo->percent;
                        $finalPrice = $unitPrice - ($unitPrice * ($discount / 100));
                    } elseif ($promo->percentOrAmount === 'amount') {
                        $discount = $promo->amount;
                        $finalPrice = $unitPrice - $discount;
                        if ($finalPrice < 0) $finalPrice = 0;
                    }

                    $totalDiscount += ($unitPrice - $finalPrice) * $prod['quantity'];

                    $discountedProducts[] = [
                        'productId' => $prod['productId'],
                        'quantity' => $prod['quantity'],
                        'originalPrice' => $unitPrice,
                        'discount' => $discount,
                        'finalPrice' => $finalPrice,
                        'promoId' => $promo->promoMasterId,
                    ];

                    $productName = $productNames[$prod['productId']] ?? "Produk ID {$prod['productId']}";
                    $discountDetails[] = "Diskon untuk {$productName}: Potongan " .
                        ($promo->percentOrAmount === 'percent' ? "{$discount}%" : "Rp " . number_format($discount));

                    $usedPromoIds[$promo->id] = true;
                }
            }
        }

        if (!empty($usedPromoIds)) {
            DB::table('promotionDiscounts')
                ->whereIn('id', array_keys($usedPromoIds))
                ->where('totalMaxUsage', '>', 0)
                ->decrement('totalMaxUsage', 1);
        }

        return [
            'discountedProducts' => $discountedProducts,
            'discountDetails' => $discountDetails,
            'totalDiscount' => $totalDiscount,
        ];
    }

    private function handleBundlePromos($locationId, $bundlePromoIds, $userId, $transactionId)
    {
        $lowStockWarnings = [];
        $promoNotes = [];
        $bundleTotalAmount = 0;
        $bundleTotalItem = 0;

        $bundles = DB::table('promotionBundles')
            ->whereIn('promoMasterId', $bundlePromoIds)
            ->get();

        foreach ($bundles as $bundle) {
            $details = DB::table('promotionBundleDetails')
                ->where('promoBundleId', $bundle->id)
                ->get();

            foreach ($details as $detail) {
                $productLoc = DB::table('productLocations')
                    ->where('locationId', $locationId)
                    ->where('productId', $detail->productId)
                    ->first();

                if (!$productLoc) {
                    throw new \Exception("Produk ID {$detail->productId} tidak ditemukan di cabang.");
                }

                $remainingStock = $productLoc->inStock - $detail->quantity;

                if ($detail->quantity > $productLoc->inStock) {
                    throw new \Exception("Stok produk bundle '{$detail->productId}' tidak mencukupi. Tersedia: {$productLoc->inStock}, Dibutuhkan: {$detail->quantity}");
                }

                if ($remainingStock < $productLoc->lowStock) {
                    $lowStockWarnings[] = "Stok produk '{$detail->productId}' akan di bawah minimum ({$productLoc->lowStock}). Sisa: {$remainingStock}";
                }

                DB::table('productLocations')
                    ->where('locationId', $locationId)
                    ->where('productId', $detail->productId)
                    ->decrement('inStock', $detail->quantity);

                updateDiffStock($locationId, $detail->productId);

                DB::table('transactionpetshopdetail')->insert([
                    'transactionpetshopId' => $transactionId,
                    'productId' => $detail->productId,
                    'quantity' => $detail->quantity,
                    'price' => 0,
                    'discount' => 0,
                    'final_price' => 0,
                    'promoId' => $bundle->promoMasterId,
                    'isDeleted' => false,
                    'userId' => $userId,
                    'userUpdateId' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $bundleTotalItem += $detail->quantity;
            }

            $bundleTotalAmount += $bundle->price;
            $promoNotes[] = "Bundle Promo: ID {$bundle->promoMasterId} - Harga: Rp " . number_format($bundle->price);
            DB::table('promotionBundles')
                ->where('id', $bundle->id)
                ->where('totalMaxUsage', '>', 0)
                ->decrement('totalMaxUsage', 1);
        }


        return [
            'bundleItems' => [],
            'lowStockWarnings' => $lowStockWarnings,
            'promoNotes' => $promoNotes,
            'bundleTotalAmount' => $bundleTotalAmount,
            'bundleTotalItem' => $bundleTotalItem,
        ];
    }

    private function handleBasedSalesPromo(array $promoIds, float $baseTotal)
    {
        $discount = 0;
        $note = '';

        $basedSales = DB::table('promotionBasedSales')
            ->whereIn('promoMasterId', $promoIds)
            ->get();

        foreach ($basedSales as $promo) {
            if ($baseTotal >= $promo->minPurchase && $baseTotal <= $promo->maxPurchase) {
                if ($promo->percentOrAmount === 'percent') {
                    $discount = round(($baseTotal * $promo->percent) / 100, 2);
                    $note = "Diskon {$promo->percent}% (Rp" . number_format($discount, 2) . ") dari promo basedSales ID {$promo->promoMasterId}";
                } elseif ($promo->percentOrAmount === 'amount') {
                    $discount = $promo->amount;
                    $note = "Diskon tetap Rp" . number_format($discount, 2) . " dari promo basedSales ID {$promo->promoMasterId}";
                }
                break;
            }
        }

        if ($discount === 0) {
            throw new \Exception("Tidak ada promo basedSales yang memenuhi syarat untuk total belanja Rp" . number_format($baseTotal, 2));
        }


        DB::table('promotionFreeItems')
            ->where('id', $promo->id)
            ->where('totalMaxUsage', '>', 0)
            ->decrement('totalMaxUsage', 1);

        return [
            'discount' => $discount,
            'note' => $note
        ];
    }

    public function delete(Request $request)
    {
        $ids = $request->input('id');

        if (empty($ids) || !is_array($ids)) {
            return response()->json([
                'message' => 'ID transaksi tidak valid.',
                'errors' => ['Silakan kirim array ID untuk dihapus.'],
            ], 422);
        }

        $transaksis = TransactionPetShop::whereIn('id', $ids)->get();

        if (count($transaksis) !== count($ids)) {
            return response()->json([
                'message' => 'Beberapa ID tidak ditemukan.',
                'errors' => ['Pastikan semua ID transaksi valid.'],
            ], 422);
        }

        $deletedIds = [];

        foreach ($transaksis as $tran) {
            $tran->deletedBy = $request->user()->id;
            $tran->isDeleted = true;
            $tran->deletedAt = Carbon::now();
            $tran->save();

            DB::table('transactionpetshopdetail')
                ->where('transactionpetshopId', $tran->id)
                ->update([
                    'isDeleted' => true,
                    'userUpdateId' => $request->user()->id,
                    'updated_at' => now(),
                ]);

            transactionPetshopLog($tran->id, 'Transaction Deleted', '', $request->user()->id);
            $deletedIds[] = $tran->id;
        }

        return response()->json([
            'message' => 'Delete Data Successful',
            'deletedIds' => $deletedIds
        ]);
    }

    public function export(Request $request)
    {
        if ($request->user()->roleId != 1) {
            return response()->json([
                'message' => 'Unauthorized. Only admin can export data.'
            ], 403);
        }
        $filter = $request->only(['locationId', 'customerGroupId']);

        $data = DB::table('transactionpetshop as tp')
            ->join('customer as c', 'tp.customerId', '=', 'c.id')
            ->join('location as l', 'tp.locationId', '=', 'l.id')
            ->leftJoin('customerGroups as cg', 'c.customerGroupId', '=', 'cg.id')
            ->leftJoin('users as u', 'tp.userId', '=', 'u.id')
            ->leftJoin('paymentmethod as pm', 'tp.paymentMethod', '=', 'pm.id')
            ->where('tp.isDeleted', '=', 0)
            // ->when(!empty($filter['locationId']), function ($query) use ($filter) {
            //     $query->whereIn('tp.locationId', $filter['locationId']);
            // })
            // ->when(!empty($filter['customerGroupId']), function ($query) use ($filter) {
            //     $query->whereIn('c.customerGroupId', $filter['customerGroupId']);
            // })
            ->select(
                'tp.id',
                'tp.registrationNo',
                'l.locationName',
                'c.firstName as customerName',
                'cg.customerGroup',
                'pm.name as paymentMethod',
                'tp.created_at',
                'u.firstName as createdBy'
            )
            ->get();

        foreach ($data as $item) {
            $item->totalUsePromo = DB::table('transactionpetshopdetail')
                ->where('transactionpetshopId', $item->id)
                ->whereNotNull('promoId')
                ->count();

            $item->totalItem = DB::table('transactionpetshopdetail')
                ->where('transactionpetshopId', $item->id)
                ->sum('quantity');

            $item->totalAmount = DB::table('transactionpetshopdetail')
                ->where('transactionpetshopId', $item->id)
                ->select(DB::raw('SUM(quantity * price) as total'))
                ->value('total');
        }

        $spreadsheet = IOFactory::load(public_path() . '/template/transaction/' . 'Template_Export_Transaction_Pet_Shop.xlsx');
        $sheet = $spreadsheet->getSheet(0);

        $sheet->setCellValue('A1', 'No');
        $sheet->setCellValue('B1', 'No Transaksi');
        $sheet->setCellValue('C1', 'Cabang');
        $sheet->setCellValue('D1', 'Nama Pelanggan');
        $sheet->setCellValue('E1', 'Grup Pelanggan');
        $sheet->setCellValue('F1', 'Total Gunakan Promo');
        $sheet->setCellValue('G1', 'Total Item');
        $sheet->setCellValue('H1', 'Jumlah Transaksi');
        $sheet->setCellValue('I1', 'Metode Pembayaran');
        $sheet->setCellValue('J1', 'Tanggal Dibuat');
        $sheet->setCellValue('K1', 'Dibuat Oleh');

        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:K1')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $row = 2;
        $no = 1;
        foreach ($data as $item) {
            $sheet->setCellValue("A{$row}", $no);
            $sheet->setCellValue("B{$row}", $item->registrationNo);
            $sheet->setCellValue("C{$row}", $item->locationName);
            $sheet->setCellValue("D{$row}", $item->customerName);
            $sheet->setCellValue("E{$row}", $item->customerGroup);
            $sheet->setCellValue("F{$row}", $item->totalUsePromo ?? 0);
            $sheet->setCellValue("G{$row}", $item->totalItem ?? 0);
            $sheet->setCellValue("H{$row}", $item->totalAmount ?? 0);
            $sheet->setCellValue("I{$row}", $item->paymentMethod ?? '-');
            $sheet->setCellValue("J{$row}", $item->created_at);
            $sheet->setCellValue("K{$row}", $item->createdBy ?? '-');

            $sheet->getStyle("A{$row}:K{$row}")
                ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $sheet->getStyle("A{$row}:K{$row}")->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

            $row++;
            $no++;
        }

        foreach (range('A', 'K') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . 'Export Transaction Pet Shop.xlsx';
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Export Transaction Pet Shop.xlsx"',
        ]);
    }

    public function transactionDiscount(Request $request)
    {
        $products = json_decode($request->products, true);
        $freeItems = json_decode($request->freeItems, true);
        $discounts = json_decode($request->discounts, true);
        $bundles = json_decode($request->bundles, true);

        $results = [];
        $promoNotes = [];
        $subtotal = 0;
        $totalDiscount = 0;

        foreach ($products as $value) {
            $isGetPromo = false;

            foreach ($freeItems as $free) {

                $res = DB::table('promotionMasters as pm')
                    ->join('promotionFreeItems as fi', 'pm.id', 'fi.promoMasterId')
                    ->join('products as pbuy', 'pbuy.id', 'fi.productBuyId')
                    ->join('products as pfree', 'pfree.id', 'fi.productFreeId')
                    ->select(
                        'pbuy.fullName as item_name',
                        'pbuy.id as buy_product_id',
                        'pfree.id as free_product_id',
                        'pbuy.category',
                        'fi.quantityBuyItem as quantity',
                        'fi.quantityFreeItem as bonus',
                        DB::raw('0 as discount'),
                        DB::raw($value['eachPrice'] . ' as unit_price'),
                        DB::raw($value['priceOverall'] . ' as total'),
                        DB::raw("CONCAT('Buy ', fi.quantityBuyItem, ' Get ', fi.quantityFreeItem, ' Free for ', pbuy.fullName) as note")
                    )
                    ->where('pm.id', '=', $free)
                    ->where('pbuy.id', '=', $value['productId'])
                    ->get();

                if (count($res) > 0) {
                    $isGetPromo = true;
                }

                foreach ($res as $item) {
                    $results[] = (array)$item;
                    $subtotal += $item->total;
                    $promoNotes[] = $item->note;
                }
            }

            foreach ($bundles as $bundle) {

                $bundleData = DB::table('promotionMasters as pm')
                    ->join('promotionLocations as pl', 'pm.id', 'pl.promoMasterId')
                    ->join('promotionBundles as pb', 'pm.id', 'pb.promoMasterId')
                    ->select(
                        'pm.name as item_name',
                        DB::raw('"" as category'),
                        DB::raw('1 as quantity'),
                        DB::raw('0 as bonus'),
                        DB::raw('0 as discount'),
                        'pb.price as total',
                        'pb.id as promoBundleId',
                    )
                    ->where('pm.id', '=', $bundle)
                    ->where('pl.locationId', '=', $value['locationId'])
                    ->first();

                if (!$bundleData) continue;

                $includedItems = DB::table('promotion_bundle_detail_products as pbd')
                    ->join('products as p', 'p.id', '=', 'pbd.productId')
                    ->where('pbd.promoBundleId', '=', $bundleData->promoBundleId)
                    ->select('p.fullName as name', 'p.price as normal_price')
                    ->get()
                    ->toArray();

                // Hitung nilai normal total
                $normalTotal = array_sum(array_column($includedItems, 'normal_price'));
                $bundleNote = $bundleData->item_name . " only Rp" . number_format($bundleData->total, 0, ',', '.') .
                    " (save Rp" . number_format($normalTotal - $bundleData->total, 0, ',', '.') . ")";


                $results[] = [
                    'item_name' => $bundleData->item_name,
                    'free_product_id' => $item->free_product_id,
                    'category' => $bundleData->category,
                    'quantity' => $bundleData->quantity,
                    'bonus' => $bundleData->bonus,
                    'discount' => $bundleData->discount,
                    'total' => $bundleData->total,
                    'included_items' => $includedItems
                ];

                $subtotal += $bundleData->total;
                $promoNotes[] = $bundleNote;

                $isGetPromo = true;
            }

            foreach ($discounts as $disc) {

                $data = DB::table('promotionMasters as pm')
                    ->join('promotion_discount_products as pd', 'pm.id', 'pd.promoMasterId')
                    ->join('products as p', 'p.id', 'pd.productId')
                    ->select(
                        'p.fullName as item_name',
                        'p.category',
                        DB::raw($value['quantity'] . ' as quantity'),
                        DB::raw('0 as bonus'),
                        DB::raw("CASE WHEN pd.discountType = 'percent' THEN pd.percent ELSE pd.amount END as discount"),
                        DB::raw($value['eachPrice'] . ' as unit_price'),
                        DB::raw($value['priceOverall'] . ' as total'),
                        'pd.discountType',
                        'pd.percent',
                        'pd.amount'
                    )
                    ->where('pm.id', '=', $disc)
                    ->first();

                if (!$data) continue;

                if ($data->discountType === 'percent') {
                    $amount_discount = ($data->percent / 100) * $value['eachPrice'];
                    $discountNote = 'Diskon produk ' . $data->item_name . ' sebesar ' . $data->percent . '% (hemat Rp' . number_format($amount_discount, 0, ',', '.') . ')';
                    $saved = $amount_discount;
                } else {
                    $discountNote = 'Diskon produk ' . $data->item_name . ' sebesar Rp' . number_format($data->amount, 0, ',', '.');
                    $saved = $data->amount * $value['quantity'];
                }

                if (count($results) > 0) {

                    $collection = collect($results);
                    $tmp_res = $collection->where('item_name', '=', $data->item_name);

                    if (count($tmp_res) == 0) {
                        $results[] = [
                            'item_name' => $data->item_name,
                            'category' => $data->category,
                            'quantity' => $data->quantity,
                            'bonus' => $data->bonus,
                            'discountType' => $data->discountType,
                            'discount' => $data->discount,
                            'total' => $data->total,
                            'note' => $discountNote,
                        ];

                        $subtotal += $data->total;
                        $totalDiscount += $saved;
                        $promoNotes[] = $discountNote;
                    }
                    $isGetPromo = true;
                } else {
                    $results[] = [
                        'item_name' => $data->item_name,
                        'category' => $data->category,
                        'quantity' => $data->quantity,
                        'bonus' => $data->bonus,
                        'discountType' => $data->discountType,
                        'discount' => $data->discount,
                        'total' => $data->total,
                        'note' => $discountNote,
                    ];

                    $subtotal += $data->total;
                    $totalDiscount += $saved;
                    $promoNotes[] = $discountNote;
                    $isGetPromo = true;
                }
            }

            if (!$isGetPromo) {
                $res = DB::table('products as p')
                    ->select(
                        'p.fullName as item_name',
                        'p.category',
                        DB::raw($value['quantity'] . ' as quantity'),
                        DB::raw('0 as bonus'),
                        DB::raw('0 as discount'),
                        DB::raw($value['eachPrice'] . ' as unit_price'),
                        DB::raw($value['priceOverall'] . ' as total'),
                        DB::raw("'' as note")
                    )
                    ->where('p.id', '=', $value['productId'])
                    ->get();

                foreach ($res as $item) {
                    $results[] = (array)$item;
                    $subtotal += $item->total;
                }
            }
        }

        //perhitungan based sales
        $res = DB::table('promotionMasters as pm')
            ->join('promotionBasedSales as pb', 'pm.id', 'pb.promoMasterId')
            ->select(
                'pm.name',
                'pb.minPurchase',
                DB::raw("
            CASE
                WHEN percentOrAmount = 'amount' THEN 'amount'
                WHEN percentOrAmount = 'percent' THEN 'percent'
                ELSE ''
            END as discountType
            "),
                DB::raw("
            CASE
                WHEN percentOrAmount = 'amount' THEN amount
                WHEN percentOrAmount = 'percent' THEN percent
                ELSE 0
            END as totaldiscount
            ")
            )
            ->where('pm.id', '=', $request->basedSale)
            ->where('minPurchase', '<=', $subtotal)
            ->where('maxPurchase', '>=', $subtotal)
            ->first();

        if ($res) {

            if ($res->discountType == 'amount') {
                $totalPayment = $subtotal - $res->totaldiscount;
                $promoNotes[] = 'Diskon Rp ' . $res->totaldiscount . ' untuk pembelian lebih dari Rp ' . $res->minPurchase;
                $discountNote = 'Diskon Nominal (Belanja > Rp ' . $res->minPurchase . ')';
                $totalDiscount = $res->totaldiscount;
            } else if ($res->discountType == 'percent') {

                $totalPayment = $subtotal - ($subtotal * ($res->totaldiscount / 100));
                $promoNotes[] = 'Diskon ' . $res->totaldiscount . '% untuk pembelian lebih dari Rp ' . $res->minPurchase;
                $discountNote = 'Diskon ' . $res->totaldiscount . ' % (Belanja > Rp ' . $res->minPurchase . ')';
                $totalDiscount = $res->totaldiscount;
            }
        } else {
            // $totalPayment = $subtotal;
            $discountNote = '';
            // $totalDiscount = 0;
        }

        return [
            'purchases' => $results,
            'subtotal' => $subtotal,
            'discount_note' => $discountNote,
            'total_discount' => $totalDiscount,
            'total_payment' => $subtotal - $totalDiscount,
            // 'total_payment' => $totalPayment,
            'promo_notes' => $promoNotes
        ];
    }

    public function detail(Request $request)
    {
        $transactionId = $request->input('transactionId') ?? $request->input('id');

        if (!$transactionId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction ID tidak ditemukan dalam request.'
            ], 400);
        }

        $transaction = DB::table('transactionpetshop as t')
            ->leftJoin('location as l', 'l.id', '=', 't.locationId')
            ->leftJoin('customer as c', 'c.id', '=', 't.customerId')
            ->leftJoin('users as u', 'u.id', '=', 't.userId')
            ->leftJoin('paymentmethod as pm', 'pm.id', '=', 't.paymentMethod')
            ->select(
                't.id',
                't.registrationNo',
                't.note',
                't.proofOfPayment',
                't.created_at',
                'l.locationName as locationName',
                'c.firstName as customerName',
                'pm.name as paymentMethod',
                'u.firstName as createdBy'
            )
            ->where('t.id', $transactionId)
            ->where('t.isDeleted', 0)
            ->first();

        $transaction->createdAt = Carbon::parse($transaction->created_at)->format('d/m/Y H:i:s');
        $transaction->proofOfPayment = $transaction->proofOfPayment ?? null;

        $products = DB::table('transactionpetshopdetail as d')
            ->join('products as p', 'p.id', '=', 'd.productId')
            ->select(
                'p.fullName as item_name',
                'p.category',
                'd.quantity',
                'd.discount',
                'd.bonus',
                'd.price as unit_price',
                'd.total_final_price as total',
                'd.id',
                'd.productId',
                'd.promoId'
            )
            ->where('d.transactionpetshopId', $transactionId)
            ->where('d.isDeleted', 0)
            ->get();


        $promoIds = $products->pluck('promoId')->filter()->unique()->values()->all();


        $promoBundles = DB::table('promotionBundles')
            ->whereIn('promoMasterId', $promoIds)
            ->pluck('promoMasterId')
            ->toArray();

        $promoDiscounts = DB::table('promotionDiscounts')
            ->whereIn('promoMasterId', $promoIds)
            ->pluck('promoMasterId')
            ->toArray();

        $promoFreeItems = DB::table('promotionFreeItems')
            ->whereIn('promoMasterId', $promoIds)
            ->pluck('promoMasterId')
            ->toArray();

        $promoBasedSales = DB::table('promotionBasedSales')
            ->whereIn('promoMasterId', $promoIds)
            ->pluck('promoMasterId')
            ->toArray();

        $selectedPromos = [
            'freeItems' => $promoFreeItems,
            'discounts' => $promoDiscounts,
            'bundles' => $promoBundles,
            'basedSales' => $promoBasedSales,
        ];

        $mappedProducts = $products->map(function ($item) {
            return $item;
        });

        $logs = DB::table('transaction_petshop_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.userId')
            ->select(
                'l.id',
                'l.activity',
                'l.remark',
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(l.created_at, '%d-%m-%Y %H:%i:%s') as createdAt")
            )
            ->where('l.transactionId', $transactionId)
            ->orderBy('l.created_at', 'desc')
            ->get();

        return response()->json([
            'detail' => [
                'locationName' => $transaction->locationName,
                'customerName' => $transaction->customerName,
                'paymentMethod' => $transaction->paymentMethod,
                'createdBy' => $transaction->createdBy,
                'createdAt' => $transaction->createdAt,
                'notes' => $transaction->note,
                'proofOfPayment' => $transaction->proofOfPayment,
                'products' => $mappedProducts,
                'selectedPromos' => $selectedPromos
            ],
            'transactionLogs' => $logs,

        ]);
    }

    public function confirmPayment(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:transactionpetshop,id',
            'proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048'
        ]);

        $transaction = TransactionPetShop::find($request->id);

        if ($transaction->isPayed == 2) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi sudah dikonfirmasi sebelumnya.'
            ], 400);
        }

        $payment = DB::table('paymentmethod')->where('id', $transaction->paymentMethod)->first();

        if ($payment->name == 'Cash' || $payment->name == 'cash') {
            return response()->json([
                'status' => 'warning',
                'message' => 'Metode pembayaran Cash tidak perlu konfirmasi atau bukti pembayaran.'
            ], 400);
        }

        if (!$request->hasFile('proof')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bukti pembayaran wajib diunggah untuk metode non-tunai.'
            ], 422);
        }

        $filePath = null;
        $originalName = null;
        $randomName = null;

        if ($request->hasFile('proof')) {
            $file = $request->file('proof');
            $originalName = $file->getClientOriginalName();
            $randomName = 'proof_' . $transaction->id . '_' . time() . '.' . $file->getClientOriginalExtension();

            if (!Storage::disk('public')->exists('Transaction/Petshop/proof_of_payment')) {
                Storage::disk('public')->makeDirectory('Transaction/Petshop/proof_of_payment');
            }

            $filePath = $file->storeAs('Transaction/Petshop/proof_of_payment', $randomName, 'public');

            $transaction->proofOfPayment = $filePath;
            $transaction->originalName = $originalName;
            $transaction->proofRandomName = $randomName;
        }

        $transaction->isPayed = 2;
        $transaction->updated_at = now();
        $transaction->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Pembayaran berhasil dikonfirmasi.',
            'isPayed' => 2,
            'proof' => $filePath,
            'originalName' => $originalName,
            'randomName' => $randomName
        ]);
    }

    public function generateInvoice($id)
    {
        if (!is_numeric($id) || !DB::table('transactionpetshop')->where('id', $id)->exists()) {
            return response()->json(['message' => 'Invalid or missing transaction ID.'], 400);
        }

        $transaction = DB::table('transactionpetshop as t')
            ->leftJoin('customer as c', 't.customerId', '=', 'c.id')
            ->leftJoin('customerTelephones as ct', 'c.id', '=', 'ct.customerId')
            ->where('t.id', $id)
            ->select(
                't.*',
                't.no_nota',
                'c.memberNo',
                'c.firstName',
                'ct.phoneNumber'
            )
            ->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        $locations = DB::table('location')
            ->leftJoin('location_telephone', 'location.codeLocation', '=', 'location_telephone.codeLocation')
            ->where(function ($query) {
                $query->where('location_telephone.usage', 'Utama')
                    ->orWhereNull('location_telephone.usage');
            })
            ->select(
                'location.locationName',
                'location.description',
                'location_telephone.phoneNumber',
                'location.codeLocation'
            )
            ->distinct()
            ->get();

        $locationGroups = [];
        foreach ($locations as $location) {
            $key = $location->codeLocation;
            if (!isset($locationGroups[$key])) {
                $locationGroups[$key] = [
                    'name'        => $location->locationName,
                    'description' => $location->description,
                    'phone'       => $location->phoneNumber ?? ''
                ];
            }
        }
        $formattedLocations = array_values($locationGroups);

        $details = DB::table('transactionpetshopdetail as d')
            ->leftJoin('products as p', 'd.productId', '=', 'p.id')
            ->leftJoin('promotionMasters as pm', 'd.promoId', '=', 'pm.id')
            ->where('d.transactionpetshopId', $id)
            ->select(
                'p.fullName as product_name',
                'pm.name as promo_name',
                'd.quantity',
                'd.price',
                'd.final_price'
            )
            ->get();

        $total = $details->sum('final_price');

        $locationId = $transaction->locationId;
        $createdAt = Carbon::parse($transaction->created_at);
        $tahun = $createdAt->format('Y');
        $bulan = $createdAt->format('m');

        $monthlyTransactionCount = DB::table('transactionpetshop')
            ->where('locationId', $locationId)
            ->whereYear('created_at', $tahun)
            ->whereMonth('created_at', $bulan)
            ->where('created_at', '<=', $createdAt)
            ->count();

        $nomorUrut = str_pad($monthlyTransactionCount, 4, '0', STR_PAD_LEFT);

        // $namaFile = "INV/PS/{$locationId}/{$tahun}/{$bulan}/{$nomorUrut}.pdf";
        $namaFile = str_replace('/', '_', $transaction->no_nota ?? 'INV') . '.pdf';

        $data = [
            'locations'      => $formattedLocations,
            'nota_date'      => Carbon::parse($transaction->created_at)->format('d/m/Y'),
            'no_nota'        => $transaction->no_nota ?? '___________',
            'member_no'      => $transaction->memberNo ?? '-',
            'customer_name'  => $transaction->firstName ?? '-',
            'phone_number'   => $transaction->phoneNumber ?? '-',
            'arrival_time'   => Carbon::parse($transaction->created_at)->format('H:i'),
            'details'        => $details,
            'total'          => $total,
            'deposit'        => '-',
            'total_tagihan'  => $total,
        ];

        $pdf = Pdf::loadView('invoice.invoice_petshop', $data);
        return $pdf->download($namaFile);
    }
}
