<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\Customer\Customer;
use App\Models\Customer\CustomerPets;
use App\Models\Customer\CustomerTelephones;
use App\Models\ListBreathTransaction;
use App\Models\ListHeartTransaction;
use App\Models\ListSoundTransaction;
use App\Models\ListTemperatureTransaction;
use App\Models\ListVaginalTransaction;
use App\Models\ListWeightTransaction;
use App\Models\Products;
use App\Models\PromotionMaster;
use App\Models\Service;
use App\Models\Staff\UsersLocation;
use App\Models\transaction_pet_clinic_payment_based_sales;
use App\Models\transaction_pet_clinic_payment_bundle;
use App\Models\transaction_pet_clinic_payment_total;
use App\Models\transaction_pet_clinic_payments;
use App\Models\TransactionPetClinic;
use App\Models\TransactionPetClinicAdvice;
use App\Models\transactionPetClinicAnamnesis;
use App\Models\TransactionPetClinicCheckUpResult;
use App\Models\TransactionPetClinicDiagnose;
use App\Models\TransactionPetClinicRecipes;
use App\Models\TransactionPetClinicServices;
use App\Models\TransactionPetClinicTreatment;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Validator;
use DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TransPetClinicController extends Controller
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

        $data = DB::table('transactionPetClinics as t')
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
                'l.locationName',
                'c.firstName',
                DB::raw("IFNULL(cg.customerGroup,'') as customerGroup"),
                't.typeOfCare',
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

        if ($request->typeOfCare) {

            $data = $data->where('t.typeOfCare', '=', $request->typeOfCare);
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

        $data = DB::table('transactionPetClinics as t')
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

        $data = DB::table('transactionPetClinics as t')
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

        $data = DB::table('transactionPetClinics as t')
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
                'typeOfCare' => 'required|int',
                'doctorId' => 'required|int',
                'note' => 'required|string',
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
                'typeOfCare' => 'required|int',
                'doctorId' => 'required|int',
                'note' => 'required|string',
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

            $trx = TransactionPetClinic::where('locationId', $request->locationId)->count();

            $regisNo = 'RPC.TRX.' . $request->locationId . '.' . str_pad($trx + 1, 8, 0, STR_PAD_LEFT);

            $tran = TransactionPetClinic::create([
                'registrationNo' => $regisNo,
                'status' => 'Menunggu Dokter',
                'isNewCustomer' => $request->isNewCustomer,
                'isNewPet' => $request->isNewPet,
                'locationId' => $request->locationId,
                'customerId' => $cust->id,
                'petId' => $pet->id,
                'registrant' => $request->registrant,
                'typeOfCare' => $request->typeOfCare,
                'startDate' => $request->startDate,
                'endDate' => $request->endDate,
                'doctorId' => $request->doctorId,
                'note' => $request->note,
                'userId' => $request->user()->id,
            ]);

            transactionPetClinicLog($tran->id, 'New Transaction', '', $request->user()->id);

            DB::commit();
            return responseCreate();
        } catch (Exception $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function detail(Request $request)
    {
        $detail = DB::table('transactionPetClinics as t')
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
                't.typeOfCare',
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

        $log = DB::table('transaction_pet_clinic_logs as tl')
            ->join('transactionPetClinics as t', 't.id', 'tl.transactionId')
            ->join('users as u', 'u.id', 'tl.userId')
            ->select(
                'tl.id',
                'tl.activity',
                'tl.remark',
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tl.created_at, '%d-%m-%Y %H:%m:%s') as createdAt")
            )
            ->where('t.id', '=', $request->id)
            ->orderBy('tl.id', 'desc')
            ->get();

        $paymentLog = DB::table('transaction_pet_clinic_payment_totals as tpt')
            ->join('paymentmethod as pm', 'pm.id', 'tpt.paymentMethodId')
            ->join('users as u', 'u.id', 'tpt.userId')
            ->select(
                'tpt.id',
                'tpt.amount',
                'tpt.nota_number as notaNumber',
                'pm.name as paymentMethod',
                'u.firstName as createdBy',
                DB::raw("DATE_FORMAT(tpt.created_at, '%d-%m-%Y %H:%m:%s') as date")
            )
            ->where('tpt.transactionId', '=', $request->id)
            ->orderBy('tpt.id', 'desc')
            ->get();

        $data = ['detail' => $detail, 'transactionLogs' => $log, 'paymentLogs' => $paymentLog];

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
                'typeOfCare' => 'required|int',
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
                'typeOfCare' => 'required|int',
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

            $oldTransaction = TransactionPetClinic::find($request->id);

            $transaction = TransactionPetClinic::updateOrCreate(
                ['id' => $request->id],
                [
                    'registrationNo' => $request->registrationNo,
                    'isNewCustomer' => $request->isNewCustomer,
                    'isNewPet' => $request->isNewPet,
                    'locationId' => $request->locationId,
                    'customerId' => $request->customerId,
                    'petId' => $request->petId,
                    'registrant' => $request->registrant,
                    'serviceCategory' => $request->serviceCategory,
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
                    'typeOfCare' => 'Tipe Penanganan',
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

                            transactionPetClinicLog($request->id, 'Update Transaction', "Data '{$customName}' telah diubah menjadi {$doctor->firstName}", $request->user()->id);
                        } else {
                            transactionPetClinicLog($request->id, 'Update Transaction', "Data '{$customName}' telah diubah menjadi {$newValue}", $request->user()->id);
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
            $res = TransactionPetClinic::find($va);

            if (!$res) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['There is any Data not found!'],
                ], 422);
            }
        }

        foreach ($request->id as $va) {

            $tran = TransactionPetClinic::find($va);

            $tran->DeletedBy = $request->user()->id;
            $tran->isDeleted = true;
            $tran->DeletedAt = Carbon::now();
            $tran->save();

            transactionPetClinicLog($va, 'Transaction Deleted', '', $request->user()->id);
        }

        return responseDelete();
    }

    public function export(Request $request)
    {

        $data1 = DB::table('transactionPetClinics as t')
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
            ->where('t.isDeleted', '=', 0)
            ->where('t.typeOfCare', '=', 1);

        if (!$request->user()->roleId == 1 || !$request->user()->roleId == 2) {
            $locations = UsersLocation::select('id')->where('usersId', $request->user()->id)->get()->pluck('id')->toArray();
            $data1 = $data1->whereIn('l.id', $locations);
        } else {

            if ($request->locationId) {

                $data1 = $data1->whereIn('l.id', $request->locationId);
            }
        }

        if ($request->customerGroupId) {

            $data1 = $data1->whereIn('cg.id', $request->customerGroupId);
        }

        $data1 = $data1->orderBy('t.updated_at', 'desc')->get();

        $data2 = DB::table('transactionPetClinics as t')
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
            ->where('t.isDeleted', '=', 0)
            ->where('t.typeOfCare', '=', 2);

        if (!$request->user()->roleId == 1 || !$request->user()->roleId == 2) {
            $locations = UsersLocation::select('id')->where('usersId', $request->user()->id)->get()->pluck('id')->toArray();
            $data2 = $data2->whereIn('l.id', $locations);
        } else {

            if ($request->locationId) {

                $data2 = $data2->whereIn('l.id', $request->locationId);
            }
        }

        if ($request->customerGroupId) {

            $data2 = $data2->whereIn('cg.id', $request->customerGroupId);
        }

        $data2 = $data2->orderBy('t.updated_at', 'desc')->get();

        $data3 = DB::table('transactionPetClinics as t')
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
                DB::raw("CASE WHEN t.typeOfCare = 1 then 'Rawat Jalan' else 'Rawat Inap' end as typeOfCare"),
                't.status',
                'u.firstName as picDoctor',
                'uc.firstName as createdBy',
                DB::raw("DATE_FORMAT(t.created_at, '%d-%m-%Y %H:%m:%s') as createdAt")
            )
            ->where('t.isDeleted', '=', 0);

        if (!$request->user()->roleId == 1 || !$request->user()->roleId == 2) {
            $locations = UsersLocation::select('id')->where('usersId', $request->user()->id)->get()->pluck('id')->toArray();
            $data3 = $data3->whereIn('l.id', $locations);
        } else {

            if ($request->locationId) {

                $data3 = $data3->whereIn('l.id', $request->locationId);
            }
        }

        $data3 = $data3->whereIn('t.status', ['Selesai', 'Batal']);

        if ($request->customerGroupId) {

            $data3 = $data3->whereIn('cg.id', $request->customerGroupId);
        }

        $data3 = $data3->orderBy('t.updated_at', 'desc')->get();

        $spreadsheet = IOFactory::load(public_path() . '/template/transaction/' . 'Template_Export_Transaction_Pet_Clinic.xlsx');

        $sheet = $spreadsheet->getSheet(0);
        $row = 2;
        foreach ($data1 as $item) {

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

        $sheet = $spreadsheet->getSheet(1);
        $row = 2;
        foreach ($data2 as $item) {

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

        $sheet = $spreadsheet->getSheet(2);
        $row = 2;
        foreach ($data3 as $item) {

            $sheet->setCellValue("A{$row}", $row - 1);
            $sheet->setCellValue("B{$row}", $item->registrationNo);
            $sheet->setCellValue("C{$row}", $item->locationName);
            $sheet->setCellValue("D{$row}", $item->firstName);
            $sheet->setCellValue("E{$row}", $item->customerGroup);
            $sheet->setCellValue("F{$row}", $item->typeOfCare);
            $sheet->setCellValue("G{$row}", $item->startDate);
            $sheet->setCellValue("H{$row}", $item->endDate);
            $sheet->setCellValue("I{$row}", $item->status);
            $sheet->setCellValue("J{$row}", $item->picDoctor);
            $sheet->setCellValue("K{$row}", $item->createdBy);
            $sheet->setCellValue("L{$row}", $item->createdAt);

            $row++;
        }

        $fileName = 'Export Transaksi Pet Clinic.xlsx';

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

    public function orderNumber(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $trx = TransactionPetClinic::find($request->id);

        $loc = TransactionPetClinic::where('locationId', $trx->locationId)->count();

        $date = Carbon::now()->format('d');
        $month = Carbon::now()->format('m');
        $year = Carbon::now()->format('Y');

        $regisNo = str_pad($loc + 1, 3, 0, STR_PAD_LEFT) . '/LPIK-RIS-RPC-PC/' . $trx->locationId . '/' . $date . '/' . $month . '/' . $year;

        return response()->json($regisNo, 200);
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

        $tran = TransactionPetClinic::where([['id', '=', $request->transactionId]])->first();

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
            return responseErrorValidation('Can not accept transaction because the doctor is different branch!', 'Can not accept transaction because the doctor is different branch!');
        }

        $doctor = User::where([['id', '=', $request->user()->id]])->first();

        if ($request->status == 1) {

            statusTransactionPetClinic($request->transactionId, 'Cek Kondisi Pet', $request->user()->id);

            transactionPetClinicLog($request->transactionId, 'Pemeriksaan pasien oleh ' . $doctor->firstName, '', $request->user()->id);
        } else {

            $validate = Validator::make($request->all(), [
                'reason' => 'required|string',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return responseInvalid($errors);
            }

            statusTransactionPetClinic($request->transactionId, 'Ditolak Dokter', $request->user()->id);

            transactionPetClinicLog($request->transactionId, 'Pasien Ditolak oleh ' . $doctor->firstName, $request->reason, $request->user()->id);
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

        $user = User::where([['id', '=', $request->user()->id]])->first();

        statusTransactionPetClinic($request->transactionId, 'Menunggu Dokter', $request->user()->id);

        transactionPetClinicLog($request->transactionId, 'Menunggu konfirmasi dokter', 'Dokter dipindahkan oleh ' . $user->firstName, $request->user()->id);

        return responseCreate();
    }

    public function loadDataPetCheck(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $trx = TransactionPetClinic::find($request->id);

        if (!$trx) {
            return responseInvalid(['Transaction is not found!']);
        }

        $cust = Customer::find($trx->customerId);

        if (!$cust) {
            return responseInvalid(['Customer is not found!']);
        }

        $phone = CustomerTelephones::where('customerId', '=', $trx->customerId)
            ->where('usage', '=', 'Utama')
            ->first();

        $pet = CustomerPets::join('petCategory', 'customerPets.petCategoryId', '=', 'petCategory.id')
            ->where('customerPets.id', $trx->petId)
            ->select('customerPets.petName', 'petCategory.petCategoryName as petCategory')
            ->first();

        $phoneNumber = '';

        if ($phone) {
            $phoneNumber = $phone->phoneNumber;
        }

        return response()->json([
            'ownerName' => $cust->firstName,
            'phoneNumber' => $phoneNumber,
            'type' => $pet->petCategory,
            'petName' => $pet->petName,
        ], 200);
    }

    public function createPetCheck(Request $request)
    {
        $messages = [
            // Messages for 'required' rule
            'required' => 'Kolom :attribute wajib diisi.',

            // Messages for specific attribute rules
            'transactionPetClinicId.integer' => 'ID Transaksi Klinik Hewan harus berupa bilangan bulat.',

            'petCheckRegistrationNo.string' => 'Nomor Registrasi Pemeriksaan Hewan harus berupa teks.',

            'isAnthelmintic.boolean' => 'Pilihan Obat Cacing harus berupa nilai benar atau salah (true/false).',
            'anthelminticDate.date' => 'Tanggal Obat Cacing harus berupa format tanggal yang valid.',
            'anthelminticBrand.string' => 'Merek Obat Cacing harus berupa teks.',

            'isVaccination.boolean' => 'Pilihan Vaksinasi harus berupa nilai benar atau salah (true/false).',
            'vaccinationDate.date' => 'Tanggal Vaksinasi harus berupa format tanggal yang valid.',
            'vaccinationBrand.string' => 'Merek Vaksinasi harus berupa teks.',

            'isFleaMedicine.boolean' => 'Pilihan Obat Kutu harus berupa nilai benar atau salah (true/false).',
            'fleaMedicineDate.date' => 'Tanggal Obat Kutu harus berupa format tanggal yang valid.',
            'fleaMedicineBrand.string' => 'Merek Obat Kutu harus berupa teks.',

            'previousAction.string' => 'Tindakan Sebelumnya harus berupa teks.',
            'othersCompalints.string' => 'Keluhan Lainnya harus berupa teks.',

            'weight.numeric' => 'Berat harus berupa angka.',
            'weightCategory.integer' => 'Kategori Berat harus berupa bilangan bulat.',

            'temperature.numeric' => 'Suhu harus berupa angka.',
            'temperatureBottom.numeric' => 'Suhu Bawah harus berupa angka.',
            'temperatureTop.numeric' => 'Suhu Atas harus berupa angka.',
            'temperatureCategory.integer' => 'Kategori Suhu harus berupa bilangan bulat.',

            'isLice.boolean' => 'Pilihan Kutu Rambut harus berupa nilai benar atau salah (true/false).',
            'noteLice.string' => 'Catatan Kutu Rambut harus berupa teks.',

            'isFlea.boolean' => 'Pilihan Kutu harus berupa nilai benar atau salah (true/false).',
            'noteFlea.string' => 'Catatan Kutu harus berupa teks.',

            'isCaplak.boolean' => 'Pilihan Caplak harus berupa nilai benar atau salah (true/false).',
            'noteCaplak.string' => 'Catatan Caplak harus berupa teks.',

            'isTungau.boolean' => 'Pilihan Tungau harus berupa nilai benar atau salah (true/false).',
            'noteTungau.string' => 'Catatan Tungau harus berupa teks.',

            'ectoParasitCategory.integer' => 'Kategori Ektoparasit harus berupa bilangan bulat.',

            'isNematoda.boolean' => 'Pilihan Nematoda harus berupa nilai benar atau salah (true/false).',
            'noteNematoda.string' => 'Catatan Nematoda harus berupa teks.',

            'isTermatoda.boolean' => 'Pilihan Trematoda harus berupa nilai benar atau salah (true/false).',
            'noteTermatoda.string' => 'Catatan Trematoda harus berupa teks.',

            'isCestode.boolean' => 'Pilihan Cestode harus berupa nilai benar atau salah (true/false).',
            'noteCestode.string' => 'Catatan Cestode harus berupa teks.',

            'isFungiFound.boolean' => 'Pilihan Ditemukan Jamur harus berupa nilai benar atau salah (true/false).',

            'konjung.string' => 'Konjungtiva harus berupa teks.',
            'ginggiva.string' => 'Gingiva harus berupa teks.',
            'ear.string' => 'Telinga harus berupa teks.',
            'tongue.string' => 'Lidah harus berupa teks.',
            'nose.string' => 'Hidung harus berupa teks.',
            'CRT.string' => 'Capillary Refill Time (CRT) harus berupa teks.',

            'genitals.string' => 'Alat Kelamin harus berupa teks.',

            'neurologicalFindings.string' => 'Temuan Neurologis harus berupa teks.',
            'lokomosiFindings.string' => 'Temuan Lokomosi harus berupa teks.',

            'isSnot.boolean' => 'Pilihan Ingus harus berupa nilai benar atau salah (true/false).',
            'noteSnot.string' => 'Catatan Ingus harus berupa teks.',

            'breathType.integer' => 'Jenis Napas harus berupa bilangan bulat.',
            'breathSoundType.integer' => 'Jenis Suara Napas harus berupa bilangan bulat.',
            'breathSoundNote.string' => 'Catatan Suara Napas harus berupa teks.',
            'othersFoundBreath.string' => 'Temuan Pernapasan Lainnya harus berupa teks.',

            'pulsus.integer' => 'Pulsus harus berupa bilangan bulat.',
            'heartSound.integer' => 'Suara Jantung harus berupa bilangan bulat.',
            'othersFoundHeart.string' => 'Temuan Jantung Lainnya harus berupa teks.',

            'othersFoundSkin.string' => 'Temuan Kulit Lainnya harus berupa teks.',
            'othersFoundHair.string' => 'Temuan Rambut Lainnya harus berupa teks.',

            'maleTesticles.integer' => 'Testis Jantan harus berupa bilangan bulat.',
            'othersMaleTesticles.string' => 'Catatan Testis Jantan Lainnya harus berupa teks.',
            'penisCondition.string' => 'Kondisi Penis harus berupa teks.',
            'vaginalDischargeType.integer' => 'Jenis Keluaran Vagina harus berupa bilangan bulat.',
            'urinationType.integer' => 'Jenis Urinasi harus berupa bilangan bulat.',
            'othersUrination.string' => 'Catatan Urinasi Lainnya harus berupa teks.',
            'othersFoundUrogenital.string' => 'Temuan Urogenital Lainnya harus berupa teks.',

            'abnormalitasCavumOris.string' => 'Abnormalitas Rongga Mulut harus berupa teks.',
            'intestinalPeristalsis.string' => 'Peristalsis Usus harus berupa teks.',
            'perkusiAbdomen.string' => 'Perkusi Abdomen harus berupa teks.',
            'rektumKloaka.string' => 'Rektum/Kloaka harus berupa teks.',
            'othersCharacterRektumKloaka.string' => 'Karakter Rektum/Kloaka Lainnya harus berupa teks.',

            'fecesForm.string' => 'Bentuk Feses harus berupa teks.',
            'fecesColor.string' => 'Warna Feses harus berupa teks.',
            'fecesWithCharacter.string' => 'Karakteristik Feses harus berupa teks.',
            'othersFoundDigesti.string' => 'Temuan Pencernaan Lainnya harus berupa teks.',

            'reflectPupil.string' => 'Refleks Pupil harus berupa teks.',
            'eyeBallCondition.string' => 'Kondisi Bola Mata harus berupa teks.',
            'othersFoundVision.string' => 'Temuan Penglihatan Lainnya harus berupa teks.',

            'earlobe.string' => 'Daun Telinga harus berupa teks.',
            'earwax.integer' => 'Kotoran Telinga harus berupa bilangan bulat.',
            'earwaxCharacter.string' => 'Karakteristik Kotoran Telinga harus berupa teks.',
            'othersFoundEar.string' => 'Temuan Telinga Lainnya harus berupa teks.',

            'isInpatient.integer' => 'Pilihan Rawat Inap harus berupa bilangan bulat.',
            'noteInpatient.string' => 'Catatan Rawat Inap harus berupa teks.',

            'isTherapeuticFeed.integer' => 'Pilihan Pakan Terapeutik harus berupa bilangan bulat.',
            'noteTherapeuticFeed.string' => 'Catatan Pakan Terapeutik harus berupa teks.',

            'imuneBooster.string' => 'Peningkat Imun harus berupa teks.',
            'suplement.string' => 'Suplemen harus berupa teks.',
            'desinfeksi.string' => 'Desinfeksi harus berupa teks.',
            'care.string' => 'Perawatan harus berupa teks.',

            'isGrooming.integer' => 'Pilihan Grooming harus berupa bilangan bulat.',
            'noteGrooming.string' => 'Catatan Grooming harus berupa teks.',

            'othersNoteAdvice.string' => 'Catatan Saran Lainnya harus berupa teks.',
            'nextControlCheckup.date' => 'Tanggal Kontrol Berikutnya harus berupa format tanggal yang valid.',

            'diagnoseDisease.string' => 'Diagnosis Penyakit harus berupa teks.',
            'prognoseDisease.string' => 'Prognosis Penyakit harus berupa teks.',
            'diseaseProgressOverview.string' => 'Gambaran Kemajuan Penyakit harus berupa teks.',

            'isMicroscope.boolean' => 'Pilihan Mikroskop harus berupa nilai benar atau salah (true/false).',
            'noteMicroscope.string' => 'Catatan Mikroskop harus berupa teks.',

            'isEye.boolean' => 'Pilihan Mata harus berupa nilai benar atau salah (true/false).',
            'noteEye.string' => 'Catatan Mata harus berupa teks.',

            'isTeskit.boolean' => 'Pilihan Tes Kit harus berupa nilai benar atau salah (true/false).',
            'noteTeskit.string' => 'Catatan Tes Kit harus berupa teks.',

            'isUltrasonografi.boolean' => 'Pilihan Ultrasonografi harus berupa nilai benar atau salah (true/false).',
            'noteUltrasonografi.string' => 'Catatan Ultrasonografi harus berupa teks.',

            'isRontgen.boolean' => 'Pilihan Rontgen harus berupa nilai benar atau salah (true/false).',
            'noteRontgen.string' => 'Catatan Rontgen harus berupa teks.',

            'isBloodReview.boolean' => 'Pilihan Tinjauan Darah harus berupa nilai benar atau salah (true/false).',
            'noteBloodReview.string' => 'Catatan Tinjauan Darah harus berupa teks.',

            'isSitologi.boolean' => 'Pilihan Sitologi harus berupa nilai benar atau salah (true/false).',
            'noteSitologi.string' => 'Catatan Sitologi harus berupa teks.',

            'isVaginalSmear.boolean' => 'Pilihan Vaginal Smear harus berupa nilai benar atau salah (true/false).',
            'noteVaginalSmear.string' => 'Catatan Vaginal Smear harus berupa teks.',

            'isBloodLab.boolean' => 'Pilihan Lab Darah harus berupa nilai benar atau salah (true/false).',
            'noteBloodLab.string' => 'Catatan Lab Darah harus berupa teks.',

            'isSurgery.integer' => 'Pilihan Operasi harus berupa bilangan bulat.',
            'noteSurgery.string' => 'Catatan Operasi harus berupa teks.',

            'infusion.string' => 'Infus harus berupa teks.',
            'fisioteraphy.string' => 'Fisioterapi harus berupa teks.',
            'injectionMedicine.string' => 'Obat Suntik harus berupa teks.',
            'oralMedicine.string' => 'Obat Oral harus berupa teks.',
            'tropicalMedicine.string' => 'Obat Topikal harus berupa teks.',
            'vaccination.string' => 'Vaksinasi harus berupa teks.',
            'othersTreatment.string' => 'Pengobatan Lainnya harus berupa teks.',

        ];

        $validate = Validator::make($request->all(), [
            'transactionPetClinicId' => 'required|integer',
            'petCheckRegistrationNo' => 'required|string',

            'isAnthelmintic' => 'required|boolean',
            'anthelminticDate' => 'nullable|date',
            'anthelminticBrand' => 'nullable|string',

            'isVaccination' => 'required|boolean',
            'vaccinationDate' => 'nullable|date',
            'vaccinationBrand' => 'nullable|string',

            'isFleaMedicine' => 'required|boolean',
            'fleaMedicineDate' => 'nullable|date',
            'fleaMedicineBrand' => 'nullable|string',

            'previousAction' => 'nullable|string',
            'othersCompalints' => 'nullable|string',

            'weight' => 'required|numeric',
            'weightCategory' => 'required|integer',

            'temperature' => 'required|numeric',
            'temperatureBottom' => 'required|numeric',
            'temperatureTop' => 'required|numeric',
            'temperatureCategory' => 'required|integer',

            'isLice' => 'nullable|boolean',
            'noteLice' => 'nullable|string',

            'isFlea' => 'nullable|boolean',
            'noteFlea' => 'nullable|string',

            'isCaplak' => 'nullable|boolean',
            'noteCaplak' => 'nullable|string',

            'isTungau' => 'nullable|boolean',
            'noteTungau' => 'nullable|string',

            'ectoParasitCategory' => 'nullable|integer',

            'isNematoda' => 'nullable|boolean',
            'noteNematoda' => 'nullable|string',
            //endoparasit
            'isTermatoda' => 'nullable|boolean',
            'noteTermatoda' => 'nullable|string',

            'isCestode' => 'nullable|boolean',
            'noteCestode' => 'nullable|string',

            'isFungiFound' => 'nullable|boolean',

            'konjung' => 'nullable|string',
            'ginggiva' => 'nullable|string',
            'ear' => 'nullable|string',
            'tongue' => 'nullable|string',
            'nose' => 'nullable|string',
            'CRT' => 'nullable|string',

            //kelamin
            'genitals' => 'nullable|string',

            'neurologicalFindings' => 'nullable|string',
            'lokomosiFindings' => 'nullable|string',

            //ingus
            'isSnot' => 'nullable|boolean',
            'noteSnot' => 'nullable|string',

            'breathType' => 'nullable|integer',
            'breathSoundType' => 'nullable|integer',
            'breathSoundNote' => 'nullable|string',
            'othersFoundBreath' => 'nullable|string',

            'pulsus' => 'nullable|integer',
            'heartSound' => 'nullable|integer',
            'othersFoundHeart' => 'nullable|string',

            'othersFoundSkin' => 'nullable|string',
            'othersFoundHair' => 'nullable|string',

            //urogenital
            'maleTesticles' => 'nullable|integer',
            'othersMaleTesticles' => 'nullable|string',
            'penisCondition' => 'nullable|string',
            'vaginalDischargeType' => 'nullable|integer',
            'urinationType' => 'nullable|integer',
            'othersUrination' => 'nullable|string',
            'othersFoundUrogenital' => 'nullable|string',

            'abnormalitasCavumOris' => 'nullable|string',
            'intestinalPeristalsis' => 'nullable|string',
            'perkusiAbdomen' => 'nullable|string',
            'rektumKloaka' => 'nullable|string',
            'othersCharacterRektumKloaka' => 'nullable|string',

            'fecesForm' => 'nullable|string',
            'fecesColor' => 'nullable|string',
            'fecesWithCharacter' => 'nullable|string',
            'othersFoundDigesti' => 'nullable|string',

            'reflectPupil' => 'nullable|string',
            'eyeBallCondition' => 'nullable|string',
            'othersFoundVision' => 'nullable|string',

            'earlobe' => 'nullable|string',
            'earwax' => 'nullable|integer',
            'earwaxCharacter' => 'nullable|string',
            'othersFoundEar' => 'nullable|string',

            'isInpatient' => 'required|integer',
            'noteInpatient' => 'nullable|string',

            'isTherapeuticFeed' => 'required|integer',
            'noteTherapeuticFeed' => 'nullable|string',

            'imuneBooster' => 'nullable|string',
            'suplement' => 'nullable|string',
            'desinfeksi' => 'nullable|string',
            'care' => 'nullable|string',

            'isGrooming' => 'required|integer',
            'noteGrooming' => 'nullable|string',

            'othersNoteAdvice' => 'nullable|string',
            'nextControlCheckup' => 'nullable|date',

            'diagnoseDisease' => 'nullable|string',
            'prognoseDisease' => 'nullable|string',
            'diseaseProgressOverview' => 'nullable|string',

            'isMicroscope' => 'required|boolean',
            'noteMicroscope' => 'nullable|string',

            'isEye' => 'required|boolean',
            'noteEye' => 'nullable|string',

            'isTeskit' => 'required|boolean',
            'noteTeskit' => 'nullable|string',

            'isUltrasonografi' => 'required|boolean',
            'noteUltrasonografi' => 'nullable|string',

            'isRontgen' => 'required|boolean',
            'noteRontgen' => 'nullable|string',

            'isBloodReview' => 'required|boolean',
            'noteBloodReview' => 'nullable|string',

            'isSitologi' => 'required|boolean',
            'noteSitologi' => 'nullable|string',

            'isVaginalSmear' => 'required|boolean',
            'noteVaginalSmear' => 'nullable|string',

            'isBloodLab' => 'required|boolean',
            'noteBloodLab' => 'nullable|string',

            'isSurgery' => 'required|integer',
            'noteSurgery' => 'nullable|string',

            'infusion' => 'nullable|string',
            'fisioteraphy' => 'nullable|string',
            'injectionMedicine' => 'nullable|string',
            'oralMedicine' => 'nullable|string',
            'tropicalMedicine' => 'nullable|string',
            'vaccination' => 'nullable|string',
            'othersTreatment' => 'nullable|string',
        ], $messages);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            $errorMessage = implode(' ', $errors);
            return responseInvalid($errorMessage);
        }

        DB::beginTransaction();
        try {
            transactionPetClinicAnamnesis::create([
                'transactionPetClinicId' => $request->transactionPetClinicId,
                'petCheckRegistrationNo' => $request->petCheckRegistrationNo,
                'isAnthelmintic' => $request->isAnthelmintic,
                'anthelminticDate' => $request->anthelminticDate,
                'anthelminticBrand' => $request->anthelminticBrand,
                'isVaccination' => $request->isVaccination,
                'vaccinationDate' => $request->vaccinationDate,
                'vaccinationBrand' => $request->vaccinationBrand,
                'isFleaMedicine' => $request->isFleaMedicine,
                'fleaMedicineDate' => $request->fleaMedicineDate,
                'fleaMedicineBrand' => $request->fleaMedicineBrand,
                'previousAction' => $request->previousAction,
                'othersCompalints' => $request->othersCompalints,
                'userId' => $request->user()->id,
                'userUpdateId' => $request->user()->id, // Jika `userUpdateId` sama dengan `userId`, kamu bisa sesuaikan
            ]);

            TransactionPetClinicCheckUpResult::create([
                'transactionPetClinicId' => $request->transactionPetClinicId,
                'weight' => $request->weight,
                'weightCategory' => $request->weightCategory,
                'temperature' => $request->temperature,
                'temperatureBottom' => $request->temperatureBottom,
                'temperatureTop' => $request->temperatureTop,
                'temperatureCategory' => $request->temperatureCategory,
                'isLice' => $request->isLice,
                'noteLice' => $request->noteLice,
                'isFlea' => $request->isFlea,
                'noteFlea' => $request->noteFlea,
                'isCaplak' => $request->isCaplak,
                'noteCaplak' => $request->noteCaplak,
                'isTungau' => $request->isTungau,
                'noteTungau' => $request->noteTungau,
                'ectoParasitCategory' => $request->ectoParasitCategory,
                'isNematoda' => $request->isNematoda,
                'noteNematoda' => $request->noteNematoda,
                'isTermatoda' => $request->isTermatoda,
                'noteTermatoda' => $request->noteTermatoda,
                'isCestode' => $request->isCestode,
                'noteCestode' => $request->noteCestode,
                'isFungiFound' => $request->isFungiFound,
                'konjung' => $request->konjung,
                'ginggiva' => $request->ginggiva,
                'ear' => $request->ear,
                'tongue' => $request->tongue,
                'nose' => $request->nose,
                'CRT' => $request->CRT,
                'genitals' => $request->genitals,
                'neurologicalFindings' => $request->neurologicalFindings,
                'lokomosiFindings' => $request->lokomosiFindings,
                'isSnot' => $request->isSnot,
                'noteSnot' => $request->noteSnot,
                'breathType' => $request->breathType,
                'breathSoundType' => $request->breathSoundType,
                'breathSoundNote' => $request->breathSoundNote,
                'othersFoundBreath' => $request->othersFoundBreath,
                'pulsus' => $request->pulsus,
                'heartSound' => $request->heartSound,
                'othersFoundHeart' => $request->othersFoundHeart,
                'othersFoundSkin' => $request->othersFoundSkin,
                'othersFoundHair' => $request->othersFoundHair,
                'maleTesticles' => $request->maleTesticles,
                'othersMaleTesticles' => $request->othersMaleTesticles,
                'penisCondition' => $request->penisCondition,
                'vaginalDischargeType' => $request->vaginalDischargeType,
                'urinationType' => $request->urinationType,
                'othersUrination' => $request->othersUrination,
                'othersFoundUrogenital' => $request->othersFoundUrogenital,
                'abnormalitasCavumOris' => $request->abnormalitasCavumOris,
                'intestinalPeristalsis' => $request->intestinalPeristalsis,
                'perkusiAbdomen' => $request->perkusiAbdomen,
                'rektumKloaka' => $request->rektumKloaka,
                'othersCharacterRektumKloaka' => $request->othersCharacterRektumKloaka,
                'fecesForm' => $request->fecesForm,
                'fecesColor' => $request->fecesColor,
                'fecesWithCharacter' => $request->fecesWithCharacter,
                'othersFoundDigesti' => $request->othersFoundDigesti,
                'reflectPupil' => $request->reflectPupil,
                'eyeBallCondition' => $request->eyeBallCondition,
                'othersFoundVision' => $request->othersFoundVision,
                'earlobe' => $request->earlobe,
                'earwax' => $request->earwax,
                'earwaxCharacter' => $request->earwaxCharacter,
                'othersFoundEar' => $request->othersFoundEar,
                'userId' => $request->user()->id,
                'userUpdateId' => $request->user()->id, // Jika userUpdateId sama dengan userId, bisa disesuaikan
            ]);

            TransactionPetClinicDiagnose::create([
                'transactionPetClinicId' => $request->transactionPetClinicId,
                'diagnoseDisease' => $request->diagnoseDisease,
                'prognoseDisease' => $request->prognoseDisease,
                'diseaseProgressOverview' => $request->diseaseProgressOverview,
                'isMicroscope' => $request->isMicroscope,
                'noteMicroscope' => $request->noteMicroscope,
                'isEye' => $request->isEye,
                'noteEye' => $request->noteEye,
                'isTeskit' => $request->isTeskit,
                'noteTeskit' => $request->noteTeskit,
                'isUltrasonografi' => $request->isUltrasonografi,
                'noteUltrasonografi' => $request->noteUltrasonografi,
                'isRontgen' => $request->isRontgen,
                'noteRontgen' => $request->noteRontgen,
                'isBloodReview' => $request->isBloodReview,
                'noteBloodReview' => $request->noteBloodReview,
                'isSitologi' => $request->isSitologi,
                'noteSitologi' => $request->noteSitologi,
                'isVaginalSmear' => $request->isVaginalSmear,
                'noteVaginalSmear' => $request->noteVaginalSmear,
                'isBloodLab' => $request->isBloodLab,
                'noteBloodLab' => $request->noteBloodLab,
                'userId' => $request->user()->id,
                'userUpdateId' => $request->user()->id, // Jika `userUpdateId` sama dengan `userId`, bisa disesuaikan
            ]);

            TransactionPetClinicTreatment::create([
                'transactionPetClinicId' => $request->transactionPetClinicId,
                'isSurgery' => $request->isSurgery,
                'noteSurgery' => $request->noteSurgery,
                'infusion' => $request->infusion,
                'fisioteraphy' => $request->fisioteraphy,
                'injectionMedicine' => $request->injectionMedicine,
                'oralMedicine' => $request->oralMedicine,
                'tropicalMedicine' => $request->tropicalMedicine,
                'vaccination' => $request->vaccination,
                'othersTreatment' => $request->othersTreatment,
                'userId' => $request->user()->id,
                'userUpdateId' => $request->user()->id, // Jika `userUpdateId` sama dengan `userId`, bisa disesuaikan
            ]);

            TransactionPetClinicAdvice::create([
                'transactionPetClinicId' => $request->transactionPetClinicId,
                'inpatient' => $request->isInpatient,
                'noteInpatient' => $request->noteInpatient,
                'therapeuticFeed' => $request->isTherapeuticFeed,
                'noteTherapeuticFeed' => $request->noteTherapeuticFeed,
                'imuneBooster' => $request->imuneBooster,
                'suplement' => $request->suplement,
                'desinfeksi' => $request->desinfeksi,
                'care' => $request->care,
                'grooming' => $request->isGrooming,
                'noteGrooming' => $request->noteGrooming,
                'othersNoteAdvice' => $request->othersNoteAdvice,
                'nextControlCheckup' => $request->nextControlCheckup,
                'userId' => $request->user()->id,
                'userUpdateId' => $request->user()->id, // Jika `userUpdateId` sama dengan `userId`, bisa disesuaikan
            ]);

            if ($request->isInpatient == 1) {
                $status = 'Proses Rawat Inap';
                $typeOfCare = 2; // Rawat Inap
            } else {
                $status = 'Input Service dan Obat';
                $typeOfCare = 1; // Rawat Jalan
            }

            TransactionPetClinic::updateOrCreate(
                ['id' => $request->transactionPetClinicId],
                [
                    'status' => $status,
                    'typeOfCare' => $typeOfCare,
                    'userUpdatedId' => $request->user()->id,
                ]
            );

            transactionPetClinicLog($request->transactionPetClinicId, 'Cek kondisi vet sudah selesai', '', $request->user()->id);

            DB::commit();
            return responseCreate();
        } catch (Exception $th) {

            return responseInvalid([$th->getMessage()]);
        }
    }

    public function serviceandrecipe(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'transactionPetClinicId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        if (is_string($request->services)) {
            $dataServices = json_decode($request->services, true);
        } elseif (is_array($request->services)) {
            $dataServices = $request->services;
        }

        foreach ($dataServices as $val) {
            $find = Service::find($val['serviceId']);
            if (!$find) {
                return responseInvalid(['Service not found!']);
            }
        }

        // $ResultRecipe = json_decode($request->recipes, true);

        if (is_string($request->recipes)) {
            $ResultRecipe = json_decode($request->recipes, true);
        } elseif (is_array($request->recipes)) {
            $ResultRecipe = $request->recipes;
        }

        foreach ($ResultRecipe as $val) {
            $find = Products::find($val['productId']);
            if (!$find) {
                return responseInvalid(['Product not found!']);
            }
        }

        DB::beginTransaction();
        try {
            // Add services
            foreach ($dataServices as $val) {
                TransactionPetClinicServices::create([
                    'transactionPetClinicId' => $request->transactionPetClinicId,
                    'serviceId' => $val['serviceId'],
                    'quantity' => $val['quantity'],
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id,
                ]);
            }

            // Add recipes
            foreach ($ResultRecipe as $val) {
                TransactionPetClinicRecipes::create([
                    'transactionPetClinicId' => $request->transactionPetClinicId,
                    'productId' => $val['productId'],
                    'dosage' => $val['dosage'],
                    'unit' => $val['unit'],
                    'frequency' => $val['frequency'],
                    'duration' => $val['duration'],
                    'giveMedicine' => $val['giveMedicine'],
                    'notes' => $val['notes'],
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id,
                ]);
            }

            statusTransactionPetClinic($request->transactionId, 'Proses Pembayaran', $request->user()->id);

            transactionPetClinicLog($request->transactionPetClinicId, 'Input Layanan dan Resep Sudah Selesai', '', $request->user()->id);

            DB::commit();
            return responseCreate();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function showDataBeforePayment(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'transactionPetClinicId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $trans = TransactionPetClinic::find($request->transactionPetClinicId);

        $phone = CustomerTelephones::where('customerId', '=', $trans->customerId)
            ->where('usage', '=', 'Utama')
            ->first();

        $cust = Customer::find($trans->customerId);

        $dataServices = TransactionPetClinicServices::from('transaction_pet_clinic_services as tpcs')
            ->join('services as s', 's.id', '=', 'tpcs.serviceId')
            ->join('servicesPrice as sp', 's.id', '=', 'sp.service_id')
            ->select(
                's.id as serviceId',
                's.fullName as serviceName',
                DB::raw("TRIM(tpcs.quantity)+0 as quantity"),
                DB::raw("TRIM(sp.price)+0 as basedPrice"),
            )
            ->where('tpcs.transactionPetClinicId', '=', $request->transactionPetClinicId)
            ->where('sp.location_id', '=', $trans->locationId)
            ->get();

        $dataRecipes = TransactionPetClinicRecipes::from('transaction_pet_clinic_recipes as rc')
            ->join('products as p', 'p.id', '=', 'rc.productId')
            ->join('productLocations as pl', 'p.id', '=', 'pl.productId')
            ->select(
                'p.id as productId',
                'p.fullName as productName',
                DB::raw("TRIM(rc.dosage) AS dosage"),
                DB::raw("TRIM(rc.unit) AS unit"),
                DB::raw("TRIM(rc.frequency) AS frequency"),
                DB::raw("TRIM(rc.duration) AS duration"),
                'rc.giveMedicine',
                'rc.notes',
                'p.price as basedPrice'
            )
            ->where('rc.transactionPetClinicId', '=', $request->transactionPetClinicId)
            ->where('pl.locationId', '=', $trans->locationId)
            ->get();

        $data = [
            'services' => $dataServices,
            'recipes' => $dataRecipes,
        ];

        return response()->json([
            'customerName' => $cust ? $cust->firstName : '',
            'phoneNumber' => $phone ? $phone->phoneNumber : '',
            'arrivalTime' => $trans->created_at->locale('id')->translatedFormat('l, j F Y H:i'),
            'data' => $data,
        ]);
    }

    public function checkPromo(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'transactionPetClinicId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $trans = TransactionPetClinic::find($request->transactionPetClinicId);

        if (!$trans) {
            return responseInvalid(['Transaction not found!']);
        }

        $custGroup = "";

        if (!is_null($trans->customerId)) {
            $cust = Customer::find($trans->customerId);
            $custGroup = $cust->customerGroupId;
        }

        $dataRecipes = $this->ensureIsArray($request->recipes);
        $dataServices = $this->ensureIsArray($request->services);
        $dataProducts = $this->ensureIsArray($request->products);

        $tempFree = [];
        $tempDiscount = [];
        $resultBundle = [];

        //free item
        foreach ($dataRecipes as $value) {

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
        foreach ($dataRecipes as $value) {
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
        foreach ($dataRecipes as $value) {
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
        foreach ($dataRecipes as $value) {
            $totalTransaction += $value['priceOverall'];
        }

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

    public function transactionDiscount(Request $request)
    {
        $services = $this->ensureIsArray($request->services);

        $recipes = $this->ensureIsArray($request->recipes);

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

        $trans = TransactionPetClinic::find($request->transactionPetClinicId);

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

        foreach ($recipes as $value) {
            $isGetPromo = false;

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

                    $includedItems = DB::table('promotion_bundle_detail_products as pbd')
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
                            'pm.id as promoId',
                            'p.id as productId',
                            'p.fullName as item_name',
                            'p.category',
                            DB::raw($value['dosage'] * $value['frequency'] * $value['duration'] . ' as quantity'),
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
                        'productId' => $data->productId,
                        'promoCategory' => 'discount',
                        'discountType' => $data->discountType,
                    ];

                    $subtotal += ($value['priceOverall'] - $saved);
                    $totalDiscount += $saved;
                    $promoNotes[] = $discountNote;
                }
            }

            if (!$isGetPromo) {
                $res = DB::table('products as p')
                    ->select(
                        DB::raw('NULL as promoId'),
                        'p.id as productId',
                        'p.fullName as item_name',
                        'p.category',
                        DB::raw($value['dosage'] * $value['frequency'] * $value['duration'] . ' as quantity'),
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

    //pembayara rawat inap
    public function paymentInpatient(Request $request) {}

    //pembayaran rawat jalan
    public function paymentOutpatient(Request $request)
    {
        $purchases = $this->ensureIsArray($request->purchases);
        $json_string = $request->payment_method;
        $payment = json_decode($json_string, true);

        $validate = Validator::make($request->all(), [
            'transactionPetClinicId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        $trans = TransactionPetClinic::find($request->transactionPetClinicId);
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

                            $trx = new transaction_pet_clinic_payments();
                            $trx->transactionId = $request->transactionPetClinicId;
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
                        $trx = new transaction_pet_clinic_payments();
                        $trx->transactionId = $request->transactionPetClinicId;
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

                            $trx = new transaction_pet_clinic_payments();
                            $trx->transactionId = $request->transactionPetClinicId;
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
                        $trx = new transaction_pet_clinic_payments();
                        $trx->transactionId = $request->transactionPetClinicId;
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

                    $trx = new transaction_pet_clinic_payments();
                    $trx->transactionId = $request->transactionPetClinicId;
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

                    $trx = new transaction_pet_clinic_payments();
                    $trx->transactionId = $request->transactionPetClinicId;
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

                            $bundle = new transaction_pet_clinic_payment_bundle();
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

                            $bundle = new transaction_pet_clinic_payment_bundle();
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

            $detail = json_decode($request->detail_total, true);

            if (array_key_exists('promoBasedSaleId', $detail)) {

                $promo = PromotionMaster::find($detail['promoBasedSaleId']);
                if (!$promo) {
                    DB::rollBack();
                    return responseInvalid(['Promotion based sales not found!']);
                }

                $sales = new transaction_pet_clinic_payment_based_sales();
                $sales->transactionId = $request->transactionPetClinicId;
                $sales->paymentMethodId = $payment['paymentId'];
                $sales->promoId = $detail['promoBasedSaleId'];
                $sales->amountDiscount = $detail['discount_based_sales'];
                $sales->userId = $request->user()->id;
                $sales->save();
            }

            //detail total
            $total = new transaction_pet_clinic_payment_total();
            $total->transactionId = $request->transactionPetClinicId;
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

            $jumlahTransaksi = DB::table('transaction_pet_clinic_payment_totals as tp')
                ->join('transaction_pet_clinics as tpc', 'tp.transactionId', '=', 'tpc.id')
                ->where('tpc.locationId', $locationId)
                ->whereYear('tp.created_at', $tahun)
                ->whereMonth('tp.created_at', $bulan)
                ->count();

            $nomorUrut = str_pad($jumlahTransaksi + 1, 4, '0', STR_PAD_LEFT);

            $notaNumber = "INV/PC/{$locationId}/{$tahun}/{$bulan}/{$nomorUrut}";
            $total->nota_number = $notaNumber;

            $total->userId = $request->user()->id;
            $total->save();

            transactionPetClinicLog($request->transactionPetClinicId, 'Nota diterbitkan', '', $request->user()->id);

            statusTransactionPetClinic($request->transactionId, 'Menunggu konfirmasi pembayaran', $request->user()->id);
            DB::commit();

            updateLastTransaction($trans->customerId);

            return responseCreate();
        } catch (\Throwable $th) {
            DB::rollback();
            return responseInvalid([$th->getMessage()]);
        }
    }

    public function createList(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'category' => 'required|string|in:weight,temperature,breath,sound,heart,vaginal',
            'name' => 'required|string'
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();
            return responseInvalid($errors);
        }

        if ($request->category == 'weight') {
            ListWeightTransaction::create(
                [
                    'name' => $request->name,
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id
                ]
            );
        } elseif ($request->category == 'temperature') {
            ListTemperatureTransaction::create(
                [
                    'name' => $request->name,
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id
                ]
            );
        } elseif ($request->category == 'breath') {
            ListBreathTransaction::create(
                [
                    'name' => $request->name,
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id
                ]
            );
        } elseif ($request->category == 'sound') {
            ListSoundTransaction::create(
                [
                    'name' => $request->name,
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id
                ]
            );
        } elseif ($request->category == 'heart') {
            ListHeartTransaction::create(
                [
                    'name' => $request->name,
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id
                ]
            );
        } elseif ($request->category == 'vaginal') {
            ListVaginalTransaction::create(
                [
                    'name' => $request->name,
                    'userId' => $request->user()->id,
                    'userUpdateId' => $request->user()->id
                ]
            );
        }

        return responseCreate();
    }

    public function printInvoce(Request $request)
    {
        $trans = transaction_pet_clinic_payment_total::find($request->paymentId);

        if (!$trans) {
            return responseInvalid(['Transaction not found!']);
        }

        $payment = transaction_pet_clinic_payments::where('transactionId', $trans->transactionId)->get();

        $trx = TransactionPetClinic::find($trans->transactionId);

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
            ->where('c.id', '=', $trx->customerId)
            ->select('c.firstName', 'ct.phoneNumber', 'c.memberNo')
            ->first();

        $details = $payment;
        $namaFile = $trans->nota_number . '.pdf';

        $detail_total = $trans;

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
            'total_tagihan'  => $detail_total['total_payment'],
        ];

        $pdf = Pdf::loadView('invoice.invoice_petclinic_outpatient', $data);
        return $pdf->download($namaFile);
    }

    public function printInvoceOutpatient(Request $request)
    {
        $trans = TransactionPetClinic::find($request->transactionPetClinicId);

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
            'total_tagihan'  => $detail_total['total_payment'],
        ];

        $pdf = Pdf::loadView('invoice.invoice_petclinic_outpatient', $data);
        return $pdf->download($namaFile);

        return view('transaction.petclinic.print_invoice_outpatient');
    }

    public function confirmPayment(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048'
        ]);

        $trans_pay = transaction_pet_clinic_payment_total::find($request->id);

        if (!$trans_pay) {
            return responseInvalid(['Transaction is not found!']);
        }

        if ($trans_pay->isPayed == 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi sudah dikonfirmasi sebelumnya.'
            ], 400);
        }

        if (!$request->hasFile('proof')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bukti pembayaran wajib diunggah!'
            ], 422);
        }

        $filePath = null;
        $originalName = null;
        $randomName = null;

        if ($request->hasFile('proof')) {
            $file = $request->file('proof');
            $originalName = $file->getClientOriginalName();
            $randomName = 'proof_' . $trans_pay->id . '_' . time() . '.' . $file->getClientOriginalExtension();

            if (!Storage::disk('public')->exists('Transaction/Petclinic/proof_of_payment')) {
                Storage::disk('public')->makeDirectory('Transaction/Petclinic/proof_of_payment');
            }

            $filePath = $file->storeAs('Transaction/Petclinic/proof_of_payment', $randomName, 'public');

            $trans_pay->proofOfPayment = $filePath;
            $trans_pay->originalName = $originalName;
            $trans_pay->proofRandomName = $randomName;
        }

        $trans_pay->isPayed = 1;
        $trans_pay->updated_at = now();
        $trans_pay->save();

        $trans = transaction_pet_clinic_payment_total::where('transactionId', $trans_pay->transactionId)->first();

        $total_amount = $trans->amount;
        $amount_paid = transaction_pet_clinic_payment_total::where('transactionId', $trans_pay->transactionId)->sum('amountPaid');

        if ($amount_paid < $total_amount)
            statusTransactionPetClinic($trans_pay->transactionId, 'Menunggu Pembayaran Berikutnya', $request->user()->id);
        else
            statusTransactionPetClinic($trans_pay->transactionId, 'Selesai', $request->user()->id);


        transactionPetClinicLog($trans_pay->transactionId, 'Pembayaran Dikonfirmasi', '', $request->user()->id);

        return responseCreate();
    }

    public function listDataWeight()
    {
        $data = ListWeightTransaction::select('id', 'name')
            ->where('isDeleted', '=', 0)
            ->get();

        return responseList($data);
    }

    public function listDataTemperature()
    {
        $data = ListTemperatureTransaction::select('id', 'name')
            ->where('isDeleted', '=', 0)
            ->get();

        return responseList($data);
    }

    public function listDatabreath()
    {
        $data = ListBreathTransaction::select('id', 'name')
            ->where('isDeleted', '=', 0)
            ->get();

        return responseList($data);
    }

    public function listDatasound()
    {
        $data = ListSoundTransaction::select('id', 'name')
            ->where('isDeleted', '=', 0)
            ->get();

        return responseList($data);
    }

    public function listDataheart()
    {
        $data = ListHeartTransaction::select('id', 'name')
            ->where('isDeleted', '=', 0)
            ->get();

        return responseList($data);
    }

    public function listDatavaginal()
    {
        $data = ListVaginalTransaction::select('id', 'name')
            ->where('isDeleted', '=', 0)
            ->get();

        return responseList($data);
    }
}
