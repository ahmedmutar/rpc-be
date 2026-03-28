<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Customer\Customer;
use App\Models\Customer\CustomerPets;
use App\Models\Customer\CustomerTelephones;
use App\Models\PromotionMaster;
use App\Models\Staff\UsersLocation;
use App\Models\transaction_pet_hotel_payment_based_sales;
use App\Models\transaction_pet_hotel_payment_bundle;
use App\Models\transaction_pet_hotel_payment_total;
use App\Models\transaction_pet_hotel_payments;
use App\Models\TransactionPetHotel;
use App\Models\TransactionPetHotelCheck;
use App\Models\transactionPetHotelTreatmentCage;
use App\Models\TransactionPetHotelTreatmentProduct;
use App\Models\TransactionPetHotelTreatmentService;
use App\Models\TransactionPetHotelTreatmentTreatPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Validator;
use DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class PetHotelController extends Controller
{
    public function index(Request $request)
    {
        $itemPerPage = $request->rowPerPage;

        $page = $request->goToPage;

        $doctorId = DB::table('users as u')
            ->join('usersLocation as ul', 'u.id', 'ul.usersId')
            ->join('jobTitle as j', 'j.id', 'u.jobTitleId')
            ->select(
                'u.id',
            )->where('j.id', '=', 17)   //id job title dokter hewan
            ->where('u.isDeleted', '=', 0)
            ->where('u.id', '=', $request->user()->id)
            ->first();

        $statusDoc = 0;
        if ($doctorId) {
            $statusDoc = 1;
        }

        $data = DB::table('transaction_pet_hotels as t')
            ->join('location as l', 'l.id', 't.locationId')
            ->join('customer as c', 'c.id', 't.customerId')
            ->join('customerPets as cp', 'cp.id', 't.PetId')
            ->leftjoin('customerGroups as cg', 'cg.id', 'c.customerGroupId')
            ->join('users as u', 'u.id', 't.doctorId')
            ->join('users as uc', 'uc.id', 't.userId')
            ->select(
                't.id',
                'l.id as locationId',
                't.registrationNo',
                't.isTreatment',
                'l.locationName',
                'c.firstName',
                DB::raw("IFNULL(cg.customerGroup,'') as customerGroup"),
                DB::raw("IFNULL(t.startDate,'') as startDate"),
                DB::raw("IFNULL(t.endDate,'') as endDate"),
                't.status',
                'u.firstName as picDoctor',
                'uc.firstName as createdBy',
                DB::raw("DATE_FORMAT(t.created_at, '%d-%m-%Y %H:%m:%s') as createdAt"),
                DB::raw('CASE WHEN ' . $statusDoc . '=1 and u.id=' . $request->user()->id . ' and t.status="Cek Kondisi Pet" THEN 1 ELSE 0 END as isPetCheck')
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->status == 'ongoing') {
            $data = $data->whereNotIn('t.status', ['Selesai', 'Batal']);
        } elseif ($request->status == 'finished') {
            $data = $data->whereIn('t.status', ['Selesai', 'Batal']);
        }

        if (!$request->user()->roleId == 1 || !$request->user()->roleId == 2) {
            $locations = UsersLocation::select('id')->where('usersId', $request->user()->id)->get()->pluck('id')->toArray();
            $data = $data->whereIn('l.id', $locations);
        } else {

            if ($request->locationId) {

                $data = $data->whereIn('l.id', $request->locationId);
            }
        }

        if ($request->customerGroupId) {

            $data = $data->whereIn('cg.id', $request->customerGroupId);
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

        $data = $data->orderBy('t.updated_at', 'desc');

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

    private function Search($request)
    {
        $temp_column = null;

        $data = DB::table('transaction_pet_hotels as t')
            ->select(
                't.registrationNo'
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('t.registrationNo', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 't.registrationNo';
        }
        //------------------------

        $data = DB::table('transaction_pet_hotels as t')
            ->join('customer as c', 'c.id', 't.customerId')
            ->select(
                'c.firstName'
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('c.firstName', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 'c.firstName';
        }
        //------------------------

        $data = DB::table('transaction_pet_hotels as t')
            ->join('customer as c', 'c.id', 't.customerId')
            ->join('users as u', 'u.id', 't.doctorId')
            ->select(
                'u.firstName',
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->search) {
            $data = $data->where('u.firstName', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column[] = 'u.firstName';
        }
        //------------------------

        return $temp_column;
    }

    public function create(Request $request)
    {

        if ($request->isNewCustomer == true) {
            $validate = Validator::make($request->all(), [
                'isNewCustomer' => 'required|bool',
                'locationId' => 'required|integer',
                //'customerId' => 'nullable|integer',
                'customerName' => 'nullable|string',
                //'registrant' => 'nullable|string',
                //'petId' => 'nullable|integer',
                'petName' => 'required|string',
                'petCategory' => 'required|integer',
                'condition' => 'required|string',
                'petGender' => 'required|string|in:J,B',
                'isSterile' => 'required|bool',
                'doctorId' => 'required|int',
                'notes' => 'nullable|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }
        } else {
            $validate = Validator::make($request->all(), [
                'isNewCustomer' => 'required|bool',
                'locationId' => 'required|integer',
                'customerId' => 'nullable|integer',
                //'customerName' => 'nullable|string',
                'registrant' => 'nullable|string',
                'petId' => 'nullable|integer',
                //'petName' => 'required|string',
                //'petCategory' => 'required|integer',
                //'condition' => 'required|string',
                //'petGender' => 'required|string|in:J,B',
                //'isSterile' => 'required|bool',
                'doctorId' => 'required|int',
                'notes' => 'nullable|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }
        }

        $validate = Validator::make($request->all(), [
            'startDate' => 'required|date',
            'endDate' => 'required|date',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $startDate = Carbon::parse($request->startDate);
        $endDate = Carbon::parse($request->endDate);

        if ($startDate > $endDate) {
            return responseInvalid(['Start Date must be less than End Date']);
        }

        $loc = TransactionPetHotel::where('locationId', $request->locationId)->count();

        $date = Carbon::now()->format('d');
        $month = Carbon::now()->format('m');
        $year = Carbon::now()->format('Y');

        $petCheckRegistrationNo = str_pad($loc + 1, 3, 0, STR_PAD_LEFT) . '/LPIK-RIS-RPC-PH/' . $request->locationId . '/' . $date . '/' . $month . '/' . $year;

        DB::beginTransaction();
        try {

            if ($request->isNewCustomer == true) {
                $cust = Customer::create(
                    [
                        'firstName' => $request->customerName,
                        'locationId' => $request->locationId,
                        'typeId' => 0,

                        'memberNo' => '',
                        'gender' => '',
                        'joinDate' => Carbon::now(),
                        'createdBy' => $request->user()->id,
                        'userUpdateId' => $request->user()->id
                    ]
                );

                if ($request->isNewPet == true) {
                    $pet = CustomerPets::create(
                        [
                            'customerId' => $cust->id,
                            'petName' => $request->petName,
                            'petCategoryId' => $request->petCategory,
                            'races' => '',
                            'condition' => $request->condition,
                            'color' => '',
                            'petMonth' => $request->month,
                            'petYear' => $request->year,
                            'dateOfBirth' => $request->birthDate,
                            'petGender' => $request->petGender,
                            'isSteril' => $request->isSterile,
                            'createdBy' => $request->user()->id,
                            'userUpdateId' => $request->user()->id
                        ]
                    );
                }
            } else {
                //$locations = UsersLocation::select('id')->where('usersId', $request->user()->id)->get()->pluck('id')->toArray();
                $cust = Customer::select('id', 'isDeleted')->where('id', $request->customerId)->where('isDeleted', 0)->first();

                if (!$cust) {
                    responseInvalid(['Customer is Not Found']);
                }

                if ($request->isNewPet == true) {
                    $pet = CustomerPets::create(
                        [
                            'customerId' => $cust->id,
                            'petName' => $request->petName,
                            'petCategoryId' => $request->petCategory,
                            'races' => '',
                            'condition' => $request->condition,
                            'color' => '',
                            'petMonth' => $request->month,
                            'petYear' => $request->year,
                            'dateOfBirth' => $request->birthDate,
                            'petGender' => $request->petGender,
                            'isSteril' => $request->isSterile,
                            'createdBy' => $request->user()->id,
                            'userUpdateId' => $request->user()->id
                        ]
                    );
                } else {

                    $pet = CustomerPets::select('id', 'isDeleted')->where('id', $request->petId)->where('customerId', $request->customerId)->where('isDeleted', 0)->first();

                    if (!$pet) {
                        return responseInvalid(['Pet is Not Found']);
                    }
                }
            }

            $doctorId = DB::table('users as u')
                ->join('usersLocation as ul', 'u.id', 'ul.usersId')
                ->join('jobTitle as j', 'j.id', 'u.jobTitleId')
                ->select(
                    'u.id',
                )->where('j.id', '=', 17)   //id job title dokter hewan
                ->where('u.isDeleted', '=', 0)
                ->where('u.id', '=', $request->doctorId)
                ->first();

            if (!$doctorId) {
                return responseInvalid(['Doctor is Not Found']);
            }

            $trx = TransactionPetHotel::where('locationId', $request->locationId)->count();

            $regisNo = 'RPC.TRX.' . $request->locationId . '.' . str_pad($trx + 1, 8, 0, STR_PAD_LEFT);

            $tran = TransactionPetHotel::create([
                'registrationNo' => $regisNo,
                'petCheckRegistrationNo' => $petCheckRegistrationNo,
                'status' => 'Menunggu Dokter',
                'isNewCustomer' => $request->isNewCustomer,
                'isNewPet' => $request->isNewPet,
                'locationId' => $request->locationId,
                'customerId' => $cust->id,
                'petId' => $pet->id,
                'registrant' => $request->registrant,
                'startDate' => $request->startDate,
                'endDate' => $request->endDate,
                'doctorId' => $request->doctorId,
                'note' => $request->note,
                'userId' => $request->user()->id,
            ]);

            transactionPetHotelLog($tran->id, 'New Transaction', '', $request->user()->id);

            DB::commit();
            return responseCreate();
        } catch (Exception $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function detail(Request $request)
    {
        $detail = DB::table('transaction_pet_hotels as t')
            ->join('location as l', 'l.id', 't.locationId')
            ->join('customer as c', 'c.id', 't.customerId')
            ->join('customerPets as cp', 'cp.id', 't.PetId')
            ->join('petCategory as pc', 'pc.id', 'cp.petCategoryId')
            ->leftjoin('customerGroups as cg', 'cg.id', 'c.customerGroupId')
            ->join('users as u', 'u.id', 't.doctorId')
            ->join('users as uc', 'uc.id', 't.userId')
            ->select(
                't.id',
                't.registrationNo',
                't.isNewCustomer',
                't.registrant',
                'l.id as locationId',
                'l.locationName',
                'c.id as customerId',
                'c.firstName as customerName',
                DB::raw("IFNULL(cg.customerGroup,'') as customerGroup"),
                DB::raw("IFNULL(t.startDate,'') as startDate"),
                DB::raw("IFNULL(t.endDate,'') as endDate"),
                't.status',
                'u.id as doctorId',
                'u.firstName as picDoctor',
                't.note',

                'cp.id as petId',
                'cp.petName',
                'cp.petCategoryId',
                'pc.petCategoryName',
                'cp.condition',
                'cp.petGender',
                'cp.isSteril as petSterile',
                'cp.petMonth',
                'cp.petYear',
                'cp.dateOfBirth',

                'uc.firstName as createdBy',
                DB::raw("DATE_FORMAT(t.created_at, '%d-%m-%Y %H:%m:%s') as createdAt")
            )
            ->where('t.id', '=', $request->id)
            ->first();

        $log = DB::table('transaction_pet_hotel_logs as tl')
            ->join('transaction_pet_hotels as t', 't.id', 'tl.transactionId')
            ->join('users as u', 'u.id', 'tl.userId')
            ->select(
                'tl.id',
                'tl.activity',
                'tl.remark',
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tl.created_at, '%d-%m-%Y %H:%m:%s') as createdAt")
            )
            ->where('tl.transactionId', '=', $request->id)
            ->orderBy('tl.id', 'desc')
            ->get();

        $data = ['detail' => $detail, 'transactionLogs' => $log];

        return response()->json($data, 200);
    }

    public function update(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        if ($request->isNewCustomer == true) {
            $validate = Validator::make($request->all(), [
                'isNewCustomer' => 'required|bool',
                'locationId' => 'required|integer',
                //'customerId' => 'nullable|integer',
                'customerName' => 'nullable|string',
                //'registrant' => 'nullable|string',
                //'petId' => 'nullable|integer',
                'petName' => 'required|string',
                'petCategory' => 'required|integer',
                'condition' => 'required|string',
                'petGender' => 'required|string|in:J,B',
                'isSterile' => 'required|bool',
                'doctorId' => 'required|int',
                'notes' => 'nullable|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }
        } else {
            $validate = Validator::make($request->all(), [
                'isNewCustomer' => 'required|bool',
                'locationId' => 'required|integer',
                'customerId' => 'nullable|integer',
                //'customerName' => 'nullable|string',
                'registrant' => 'nullable|string',
                'petId' => 'nullable|integer',
                //'petName' => 'required|string',
                //'petCategory' => 'required|integer',
                //'condition' => 'required|string',
                //'petGender' => 'required|string|in:J,B',
                //'isSterile' => 'required|bool',
                'doctorId' => 'required|int',
                'notes' => 'nullable|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }
        }

        $validate = Validator::make($request->all(), [
            'startDate' => 'required|date',
            'endDate' => 'required|date',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $startDate = Carbon::parse($request->startDate);
        $endDate = Carbon::parse($request->endDate);

        if ($startDate > $endDate) {
            return responseInvalid(['Start Date must be less than End Date']);
        }


        DB::beginTransaction();

        try {

            $oldTransaction = TransactionPetHotel::find($request->id);

            $transaction = TransactionPetHotel::updateOrCreate(
                ['id' => $request->id],
                [
                    'registrationNo' => $request->registrationNo,
                    'isNewCustomer' => $request->isNewCustomer,
                    'isNewPet' => $request->isNewPet,
                    'locationId' => $request->locationId,
                    'customerId' => $request->customerId,
                    'petId' => $request->petId,
                    'registrant' => $request->registrant,
                    'startDate' => $request->startDate,
                    'endDate' => $request->endDate,
                    'doctorId' => $request->doctorId,
                    'note' => $request->note,
                    'userUpdatedId' => $request->user()->id,
                ]
            );

            if ($oldTransaction) {
                $fieldNames = [
                    'registrationNo' => 'Nomor Registrasi',
                    'locationId' => 'Lokasi',
                    'customerId' => 'ID Pelanggan',
                    'petId' => 'Data Hewan',
                    'registrant' => 'Pendaftar',
                    'startDate' => 'Tanggal Mulai',
                    'endDate' => 'Tanggal Selesai',
                    'doctorId' => 'Dokter yang menangani',
                    'note' => 'Catatan',
                ];

                $changes = $transaction->getChanges();

                foreach ($changes as $field => $newValue) {
                    if ($field != 'updated_at') {
                        $customName = $fieldNames[$field] ?? $field;

                        if ($customName == 'Dokter yang menangani') {
                            $doctor = User::where([['id', '=', $newValue]])->first();

                            transactionPetHotelLog($request->id, 'Update Transaction', "Data '{$customName}' telah diubah menjadi {$doctor->firstName}", $request->user()->id);
                        } else {
                            transactionPetHotelLog($request->id, 'Update Transaction', "Data '{$customName}' telah diubah menjadi {$newValue}", $request->user()->id);
                        }
                    }
                }
            }

            DB::commit();
            return responseUpdate();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function delete(Request $request)
    {

        foreach ($request->id as $va) {
            $res = TransactionPetHotel::find($va);

            if (!$res) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['There is any Data not found!'],
                ], 422);
            }
        }

        foreach ($request->id as $va) {

            $tran = TransactionPetHotel::find($va);

            $tran->DeletedBy = $request->user()->id;
            $tran->isDeleted = true;
            $tran->DeletedAt = Carbon::now();
            $tran->save();

            transactionPetHotelLog($va, 'Transaction Deleted', '', $request->user()->id);
        }

        return responseDelete();
    }

    public function export(Request $request)
    {

        $data = DB::table('transaction_pet_hotels as t')
            ->join('location as l', 'l.id', 't.locationId')
            ->join('customer as c', 'c.id', 't.customerId')
            ->join('customerPets as cp', 'cp.id', 't.PetId')
            ->leftjoin('customerGroups as cg', 'cg.id', 'c.customerGroupId')
            ->join('users as u', 'u.id', 't.doctorId')
            ->join('users as uc', 'uc.id', 't.userId')
            ->select(
                't.registrationNo',
                'l.locationName',
                'c.firstName',
                DB::raw("IFNULL(cg.customerGroup,'') as customerGroup"),
                DB::raw("IFNULL(t.startDate,'') as startDate"),
                DB::raw("IFNULL(t.endDate,'') as endDate"),
                't.status',
                'u.firstName as picDoctor',
                'uc.firstName as createdBy',
                DB::raw("DATE_FORMAT(t.created_at, '%d-%m-%Y %H:%m:%s') as createdAt")
            )
            ->where('t.isDeleted', '=', 0);

        if ($request->status == 'ongoing') {
            $data = $data->whereNotIn('t.status', ['Selesai', 'Batal']);
        } elseif ($request->status == 'finished') {
            $data = $data->whereIn('t.status', ['Selesai', 'Batal']);
        }

        $data = $data->orderBy('t.updated_at', 'desc')->get();

        $spreadsheet = IOFactory::load(public_path() . '/template/transaction/' . 'Template_Export_Transaction.xlsx');

        $sheet = $spreadsheet->getSheet(0);

        $row = 2;
        foreach ($data as $item) {

            $sheet->setCellValue("A{$row}", $row - 1);
            $sheet->setCellValue("B{$row}", $item->registrationNo);
            $sheet->setCellValue("C{$row}", $item->locationName);
            $sheet->setCellValue("D{$row}", $item->firstName);
            $sheet->setCellValue("E{$row}", $item->customerGroup);
            $sheet->setCellValue("F{$row}", $item->startDate);
            $sheet->setCellValue("G{$row}", $item->endDate);
            $sheet->setCellValue("H{$row}", $item->status);
            $sheet->setCellValue("I{$row}", $item->picDoctor);
            $sheet->setCellValue("J{$row}", $item->createdBy);
            $sheet->setCellValue("K{$row}", $item->createdAt);

            $row++;
        }

        $fileName = 'Export Transaksi Pet Hotel.xlsx';

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . $fileName; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    public function acceptionTransaction(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'transactionId' => 'required|integer',
            'status' => 'required|bool',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $tran = TransactionPetHotel::where([['id', '=', $request->transactionId]])->first();

        $locs = UsersLocation::where([['usersId', '=', $request->user()->id]])->get();

        $user = User::where([['id', '=', $request->user()->id]])->first();

        if ($user->jobTitleId != 17) { //id job title dokter hewan
            return responseErrorValidation('You are not a doctor!', 'You are not a doctor!');
        }

        $temp = false;
        foreach ($locs as $val) {
            if ($val['locationId'] == $tran->locationId) {
                $temp = true;
            }
        }

        if (!$temp) {
            return responseErrorValidation('Can not accept transaction because the designated doctor is different!', 'Can not accept transaction because the designated doctor is different!');
        }

        $doctor = User::where([['id', '=', $request->user()->id]])->first();

        if ($request->status == 1) {

            statusTransactionPetHotel($request->transactionId, 'Cek Kondisi Pet', $request->user()->id);

            transactionPetHotelLog($request->transactionId, 'Pemeriksaan pasien oleh ' . $doctor->firstName, '', $request->user()->id);
        } else {

            $validate = Validator::make($request->all(), [
                'reason' => 'required|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }

            statusTransactionPetHotel($request->transactionId, 'Ditolak Dokter', $request->user()->id);

            transactionPetHotelLog($request->transactionId, 'Pasien Ditolak oleh ' . $doctor->firstName, $request->reason, $request->user()->id);
        }

        return responseCreate();
    }

    public function reassignDoctor(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'transactionId' => 'required|integer',
            'doctorId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $doctor = User::where([['id', '=', $request->doctorId]])->first();

        $user = User::where([['id', '=', $request->user()->id]])->first();

        statusTransactionPetHotel($request->transactionId, 'Menunggu Dokter', $request->user()->id);

        transactionPetHotelLog($request->transactionId, 'Menunggu konfirmasi dokter', 'Dokter dipindahkan oleh ' . $user->firstName, $request->user()->id);

        return responseCreate();
    }

    public function createPetCheck(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'transactionId' => 'required|integer',
            'numberVaccines' => 'required|integer',
            'isLiceFree' => 'required|bool',
            'noteLiceFree' => 'nullable|string',
            'isFungusFree' => 'required|bool',
            'noteFungusFree' => 'nullable|string',
            'isPregnant' => 'required|bool',
            'estimateDateofBirth' => 'nullable|date_format:Y-m-d',
            'isRecomendInpatient' => 'nullable|bool',
            'noteInpatient' => 'nullable|string',
            'isParent' => 'required|bool',
            'isBreastfeeding' => 'nullable|bool',
            'numberofChildren' => 'nullable|integer',
            'isAcceptToProcess' => 'required|bool',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        if ($request->isAcceptToProcess == 0) {
            $validate = Validator::make($request->all(), [
                'reasonReject' => 'required|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }
        }

        TransactionPetHotelCheck::create([
            'transactionId' => $request->transactionId,
            'numberVaccines' => $request->numberVaccines,
            'isLiceFree' => $request->isLiceFree,
            'noteLiceFree' => $request->noteLiceFree,
            'isFungusFree' => $request->isFungusFree,
            'noteFungusFree' => $request->noteFungusFree,
            'isPregnant' => $request->isPregnant,
            'estimateDateofBirth' => $request->estimateDateofBirth,
            'isRecomendInpatient' => $request->isRecomendInpatient,
            'noteInpatient' => $request->noteInpatient,
            'isParent' => $request->isParent,
            'isBreastfeeding' => $request->isBreastfeeding,
            'numberofChildren' => $request->numberofChildren,
            'isAcceptToProcess' => $request->isAcceptToProcess,
            'reasonReject' => $request->reasonReject,
            'userId' => $request->user()->id,
        ]);

        $doctor = User::where([['id', '=', $request->user()->id]])->first();

        if ($request->isAcceptToProcess) {

            if ($request->isRecomendInpatient) {
                transactionPetHotelLog($request->transactionId, 'Pet Selesai diperiksa oleh ' . $doctor->firstName, 'Pet dipindahkan ke Pet Clinic', $request->user()->id);
                statusTransactionPetHotel($request->transactionId, 'Pet dipindahkan ke Pet Clinic', $request->user()->id);
            } else {
                transactionPetHotelLog($request->transactionId, 'Pet Selesai diperiksa oleh ' . $doctor->firstName, 'Pet diterima masuk Pet Hotel', $request->user()->id);
                statusTransactionPetHotel($request->transactionId, 'Pet diterima masuk Pet Hotel', $request->user()->id);
                TransactionPetHotel::where('id', '=', $request->transactionId)
                    ->update([
                        'isTreatment' => true,
                    ]);
            }
        } else {
            transactionPetHotelLog($request->transactionId, 'Pet Selesai diperiksa oleh ' . $doctor->firstName, 'Pet ditolak masuk Pet Hotel karena ' . $request->reasonReject, $request->user()->id);
            statusTransactionPetHotel($request->transactionId, 'Pet ditolak Pet Hotel', $request->user()->id);
        }

        return responseCreate();
    }

    public function Treatment(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'transactionId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $tran = TransactionPetHotel::where('id', '=', $request->transactionId)->where('isDeleted', '=', 0)->first();

        if (!$tran) {
            return responseInvalid(['Transaction is not found or already deleted!']);
        }

        $services = json_decode($request->services, true);
        $productSell = json_decode($request->productSells, true);
        $productClinic = json_decode($request->productClinics, true);
        $treatmentPlans = json_decode($request->treatmentPlans, true);

        if (count($services) == 0 && count($productSell) == 0 && count($productClinic) == 0 && count($treatmentPlans) == 0) {

            return responseInvalid(['All category must one to filled!']);
        }

        // if ($services) {

        //     $validateServices = Validator::make(
        //         $services,
        //         [
        //             '*.id' => 'required|integer',
        //             '*.quantity' => 'required|integer',
        //         ],
        //         [
        //             '*.id.integer' => 'Id Should be Integer!',
        //             '*.id.required' => 'Id Should be Required!',
        //             '*.quantity.integer' => 'Quantity Should be Integer!',
        //             '*.quantity.required' => 'Quantity Should be Required!',
        //         ]
        //     );

        //     if ($validateServices->fails()) {
        //         $errors = $validateServices->errors()->first();

        //         return response()->json([
        //             'message' => 'The given data was invalid.',
        //             'errors' => [$errors],
        //         ], 422);
        //     }
        // }

        // if ($productSell) {

        //     $validateProductSell = Validator::make(
        //         $productSell,
        //         [
        //             '*.id' => 'required|integer',
        //             '*.quantity' => 'required|integer',
        //         ],
        //         [
        //             '*.id.integer' => 'Id Should be Integer!',
        //             '*.id.required' => 'Id Should be Required!',
        //             '*.quantity.integer' => 'Quantity Should be Integer!',
        //             '*.quantity.required' => 'Quantity Should be Required!',
        //         ]
        //     );

        //     if ($validateProductSell->fails()) {
        //         $errors = $validateProductSell->errors()->first();

        //         return response()->json([
        //             'message' => 'The given data was invalid.',
        //             'errors' => [$errors],
        //         ], 422);
        //     }
        // }

        // if ($productClinic) {

        //     $validateProductClinic = Validator::make(
        //         $productClinic,
        //         [
        //             '*.id' => 'required|integer',
        //             '*.quantity' => 'required|integer',
        //         ],
        //         [
        //             '*.id.integer' => 'Id Should be Integer!',
        //             '*.id.required' => 'Id Should be Required!',
        //             '*.quantity.integer' => 'Quantity Should be Integer!',
        //             '*.quantity.required' => 'Quantity Should be Required!',
        //         ]
        //     );

        //     if ($validateProductClinic->fails()) {
        //         $errors = $validateProductClinic->errors()->first();

        //         return response()->json([
        //             'message' => 'The given data was invalid.',
        //             'errors' => [$errors],
        //         ], 422);
        //     }
        // }

        // if ($treatmentPlans) {

        //     $validateTreatmentPlans = Validator::make(
        //         ['treatmentPlans' => $treatmentPlans],
        //         [
        //             'treatmentPlans' => 'required|array',
        //             'treatmentPlans.*' => 'required|integer',
        //         ],
        //         [
        //             'treatmentPlans.*.required' => 'Id is required!',
        //             'treatmentPlans.*.integer' => 'Id should be integer!',
        //         ]
        //     );

        //     if ($validateTreatmentPlans->fails()) {
        //         $errors = $validateTreatmentPlans->errors()->first();

        //         return response()->json([
        //             'message' => 'The given data was invalid.',
        //             'errors' => [$errors],
        //         ], 422);
        //     }
        // }

        //proses insert
        DB::beginTransaction();
        try {
            foreach ($services as $value) {

                TransactionPetHotelTreatmentService::create([
                    'transactionId' => $request->transactionId,
                    'serviceId' => $value['id'],
                    'quantity' => $value['quantity'],
                    'userId' => $request->user()->id,
                ]);
            }

            foreach ($productSell as $value) {
                TransactionPetHotelTreatmentProduct::create([
                    'transactionId' => $request->transactionId,
                    'productId' => $value['id'],
                    'quantity' => $value['quantity'],
                    'userId' => $request->user()->id,
                ]);
            }

            foreach ($productClinic as $value) {
                TransactionPetHotelTreatmentProduct::create([
                    'transactionId' => $request->transactionId,
                    'productId' => $value['id'],
                    'quantity' => $value['quantity'],
                    'userId' => $request->user()->id,
                ]);
            }

            foreach ($treatmentPlans as $value) {
                TransactionPetHotelTreatmentTreatPlan::create([
                    'transactionId' => $request->transactionId,
                    'treatmentPlanId' => $value['id'],
                    'userId' => $request->user()->id,
                ]);
            }

            transactionPetHotelTreatmentCage::create([
                'transactionId' => $request->transactionId,
                'cageId' => $request->cageId,
                'userId' => $request->user()->id,
            ]);

            statusTransactionPetHotel($request->transactionId, 'Proses Pembayaran', $request->user()->id);

            transactionPetHotelLog($request->transactionId, 'Input Treatment dan Kandang Sudah Selesai', '', $request->user()->id);

            return responseCreate();
        } catch (\Throwable $th) {
            DB::rollback();

            return response()->json([
                'message' => 'Failed',
                'errors' => $th,
            ]);
        }
    }

    public function showDataBeforePayment(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'transactionId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $trans = TransactionPetHotel::find($request->transactionId);

        $cages = TransactionPetHotelTreatmentCage::from('transactionPetHotelTreatmentCages as tpcs')
            ->join('facility_unit as fu', 'fu.id', '=', 'tpcs.cageId')
            ->select('fu.id', 'fu.unitName')->where('transactionId', $request->transactionId)
            ->first();

        if (!$trans) {
            return responseInvalid(['Transaction is not found!']);
        }

        $phone = CustomerTelephones::where('customerId', '=', $trans->customerId)
            ->where('usage', '=', 'Utama')
            ->first();

        $cust = Customer::find($trans->customerId);

        $dataServices = TransactionPetHotelTreatmentService::from('transactionPetHotelTreatmentServices as tpcs')
            ->join('services as s', 's.id', '=', 'tpcs.serviceId')
            ->join('servicesPrice as sp', 's.id', '=', 'sp.service_id')
            ->select(
                's.id as serviceId',
                's.fullName as serviceName',
                DB::raw("TRIM(tpcs.quantity)+0 as quantity"),
                DB::raw("TRIM(sp.price)+0 as basedPrice"),
            )
            ->where('tpcs.transactionId', '=', $request->transactionId)
            ->where('sp.location_id', '=', $trans->locationId)
            ->get();

        $dataProducts = TransactionPetHotelTreatmentProduct::from('transactionPetHotelTreatmentProducts as rc')
            ->join('products as p', 'p.id', '=', 'rc.productId')
            ->join('productLocations as pl', 'p.id', '=', 'pl.productId')
            ->select(
                'p.id as productId',
                'p.fullName as productName',
                DB::raw("CASE WHEN p.category = 'sell' THEN 'Produk Jual' WHEN p.category = 'clinic' THEN 'Produk Klinik' END as productType"),
                DB::raw("TRIM(rc.quantity)+0 AS quantity"),
                DB::raw("TRIM(p.price)+0 AS basedPrice")
            )
            ->where('rc.transactionId', '=', $request->transactionId)
            ->where('pl.locationId', '=', $trans->locationId)
            ->get();

        $data = [
            'services' => $dataServices,
            'products' => $dataProducts,
        ];

        $date = Carbon::parse($trans->endDate);
        $formatted = $date->locale('id')->isoFormat('dddd, D MMMM YYYY');

        return response()->json([
            'customerName' => $cust ? $cust->firstName : '',
            'phoneNumber' => $phone ? $phone->phoneNumber : '',
            'arrivalTime' => $trans->created_at->locale('id')->translatedFormat('l, j F Y H:i'),
            'finishTime' => $formatted,
            'cage' => $cages,
            'data' => $data,
        ]);
    }

    protected function ensureIsArray($data): ?array
    {
        // Jika data sudah berupa array, kembalikan saja.
        if (is_array($data)) {
            return $data;
        }

        // Jika data berupa string (kemungkinan JSON), coba decode.
        if (is_string($data)) {
            $decoded = json_decode($data, true);

            // Pastikan hasil decode adalah array yang valid
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Kembalikan null atau array kosong jika input tidak valid
        return null;
    }

    public function checkPromo(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'transactionId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $trans = TransactionPetHotel::find($request->transactionId);

        if (!$trans) {
            return responseInvalid(['Transaction not found!']);
        }

        $custGroup = "";

        if (!is_null($trans->customerId)) {
            $cust = Customer::find($trans->customerId);
            $custGroup = $cust->customerGroupId;
        }

        $dataServices = $this->ensureIsArray($request->services);
        $dataProducts = $this->ensureIsArray($request->products);

        $tempFree = [];
        $tempDiscount = [];
        $resultBundle = [];

        //free product
        foreach ($dataProducts as $value) {

            $res = DB::table('promotionMasters as pm')
                ->leftjoin('promotionCustomerGroups as pcg', 'pm.id', 'pcg.promoMasterId')
                ->join('promotionLocations as pl', 'pm.id', 'pl.promoMasterId')
                ->join('promotionFreeItems as fi', 'pm.id', 'fi.promoMasterId')
                ->join('products as pbuy', 'pbuy.id', 'fi.productBuyId')
                ->join('products as pfree', 'pfree.id', 'fi.productFreeId')
                ->select(
                    'pm.id',
                    'pm.name',
                    DB::raw("CONCAT('Pembelian ', fi.quantityBuyItem, ' ',pbuy.fullName,' gratis ',fi.quantityFreeItem,' ',pfree.fullName) as note")
                )
                ->where('pl.locationId', '=', $trans->locationId)
                ->where('fi.productBuyId', '=', $value['productId'])
                ->where('pcg.customerGroupId', '=', $custGroup)
                ->where('pm.startDate', '<=', Carbon::now())
                ->where('pm.endDate', '>=', Carbon::now())
                ->where('pm.status', '=', 1)
                ->get()
                ->toArray();

            $tempFree = array_merge($tempFree, $res);
        }

        //discount
        foreach ($dataProducts as $value) {

            $res = DB::table('promotionMasters as pm')
                ->leftjoin('promotionCustomerGroups as pcg', 'pm.id', 'pcg.promoMasterId')
                ->join('promotionLocations as pl', 'pm.id', 'pl.promoMasterId')
                ->join('promotion_discount_products as pd', 'pm.id', 'pd.promoMasterId')
                ->join('products as p', 'p.id', 'pd.productId')
                ->select(
                    'pm.id',
                    'pm.name',
                    DB::raw("
                            CONCAT(
                                'Pembelian Produk ',
                                p.fullName,
                                CASE
                                    WHEN pd.discountType = 'percent' THEN CONCAT(' diskon ', pd.percent, '%')
                                    WHEN pd.discountType = 'amount' THEN CONCAT(' diskon Rp ', pd.amount)
                                    ELSE ''
                                END
                            ) as note
                        ")

                )
                ->where('pl.locationId', '=', $trans->locationId)
                ->where('pd.productId', '=', $value['productId'])
                ->where('pcg.customerGroupId', '=', $custGroup)
                ->where('pm.startDate', '<=', Carbon::now())
                ->where('pm.endDate', '>=', Carbon::now())
                ->where('pm.status', '=', 1)
                ->get()
                ->toArray();

            $tempDiscount = array_merge($tempDiscount, $res);
        }

        foreach ($dataServices as $value) {

            $res = DB::table('promotionMasters as pm')
                ->leftjoin('promotionCustomerGroups as pcg', 'pm.id', 'pcg.promoMasterId')
                ->join('promotionLocations as pl', 'pm.id', 'pl.promoMasterId')
                ->join('promotion_discount_services as pd', 'pm.id', 'pd.promoMasterId')
                ->join('products as p', 'p.id', 'pd.serviceId')
                ->select(
                    'pm.id',
                    'pm.name',
                    DB::raw("
                            CONCAT(
                                'Pembelian Produk ',
                                p.fullName,
                                CASE
                                    WHEN pd.discountType = 'percent' THEN CONCAT(' diskon ', pd.percent, '%')
                                    WHEN pd.discountType = 'amount' THEN CONCAT(' diskon Rp ', pd.amount)
                                    ELSE ''
                                END
                            ) as note
                        ")

                )
                ->where('pl.locationId', '=', $trans->locationId)
                ->where('pd.serviceId', '=', $value['serviceId'])
                ->where('pcg.customerGroupId', '=', $custGroup)
                ->where('pm.startDate', '<=', Carbon::now())
                ->where('pm.endDate', '>=', Carbon::now())
                ->where('pm.status', '=', 1)
                ->get()
                ->toArray();

            $tempDiscount = array_merge($tempDiscount, $res);
        }

        //bundle
        foreach ($dataServices as $value) {
            // return $value;
            $res = DB::table('promotionMasters as pm')
                ->leftjoin('promotionCustomerGroups as pcg', 'pm.id', 'pcg.promoMasterId')
                ->join('promotionLocations as pl', 'pm.id', 'pl.promoMasterId')
                ->join('promotionBundles as pb', 'pm.id', 'pb.promoMasterId')
                ->join('promotion_bundle_detail_services as pbd', 'pb.id', 'pbd.promoBundleId')
                ->join('products as p', 'p.id', 'pbd.serviceId')
                ->select(
                    'pbd.promoBundleId',
                    'pm.name',
                )
                ->where('pl.locationId', '=', $trans->locationId)
                ->where('pbd.serviceId', '=', $value['serviceId'])
                ->where('pcg.customerGroupId', '=', $custGroup)
                ->where('pm.startDate', '<=', Carbon::now())
                ->where('pm.endDate', '>=', Carbon::now())
                ->where('pm.status', '=', 1)
                ->get();

            foreach ($res as $valdtl) {

                $data = DB::table('promotion_bundle_detail_services as b')
                    ->join('products as p', 'p.id', 'b.serviceId')
                    ->join('promotionBundles as pb', 'pb.id', 'b.promoBundleId')
                    ->join('promotionMasters as m', 'pb.promoMasterId', 'm.id')
                    ->select('pb.id', 'p.fullName', 'b.quantity', 'pb.price', 'm.name')
                    ->where('b.promoBundleId', '=', $valdtl->promoBundleId)
                    ->get();
                $kalimat = 'paket bundling layanan ';

                for ($i = 0; $i < count($data); $i++) {

                    if (count($data) == 1) {
                        $kalimat .= $data[$i]->quantity . ' ' . $data[$i]->fullName;
                    } else {
                        if ($i == count($data) - 1) {
                            $kalimat .= 'dan ' . $data[$i]->quantity . ' ' . $data[$i]->fullName;
                        } else {
                            $kalimat .= $data[$i]->quantity . ' ' . $data[$i]->fullName . ', ';
                        }
                    }
                }

                $kalimat .= ' sebesar Rp ' . $data[0]->price;

                $resultBundle[] = [
                    'id' => $data[0]->id,
                    'note' => $kalimat,
                    'name' => $data[0]->name
                ];
            }
        }

        foreach ($dataProducts as $value) {
            // return $value;
            $res = DB::table('promotionMasters as pm')
                ->leftjoin('promotionCustomerGroups as pcg', 'pm.id', 'pcg.promoMasterId')
                ->join('promotionLocations as pl', 'pm.id', 'pl.promoMasterId')
                ->join('promotionBundles as pb', 'pm.id', 'pb.promoMasterId')
                ->join('promotion_bundle_detail_products as pbd', 'pb.id', 'pbd.promoBundleId')
                ->join('products as p', 'p.id', 'pbd.productId')
                ->select(
                    'pbd.promoBundleId',
                    'pm.name',
                )
                ->where('pl.locationId', '=', $trans->locationId)
                ->where('pbd.productId', '=', $value['productId'])
                ->where('pcg.customerGroupId', '=', $custGroup)
                ->where('pm.startDate', '<=', Carbon::now())
                ->where('pm.endDate', '>=', Carbon::now())
                ->where('pm.status', '=', 1)
                ->get();

            foreach ($res as $valdtl) {

                $data = DB::table('promotion_bundle_detail_products as b')
                    ->join('products as p', 'p.id', 'b.productId')
                    ->join('promotionBundles as pb', 'pb.id', 'b.promoBundleId')
                    ->join('promotionMasters as m', 'pb.promoMasterId', 'm.id')
                    ->select('pb.id', 'p.fullName', 'b.quantity', 'pb.price', 'm.name')
                    ->where('b.promoBundleId', '=', $valdtl->promoBundleId)
                    ->get();
                $kalimat = 'paket bundling produk ';

                for ($i = 0; $i < count($data); $i++) {

                    if (count($data) == 1) {
                        $kalimat .= $data[$i]->quantity . ' ' . $data[$i]->fullName;
                    } else {
                        if ($i == count($data) - 1) {
                            $kalimat .= 'dan ' . $data[$i]->quantity . ' ' . $data[$i]->fullName;
                        } else {
                            $kalimat .= $data[$i]->quantity . ' ' . $data[$i]->fullName . ', ';
                        }
                    }
                }

                $kalimat .= ' sebesar Rp ' . $data[0]->price;

                $resultBundle[] = [
                    'id' => $data[0]->id,
                    'note' => $kalimat,
                    'name' => $data[0]->name
                ];
            }
        }

        $resultBasedSales = [];

        $totalTransaction = 0;

        foreach ($dataServices as $value) {
            $totalTransaction += $value['priceOverall'];
        }

        foreach ($dataProducts as $value) {
            $totalTransaction += $value['priceOverall'];
        }

        $findBasedSales = DB::table('promotionMasters as pm')
            ->leftjoin('promotionCustomerGroups as pcg', 'pm.id', 'pcg.promoMasterId')
            ->join('promotionLocations as pl', 'pm.id', 'pl.promoMasterId')
            ->join('promotionBasedSales as bs', 'pm.id', 'bs.promoMasterId')
            ->select('pm.id', 'pm.name', 'bs.percentOrAmount', 'bs.percent', 'bs.amount', 'bs.minPurchase', 'bs.maxPurchase')
            ->where('pl.locationId', '=', $trans->locationId)
            ->where('bs.minPurchase', '<=', $totalTransaction)
            ->where('bs.maxPurchase', '>=', $totalTransaction)
            ->where('pcg.customerGroupId', '=', $custGroup)
            ->where('pm.startDate', '<=', Carbon::now())
            ->where('pm.endDate', '>=', Carbon::now())
            ->where('pm.status', '=', 1)
            ->get();

        $text = "";

        foreach ($findBasedSales as $sale) {

            if ($sale->percentOrAmount == 'percent') {
                $text = 'Diskon ' . $sale->percent . ' % setiap pembelian minimal Rp ' . $sale->minPurchase;
            } elseif ($sale->percentOrAmount == 'amount') {
                $text = 'Potongan harga sebesar Rp ' . $sale->amount . ' setiap pembelian minimal Rp ' . $sale->minPurchase;
            }

            $resultBasedSales[] = [
                'id' => $sale->id,
                'note' => $text,
                'name' => $sale->name
            ];

            $text = "";
        }

        $result = [
            'freeItem' => $tempFree,
            'discount' => $tempDiscount,
            'bundles' => $resultBundle,
            'basedSales' => $resultBasedSales,
        ];

        return response()->json($result);
    }

    public function transactionDiscount(Request $request)
    {
        $services = $this->ensureIsArray($request->services);

        $products = $this->ensureIsArray($request->products);
        // Mengambil 'freeItems'
        $freeItems = $this->ensureIsArray($request->freeItems);
        // Mengambil 'discounts'
        $discounts = $this->ensureIsArray($request->discounts);

        // Mengambil 'bundles'
        $bundles = $this->ensureIsArray($request->bundles);

        $results = [];
        $promoNotes = [];
        $subtotal = 0;
        $totalDiscount = 0;

        $trans = TransactionPetHotel::find($request->transactionId);

        foreach ($services as $value) {
            $isGetPromo = false;

            if ($request->has('discounts')) {
                foreach ($discounts as $disc) {

                    $data = DB::table('promotionMasters as pm')
                        ->join('promotion_discount_services as pd', 'pm.id', 'pd.promoMasterId')
                        ->join('services as s', 's.id', 'pd.serviceId')
                        ->join('serviceCategory as sc', 's.type', 'sc.id')
                        ->select(
                            'pm.id as promoId',
                            's.id as serviceId',
                            's.fullName as item_name',
                            's.type as category',
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
                        $saved = $data->amount;
                    }

                    $results[] = [
                        'item_name' => $data->item_name,
                        'category' => $data->category,
                        'quantity' => $data->quantity,
                        'bonus' => $data->bonus,
                        'discount' => $data->discount,
                        'unit_price' => $data->unit_price,
                        'total' => $value['priceOverall'] - $saved,
                        'promoId' => $data->promoId,
                        'serviceId' => $data->serviceId,
                        'promoCategory' => 'discount',
                    ];

                    $subtotal += ($value['priceOverall'] - $saved);
                    $totalDiscount += $saved;
                    $promoNotes[] = $discountNote;
                }
            }

            if ($request->has('bundles')) {
                foreach ($bundles as $bundle) {

                    $bundleData = DB::table('promotionMasters as pm')
                        ->join('promotionLocations as pl', 'pm.id', 'pl.promoMasterId')
                        ->join('promotionBundles as pb', 'pm.id', 'pb.promoMasterId')
                        ->join('promotion_bundle_detail_services as pbd', 'pm.id', 'pbd.promoBundleId')
                        ->select(
                            'pm.id as promoId',
                            'pm.name as item_name',
                            DB::raw('"" as category'),
                            DB::raw('1 as quantity'),
                            DB::raw('0 as bonus'),
                            DB::raw('0 as discount'),
                            'pb.price as total',
                            'pb.id as promoBundleId',
                        )
                        ->where('pm.id', '=', $bundle)
                        ->where('pl.locationId', '=', $trans->locationId)
                        ->first();

                    if (!$bundleData) continue;

                    $includedItems = DB::table('promotion_bundle_detail_services as pbd')
                        ->join('services as s', 's.id', '=', 'pbd.serviceId')
                        ->join('servicesPrice as sp', 'sp.serviceId', '=', 's.id')
                        ->where('pbd.promoBundleId', '=', $bundleData->promoBundleId)
                        ->where('sp.location_id', '=', $trans->locationId)
                        ->select('s.id as serviceId', 's.fullName as name', 'sp.price as normal_price')
                        ->get()
                        ->toArray();

                    // Hitung nilai normal total
                    $normalTotal = array_sum(array_column($includedItems, 'normal_price'));
                    $bundleNote = $bundleData->item_name . " only Rp" . number_format($bundleData->total, 0, ',', '.') .
                        " (save Rp" . number_format($normalTotal - $bundleData->total, 0, ',', '.') . ")";

                    $results[] = [
                        'item_name' => $bundleData->item_name,
                        'category' => $bundleData->category,
                        'quantity' => $bundleData->quantity,
                        'bonus' => $bundleData->bonus,
                        'discount' => $bundleData->discount,
                        'total' => $bundleData->total,
                        'included_items' => $includedItems,
                        'promoId' => $bundleData->promoId,
                        'promoCategory' => 'bundle',
                    ];

                    $subtotal += $bundleData->total;
                    $promoNotes[] = $bundleNote;

                    $isGetPromo = true;
                }
            }

            if (!$isGetPromo) {
                $res = DB::table('services as p')
                    ->join('serviceCategory as sc', 'p.type', 'sc.id')
                    ->select(
                        DB::raw('NULL as promoId'),
                        'p.id as serviceId',
                        'p.fullName as item_name',
                        'sc.categoryName as category',
                        DB::raw($value['quantity'] . ' as quantity'),
                        DB::raw('0 as bonus'),
                        DB::raw('0 as discount'),
                        DB::raw($value['eachPrice'] . ' as unit_price'),
                        DB::raw($value['priceOverall'] . ' as total'),
                        DB::raw("'' as note")
                    )
                    ->where('p.id', '=', $value['serviceId'])
                    ->get();

                foreach ($res as $item) {
                    $results[] = (array)$item;
                    $subtotal += $item->total;
                }
            }
        }

        foreach ($products as $value) {
            $isGetPromo = false;

            //mulai free item
            if ($request->has('freeItems')) {
                foreach ($freeItems as $free) {

                    $res = DB::table('promotionMasters as pm')
                        ->join('promotionFreeItems as fi', 'pm.id', 'fi.promoMasterId')
                        ->join('products as pbuy', 'pbuy.id', 'fi.productBuyId')
                        ->join('products as pfree', 'pfree.id', 'fi.productFreeId')
                        ->select(
                            'pm.id as promoId',
                            'pbuy.fullName as item_name',
                            'pbuy.id as buy_product_id',
                            'pfree.id as free_product_id',
                            'pbuy.category',
                            'fi.quantityBuyItem as quantity',
                            'fi.quantityFreeItem as bonus',
                            DB::raw('0 as discount'),
                            DB::raw($value['eachPrice'] . ' as unit_price'),
                            DB::raw($value['priceOverall'] . ' as total'),
                            DB::raw("CONCAT('Beli ', fi.quantityBuyItem, ' ', pbuy.fullname, ' Gratis ', fi.quantityFreeItem, pfree.fullName) as note"),
                            DB::raw("'freeItem' as promoCategory"),
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
            }

            if ($request->has('bundles')) {
                foreach ($bundles as $bundle) {

                    $bundleData = DB::table('promotionMasters as pm')
                        ->join('promotionLocations as pl', 'pm.id', 'pl.promoMasterId')
                        ->join('promotionBundles as pb', 'pm.id', 'pb.promoMasterId')
                        ->select(
                            'pm.id as promoId',
                            'pm.name as item_name',
                            DB::raw('"" as category'),
                            DB::raw('1 as quantity'),
                            DB::raw('0 as bonus'),
                            DB::raw('0 as discount'),
                            'pb.price as total',
                            'pb.id as promoBundleId',
                        )
                        ->where('pm.id', '=', $bundle)
                        ->where('pl.locationId', '=', $trans->locationId)
                        ->first();

                    if (!$bundleData) continue;

                    $includedItems = DB::table('promotionBundleDetails as pbd')
                        ->join('products as p', 'p.id', '=', 'pbd.productId')
                        ->where('pbd.promoBundleId', '=', $bundleData->promoBundleId)
                        ->select('p.id as productId', 'p.fullName as name', 'p.price as normal_price')
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
                        'included_items' => $includedItems,
                        'promoId' => $bundleData->promoId,
                        'promoCategory' => 'bundle',
                    ];

                    $subtotal += $bundleData->total;
                    $promoNotes[] = $bundleNote;

                    $isGetPromo = true;
                }
            }

            if ($request->has('discounts')) {
                foreach ($discounts as $disc) {

                    $data = DB::table('promotionMasters as pm')
                        ->join('promotion_discount_products as pd', 'pm.id', 'pd.promoMasterId')
                        ->join('products as p', 'p.id', 'pd.productId')
                        ->select(
                            'p.id as productId',
                            'pm.id as promoId',
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
                                'promoId' => $data->promoId,
                                'productId' => $data->productId,
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
                            'promoId' => $data->promoId,
                            'productId' => $data->productId,
                        ];

                        $subtotal += $data->total;
                        $totalDiscount += $saved;
                        $promoNotes[] = $discountNote;
                        $isGetPromo = true;
                    }
                }
            }

            if (!$isGetPromo) {
                $res = DB::table('products as p')
                    ->select(
                        'p.id as productId',
                        DB::raw('NULL as promoId'),
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

        $discount_based_sales = 0;
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
                $discount_based_sales = $res->totaldiscount;
                // $totalPayment = $subtotal - $res->totaldiscount;
                $promoNotes[] = 'Diskon Rp ' . $res->totaldiscount . ' untuk pembelian lebih dari Rp ' . $res->minPurchase;
                $discountNote = 'Diskon Nominal (Belanja > Rp ' . $res->minPurchase . ')';
                $totalDiscount = $res->totaldiscount;
            } else if ($res->discountType == 'percent') {
                $discount_based_sales = $subtotal * ($res->totaldiscount / 100);
                // $totalPayment = $subtotal - ($subtotal * ($res->totaldiscount / 100));
                $promoNotes[] = 'Diskon ' . $res->totaldiscount . '% untuk pembelian lebih dari Rp ' . $res->minPurchase;
                $discountNote = 'Diskon ' . $res->totaldiscount . ' % (Belanja > Rp ' . $res->minPurchase . ')';
                $totalDiscount = $res->totaldiscount;
            }
        } else {
            $discountNote = '';
        }

        $response = [
            'purchases' => $results,
            'subtotal' => $subtotal,
            'discount_note' => $discountNote,
            'discount_based_sales' => floatval($discount_based_sales),
            'total_discount' => floatval($totalDiscount),
            'total_payment' => $subtotal - $totalDiscount,
            'promo_notes' => $promoNotes,
        ];
        if ($request->basedSale) {
            $response['promoBasedSaleId'] = $request->basedSale;
        }

        return response()->json($response);
    }

    public function payment(Request $request)
    {
        $purchases = $this->ensureIsArray($request->purchases);
        //$json_string = $request->payment_method;
        $payment = $this->ensureIsArray($request->payment_method);

        $validate = Validator::make($request->all(), [
            'transactionId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $trans = TransactionPetHotel::find($request->transactionId);
        if (!$trans) {
            return responseInvalid(['Transaction not found!']);
        }

        try {
            DB::beginTransaction();

            foreach ($purchases as $value) {

                if (array_key_exists('serviceId', $value)) {

                    if ($value['promoId'] != null) {

                        $promo = PromotionMaster::find($value['promoId']);
                        if (!$promo) {
                            DB::rollBack();
                            return responseInvalid(['Promotion not found!']);
                        }

                        //promo free item
                        if ($promo->type == 2) {

                            $trx = new transaction_pet_hotel_payments();
                            $trx->transactionId = $request->transactionId;
                            $trx->paymentMethodId = $payment['paymentId'];
                            $trx->promoId = $promo->id;
                            $trx->serviceId = $value['serviceId'];
                            $trx->quantity = $value['quantity'];
                            $trx->discountType = $value['discountType'];
                            if ($value['discountType'] == 'percent') {
                                $trx->discountPercent = $value['discount'];
                            } else {
                                $trx->discountAmount = $value['discount'];
                            }
                            $trx->userId = $request->user()->id;
                            $trx->save();
                        }
                    } else {
                        $trx = new transaction_pet_hotel_payments();
                        $trx->transactionId = $request->transactionId;
                        $trx->paymentMethodId = $payment['paymentId'];
                        $trx->serviceId = $value['serviceId'];
                        $trx->quantity = $value['quantity'];
                        $trx->price = $value['unit_price'];
                        $trx->priceOverall = $value['total'];
                        $trx->userId = $request->user()->id;
                        $trx->save();
                    }
                } else if (array_key_exists('productId', $value)) {

                    if ($value['promoId'] != null) {

                        $promo = PromotionMaster::find($value['promoId']);
                        if (!$promo) {
                            DB::rollBack();
                            return responseInvalid(['Promotion not found!']);
                        }

                        if ($promo->type == 2) {

                            $trx = new transaction_pet_hotel_payments();
                            $trx->transactionId = $request->transactionId;
                            $trx->paymentMethodId = $payment['paymentId'];
                            $trx->promoId = $promo->id;
                            $trx->productId = $value['productId'];
                            $trx->quantity = $value['quantity'];
                            $trx->discountType = $value['discountType'];
                            if ($value['discountType'] == 'percent') {
                                $trx->discountPercent = $value['discount'];
                                $trx->discountAmount = 0;
                            } else {
                                $trx->discountAmount = $value['discount'];
                                $trx->discountPercent = 0;
                            }
                            $trx->price = $value['unit_price'];
                            $trx->priceOverall = $value['total'];
                            $trx->userId = $request->user()->id;
                            $trx->save();
                        } elseif ($promo->type == 3) {
                            //bundle

                        }
                    } else {
                        $trx = new transaction_pet_hotel_payments();
                        $trx->transactionId = $request->transactionId;
                        $trx->paymentMethodId = $payment['paymentId'];
                        $trx->promoId = $promo->id;
                        $trx->productId = $value['productId'];
                        $trx->quantity = $value['quantity'];
                        $trx->price = $value['unit_price'];
                        $trx->priceOverall = $value['total'];
                        $trx->userId = $request->user()->id;
                        $trx->save();
                    }
                } else if (array_key_exists('buy_product_id', $value)) {

                    $promo = PromotionMaster::find($value['promoId']);
                    if (!$promo) {
                        DB::rollBack();
                        return responseInvalid(['Promotion not found!']);
                    }

                    $trx = new transaction_pet_hotel_payments();
                    $trx->transactionId = $request->transactionId;
                    $trx->paymentMethodId = $payment['paymentId'];
                    $trx->promoId = $promo->id;
                    $trx->productBuyId = $value['buy_product_id'];
                    $trx->productFreeId = $value['free_product_id'];
                    $trx->quantity = $value['quantity'] + $value['bonus'];
                    $trx->quantityBuy = $value['quantity'];
                    $trx->quantityFree = $value['bonus'];
                    $trx->price = $value['unit_price'];
                    $trx->priceOverall = $value['total'];
                    $trx->userId = $request->user()->id;
                    $trx->save();
                } else if ($value['promoId'] != 'null' && $value['promoCategory'] == 'bundle') {

                    //bundle
                    $promo = PromotionMaster::find($value['promoId']);
                    if (!$promo) {
                        DB::rollBack();
                        return responseInvalid(['Promotion not found!']);
                    }

                    $trx = new transaction_pet_hotel_payments();
                    $trx->transactionId = $request->transactionId;
                    $trx->paymentMethodId = $payment['paymentId'];
                    $trx->promoId = $promo->id;
                    $trx->price = $value['unit_price'];
                    $trx->priceOverall = $value['total'];
                    $trx->isBundle = true;
                    $trx->userId = $request->user()->id;
                    $trx->save();

                    // $amountBundling = $value['total'];
                    // $amountTotal = 0;

                    // foreach ($value['included_items'] as $item) {
                    //     $amountTotal += $item['unit_price'];
                    // }

                    // $normalPriceRatio = $amountBundling / $amountTotal;

                    foreach ($value['included_items'] as $item) {
                        if (array_key_exists('serviceId', $item)) {

                            $bundle = new transaction_pet_hotel_payment_bundle();
                            $bundle->paymentId = $trx->id;
                            $bundle->promoId = $promo->id;
                            $bundle->serviceId = $item['serviceId'];
                            $bundle->quantity = $item['quantity'];
                            $bundle->amount = $item['unit_price'];
                            //* $normalPriceRatio;
                            //$bundle->priceOverall = $item['quantity'] * ($item['unit_price'] * $normalPriceRatio);
                            $bundle->userId = $request->user()->id;
                            $bundle->save();
                        } else if (array_key_exists('productId', $item)) {

                            $bundle = new transaction_pet_hotel_payment_bundle();
                            $bundle->paymentId = $trx->id;
                            $bundle->promoId = $promo->id;
                            $bundle->productId = $item['productId'];
                            $bundle->quantity = $item['quantity'];
                            $bundle->amount = $item['unit_price'];
                            //* $normalPriceRatio;
                            //$bundle->priceOverall = $item['quantity'] * ($item['unit_price'] * $normalPriceRatio);
                            $bundle->userId = $request->user()->id;
                            $bundle->save();
                        }
                    }
                }
            }

            $detail = $this->ensureIsArray($request->detail_total);

            if (array_key_exists('promoBasedSaleId', $detail)) {

                $promo = PromotionMaster::find($detail['promoBasedSaleId']);
                if (!$promo) {
                    DB::rollBack();
                    return responseInvalid(['Promotion based sales not found!']);
                }

                $sales = new transaction_pet_hotel_payment_based_sales();
                $sales->transactionId = $request->transactionId;
                $sales->paymentMethodId = $payment['paymentId'];
                $sales->promoId = $detail['promoBasedSaleId'];
                $sales->amountDiscount = $detail['discount_based_sales'];
                $sales->userId = $request->user()->id;
                $sales->save();
            }

            //detail total
            $total = new transaction_pet_hotel_payment_total();
            $total->transactionId = $request->transactionId;
            $total->paymentmethodId = $payment['paymentId'];
            $total->amount = $detail['total_payment'];
            $total->amountPaid = $payment['amountPaid'];

            if (array_key_exists('next_payment', $payment)) {
                $total->nextPayment = $payment['next_payment'];
            }

            if (array_key_exists('duration', $payment)) {
                $total->duration = $payment['duration'];
                $total->tenor = $payment['tenor'];
            }

            $locationId = $trans->locationId;

            $now = Carbon::now();
            $tahun = $now->format('Y');
            $bulan = $now->format('m');

            $jumlahTransaksi = DB::table('transaction_pet_hotel_payment_totals as tp')
                ->join('transaction_pet_hotels as tpc', 'tp.transactionId', '=', 'tpc.id')
                ->where('tpc.locationId', $locationId)
                ->whereYear('tp.created_at', $tahun)
                ->whereMonth('tp.created_at', $bulan)
                ->count();

            $nomorUrut = str_pad($jumlahTransaksi + 1, 4, '0', STR_PAD_LEFT);

            $notaNumber = "INV/PH/{$locationId}/{$tahun}/{$bulan}/{$nomorUrut}";
            $total->nota_number = $notaNumber;

            $total->userId = $request->user()->id;
            $total->save();

            transactionPetHotelLog($request->transactionId, 'Nota diterbitkan', '', $request->user()->id);

            statusTransactionPetHotel($request->transactionId, 'Menunggu konfirmasi pembayaran', $request->user()->id);
            DB::commit();

            updateLastTransaction($trans->customerId);

            return responseCreate();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function printInvoce(Request $request)
    {
        $trans = TransactionPetHotel::find($request->transactionId);

        if (!$trans) {
            return responseInvalid(['Transaction not found!']);
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

        $customer = DB::table('customer as c')
            ->join('customerTelephones as ct', 'c.id', '=', 'ct.customerId')
            ->where('c.id', '=', $trans->customerId)
            ->select('c.firstName', 'ct.phoneNumber', 'c.memberNo')
            ->first();

        $details = $this->ensureIsArray($request->purchases);
        $namaFile = str_replace('/', '_', $trans->nota_number ?? 'INV') . '.pdf';

        $detail_total = $this->ensureIsArray($request->detail_total);

        $data = [
            'locations'      => $formattedLocations,
            'nota_date'      => Carbon::parse($trans->created_at)->format('d/m/Y'),
            'no_nota'        => $trans->nota_number ?? '___________',
            'member_no'      => $customer->memberNo ?? '-',
            'customer_name'  => $customer->firstName ?? '-',
            'phone_number'   => $customer->phoneNumber ?? '-',
            'arrival_time'   => Carbon::parse($trans->created_at)->format('H:i'),
            'details'        => $details,
            'total'          => $detail_total,
            'deposit'        => '-',
            'total_payment'  => $detail_total['total_payment'],
            'total_discount'  => $detail_total['total_discount'],
            'subtotal'  => $detail_total['subtotal'],
        ];

        $pdf = Pdf::loadView('invoice.invoice_pethotel', $data);
        return $pdf->download($namaFile);

        return view('transaction.pethotel.print_invoice_pethotel');
    }
}
