<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\Staff\exportStaff;
use Illuminate\Http\Request;
use App\Mail\SendEmail;
use GuzzleHttp\Client;
use Carbon\Carbon;
use Validator;
use File;
use DB;
use App\Imports\Staff\ImportStaff;
use App\Models\Staff\JobTitle;
use App\Models\Staff\PayPeriod;
use App\Models\Staff\UsersDetailAddresses;
use App\Models\Staff\UsersEmails;
use App\Models\Staff\UsersLocation;
use App\Models\Staff\UsersMessengers;
use App\Models\Staff\UsersRoles;
use App\Models\Staff\UsersTelephones;
use App\Models\staffcontract;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class StaffController extends Controller
{

    private $client;
    private $api_key;
    private $country;

    public function insertStaff(Request $request)
    {
        if (!checkAccessModify('staff-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }
        DB::beginTransaction();

        try {

            $validate = Validator::make(
                $request->all(),
                [
                    'firstName' => 'required|max:20|min:3',
                    'middleName' => 'max:20|min:3|nullable',
                    'lastName' => 'max:20|min:3|nullable',
                    'nickName' => 'max:20|min:3|nullable',
                    'gender' => 'string|nullable',
                    'status' => 'required|integer',
                    'lineManagerId' => 'required|integer',
                    'jobTitleId' => 'required|integer',
                    'startDate' => 'required|date',
                    'endDate' => 'required|date|after:startDate',
                    'registrationNo' => 'string|max:20|min:5|nullable',
                    'designation' => 'string|max:20|min:5|nullable',
                    'locationId' => 'required',
                    'annualSickAllowance' => 'integer|nullable',
                    'annualLeaveAllowance' => 'integer|nullable',
                    'payPeriodId' => 'required|integer',
                    'payAmount' => 'numeric|nullable',
                    //'typeId' => 'required',
                    //'identificationNumber' => 'string|nullable|max:30',
                    'additionalInfo' => 'string|nullable|max:100',
                    'generalCustomerCanSchedule' => 'integer|nullable',
                    'generalCustomerReceiveDailyEmail' => 'integer|nullable',
                    'generalAllowMemberToLogUsingEmail' => 'integer|nullable',
                    'reminderEmail' => 'integer|nullable',
                    'reminderWhatsapp' => 'integer|nullable',
                    'roleId' => 'integer|nullable',
                ]
            );

            if ($validate->fails()) {
                $errors = $validate->errors()->all();

                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }

            $start = Carbon::parse($request->startDate);
            $end = Carbon::parse($request->endDate);
            $data_item = [];

            if ($request->detailAddress) {

                $arrayDetailAddress = json_decode($request->detailAddress, true);

                $messageAddress = [
                    'addressName.required' => 'Address name on tab Address is required',
                    'provinceCode.required' => 'Province code on tab Address is required',
                    'cityCode.required' => 'City code on tab Address is required',
                    'country.required' => 'Country on tab Address is required',
                ];


                $primaryCount = 0;
                foreach ($arrayDetailAddress as $item) {
                    if (isset($item['isPrimary']) && $item['isPrimary'] == 1) {
                        $primaryCount++;
                    }
                }

                if ($primaryCount == 0) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Detail address must have at least 1 primary address',
                    ], 422);
                } elseif ($primaryCount > 1) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Detail address have 2 primary address, please check again',
                    ], 422);
                }

                foreach ($arrayDetailAddress as $key) {

                    $validateDetail = Validator::make(
                        $key,
                        [
                            'addressName' => 'required',
                            'provinceCode' => 'required',
                            'cityCode' => 'required',
                            'country' => 'required',
                        ],
                        $messageAddress
                    );

                    if ($validateDetail->fails()) {

                        $errors = $validateDetail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_item))) {
                                array_push($data_item, $checkisu);
                            }
                        }
                    }
                }

                if ($data_item) {

                    return response()->json([
                        'message' =>  'Inputed data is not valid',
                        'errors' => $data_item,
                    ], 422);
                }
            } else {

                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Detail address can not be empty!'],
                ], 422);
            }



            $checkusageUtama = 0;
            $data_telephone = [];

            if ($request->telephone) {

                $arraytelephone = json_decode($request->telephone, true);

                $messagePhone = [
                    'phoneNumber.required' => 'Phone Number on tab telephone is required',
                    'type.required' => 'Type on tab telephone is required',
                    'usage.required' => 'Usage on tab telephone is required',
                ];

                foreach ($arraytelephone as $key) {

                    $validateTelephone = Validator::make(
                        $key,
                        [
                            'phoneNumber' => 'required',
                            'type' => 'required',
                            'usage' => 'required',
                        ],
                        $messagePhone
                    );


                    if (strtolower($key['usage']) == "utama" || strtolower($key['usage']) == "primary") {
                        $checkusageUtama = $checkusageUtama + 1;
                    }

                    if ($checkusageUtama > 1) {
                        return responseInvalid(['Usage utama on phone must only one!']);
                    }


                    if ($validateTelephone->fails()) {

                        $errors = $validateTelephone->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_telephone))) {
                                array_push($data_telephone, $checkisu);
                            }
                        }
                    }

                    if (strtolower($key['type']) == "whatshapp") {

                        if (!(substr($key['phoneNumber'], 0, 2) === "62")) {
                            return response()->json([
                                'message' => 'Inputed data is not valid',
                                'errors' => 'Please check your phone number, for type whatshapp must start with 62',
                            ], 422);
                        }
                    }
                }

                if ($data_telephone) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $data_telephone,
                    ], 422);
                }

                $checkTelephone = [];

                foreach ($arraytelephone as $val) {

                    $checkIfTelephoneAlreadyExists = DB::table('usersTelephones')
                        ->where([
                            ['phoneNumber', '=', $val['phoneNumber'],],
                            ['isDeleted', '=', '0']
                        ])
                        ->first();

                    if ($checkIfTelephoneAlreadyExists) {
                        array_push($checkTelephone, 'Phonenumber : ' . $val['phoneNumber'] . ' already exists, please try different number');
                    }
                }


                if ($checkTelephone) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $checkTelephone,
                    ], 422);
                }
            }

            $checkEmailUtama = 0;
            $data_error_email = [];
            $insertEmailUsers = '';
            if ($request->email) {

                $arrayemail = json_decode($request->email, true);

                $messageEmail = [
                    'email.required' => 'Email on tab email is required',
                    'usage.required' => 'Usage on tab email is required',
                ];

                foreach ($arrayemail as $key) {

                    $validateEmail = Validator::make(
                        $key,
                        [
                            'email' => 'required',
                            'usage' => 'required',
                        ],
                        $messageEmail
                    );


                    if (strtolower($key['usage']) == "utama" || strtolower($key['usage']) == "primary") {
                        $checkEmailUtama = $checkEmailUtama + 1;
                    }

                    if ($checkEmailUtama > 1) {
                        return responseInvalid(['Usage utama on email must only one!']);
                    }

                    if ($validateEmail->fails()) {

                        $errors = $validateEmail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_error_email))) {
                                array_push($data_error_email, $checkisu);
                            }
                        }
                    }
                }


                if ($data_error_email) {
                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' =>  $data_error_email,
                    ], 422);
                }

                $checkUsageEmail = false;
                $checkEmail = [];
                foreach ($arrayemail as $val) {

                    $checkIfEmailExists = DB::table('usersEmails')
                        ->where([
                            ['email', '=', $val['email'],],
                            ['isDeleted', '=', '0']
                        ])
                        ->first();

                    if ($checkIfEmailExists) {
                        array_push($checkEmail, 'Email : ' . $val['email'] . ' already exists, please try different email address');
                    }

                    if ($val['usage'] == 'Utama') {
                        $checkUsageEmail = true;
                        $insertEmailUsers = $val['email'];
                    }
                }

                if ($checkEmail) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $checkEmail,
                    ], 422);
                }

                if ($checkUsageEmail == false) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Must have one primary email',
                    ], 422);
                }
            } else {

                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Email can not be empty!'],
                ], 422);
            }


            $data_error_messenger = [];

            if ($request->messenger) {

                $arraymessenger = json_decode($request->messenger, true);

                $messageMessenger = [
                    'messengerNumber.required' => 'messenger number on tab messenger is required',
                    'type.required' => 'Type on tab messenger is required',
                    'usage.required' => 'Usage on tab messenger is required',
                ];



                $checkMessengerUtama = 0;

                foreach ($arraymessenger as $key) {

                    $validateMessenger = Validator::make(
                        $key,
                        [
                            'messengerNumber' => 'required',
                            'type' => 'required',
                            'usage' => 'required',
                        ],
                        $messageMessenger
                    );


                    if (strtolower($key['usage']) == "utama" || strtolower($key['usage']) == "primary") {
                        $checkMessengerUtama = $checkMessengerUtama + 1;
                    }


                    if ($checkMessengerUtama > 1) {
                        return responseInvalid(['Usage utama on messenger must only one!']);
                    }

                    if ($validateMessenger->fails()) {

                        $errors = $validateMessenger->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_error_messenger))) {
                                array_push($data_error_messenger, $checkisu);
                            }
                        }
                    }


                    if (strtolower($key['type']) == "whatshapp") {

                        if (!(substr($key['messengerNumber'], 0, 3) === "62")) {

                            return response()->json([
                                'message' => 'Inputed data is not valid',
                                'errors' => 'Please check your phone number, for type whatshapp must start with 62',
                            ], 422);
                        }
                    }
                }


                if ($data_error_messenger) {
                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => $data_error_messenger,
                    ], 422);
                }

                $checkMessenger = [];
                foreach ($arraymessenger as $val) {

                    $checkifMessengerExists = DB::table('usersMessengers')
                        ->where([
                            ['messengerNumber', '=', $val['messengerNumber'],],
                            ['isDeleted', '=', '0']
                        ])
                        ->first();

                    if ($checkifMessengerExists) {
                        array_push($checkMessenger, 'Messenger number  : ' . $val['messengerNumber'] . ' already exists, please try different number');
                    }
                }

                if ($checkMessenger) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $checkMessenger,
                    ], 422);
                }
            }

            $lastInsertedID = DB::table('users')
                ->insertGetId([
                    'firstName' => $request->firstName,
                    'middleName' => $request->middleName,
                    'lastName' => $request->lastName,
                    'nickName' => $request->nickName,
                    'gender' => $request->gender,
                    'status' => $request->status,
                    'lineManagerId' => $request->lineManagerId,
                    'jobTitleId' => $request->jobTitleId,
                    'startDate' =>  $start,
                    'endDate' => $end,
                    'joinDate' => $start,
                    'registrationNo' => $request->registrationNo,
                    'designation' => $request->designation,
                    'annualSickAllowance' => $request->annualSickAllowance,
                    'annualSickAllowanceRemaining' => $request->annualSickAllowance,
                    'annualLeaveAllowance' => $request->annualLeaveAllowance,
                    'annualLeaveAllowanceRemaining' => $request->annualLeaveAllowance,
                    'payPeriodId' => $request->payPeriodId,
                    'payAmount' => $request->payAmount,
                    'typeId' => 0,
                    'identificationNumber' => '',
                    'additionalInfo' => $request->additionalInfo,
                    'generalCustomerCanSchedule' => $request->generalCustomerCanSchedule,
                    'generalCustomerReceiveDailyEmail' => $request->generalCustomerReceiveDailyEmail,
                    'generalAllowMemberToLogUsingEmail' => $request->generalAllowMemberToLogUsingEmail,
                    'reminderEmail' => $request->reminderEmail,
                    'reminderWhatsapp' => $request->reminderWhatsapp,
                    'roleId' => $request->roleId,
                    'isDeleted' => 0,
                    'createdBy' => $request->user()->firstName,
                    'email' => $insertEmailUsers,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'password' => null,
                    'isLogin' => 0,
                ]);

            recentActivity(
                $request->user()->id,
                'Staff',
                'Add Staff',
                'Add new staff'
            );

            staffcontract::create([
                'staffId' => $lastInsertedID,
                'startDate' => $start,
                'endDate' => $end,
                'userId' => $request->user()->id,
            ]);

            $locationId = json_decode($request->locationId, true);

            if ($locationId) {
                foreach ($locationId as $val) {

                    DB::table('usersLocation')
                        ->insert([
                            'usersId' => $lastInsertedID,
                            'locationId' => $val,
                            'isMainLocation' => 1,
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }


            if ($request->detailAddress) {

                foreach ($arrayDetailAddress as $val) {

                    DB::table('usersDetailAddresses')
                        ->insert([
                            'usersId' => $lastInsertedID,
                            'addressName' => $val['addressName'],
                            'additionalInfo' => $val['additionalInfo'],
                            'provinceCode' => $val['provinceCode'],
                            'cityCode' => $val['cityCode'],
                            'postalCode' => $val['postalCode'],
                            'country' => $val['country'],
                            'isPrimary' => $val['isPrimary'],
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }


            $identify = json_decode($request->typeIdentifications, true);

            $flag = false;
            $res_data = [];
            $files[] = $request->file('imageIdentifications');
            $count = 0;

            if ($flag == false) {

                if ($request->hasfile('imageIdentifications')) {

                    foreach ($files as $file) {

                        foreach ($file as $fil) {

                            $name = $fil->hashName();

                            $fil->move(public_path() . '/UsersIdentificationImages/', $name);

                            $fileName = "/UsersIdentificationImages/" . $name;

                            DB::table('usersIdentifications')
                                ->insert([
                                    'usersId' => $lastInsertedID,
                                    'typeId' => $identify[$count]['typeId'],
                                    'identification' => $identify[$count]['identificationNumber'],
                                    'imagePath' => $fileName,
                                    'status' => 1,
                                    'reason' => '',
                                    'approvedBy' => $request->user()->id,
                                    'approvedAt' => now(),
                                    'isDeleted' => 0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                            array_push($res_data, $file);

                            $count += 1;
                        }
                    }

                    $flag = true;
                }
            } else {

                foreach ($res_data as $res) {

                    DB::table('usersIdentifications')
                        ->insert([
                            'usersId' => $lastInsertedID,
                            'typeId' => $identify[$count]['typeId'],
                            'identification' => $identify[$count]['identificationNumber'],
                            'imagePath' => $res['imagePath'],
                            'status' => 1,
                            'reason' => '',
                            'approvedBy' => $request->user()->id,
                            'approvedAt' => now(),
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }

            if ($request->messenger) {

                foreach ($arraymessenger as $val) {

                    DB::table('usersMessengers')
                        ->insert([
                            'usersId' => $lastInsertedID,
                            'messengerNumber' => $val['messengerNumber'],
                            'type' => $val['type'],
                            'usage' => $val['usage'],
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }

            if ($request->email) {

                foreach ($arrayemail as $val) {

                    DB::table('usersEmails')
                        ->insert([
                            'usersId' => $lastInsertedID,
                            'email' => $val['email'],
                            'usage' => $val['usage'],
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }

            if ($request->telephone) {

                foreach ($arraytelephone as $val) {

                    DB::table('usersTelephones')
                        ->insert([
                            'usersId' => $lastInsertedID,
                            'phoneNumber' => $val['phoneNumber'],
                            'type' => $val['type'],
                            'usage' => $val['usage'],
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }

            if ($request->status == 1) {

                $sendEmailPrimary = DB::table('usersEmails as usersEmails')
                    ->leftjoin('users as users', 'users.id', '=', 'usersEmails.usersId')
                    ->select(
                        'usersEmails.usersId',
                        'usersEmails.email',
                        DB::raw("
                        REPLACE(
                            TRIM(
                                REPLACE(
                                    CONCAT(
                                        IFNULL(users.firstName, ''),
                                        IF(users.middleName IS NOT NULL AND users.middleName != '', CONCAT(' ', users.middleName), ''),
                                        IFNULL(CONCAT(' ', users.lastName), ''),
                                        IFNULL(CONCAT(' (', users.nickName, ')'), '')
                                    ),
                                    '  (',
                                    '('
                                )
                            ),
                            ' (',
                            '('
                        ) AS name
                        "),
                    )
                    ->where([
                        ['usersEmails.usersId', '=', $lastInsertedID],
                        ['usersEmails.isDeleted', '=', '0'],
                        ['usersEmails.usage', '=', 'Utama']
                    ])
                    ->first();

                $jobtitleName = DB::table('jobTitle')
                    ->select('jobName')
                    ->where([
                        ['id', '=', $request->jobTitleId],
                        ['isActive', '=', 1]
                    ])
                    ->first();

                $data = [
                    'subject' => 'RPC Petshop',
                    'body' => 'Please verify your account',
                    'isi' => 'This e-mail was sent from a notification-only address that cannot accept incoming e-mails. Please do not reply to this message.',
                    'name' => $sendEmailPrimary->name,
                    'email' => $sendEmailPrimary->email,
                    'jobTitle' => $jobtitleName->jobName,
                    'usersId' => $sendEmailPrimary->usersId,
                ];

                Mail::to($sendEmailPrimary->email)->send(new SendEmail($data));

                DB::commit();

                return response()->json(
                    [
                        'result' => 'success',
                        'message' => 'Insert Data Users Successful! Please check your email for verification',
                    ],
                    200
                );
            } else {

                DB::commit();

                return response()->json(
                    [
                        'result' => 'success',
                        'message' => 'Insert Data Users Successful!',
                    ],
                    200
                );
            }


            DB::commit();

            return response()->json(
                [
                    'result' => 'success',
                    'message' => 'Insert Data Users Successful!',
                ],
                200
            );
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'result' => 'failed',
                'message' => $e,
            ], 422);
        }
    }

    public function sendEmailVerification(Request $request)
    {

        if (adminAccess($request->user()->id) != 1) {
            return response()->json([
                'message' => 'The user role was invalid.',
                'errors' => ['User Access not Authorize!'],
            ], 403);
        }

        $validate = Validator::make($request->all(), [
            'id' => 'required',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        DB::beginTransaction();
        try {

            $checkIfDataExits = DB::table('users')
                ->where([
                    ['id', '=', $request->id],
                    ['isDeleted', '=', 0],
                ])
                ->first();

            if (!$checkIfDataExits) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Data not found! try different ID'],
                ], 422);
            }


            $users = DB::table('users')
                ->select(
                    'status',
                    'password',
                )
                ->where('id', '=', $request->id)
                ->first();

            if ($users->status == 0) {
                return response()->json([
                    'message' => 'Failed',
                    'errors' => 'Please activated your account first',
                ], 406);
            } else {


                if ($users->password != null) {
                    return response()->json([
                        'message' => 'failed',
                        'errors' => 'Your account password has been set and verified within email',
                    ], 406);
                } else {

                    $sendEmailPrimary = DB::table('users')
                        ->select(
                            'jobTitleId',
                            'email',
                            DB::raw("CONCAT(IFNULL(users.firstName,'') ,' ', IFNULL(users.middleName,'') ,' ', IFNULL(users.lastName,'') ,'(', IFNULL(users.nickName,'') ,')'  ) as name"),
                        )
                        ->where([
                            ['id', '=', $request->id],
                            ['isDeleted', '=', 0],

                        ])
                        ->first();


                    $jobtitleName = DB::table('jobTitle')
                        ->select('jobName')
                        ->where([
                            ['id', '=', $sendEmailPrimary->jobTitleId],
                            ['isActive', '=', 1]
                        ])
                        ->first();

                    $data = [
                        'subject' => 'RPC Petshop',
                        'body' => 'Please verify your account',
                        'isi' => 'This e-mail was sent from a notification-only address that cannot accept incoming e-mails. Please do not reply to this message.',
                        'name' => $sendEmailPrimary->name,
                        'email' => $sendEmailPrimary->email,
                        'jobTitle' => $jobtitleName->jobName,
                        'usersId' => $request->id,
                    ];

                    Mail::to($sendEmailPrimary->email)->send(new SendEmail($data));
                }
            }

            return response()->json([
                'result' => 'success',
                'message' => 'Successfully send email! Please check your email for verification',
            ], 200);
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function updateStatusUsers(Request $request)
    {

        if (adminAccess($request->user()->id) != 1) {
            return response()->json([
                'message' => 'The user role was invalid.',
                'errors' => ['User Access not Authorize!'],
            ], 403);
        }

        $validate = Validator::make($request->all(), [
            'id' => 'required',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        DB::beginTransaction();
        try {

            $checkIfDataExits = DB::table('users')
                ->where([
                    ['id', '=', $request->id],
                    ['isDeleted', '=', 0],
                ])
                ->first();

            if (!$checkIfDataExits) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Data not found! try different ID'],
                ], 422);
            }

            $users = DB::table('users')
                ->select('status')
                ->where('id', '=', $request->id)
                ->first();

            if ($users->status == 1) {
                return response()->json([
                    'message' => 'failed',
                    'errors' => 'Your account already been activated',
                ], 406);
            } else {

                $users = DB::table('users')
                    ->select('status')
                    ->where('id', '=', $request->id)
                    ->first();

                DB::table('users')
                    ->where('id', '=', $request->id)
                    ->update([
                        'status' => 1,
                    ]);

                DB::commit();

                return response()->json([
                    'result' => 'success',
                    'message' => 'Successfuly activated your account, you can send email for setting your password',
                ], 200);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }



    public function getAllHolidaysDate(Request $request)
    {

        try {

            $valYear = null;

            if ($request->year) {
                $valYear = $request->year;
            } else {
                $valYear = date('Y');
            }


            $response = $this->client->request('GET', 'holidays', [
                'query' => [
                    'api_key' => $this->api_key,
                    'country' => $this->country,
                    'year' => $valYear,
                ],
            ]);

            $holidays = json_decode($response->getBody())->response->holidays;

            foreach ($holidays as $val) {

                if ($val->type[0] == "National holiday") {

                    if (DB::table('holidays')
                        ->where('type', $val->type[0])
                        ->where('year', $valYear)
                        ->where('date', $val->date->iso)
                        ->exists()
                    ) {

                        DB::table('holidays')
                            ->where('type', $val->type[0])
                            ->where('date', $val->date->iso)
                            ->where('year', $valYear)
                            ->update([
                                'date' => $val->date->iso,
                                'type' => $val->type[0],
                                'description' => $val->name,
                                'year' => $valYear,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                    } else {

                        DB::table('holidays')
                            ->insert([
                                'date' => $val->date->iso,
                                'type' => $val->type[0],
                                'year' => $valYear,
                                'description' => $val->name,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'result' => 'Success',
                'message' => "Successfully input date holidays",
            ], 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://calendarific.com/api/v2/',
        ]);
        $this->api_key = '40a18b1a57c593a8ba3e949ce44420e52b610171';
        $this->country = 'ID';
    }


    public function getRoleStaff(Request $request)
    {

        try {

            $getRole = DB::table('usersRoles')
                ->select(
                    'id',
                    'roleName'
                )
                ->where([['isActive', '=', '1']])
                ->orderBy('id', 'asc')
                ->get();

            return response()->json($getRole, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function getRoleName(Request $request)
    {

        try {

            $getRole = DB::table('usersRoles')
                ->select(
                    'id',
                    'roleName'
                )
                ->where([['isActive', '=', '1']])
                ->orderBy('id', 'asc')
                ->get();

            return response()->json($getRole, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }
    public function getDataIndex()
    {


        $dataUserLocation = DB::table('usersLocation as a')
            ->leftJoin('location as b', 'b.id', '=', 'a.locationId')
            ->select('a.usersId', DB::raw("GROUP_CONCAT(b.id) as locationId"), DB::raw("GROUP_CONCAT(b.locationName) as locationName"))
            ->groupBy('a.usersId')
            ->where('a.isDeleted', '=', 0);

        $subquery = DB::table('users as a')
            ->leftjoin('jobTitle as b', 'b.id', '=', 'a.jobTitleId')
            ->leftjoin('usersEmails as c', 'c.usersId', '=', 'a.id')
            ->leftjoin('usersTelephones as d', 'd.usersId', '=', 'a.id')
            ->leftJoinSub($dataUserLocation, 'e', function ($join) {
                $join->on('e.usersId', '=', 'a.id');
            })
            ->select(
                'a.id as id',
                DB::raw("
                REPLACE(
                    TRIM(
                        REPLACE(
                            CONCAT(
                                IFNULL(a.firstName, ''),
                                IF(a.middleName IS NOT NULL AND a.middleName != '', CONCAT(' ', a.middleName), ''),
                                IFNULL(CONCAT(' ', a.lastName), ''),
                                IFNULL(CONCAT(' (', a.nickName, ')'), '')
                            ),
                            '  (',
                            '('
                        )
                    ),
                    ' (',
                    '('
                ) AS name
                "),
                'b.jobName as jobTitle',
                'c.email as emailAddress',
                DB::raw("CONCAT(d.phoneNumber) as phoneNumber"),
                DB::raw("CASE WHEN lower(d.type)='whatshapp' then true else false end as isWhatsapp"),
                DB::raw("CASE WHEN a.status=1 then 'Active' else 'Non Active' end as status"),
                'e.locationName as location',
                'e.locationId as locationId',
                'a.createdBy as createdBy',
                DB::raw("IFNULL(DATE_FORMAT(a.created_at, '%d/%m/%Y %H:%i:%s'),'') as createdAt"),
                'a.updated_at'
            )
            ->where([
                ['a.isDeleted', '=', '0'],
                ['c.usage', '=', 'Utama'],
                ['c.isDeleted', '=', '0'],
                ['d.usage', '=', 'Utama'],
            ]);


        $data = DB::table($subquery, 'a');

        return $data;
    }



    public function index(Request $request)
    {
        if (!checkAccessIndex('staff-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        try {

            $defaultRowPerPage = 5;
            $defaultOrderBy = "asc";

            $data = $this->getDataIndex();

            if ($request->locationId) {

                $test = $request->locationId;

                $data = $data->where(function ($query) use ($test) {
                    foreach ($test as $id) {
                        $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                    }
                });
            }

            if ($request->search) {

                $res = $this->Search($request);
                if ($res == "id") {

                    $data = $data->where('id', 'like', '%' . $request->search . '%');
                } else if ($res == "name") {

                    $data = $data->where('name', 'like', '%' . $request->search . '%');
                } else if ($res == "jobTitle") {

                    $data = $data->where('jobTitle', 'like', '%' . $request->search . '%');
                } else if ($res == "emailAddress") {

                    $data = $data->where('emailAddress', 'like', '%' . $request->search . '%');
                } else if ($res == "phoneNumber") {

                    $data = $data->where('phoneNumber', 'like', '%' . $request->search . '%');
                } else if ($res == "isWhatsapp") {

                    $data = $data->where('isWhatsapp', 'like', '%' . $request->search . '%');
                } else if ($res == "status") {

                    $data = $data->where('status', 'like', '%' . $request->search . '%');
                } else if ($res == "location") {

                    $data = $data->where('location', 'like', '%' . $request->search . '%');
                } else if ($res == "createdBy") {

                    $data = $data->where('createdBy', 'like', '%' . $request->search . '%');
                } else if ($res == "createdAt") {

                    $data = $data->where('createdAt', 'like', '%' . $request->search . '%');
                } else {

                    $data = [];
                    return response()->json([
                        'totalPagination' => 0,
                        'data' => $data
                    ], 200);
                }
            }

            if ($request->orderValue) {

                $defaultOrderBy = $request->orderValue;
            }



            $checkOrder = null;
            if ($request->orderColumn && $defaultOrderBy) {

                $listOrder = array(
                    'id',
                    'name',
                    'jobTitle',
                    'emailAddress',
                    'phoneNumber',
                    'isWhatsapp',
                    'status',
                    'location',
                    'createdBy',
                    'createdAt',
                );

                if (!in_array($request->orderColumn, $listOrder)) {

                    return response()->json([
                        'message' => 'failed',
                        'errors' => 'Please try different order column',
                        'orderColumn' => $listOrder,
                    ]);
                }


                if (strtolower($defaultOrderBy) != "asc" && strtolower($defaultOrderBy) != "desc") {
                    return response()->json([
                        'message' => 'failed',
                        'errors' => 'order value must Ascending: ASC or Descending: DESC ',
                    ]);
                }

                $checkOrder = true;
            }

            if ($checkOrder) {

                $data = DB::table($data)
                    ->select(
                        'id',
                        'name',
                        'jobTitle',
                        'emailAddress',
                        'phoneNumber',
                        'isWhatsapp',
                        'status',
                        'location',
                        'createdBy',
                        'createdAt',
                    )
                    ->orderBy($request->orderColumn, $defaultOrderBy)
                    ->orderBy('updated_at', 'desc');
            } else {


                $data = DB::table($data)
                    ->select(
                        'id',
                        'name',
                        'jobTitle',
                        'emailAddress',
                        'phoneNumber',
                        'isWhatsapp',
                        'status',
                        'location',
                        'createdBy',
                        'createdAt',
                    )
                    ->orderBy('updated_at', 'desc');
            }


            if ($request->rowPerPage > 0) {
                $defaultRowPerPage = $request->rowPerPage;
            }

            $goToPage = $request->goToPage;

            $offset = ($goToPage - 1) * $defaultRowPerPage;

            $count_data = $data->count();
            $count_result = $count_data - $offset;

            if ($count_result < 0) {
                $data = $data->offset(0)->limit($defaultRowPerPage)->get();
            } else {
                $data = $data->offset($offset)->limit($defaultRowPerPage)->get();
            }

            $total_paging = $count_data / $defaultRowPerPage;

            return response()->json(['totalPagination' => ceil($total_paging), 'data' => $data], 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }


    private function Search($request)
    {

        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }

        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('id', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'id';
            return $temp_column;
        }

        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }

        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('name', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'name';
            return $temp_column;
        }

        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }

        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('jobTitle', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'jobTitle';
            return $temp_column;
        }

        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }

        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('emailAddress', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'emailAddress';
            return $temp_column;
        }


        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }


        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('phoneNumber', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'phoneNumber';
            return $temp_column;
        }


        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }

        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('isWhatsapp', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'isWhatsapp';
            return $temp_column;
        }

        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }

        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('status', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'status';
            return $temp_column;
        }


        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }

        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('location', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'location';
            return $temp_column;
        }

        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }


        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('createdBy', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'createdBy';
            return $temp_column;
        }

        $data = $this->getDataIndex();

        if ($request->locationId) {

            $test = $request->locationId;

            $data = $data->where(function ($query) use ($test) {
                foreach ($test as $id) {
                    $query->orWhereRaw("FIND_IN_SET(?, a.locationId)", [$id]);
                }
            });
        }


        $data = DB::table($data)
            ->select(
                'id',
                'name',
                'jobTitle',
                'emailAddress',
                'phoneNumber',
                'isWhatsapp',
                'status',
                'location',
                'createdBy',
                'createdAt'
            );

        if ($request->search) {
            $data = $data->where('createdAt', 'like', '%' . $request->search . '%');
        }

        $data = $data->get();

        if (count($data)) {
            $temp_column = 'createdAt';
            return $temp_column;
        }
    }

    public function uploadImageStaff(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required',
        ]);

        if ($validate->fails()) {

            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $checkIfValueExits = DB::table('users')
            ->where([
                ['id', '=', $request->id],
                ['isDeleted', '=', 0],
            ])
            ->first();

        if ($checkIfValueExits === null) {
            return response()->json([
                'message' => 'Failed',
                'errors' => "Data not exists, please try another user id",
            ], 406);
        }

        // $checkImages = DB::table('usersIdentifications')
        //     ->where([
        //         ['usersId', '=', $request->id],
        //         ['isDeleted', '=', 0],
        //     ])
        //     ->first();


        // if ($checkImages) {

        //     File::delete(public_path() . $checkImages->imagePath);

        //     DB::table('usersIdentifications')->where([
        //         ['usersId', '=', $request->id],
        //     ])->delete();
        // }

        $flag = false;
        $res_data = [];
        $files[] = $request->file('imageIdentifications');
        $count = 0;

        $identify = json_decode($request->typeIdentifications, true);
        //return $request->imageIdentifications;
        //return 'ts';
        if ($flag == false) {

            if ($request->hasfile('imageIdentifications')) {
                //return 'mask';
                foreach ($files as $file) {

                    foreach ($file as $fil) {

                        $name = $fil->hashName();

                        $fil->move(public_path() . '/UsersIdentificationImages/', $name);

                        $fileName = "/UsersIdentificationImages/" . $name;

                        DB::table('usersIdentifications')
                            ->insert([
                                'usersId' => $request->id,
                                'typeId' => $identify[$count]['typeId'],
                                'identification' => $identify[$count]['identificationNumber'],
                                'imagePath' => $fileName,
                                'status' => 1,
                                'reason' => '',
                                'approvedBy' => $request->user()->id,
                                'approvedAt' => now(),
                                'isDeleted' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                        array_push($res_data, $file);

                        $count += 1;
                    }
                }

                $flag = true;
            }
        } else {

            foreach ($res_data as $res) {

                DB::table('usersIdentifications')
                    ->insert([
                        'usersId' => $request->id,
                        'typeId' => $identify[$count]['typeId'],
                        'identification' => $identify[$count]['identificationNumber'],
                        'imagePath' => $res['imagePath'],
                        'status' => 1,
                        'reason' => '',
                        'approvedBy' => $request->user()->id,
                        'approvedAt' => now(),
                        'isDeleted' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        return response()->json(
            [
                'result' => 'success',
                'message' => 'Upload image users Success!',
            ],
            200
        );
    }


    public function getDetailStaff(Request $request)
    {

        try {

            $validate = Validator::make($request->all(), [
                'id' => 'required',
            ]);

            if ($validate->fails()) {

                $errors = $validate->errors()->all();

                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }

            $checkIfValueExits = DB::table('users')
                ->where([
                    ['id', '=', $request->id],
                    ['isDeleted', '=', 0],
                ])
                ->first();

            if ($checkIfValueExits === null) {

                return response()->json([
                    'message' => 'Failed',
                    'errors' => "Data not exists, please try another user id",
                ], 406);
            } else {


                $users = DB::table('users as a')
                    ->leftJoin('jobTitle as c', function ($join) {
                        $join->on('c.id', '=', 'a.jobTitleId')
                            ->where('c.isActive', '=', 1);
                    })
                    // ->leftJoin('typeId as d', function ($join) {
                    //     $join->on('d.id', '=', 'a.typeId')
                    //         ->where('d.isActive', '=', 1);
                    // })
                    ->leftJoin('payPeriod as e', function ($join) {
                        $join->on('e.id', '=', 'a.payPeriodId')
                            ->where('e.isActive', '=', 1);
                    })
                    ->select(
                        'a.id',
                        'a.firstName',
                        'a.middleName',
                        'a.lastName',
                        'a.nickName',
                        'a.gender',
                        'a.status',
                        'a.lineManagerId',
                        DB::raw("IF(c.id  IS NULL, '',c.id ) as jobTitleId"),
                        DB::raw("IF(c.jobName  IS NULL, '',c.jobName ) as jobName"),
                        'a.startDate',
                        'a.endDate',
                        'a.registrationNo',
                        'a.designation',
                        'a.annualSickAllowance',
                        'a.annualLeaveAllowance',
                        'a.payPeriodId',
                        DB::raw("IF(e.periodName IS NULL, '', e.periodName) as periodName"),
                        'a.payAmount',
                        //DB::raw("IF(d.id  IS NULL, '',d.id ) as typeId"),
                        //DB::raw("IF(d.typeName  IS NULL, '',d.typeName ) as typeName"),
                        //'a.identificationNumber',
                        'a.additionalInfo',

                        'a.generalCustomerCanSchedule',
                        'a.generalCustomerReceiveDailyEmail',
                        'a.generalAllowMemberToLogUsingEmail',
                        'a.reminderEmail',
                        'a.reminderWhatsapp',
                        'a.roleId',

                    )
                    ->where([
                        ['a.id', '=', $request->id],
                        ['a.isDeleted', '=', '0'],
                    ])
                    ->first();

                $usersIdentify = DB::table('usersIdentifications as a')
                    ->leftJoin('typeId as d', function ($join) {
                        $join->on('d.id', '=', 'a.typeId');
                    })
                    ->select(
                        'a.id as id',
                        'a.usersId as usersId',
                        'a.typeId',
                        DB::raw("IF(d.typeName  IS NULL, '',d.typeName ) as typeName"),
                        'a.identification',
                        'a.imagePath as imagePath',
                    )
                    ->where([
                        ['a.usersId', '=', $request->id],
                        ['a.isDeleted', '=', '0']
                    ])
                    ->get();

                $users->identify = $usersIdentify;

                $locationId = DB::table('usersLocation as a')
                    ->leftjoin('location as b', 'b.id', '=', 'a.locationId')
                    ->select(
                        'a.locationId as locationId',
                        'b.locationName as locationName',
                    )
                    ->where([
                        ['a.usersId', '=', $request->id],
                        ['a.isDeleted', '=', '0']
                    ])
                    ->get();

                $users->locationId = $locationId;


                $users_detail_address = DB::table('usersDetailAddresses as a')
                    ->select(
                        'a.addressName as addressName',
                        'a.additionalInfo as additionalInfo',
                        'a.provinceCode as provinceCode',
                        'a.cityCode as cityCode',
                        'a.postalCode as postalCode',
                        'a.country as country',
                        'a.isPrimary as isPrimary',
                    )
                    ->where([
                        ['a.usersId', '=', $request->id],
                        ['a.isDeleted', '=', '0']
                    ])
                    ->get();

                $users->detailAddress = $users_detail_address;


                $usersmessengers = DB::table('usersMessengers as a')
                    ->select(
                        'a.messengerNumber as messengerNumber',
                        'a.type as type',
                        'a.usage as usage',
                    )
                    ->where([
                        ['a.usersId', '=', $request->id],
                        ['a.isDeleted', '=', '0']
                    ])
                    ->get();

                $users->messenger = $usersmessengers;

                $usersEmails = DB::table('usersEmails as a')
                    ->select(
                        'a.email as email',
                        'a.usage as usage',
                    )
                    ->where([
                        ['a.usersId', '=', $request->id],
                        ['a.isDeleted', '=', '0']
                    ])
                    ->get();

                $users->email = $usersEmails;

                $userstelephone = DB::table('usersTelephones as a')
                    ->select(
                        'a.phoneNumber as phoneNumber',
                        'a.type as type',
                        'a.usage as usage',
                    )
                    ->where([
                        ['a.usersId', '=', $request->id],
                        ['a.isDeleted', '=', '0']
                    ])
                    ->get();

                $users->telephone = $userstelephone;

                return response()->json($users, 200);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }

    public function importStaff(Request $request)
    {
        if (!checkAccessModify('staff-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        DB::beginTransaction();
        try {

            $validate = Validator::make($request->all(), [
                'file' => 'required|mimes:xls,xlsx',
            ]);

            if ($validate->fails()) {
                $errors = $validate->errors()->all();

                return response()->json([
                    'errors' => 'The given data was invalid.',
                    'message' => $errors,
                ], 422);
            }

            $id = $request->user()->id;

            $rows = Excel::toArray(new ImportStaff($id), $request->file('file'));
            $src1 = $rows[0];
            $src2 = $rows[1];
            $src3 = $rows[2];
            $src4 = $rows[3];
            $src5 = $rows[4];
            $src6 = $rows[5];

            $count_row = 1;
            $total_data = 0;

            if (count($src1) > 2) {
                foreach ($src1 as $value) {

                    if ($value['nama_depan'] == null && $value['jenis_kelamin'] == null && $value['status'] == null) {
                        break;
                    }

                    if ($value['nama_depan'] == "Wajib Diisi") {
                        $count_row += 2;
                        continue;
                    }

                    if ($value['id'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Id at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['nama_depan'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Nama Depan at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['jenis_kelamin'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Jenis Kelamin at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['jenis_kelamin'] != "P" && $value['jenis_kelamin'] != "W") {

                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any invalid input on column Jenis Kelamin at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['status'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Status at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['status'] != "0" && $value['status'] != "1") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any invalid input on column Status at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['line_manager'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Line Manager at row ' . $count_row],
                        ], 422);
                    }

                    $lineManager =  DB::table('users')
                        ->where('id', '=', $value['line_manager'])->where('isDeleted', '=', 0)->first();

                    if (!$lineManager) {
                        return 'msk';
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is no any Line Manager at system at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['jabatan'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Jabatan at row ' . $count_row],
                        ], 422);
                    }

                    $title = JobTitle::where('id', '=', $value['jabatan'])->first();

                    if (!$title) {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is no any Jabatan on system at row ' . $count_row],
                        ], 422);
                    }

                    $checkSerial = $this->isExcelSerialDate($value['tanggal_mulai']);
                    $status = false;

                    if ($checkSerial) {
                        $status = true;
                    }

                    if (!$status) {

                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is invalid date format Tanggal Mulai on sheet Detail at row ' . $count_row],
                        ], 422);
                    }

                    $checkSerial = $this->isExcelSerialDate($value['tanggal_berakhir']);
                    $status = false;

                    if ($checkSerial) {
                        $status = true;
                    }

                    if (!$status) {

                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is invalid date format Tanggal Berakhir on sheet Detail at row ' . $count_row],
                        ], 422);
                    }

                    $codeLocation = explode(';', $value['lokasi']);

                    if (count($codeLocation) !== count(array_unique($codeLocation))) {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any duplicate Lokasi. Please check again at row ' . $count_row],
                        ], 422);
                    }

                    foreach ($codeLocation as $valcode) {

                        $chk = DB::table('location')
                            ->where('id', '=', $valcode)->where('isDeleted', '=', 0)->first();

                        if (!$chk) {
                            return response()->json([
                                'errors' => 'The given data was invalid.',
                                'message' => ['There is any invalid Kode Lokasi at row ' . $count_row],
                            ], 422);
                        }
                    }

                    if ($value['durasi_pembayaran'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Durasi Pembayaran at row ' . $count_row],
                        ], 422);
                    }

                    $payPeriod = PayPeriod::where('id', '=', $value['durasi_pembayaran'])->first();

                    if (!$payPeriod) {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is no any Durasi Pembayaran on system at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['kartu_identitas'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Kartu Identitas at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['nomor_kartu_identitas'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Nomor Kartu Identitas at row ' . $count_row],
                        ], 422);
                    }

                    $cardIden = explode(';', $value['kartu_identitas']);
                    $noCardIden = explode(';', $value['nomor_kartu_identitas']);

                    if (count($cardIden) !== count($noCardIden)) {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any different total data Kartu Identitas and Nomor Kartu Identitas. Please check again at row ' . $count_row],
                        ], 422);
                    }

                    foreach ($cardIden as $valcode) {

                        $chk = DB::table('typeId')
                            ->where('id', '=', $valcode)->where('isActive', '=', 1)->first();

                        if (!$chk) {
                            return response()->json([
                                'errors' => 'The given data was invalid.',
                                'message' => ['There is any invalid Kartu Identitas at row ' . $count_row],
                            ], 422);
                        }
                    }

                    $total_data += 1;
                    $count_row += 1;
                }
            }

            $count_row = 1;

            if (count($src2) > 2) {

                foreach ($src2 as $value) {

                    if ($value['id'] == "Wajib diisi berdasarkan ID di sheet Detail") {
                        $count_row += 2;
                        continue;
                    }

                    if ($value['id'] == null && $value['pelanggan_dapat_menjadwalkan_anggota_staff_ini_secara_online'] == null && $value['terima_email_harian_yang_berisi_janji_temu_terjadwal_mereka'] == null && $value['izinkan_anggota_staff_ini_untuk_masuk_menggunakan_alamat_email_mereka'] == null) {
                        break;
                    }

                    if ($value['pelanggan_dapat_menjadwalkan_anggota_staff_ini_secara_online'] != "0" && $value['pelanggan_dapat_menjadwalkan_anggota_staff_ini_secara_online'] != "1") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any invalid input on column Pelanggan Dapat Menjadwalkan Anggota Staff ini Secara Online at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['terima_email_harian_yang_berisi_janji_temu_terjadwal_mereka'] != "0" && $value['terima_email_harian_yang_berisi_janji_temu_terjadwal_mereka'] != "1") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any invalid input on column Terima Email Harian at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['izinkan_anggota_staff_ini_untuk_masuk_menggunakan_alamat_email_mereka'] != "0" && $value['izinkan_anggota_staff_ini_untuk_masuk_menggunakan_alamat_email_mereka'] != "1") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any invalid input on column Izinkan Anggota Staff ini untuk Masuk Menggunakan Alamat Email at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['pengingat_email'] != "0" && $value['pengingat_email'] != "1") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any invalid input on column Pengingat Email at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['pengingat_whatsapp'] != "0" && $value['pengingat_whatsapp'] != "1") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any invalid input on column Pengingat Whatsapp at row ' . $count_row],
                        ], 422);
                    }

                    $role = UsersRoles::where('id', '=', $value['grup_keamanan'])->first();

                    if (!$role) {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is no any Grup Keamanan on system at row ' . $count_row],
                        ], 422);
                    }

                    $count_row += 1;
                }
            }

            $count_row = 1;

            if (count($src3) > 2) {

                foreach ($src3 as $value) {

                    if ($value['id'] == "Wajib diisi berdasarkan ID di sheet Detail") {
                        $count_row += 2;
                        continue;
                    }

                    if ($value['id'] == null && $value['alamat_jalan'] == null && $value['jadikan_sebagai_alamat_utama'] == null) {
                        break;
                    }

                    if ($value['alamat_jalan'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Alamat Jalan at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['jadikan_sebagai_alamat_utama'] != "0" && $value['jadikan_sebagai_alamat_utama'] != "1") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any invalid input on column Jadikan Sebagai Alamat Utama at row ' . $count_row],
                        ], 422);
                    }

                    $count_row += 1;
                }
            }

            $count_row = 1;

            //telepon
            if (count($src4) > 2) {

                foreach ($src4 as $value) {

                    if ($value['id'] == "Wajib diisi berdasarkan ID di sheet Detail") {
                        $count_row += 2;
                        continue;
                    }

                    if ($value['id'] == null && $value['id_kegunaan'] == null && $value['nomor_telepon'] == null && $value['id_tipe'] == null) {
                        break;
                    }

                    if ($value['id'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Id at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['id_kegunaan'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Id Pemakaian at sheet Telepon at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['nomor_telepon'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Nama Pengguna at sheet Telepon at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['id_tipe'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column ID Tipe at sheet Telepon at row ' . $count_row],
                        ], 422);
                    }

                    $count_row += 1;
                }
            }

            $count_row = 1;

            //email
            if (count($src5) > 2) {

                foreach ($src5 as $value) {

                    if ($value['id'] == "Wajib diisi berdasarkan ID di sheet Detail") {
                        $count_row += 2;
                        continue;
                    }

                    if ($value['id'] == null && $value['id_kegunaan'] == null && $value['alamat_email'] == null) {
                        break;
                    }

                    if ($value['id_kegunaan'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Id Pemakaian at sheet Email at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['alamat_email'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Nama Pengguna at sheet Email at row ' . $count_row],
                        ], 422);
                    }

                    $count_row += 1;
                }
            }

            $count_row = 1;

            //messenger
            if (count($src6) > 2) {
                foreach ($src6 as $value) {

                    if ($value['id'] == "Wajib diisi berdasarkan ID di sheet Detail") {
                        $count_row += 2;
                        continue;
                    }

                    if ($value['id'] == null && $value['id_kegunaan'] == null && $value['nama_pengguna'] == null && $value['id_tipe'] == null) {
                        break;
                    }

                    if ($value['id'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Id at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['id_kegunaan'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Id Pemakaian at sheet Messenger at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['nama_pengguna'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column Nama Pengguna at sheet Messenger at row ' . $count_row],
                        ], 422);
                    }

                    if ($value['id_tipe'] == "") {
                        return response()->json([
                            'errors' => 'The given data was invalid.',
                            'message' => ['There is any empty cell on column ID Tipe at sheet Messenger at row ' . $count_row],
                        ], 422);
                    }

                    $count_row += 1;
                }
            }

            $countRealData = 0;

            for ($i = 1; $i < count($src1); $i++) {
                if ($src1[$i]['nama_depan'] == null && $src1[$i]['id'] == null) {
                    break;
                }

                $countRealData++;
                $gender = "female";
                if ($src1[$i]['jenis_kelamin'] == "P") {
                    $gender = "male";
                }

                $startDate = Date::excelToDateTimeObject($src1[$i]['tanggal_mulai']);
                $endDate = Date::excelToDateTimeObject($src1[$i]['tanggal_berakhir']);

                $startDateFormatted = $startDate->format('Y-m-d'); // Change format as needed
                $endDateFormatted = $endDate->format('Y-m-d'); // Change format as needed

                $masterEmail = collect($src5)->where('id', $src1[$i]['id'])
                    ->where('id_kegunaan', 1);

                if (!$masterEmail) {
                    return response()->json([
                        'errors' => 'The given data was invalid.',
                        'message' => ['There is no any email address with usage Utama with Id ' . $src1[$i]['id']],
                    ], 422);
                }

                foreach ($masterEmail as $value) {
                    $resEmail = $value['alamat_email'];
                }

                $userId = DB::table('users')
                    ->insertGetId([
                        'userName' => '',
                        'firstName' => trim($src1[$i]['nama_depan']),
                        'middleName' => trim($src1[$i]['nama_tengah']),
                        'lastName' => trim($src1[$i]['nama_akhir']),
                        'nickName' => trim($src1[$i]['nama_panggilan']),
                        'gender' => $gender,
                        'status' => $src1[$i]['status'],
                        'lineManagerId' => $src1[$i]['line_manager'],
                        'jobTitleId' => $src1[$i]['jabatan'],
                        'startDate' => $startDateFormatted,
                        'endDate' => $endDateFormatted,
                        'joinDate' => $startDateFormatted,
                        'registrationNo' => trim($src1[$i]['nomor_registrasi']),
                        'designation' => trim($src1[$i]['penunjukkan']),
                        'annualSickAllowance' => $src1[$i]['tunjangan_sakit_tahunan'],
                        'annualLeaveAllowance' => $src1[$i]['tunjangan_cuti_tahunan'],
                        'annualSickAllowanceRemaining' => 0,
                        'annualLeaveAllowanceRemaining' => 0,
                        'payPeriodId' => $src1[$i]['durasi_pembayaran'],
                        'payAmount' => $src1[$i]['nominal_pembayaran'],
                        'typeId' => 0, //$src1[$i]['kartu_identitas'],
                        'identificationNumber' => '', //trim($src1[$i]['nomor_kartu_identitas']),
                        'additionalInfo' => trim($src1[$i]['catatan_tambahan']),
                        'generalCustomerCanSchedule' => $src2[$i]['pelanggan_dapat_menjadwalkan_anggota_staff_ini_secara_online'],
                        'generalCustomerReceiveDailyEmail' => $src2[$i]['terima_email_harian_yang_berisi_janji_temu_terjadwal_mereka'],
                        'generalAllowMemberToLogUsingEmail' => $src2[$i]['izinkan_anggota_staff_ini_untuk_masuk_menggunakan_alamat_email_mereka'],
                        'reminderEmail' => $src2[$i]['pengingat_email'],
                        'reminderWhatsapp' => $src2[$i]['pengingat_whatsapp'],
                        'roleId' => $src2[$i]['grup_keamanan'],
                        'imageName' => '',
                        'imagePath' => '',
                        'password' => '',
                        'email' => trim($resEmail),
                        'isDeleted' => 0,
                        'createdBy' => $request->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'isLogin' => 0,
                    ]);

                staffcontract::create([
                    'staffId' => $userId,
                    'startDate' => $startDateFormatted,
                    'endDate' => $endDateFormatted,
                    'userId' => $request->user()->id,
                ]);

                $jobtitleName = JobTitle::where('id', '=', $src1[$i]['jabatan'])->first();

                //send email
                $dataSendEmail = [
                    'subject' => 'Radhiyan Pet and Care',
                    'body' => 'Please verify your account',
                    'isi' => 'This e-mail was sent from a notification-only address that cannot accept incoming e-mails. Please do not reply to this message.',
                    'name' => trim($src1[$i]['nama_depan']),
                    'email' =>  trim($resEmail),
                    'jobTitle' => $jobtitleName->jobName,
                    'usersId' => $userId,
                ];

                Mail::to(trim($resEmail))->send(new SendEmail($dataSendEmail));

                $cardIdentity = explode(';', trim($src1[$i]['kartu_identitas']));
                $noCardIdentity = explode(';', trim($src1[$i]['nomor_kartu_identitas']));

                for ($j = 0; $j < count($cardIdentity); $j++) {

                    DB::table('usersIdentifications')
                        ->insert([
                            'usersId' => $userId,
                            'typeId' => $cardIdentity[$j],
                            'identification' => $noCardIdentity[$j],
                            'imagePath' => '',
                            'status' => 1,
                            'reason' => '',
                            'approvedBy' => $request->user()->id,
                            'approvedAt' => now(),
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                $codeLocation = explode(';', trim($src1[$i]['lokasi']));

                foreach ($codeLocation as $valcode) {

                    UsersLocation::create(
                        [
                            'usersId' => $userId,
                            'locationId' => $valcode,
                            'isMainLocation' => 1,
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }

                $resultAddress = collect($src3)->where('id', $src1[$i]['id']);

                if ($resultAddress) {
                    foreach ($resultAddress as $value) {


                        UsersDetailAddresses::create(
                            [
                                'usersid' => $userId,
                                'addressName' => trim($value['alamat_jalan']),
                                'additionalInfo' => trim($value['informasi_tambahan']),
                                'provinceCode' => $value['kode_provinsi'],
                                'cityCode' => $value['kode_kota'],
                                'country' => 'Indonesia',
                                'isPrimary' => $value['jadikan_sebagai_alamat_utama'],
                                'createdBy' => $request->user()->id,
                                'created_at' => now(),
                                'updated_at' => now(),
                                'isDeleted' => 0,
                            ]
                        );
                    }
                }

                $resulEmail = collect($src5)->where('id', $src1[$i]['id']);

                if ($resulEmail) {
                    foreach ($resulEmail as $value) {

                        $staticUsage = DB::table('dataStaticStaff')
                            ->select('id', 'name')
                            ->where('isDeleted', '=', '0')
                            ->where('value', '=', 'Usage')
                            ->where('id', '=', $value['id_kegunaan'])
                            ->first();

                        UsersEmails::create([
                            'usersId' => $userId,
                            'email' => trim($value['alamat_email']),
                            'email_verified_at' => now(),
                            'usage' => $staticUsage->name,
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $resultTelp = collect($src4)->where('id', $src1[$i]['id']);

                if ($resultTelp) {
                    foreach ($resultTelp as $value) {

                        $staticTelp = DB::table('dataStaticStaff')
                            ->select('id', 'name')
                            ->where('isDeleted', '=', '0')
                            ->where('value', '=', 'Telephone')
                            ->where('id', '=', $value['id_tipe'])
                            ->first();

                        $staticUsage = DB::table('dataStaticStaff')
                            ->select('id', 'name')
                            ->where('isDeleted', '=', '0')
                            ->where('value', '=', 'Usage')
                            ->where('id', '=', $value['id_kegunaan'])
                            ->first();

                        UsersTelephones::create([

                            'usersId' => $userId,
                            'phoneNumber' => $value['nomor_telepon'],
                            'type' => $staticTelp->name,
                            'usage' => $staticUsage->name,
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $resultMess = collect($src6)->where('id', $src1[$i]['id']);

                if ($resultMess) {
                    foreach ($resultMess as $value) {

                        $staticMes = DB::table('dataStaticStaff')
                            ->select('id', 'name')
                            ->where('isDeleted', '=', '0')
                            ->where('value', '=', 'Messenger')
                            ->where('id', '=', $value['id_tipe'])
                            ->first();

                        $staticUsage = DB::table('dataStaticStaff')
                            ->select('id', 'name')
                            ->where('isDeleted', '=', '0')
                            ->where('value', '=', 'Usage')
                            ->where('id', '=', $value['id_kegunaan'])
                            ->first();

                        UsersMessengers::create([

                            'usersId' => $userId,
                            'messengerNumber' => trim($value['nama_pengguna']),
                            'type' => $staticMes->name,
                            'usage' => $staticUsage->name,
                            'isDeleted' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            DB::commit();

            return responseSuccess($countRealData, 'Insert Data Successful!');
        } catch (Exception $e) {
            DB::rollback();

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ]);
        }
    }

    public function exportStaff(Request $request)
    {
        if (!checkAccessIndex('staff-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        try {

            $tmp = "";
            $fileName = "";
            $date = Carbon::now()->format('d-m-Y');

            if ($request->locationId) {

                $location = DB::table('location')
                    ->select('locationName')
                    ->whereIn('id', $request->locationId)
                    ->get();

                if ($location) {

                    foreach ($location as $key) {
                        $tmp = $tmp . (string) $key->locationName . ",";
                    }
                }
                $tmp = rtrim($tmp, ", ");
            }

            if ($tmp == "") {
                $fileName = "Staff " . $date . ".xlsx";
            } else {
                $fileName = "Staff " . $tmp . " " . $date . ".xlsx";
            }

            return Excel::download(
                new exportStaff(
                    $request->orderValue,
                    $request->orderColumn,
                    $request->locationId,
                ),
                $fileName
            );
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ]);
        }
    }

    private function isExcelSerialDate($value)
    {
        // Ensure the value is numeric and not a decimal (for dates without time)
        if (is_numeric($value)) {
            // Check if it's an integer and within the valid Excel serial date range
            if ($value >= 1 && $value <= 2958465 && $value == floor($value)) {
                return true; // It's a valid Excel serial date
            }
        }
        return false; // Not a valid Excel serial date
    }

    public function template(Request $request)
    {
        if (!checkAccessIndex('staff-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        $spreadsheet = IOFactory::load(public_path() . '/template/' . 'Template_Input_Staff.xlsx');

        $row = 2;
        $sheet = $spreadsheet->getSheet(6);

        $lineManagers = DB::table('users as u')
            ->join('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select(
                'u.id',
                'u.firstName as name',
            )
            ->whereIn('u.roleId', [1, 2])
            ->where('u.isDeleted', '=', 0)
            ->get();


        foreach ($lineManagers as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->name);
            // Add more columns as needed
            $row++;
        }

        $row = 2;
        $sheet = $spreadsheet->getSheet(7);

        $jobTitles = DB::table('jobTitle')
            ->select('id', 'jobName')
            ->where('isActive', '=', 1)
            ->get();


        foreach ($jobTitles as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->jobName);
            // Add more columns as needed
            $row++;
        }

        $row = 2;
        $sheet = $spreadsheet->getSheet(8);

        $locations = DB::table('location')
            ->select('id', 'locationName')
            ->where('isDeleted', '=', 0)
            ->get();

        foreach ($locations as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->locationName);
            // Add more columns as needed
            $row++;
        }

        $row = 2;
        $sheet = $spreadsheet->getSheet(9);

        $payPeriods = DB::table('payPeriod')
            ->select('id', 'periodName')
            ->where('isActive', '=', 1)
            ->get();

        foreach ($payPeriods as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->periodName);
            // Add more columns as needed
            $row++;
        }

        $row = 2;
        $sheet = $spreadsheet->getSheet(10);

        $typeIds = DB::table('typeId')
            ->select('id', 'typeName')
            ->where('isActive', '=', 1)
            ->get();

        foreach ($typeIds as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->typeName);
            // Add more columns as needed
            $row++;
        }

        $row = 2;
        $sheet = $spreadsheet->getSheet(11);

        $role = DB::table('usersRoles')
            ->select('id', 'roleName')
            ->get();

        foreach ($role as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->roleName);
            // Add more columns as needed
            $row++;
        }

        $row = 2;
        $sheet = $spreadsheet->getSheet(12);

        $provinsi = DB::table('provinsi')
            ->select('id', 'namaProvinsi')
            ->get();

        foreach ($provinsi as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->namaProvinsi);
            // Add more columns as needed
            $row++;
        }

        $row = 2;
        $sheet = $spreadsheet->getSheet(13);

        $kabupaten = DB::table('kabupaten')
            ->select('kodeKabupaten', 'kodeProvinsi', 'namaKabupaten')
            ->get();

        foreach ($kabupaten as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->kodeKabupaten);
            $sheet->setCellValue("B{$row}", $item->kodeProvinsi);
            $sheet->setCellValue("C{$row}", $item->namaKabupaten);
            // Add more columns as needed
            $row++;
        }

        //usage
        $row = 2;
        $sheet = $spreadsheet->getSheet(14);

        $staticUsage = DB::table('dataStaticStaff')
            ->select('id', 'name')
            ->where('isDeleted', '=', '0')
            ->where('value', '=', 'Usage')
            ->get();

        foreach ($staticUsage as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->name);
            // Add more columns as needed
            $row++;
        }

        //tipe telepon

        $row = 2;
        $sheet = $spreadsheet->getSheet(15);

        $staticTelp = DB::table('dataStaticStaff')
            ->select('id', 'name')
            ->where('isDeleted', '=', '0')
            ->where('value', '=', 'Telephone')
            ->get();

        foreach ($staticTelp as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->name);
            // Add more columns as needed
            $row++;
        }

        //tipe messenger
        $row = 2;
        $sheet = $spreadsheet->getSheet(16);

        $staticMes = DB::table('dataStaticStaff')
            ->select('id', 'name')
            ->where('isDeleted', '=', '0')
            ->where('value', '=', 'Messenger')
            ->get();

        foreach ($staticMes as $item) {
            // Adjust according to your data structure
            $sheet->setCellValue("A{$row}", $item->id);
            $sheet->setCellValue("B{$row}", $item->name);
            // Add more columns as needed
            $row++;
        }

        // Save the changes to a new file or overwrite the existing one
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $newFilePath = public_path() . '/template_download/' . 'Template Upload Staff.xlsx'; // Set the desired path
        $writer->save($newFilePath);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template Upload Staff.xlsx"',
        ]);
    }


    public function updateStaff(Request $request)
    {
        if (!checkAccessModify('staff-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        DB::beginTransaction();

        try {

            $validate = Validator::make(
                $request->all(),
                [
                    'id' => 'required',
                    'firstName' => 'required|max:20|min:3',
                    'middleName' => 'max:20|min:3|nullable',
                    'lastName' => 'max:20|min:3|nullable',
                    'nickName' => 'max:20|min:3|nullable',
                    'gender' => 'string|nullable',
                    'status' => 'required|integer',
                    'lineManagerId' => 'required|integer',
                    'jobTitleId' => 'required|integer',
                    'startDate' => 'required|date',
                    'endDate' => 'required|date|after:startDate',
                    'registrationNo' => 'string|max:20|min:5|nullable',
                    'designation' => 'string|max:20|min:5|nullable',
                    'locationId' => 'required',
                    'annualSickAllowance' => 'integer|nullable',
                    'annualLeaveAllowance' => 'integer|nullable',
                    'payPeriodId' => 'required|integer',
                    'payAmount' => 'numeric|nullable',
                    //'typeId' => 'required|integer',
                    //'identificationNumber' => 'string|nullable|max:30',
                    'additionalInfo' => 'string|nullable|max:100',
                    'generalCustomerCanSchedule' => 'integer|nullable',
                    'generalCustomerReceiveDailyEmail' => 'integer|nullable',
                    'generalAllowMemberToLogUsingEmail' => 'integer|nullable',
                    'roleId' => 'integer|nullable',
                    'reminderEmail' => 'integer|nullable',
                    'reminderWhatsapp' => 'integer|nullable',
                ]
            );

            if ($validate->fails()) {
                $errors = $validate->errors()->all();
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $errors,
                ], 422);
            }

            $checkIfUsersExists = DB::table('users')
                ->where([
                    ['id', '=', $request->id],
                    ['isDeleted', '=', '0']
                ])
                ->first();

            if (!$checkIfUsersExists) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Spesific users not exists please try different id!'],
                ], 422);
            }


            // $getTypeIDName = TypeId::where([
            //     ['id', '=', $request->typeId],
            //     ['isActive', '=', '1']
            // ])->first();

            // if (str_contains(strtolower($getTypeIDName->typeName), 'paspor') || str_contains(strtolower($getTypeIDName->typeName), 'passpor')) {

            //     if ((is_numeric($request->identificationNumber))) {
            //         return responseInvalid(["Identification number must be alpanumeric if identification type is passport!"]);
            //     }
            // } else {
            //     if (!is_numeric($request->identificationNumber) && is_int((int)$request->identificationNumber)) {
            //         return responseInvalid(["Identification number must be integer!"]);
            //     }
            // }


            $data_error_detailaddress = [];

            if ($request->detailAddress) {

                $messageAddress = [
                    'addressName.required' => 'Address name on tab Address is required',
                    'provinceCode.required' => 'Province code on tab Address is required',
                    'cityCode.required' => 'City code on tab Address is required',
                    'country.required' => 'Country on tab Address is required',
                ];


                $primaryCount = 0;
                foreach ($request->detailAddress as $item) {
                    if (isset($item['isPrimary']) && $item['isPrimary'] == 1) {
                        $primaryCount++;
                    }
                }

                if ($primaryCount == 0) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Detail address must have at least 1 primary address',
                    ], 422);
                } elseif ($primaryCount > 1) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Detail address have 2 primary address, please check again',
                    ], 422);
                }


                foreach ($request->detailAddress as $key) {

                    $validateDetail = Validator::make(
                        $key,
                        [
                            'addressName' => 'required',
                            'provinceCode' => 'required',
                            'cityCode' => 'required',
                            'country' => 'required',
                        ],
                        $messageAddress
                    );

                    if ($validateDetail->fails()) {

                        $errors = $validateDetail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_error_detailaddress))) {
                                array_push($data_error_detailaddress, $checkisu);
                            }
                        }
                    }
                }

                if ($data_error_detailaddress) {
                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => $data_error_detailaddress,
                    ], 422);
                }
            } else {

                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Detail address can not be empty!'],
                ], 422);
            }

            $checkusageUtamaPhone = 0;
            $data_error_telephone = [];

            if ($request->telephone) {

                $messagePhone = [
                    'phoneNumber.required' => 'Phone Number on tab telephone is required',
                    'type.required' => 'Type on tab telephone is required',
                    'usage.required' => 'Usage on tab telephone is required',
                ];

                foreach ($request->telephone as $key) {

                    $telephoneDetail = Validator::make(
                        $key,
                        [
                            'phoneNumber' => 'required',
                            'type' => 'required',
                            'usage' => 'required',
                        ],
                        $messagePhone
                    );

                    if (strtolower($key['usage']) == "utama" || strtolower($key['usage']) == "primary") {
                        $checkusageUtamaPhone = $checkusageUtamaPhone + 1;
                    }


                    if ($checkusageUtamaPhone > 1) {
                        return responseInvalid(['Usage utama on phone must only one!']);
                    }

                    if ($telephoneDetail->fails()) {

                        $errors = $telephoneDetail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_error_telephone))) {
                                array_push($data_error_telephone, $checkisu);
                            }
                        }
                    }


                    if (strtolower($key['type']) == "whatshapp") {

                        if (!(substr($key['phoneNumber'], 0, 2) === "62")) {

                            return response()->json([
                                'message' => 'Failed',
                                'errors' => 'Please check your phone number, for type whatshapp must start with 62',
                            ], 422);
                        }
                    }
                }



                if ($data_error_telephone) {
                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => $data_error_telephone,
                    ], 422);
                }

                $checkTelephone = [];

                foreach ($request->telephone as $val) {

                    $checkIfTelephoneAlreadyExists = DB::table('usersTelephones')
                        ->where([
                            ['phoneNumber', '=', $val['phoneNumber'],],
                            ['isDeleted', '=', '0'],
                            ['usersId', '!=', $request->id]
                        ])
                        ->first();

                    if ($checkIfTelephoneAlreadyExists) {
                        array_push($checkTelephone, 'Phonenumber : ' . $val['phoneNumber'] . ' already exists, please try different number');
                    }
                }

                if ($checkTelephone) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $checkTelephone,
                    ], 422);
                }
            }

            $checkusageUtamaEmail = 0;
            $data_error_email = [];
            $insertEmailUsers = '';

            if ($request->email) {

                $messageEmail = [
                    'email.required' => 'Email on tab email is required',
                    'usage.required' => 'Usage on tab email is required',
                ];

                foreach ($request->email as $key) {

                    $emailDetail = Validator::make(
                        $key,
                        [
                            'email' => 'required',
                            'usage' => 'required',
                        ],
                        $messageEmail
                    );


                    if (strtolower($key['usage']) == "utama" || strtolower($key['usage']) == "primary") {
                        $checkusageUtamaEmail = $checkusageUtamaEmail + 1;
                    }


                    if ($checkusageUtamaEmail > 1) {
                        return responseInvalid(['Usage utama on email must only one!']);
                    }


                    if ($emailDetail->fails()) {

                        $errors = $emailDetail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_error_email))) {
                                array_push($data_error_email, $checkisu);
                            }
                        }
                    }
                }


                if ($data_error_email) {
                    return response()->json([
                        'message' => 'The given data was invalid.',
                        'errors' => $data_error_email,
                    ], 422);
                }


                $checkEmail = [];
                $checkUsageEmail = false;

                foreach ($request->email as $val) {

                    $checkIfEmailExists = DB::table('usersEmails')
                        ->where([
                            ['email', '=', $val['email'],],
                            ['isDeleted', '=', '0'],
                            ['usersId', '!=', $request->id]
                        ])
                        ->first();

                    if ($checkIfEmailExists) {
                        array_push($checkEmail, 'Email : ' . $val['email'] . ' already exists, please try different email address');
                    }


                    if ($val['usage'] == 'Utama') {
                        $checkUsageEmail = true;

                        $checkEmailUtama = DB::table('usersEmails')
                            ->where([
                                ['usage', '=', 'Utama'],
                                ['isDeleted', '=', '0'],
                                ['usersId', '=', $request->id]
                            ])
                            ->first();

                        if ($checkEmailUtama->email != $val['email']) {

                            $insertEmailUsers = $val['email'];
                        }
                    }
                }

                if ($checkEmail) {
                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => $checkEmail,
                    ], 422);
                }

                if ($checkUsageEmail == false) {

                    return response()->json([
                        'message' => 'Inputed data is not valid',
                        'errors' => 'Must have one primary email',
                    ], 422);
                }
            }

            $checkusageUtamaMessenger = 0;
            $data_messenger_error = [];
            if ($request->messenger) {

                $messageMessenger = [
                    'messengerNumber.required' => 'messenger number on tab messenger is required',
                    'type.required' => 'Type on tab messenger is required',
                    'usage.required' => 'Usage on tab messenger is required',
                ];

                foreach ($request->messenger as $key) {

                    $messengerDetail = Validator::make(
                        $key,
                        [
                            'messengerNumber' => 'required',
                            'type' => 'required',
                            'usage' => 'required',
                        ],
                        $messageMessenger
                    );



                    if (strtolower($key['usage']) == "utama" || strtolower($key['usage']) == "primary") {
                        $checkusageUtamaMessenger = $checkusageUtamaMessenger + 1;
                    }

                    if ($checkusageUtamaMessenger > 1) {
                        return responseInvalid(['Usage utama on messenger must only one!']);
                    }

                    if ($messengerDetail->fails()) {

                        $errors = $messengerDetail->errors()->all();

                        foreach ($errors as $checkisu) {

                            if (!(in_array($checkisu, $data_messenger_error))) {
                                array_push($data_messenger_error, $checkisu);
                            }
                        }
                    }

                    if (strtolower($key['type']) == "whatshapp") {

                        if (!(substr($key['messengerNumber'], 0, 2) === "62")) {
                            return response()->json([
                                'message' => 'Failed',
                                'errors' => 'Please check your phone number, for type whatshapp must start with 62',
                            ], 422);
                        }
                    }
                }

                if ($data_messenger_error) {

                    return response()->json([
                        'message' => 'Failed',
                        'errors' => $data_messenger_error,
                    ], 422);
                }

                $checkMessenger = [];

                foreach ($request->messenger as $val) {

                    $checkifMessengerExists = DB::table('usersMessengers')
                        ->where([
                            ['messengerNumber', '=', $val['messengerNumber'],],
                            ['isDeleted', '=', '0'],
                            ['usersId', '!=', $request->id]
                        ])
                        ->first();

                    if ($checkifMessengerExists) {
                        array_push($checkMessenger, 'Messenger number  : ' . $val['messengerNumber'] . ' already exists, please try different number');
                    }
                }

                if ($checkMessenger) {

                    return response()->json([
                        'message' => 'Failed',
                        'errors' => $checkMessenger,
                    ], 422);
                }
            }

            $start = Carbon::parse($request->startDate);
            $end = Carbon::parse($request->endDate);

            if ($insertEmailUsers) {

                $user = DB::table('users')
                    ->where([
                        ['id', '=', $request->id]
                    ])
                    ->first();

                if ($user->startDate != $start) {
                    staffcontract::create([
                        'staffId' => $request->id,
                        'startDate' => $start,
                        'endDate' => $end,
                        'userId' => $request->user()->id,
                    ]);
                }

                DB::table('users')
                    ->where('id', '=', $request->id)
                    ->update([
                        'firstName' => $request->firstName,
                        'middleName' => $request->middleName,
                        'lastName' => $request->lastName,
                        'nickName' => $request->nickName,
                        'gender' => $request->gender,
                        'status' => $request->status,
                        'lineManagerId' => $request->lineManagerId,
                        'jobTitleId' => $request->jobTitleId,
                        'startDate' => $start,
                        'endDate' => $end,
                        'registrationNo' => $request->registrationNo,
                        'designation' => $request->designation,
                        'annualSickAllowance' => $request->annualSickAllowance,
                        'annualSickAllowanceRemaining' => $request->annualSickAllowance,
                        'annualLeaveAllowance' => $request->annualLeaveAllowance,
                        'annualLeaveAllowanceRemaining' => $request->annualLeaveAllowance,
                        'payPeriodId' => $request->payPeriodId,
                        'payAmount' => $request->payAmount,
                        'typeId' => 0,
                        'identificationNumber' => '',
                        'additionalInfo' => $request->additionalInfo,
                        'generalCustomerCanSchedule' => $request->generalCustomerCanSchedule,
                        'generalCustomerReceiveDailyEmail' => $request->generalCustomerReceiveDailyEmail,
                        'generalAllowMemberToLogUsingEmail' => $request->generalAllowMemberToLogUsingEmail,
                        'reminderEmail' => $request->reminderEmail,
                        'reminderWhatsapp' => $request->reminderWhatsapp,
                        'roleId' => $request->roleId,
                        'createdBy' => $request->user()->firstName,
                        'updated_at' => now(),
                        'password' => null,
                        'email' => $insertEmailUsers,
                    ]);

                recentActivity(
                    $request->user()->id,
                    'Staff',
                    'Add Staff',
                    'Update staff'
                );



                if ($request->locationId) {

                    DB::table('usersLocation')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->locationId as $val) {

                        DB::table('usersLocation')
                            ->insert([
                                'usersId' => $request->id,
                                'locationId' => $val,
                                'isMainLocation' => 1,
                                'isDeleted' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }
                }


                if ($request->detailAddress) {

                    DB::table('usersDetailAddresses')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->detailAddress as $val) {

                        DB::table('usersDetailAddresses')
                            ->insert([
                                'usersId' => $request->id,
                                'addressName' => $val['addressName'],
                                'additionalInfo' => $val['additionalInfo'],
                                'provinceCode' => $val['provinceCode'],
                                'cityCode' => $val['cityCode'],
                                'postalCode' => $val['postalCode'],
                                'country' => $val['country'],
                                'isPrimary' => $val['isPrimary'],
                                'isDeleted' => 0,
                                'updated_at' => now(),
                            ]);
                    }
                }

                if ($request->messenger) {

                    DB::table('usersMessengers')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->messenger as $val) {
                        DB::table('usersMessengers')
                            ->insert([
                                'usersId' => $request->id,
                                'messengerNumber' => $val['messengerNumber'],
                                'type' => $val['type'],
                                'usage' => $val['usage'],
                                'isDeleted' => 0,
                                'updated_at' => now(),
                            ]);
                    }
                }

                if ($request->email) {

                    DB::table('usersEmails')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->email as $val) {
                        DB::table('usersEmails')
                            ->insert([
                                'usersId' => $request->id,
                                'email' => $val['email'],
                                'usage' => $val['usage'],
                                'isDeleted' => 0,
                                'updated_at' => now(),
                            ]);
                    }
                }



                if ($request->telephone) {

                    DB::table('usersTelephones')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->telephone as $val) {
                        DB::table('usersTelephones')
                            ->insert([
                                'usersId' => $request->id,
                                'phoneNumber' => $val['phoneNumber'],
                                'type' => $val['type'],
                                'usage' => $val['usage'],
                                'isDeleted' => 0,
                                'updated_at' => now(),
                            ]);
                    }
                }

                if ($request->status == 0) {

                    DB::commit();
                    return response()->json([
                        'result' => 'success',
                        'message' => 'successfuly update user ',
                    ]);
                } else {


                    $jobtitleName = DB::table('jobTitle')
                        ->select('jobName')
                        ->where([
                            ['id', '=', $request->jobTitleId],
                            ['isActive', '=', 1]
                        ])
                        ->first();


                    $data = [
                        'subject' => 'RPC Petshop',
                        'body' => 'Please verify your account',
                        'isi' => 'This e-mail was sent from a notification-only address that cannot accept incoming e-mails. Please do not reply to this message.',
                        'name' => $request->firstName,
                        'email' => $insertEmailUsers,
                        'jobTitle' => $jobtitleName->jobName,
                        'usersId' => $request->id,
                    ];

                    Mail::to($insertEmailUsers)->send(new SendEmail($data));

                    DB::commit();

                    return response()->json([
                        'result' => 'success',
                        'message' => 'successfuly update user, your primary email has updated, please check your new email to verify your password',
                    ]);
                }
            } else {

                $user = DB::table('users')
                    ->where([
                        ['id', '=', $request->id]
                    ])
                    ->first();

                if ($user->startDate != $start) {
                    staffcontract::create([
                        'staffId' => $request->id,
                        'startDate' => $start,
                        'endDate' => $end,
                        'userId' => $request->user()->id,
                    ]);
                }


                DB::table('users')
                    ->where('id', '=', $request->id)
                    ->update([
                        'firstName' => $request->firstName,
                        'middleName' => $request->middleName,
                        'lastName' => $request->lastName,
                        'nickName' => $request->nickName,
                        'gender' => $request->gender,
                        'status' => $request->status,
                        'lineManagerId' => $request->lineManagerId,
                        'jobTitleId' => $request->jobTitleId,
                        'startDate' => $start,
                        'endDate' => $end,
                        'registrationNo' => $request->registrationNo,
                        'designation' => $request->designation,
                        'annualSickAllowance' => $request->annualSickAllowance,
                        'annualSickAllowanceRemaining' => $request->annualSickAllowance,
                        'annualLeaveAllowance' => $request->annualLeaveAllowance,
                        'annualLeaveAllowanceRemaining' => $request->annualLeaveAllowance,
                        'payPeriodId' => $request->payPeriodId,
                        'payAmount' => $request->payAmount,
                        'typeId' => 0,
                        'identificationNumber' => '',
                        'additionalInfo' => $request->additionalInfo,
                        'generalCustomerCanSchedule' => $request->generalCustomerCanSchedule,
                        'generalCustomerReceiveDailyEmail' => $request->generalCustomerReceiveDailyEmail,
                        'generalAllowMemberToLogUsingEmail' => $request->generalAllowMemberToLogUsingEmail,
                        'reminderEmail' => $request->reminderEmail,
                        'reminderWhatsapp' => $request->reminderWhatsapp,
                        'roleId' => $request->roleId,
                        'createdBy' => $request->user()->firstName,
                        'updated_at' => now(),

                    ]);


                if ($request->locationId) {

                    DB::table('usersLocation')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->locationId as $val) {

                        DB::table('usersLocation')
                            ->insert([
                                'usersId' => $request->id,
                                'locationId' => $val,
                                'isMainLocation' => 1,
                                'isDeleted' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }
                }



                if ($request->detailAddress) {

                    DB::table('usersDetailAddresses')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->detailAddress as $val) {

                        DB::table('usersDetailAddresses')
                            ->insert([
                                'usersId' => $request->id,
                                'addressName' => $val['addressName'],
                                'additionalInfo' => $val['additionalInfo'],
                                'provinceCode' => $val['provinceCode'],
                                'cityCode' => $val['cityCode'],
                                'postalCode' => $val['postalCode'],
                                'country' => $val['country'],
                                'isPrimary' => $val['isPrimary'],
                                'isDeleted' => 0,
                                'updated_at' => now(),
                            ]);
                    }
                }


                if ($request->messenger) {

                    DB::table('usersMessengers')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->messenger as $val) {
                        DB::table('usersMessengers')
                            ->insert([
                                'usersId' => $request->id,
                                'messengerNumber' => $val['messengerNumber'],
                                'type' => $val['type'],
                                'usage' => $val['usage'],
                                'isDeleted' => 0,
                                'updated_at' => now(),
                            ]);
                    }
                }

                if ($request->email) {

                    DB::table('usersEmails')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->email as $val) {
                        DB::table('usersEmails')
                            ->insert([
                                'usersId' => $request->id,
                                'email' => $val['email'],
                                'usage' => $val['usage'],
                                'email_verified_at' => now(),
                                'isDeleted' => 0,
                                'updated_at' => now(),
                            ]);
                    }
                }

                if ($request->telephone) {

                    DB::table('usersTelephones')->where('usersId', '=', $request->id)->delete();

                    foreach ($request->telephone as $val) {
                        DB::table('usersTelephones')
                            ->insert([
                                'usersId' => $request->id,
                                'phoneNumber' => $val['phoneNumber'],
                                'type' => $val['type'],
                                'usage' => $val['usage'],
                                'isDeleted' => 0,
                                'updated_at' => now(),
                            ]);
                    }
                }

                DB::commit();

                return response()->json([
                    'result' => 'success',
                    'message' => 'successfuly update user',
                ]);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function getTypeId(Request $request)
    {

        try {

            $getTypeId = DB::table('typeId as a')
                ->select(
                    'a.id as typeId',
                    'a.typeName as typeName',
                )
                ->where([
                    ['isActive', '=', 1],
                ])
                ->orderBy('a.created_at', 'desc')
                ->get();

            return response()->json($getTypeId, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function getPayPeriod(Request $request)
    {

        try {

            $getPayPeriod = DB::table('payPeriod as a')
                ->select(
                    'a.id as payPeriodId',
                    'a.periodName as periodName',
                )
                ->where([
                    ['isActive', '=', 1],
                ])
                ->orderBy('a.created_at', 'desc')
                ->get();

            return response()->json($getPayPeriod, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }

    public function getJobTitle(Request $request)
    {

        try {

            $getjobTitle = DB::table('jobTitle as a')
                ->select(
                    'a.id as jobTitleid',
                    'a.jobName as jobName',
                )
                ->where([
                    ['isActive', '=', 1],
                ])
                ->orderBy('a.created_at', 'desc')
                ->get();

            return response()->json($getjobTitle, 200);
        } catch (Exception $e) {

            return response()->json([
                'message' => 'Failed',
                'errors' => $e,
            ], 422);
        }
    }


    public function insertTypeId(Request $request)
    {

        $request->validate([
            'typeName' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $checkIfValueExits = DB::table('typeId as a')
                ->where([
                    ['a.typeName', '=', $request->typeName],
                    ['a.isActive', '=', 1]
                ])
                ->first();

            if ($checkIfValueExits != null) {

                return response()->json([
                    'message' => 'Failed',
                    'errors' => 'Type name already exists, please choose another name',
                ]);
            } else {

                DB::table('typeId')->insert([
                    'typeName' => $request->typeName,
                    'created_at' => now(),
                    'isActive' => 1,
                ]);

                DB::commit();

                return response()->json([
                    'result' => 'success',
                    'message' => 'Successfully inserted Type ID',
                ]);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }



    public function insertJobTitle(Request $request)
    {

        $request->validate([
            'jobName' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $checkIfValueExits = DB::table('jobTitle as a')
                ->where([
                    ['a.jobName', '=', $request->jobName],
                    ['a.isActive', '=', 1]
                ])
                ->first();

            if ($checkIfValueExits != null) {

                return response()->json([
                    'message' => 'Failed',
                    'errors' => 'Job title already exists, please choose another name',
                ]);
            } else {

                DB::table('jobTitle')->insert([
                    'jobName' => $request->jobName,
                    'created_at' => now(),
                    'isActive' => 1,
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'success',
                    'errors' => 'Successfully inserted Job Title',
                ]);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }



    public function insertPayPeriod(Request $request)
    {

        $request->validate([
            'periodName' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $checkIfValueExits = DB::table('payPeriod as a')
                ->where([
                    ['a.periodName', '=', $request->periodName],
                    ['a.isActive', '=', 1]
                ])
                ->first();

            if ($checkIfValueExits != null) {

                return response()->json([
                    'message' => 'Failed',
                    'errors' => 'Pay period already exists, please choose another name',
                ]);
            } else {

                DB::table('payPeriod')->insert([
                    'periodName' => $request->periodName,
                    'created_at' => now(),
                    'isActive' => 1,
                ]);

                DB::commit();

                return response()->json([
                    'result' => 'success',
                    'message' => 'Successfully inserted pay period',
                ]);
            }
        } catch (Exception $e) {

            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e,
            ], 422);
        }
    }

    // public function deleteStaff(Request $request)
    // {
    //     if (!checkAccessDelete('staff-list', $request->user()->roleId)) {
    //         return responseUnauthorize();
    //     }

    //     $validate = Validator::make($request->all(), [
    //         'id' => 'required',
    //     ]);

    //     if ($validate->fails()) {
    //         $errors = $validate->errors()->all();

    //         return response()->json([
    //             'message' => 'The given data was invalid.',
    //             'errors' => $errors,
    //         ], 422);
    //     }

    //     DB::beginTransaction();
    //     try {


    //         foreach ($request->id as $val) {

    //             $checkIfDataExits = DB::table('users')
    //                 ->where([
    //                     ['id', '=', $val],
    //                     ['isDeleted', '=', 0],
    //                 ])
    //                 ->first();

    //             if (!$checkIfDataExits) {
    //                 return response()->json([
    //                     'message' => 'The given data was invalid.',
    //                     'errors' => ['Data not found! try different ID'],
    //                 ], 422);
    //             }
    //         }

    //         foreach ($request->id as $val) {

    //             DB::table('users')
    //                 ->where('id', '=', $val)
    //                 ->update(['isDeleted' => 1]);

    //             DB::table('usersDetailAddresses')
    //                 ->where('usersId', '=', $val)
    //                 ->update(['isDeleted' => 1]);

    //             DB::table('usersEmails')
    //                 ->where('usersId', '=', $val)
    //                 ->update(['isDeleted' => 1]);

    //             DB::table('usersMessengers')
    //                 ->where('usersId', '=', $val)
    //                 ->update(['isDeleted' => 1]);

    //             DB::table('usersIdentifications')
    //                 ->where('usersId', '=', $val)
    //                 ->update(['isDeleted' => 1]);

    //             DB::table('usersTelephones')
    //                 ->where('usersId', '=', $val)
    //                 ->update(['isDeleted' => 1]);


    //             $checkImages = DB::table('usersIdentifications')
    //                 ->where([
    //                     ['usersId', '=', $val]
    //                 ])
    //                 ->first();

    //             if ($checkImages != null) {

    //                 File::delete(public_path() . $checkImages->imagePath);
    //             }

    //             DB::commit();

    //         }

    //         return response()->json([
    //             'result' => 'success',
    //             'message' => 'Successfully deleted user',
    //         ], 200);
    //     } catch (Exception $e) {

    //         DB::rollback();

    //         return response()->json([
    //             'message' => 'failed',
    //             'errors' => $e,
    //         ], 422);
    //     }
    // }

    public function deleteStaff(Request $request)
    {
        if (!checkAccessDelete('staff-list', $request->user()->roleId)) {
            return responseUnauthorize();
        }

        $validate = Validator::make($request->all(), [
            'id' => 'required|array',
            'id.*' => 'required|integer|distinct'
        ]);

        if ($validate->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validate->errors()->all(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $ids = $request->id;

            $staffs = DB::table('users')
                ->whereIn('id', $ids)
                ->where('isDeleted', 0)
                ->get();

            $existingIds = $staffs->pluck('id')->toArray();
            $notFoundIds = array_diff($ids, $existingIds);

            if (count($notFoundIds) > 0) {
                return response()->json([
                    'message' => 'Inputed data is not valid',
                    'errors' => ['User ID not found: ' . implode(', ', $notFoundIds)],
                ], 422);
            }

            foreach ($staffs as $staff) {
                $userId = $staff->id;

                $tables = [
                    'users' => 'id',
                    'usersDetailAddresses' => 'usersId',
                    'usersEmails' => 'usersId',
                    'usersMessengers' => 'usersId',
                    'usersIdentifications' => 'usersId',
                    'usersTelephones' => 'usersId',
                ];

                foreach ($tables as $table => $column) {
                    DB::table($table)
                        ->where($column, $userId)
                        ->update(['isDeleted' => 1]);
                }

                $image = DB::table('usersIdentifications')
                    ->where('usersId', $userId)
                    ->first();

                if ($image && $image->imagePath) {
                    File::delete(public_path($image->imagePath));
                }

                recentActivity(
                    $request->user()->id,
                    'Staff',
                    'Delete Staff',
                    'Delete staff ' . $staff->firstName . ' with ID ' . $staff->id
                );
            }

            DB::commit();

            return response()->json([
                'result' => 'success',
                'message' => 'Successfully deleted user',
            ], 200);
        } catch (Exception $e) {
            DB::rollback();

            return response()->json([
                'message' => 'failed',
                'errors' => $e->getMessage(),
            ], 422);
        }
    }


    public function staffListTransferProduct(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'locationId' => 'required|integer',
        ]);

        if ($validate->fails()) {
            $errors = $validate->errors()->all();

            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $errors,
            ], 422);
        }

        $data = DB::table('users as u')
            ->join('usersLocation as ul', 'ul.usersId', 'u.id')
            ->join('location as l', 'ul.locationId', 'l.id')
            ->select('u.id', 'u.firstName as name')
            ->where('u.isDeleted', '=', 0)
            ->where('ul.locationId', '=', $request->locationId)
            ->where('u.id', '<>', $request->user()->id)
            ->orderBy('u.created_at', 'desc')
            ->get();
        return response()->json($data, 200);
    }

    public function listStaff()
    {
        $data = DB::table('users')
            ->select(
                'id',
                DB::raw("TRIM(CONCAT(CASE WHEN firstName = '' or firstName is null THEN '' ELSE CONCAT(firstName,' ') END
                ,CASE WHEN middleName = '' or middleName is null THEN '' ELSE CONCAT(middleName,' ') END,
                case when lastName = '' or lastName is null then '' else lastName end)) as fullName")
            )
            ->where('isDeleted', '=', 0)
            ->get();

        return response()->json($data, 200);
    }

    public function listStaffWithLocation(Request $request)
    {
        $request->locationId = json_decode($request->locationId);
        $data = DB::table('users as u')
            ->join('usersLocation as ul', 'u.id', 'ul.usersId')
            ->join('jobTitle as j', 'j.id', 'u.jobTitleId')
            ->select(
                DB::raw("TRIM(CONCAT(CASE WHEN firstName = '' or firstName is null THEN '' ELSE CONCAT(firstName,' ') END
                ,CASE WHEN middleName = '' or middleName is null THEN '' ELSE CONCAT(middleName,' ') END,
                case when lastName = '' or lastName is null then '' else lastName end)) as fullName"),
                'j.jobName'
            )
            ->whereIn('ul.locationId', $request->locationId)
            ->where('u.isDeleted', '=', 0)
            ->groupBy('fullName')
            ->groupBy('j.jobName')
            ->get();

        return response()->json($data, 200);
    }

    public function listStaffDoctorWithLocation(Request $request)
    {
        $value = $request->locationId;

        $data = DB::table('users as u')
            ->join('usersLocation as ul', 'u.id', 'ul.usersId')
            ->join('jobTitle as j', 'j.id', 'u.jobTitleId')
            ->select(
                'u.id',
                'u.nickName as firstName',
            );

        if ($value) {

            if (is_array($value)) {
                $data = $data->whereIn('ul.locationId', $value);
            } elseif (is_numeric($value)) {
                $data = $data->where('ul.locationId', '=', $value);
            }
        }

        $data = $data->where('j.id', '=', 17)   //id job title dokter hewan
            ->where('u.isDeleted', '=', 0)
            ->groupBy('u.nickName')
            ->groupBy('u.id')
            ->orderBy('u.id', 'asc')
            ->get();

        return response()->json($data, 200);
    }

    public function listStaffManagerAdmin()
    {
        $data = DB::table('users as u')
            ->join('usersRoles as ur', 'ur.id', 'u.roleId')
            ->select(
                'u.id',
                'u.firstName as name',
            )
            ->whereIn('u.roleId', [1, 2])
            ->where('u.isDeleted', '=', 0)
            ->get();

        return response()->json($data, 200);
    }

    public function listStaffWithLocationJobTitle(Request $request)
    {
        $data = DB::table('users as u')
            ->join('usersLocation as ul', 'u.id', 'ul.usersId')
            ->join('jobTitle as j', 'j.id', 'u.jobTitleId')
            ->select(
                'u.id',
                'u.firstName',
            )
            ->where('ul.locationId', '=', $request->locationId)
            ->where('j.id', '=', $request->jobTitleId)
            ->where('u.isDeleted', '=', 0)
            ->groupBy('u.firstName')
            ->groupBy('u.id')
            ->get();

        return response()->json($data, 200);
    }

    public function salaryCheck(Request $request)
    {
        $user = DB::table('users as u')
            ->join('jobTitle as j', 'u.jobtitleid', 'j.id')
            ->join('payPeriod as p', 'u.payPeriodId', 'p.id')
            ->select(
                DB::raw("IFNULL ((registrationNo),'') as registrationNo"),
                'u.payPeriodId',
                'p.periodName as payPeriodName',
                'j.id as jobtitleId',
                'j.jobName',
                DB::raw("'Mitra Kerja' as status"), //Mitra Kerja atau Masa Percobaan
                'u.startDate',
                'u.endDate',
                DB::raw("TRIM(u.payAmount)+0 as payAmount"),
            )
            ->where('u.id', '=', $request->staffId)
            ->where('u.isDeleted', '=', 0)
            ->first();

        if (!$user) {
            return response()->json([
                'message' => 'Staff not found or has been deleted',
            ], 404);
        }

        $dateFrom = Carbon::parse($request->input('dateFrom'));
        $dateTo = Carbon::parse($request->input('dateTo'));

        // 3. Melakukan Perhitungan
        $fullDay = $dateFrom->diffInDays($dateTo) - 4;
        // $jumlahHariTermasukKeduanya = $jumlahHariPenuh + 1;
        // $jumlahHariKerja = $dateFrom->diffInWeekdays($dateTo);

        $late = DB::table('staffAbsents as sa')
            ->where('sa.userId', '=', $request->staffId)
            ->whereBetween('sa.presentTime', [$request->dateFrom, $request->dateTo]) // Tambahkan baris ini
            ->where('sa.status', '=', 'Terlambat')
            ->where('sa.isDeleted', '=', 0)
            ->count();

        $comingDays = DB::table('staffAbsents as sa')
            ->where('sa.userId', '=', $request->staffId)
            ->whereBetween('sa.presentTime', [$request->dateFrom, $request->dateTo]) // Tambahkan baris ini
            ->where('sa.isDeleted', '=', 0)
            ->count();

        $notComing = $fullDay - $comingDays;


        if ($user->jobtitleId == 3) {   //groomer
            $data = [
                'basicIncome' => $user->payAmount,
                'annualIncreaseIncentive' => 0,
                'attendanceAllowance' => 520000,
                'mealAllowance' => 280000,
                'positionAllowance' => 100000,

                'quantityXray' => 0,
                'eachXray' => 5000,
                'labXrayIncentive' => 0,

                'quantityGrooming' => 1,
                'eachGrooming' => 10000,
                'groomingIncentive' => 0,

                'groomingAchievementBonus' => 0,
                'salesBonus' => 0,

                'quantitySubstituteDayWage' => 0,
                'eachSubstituteDayWage' => 50000, // nominal per hari
                'totalSubstituteDayWage' => 0, // total keseluruhan

                'bpjsHealthAllowance' => 0,

                'notComingToWork' => $notComing, // jumlah hari
                'eachNotComingToWork' => 50000, // nominal potongan per hari
                'notComingToWorkTotal' => $notComing * 50000, // nominal potongan total
                'quantityLate' => $late, // jumlah keterlambatan
                'eachLate' => 50000, // jumlah keterlambatan
                'late' => $late * 50000, // jumlah keterlambatan
            ];
        } elseif ($user->jobtitleId == 2) {   //helper
            $data = [
                // Pendapatan
                'basicIncome' => $user->payAmount,
                'annualIncreaseIncentive' => 0,
                'attendanceAllowance' => 520000,
                'mealAllowance' => 280000,
                'positionAllowance' => 200000,

                'quantityXray' => 0,
                'eachXray' => 5000,
                'labXrayIncentive' => 0,

                'quantityGrooming' => 0,
                'eachGrooming' => 5000,
                'groomingIncentive' => 0,

                'clinicAchievementBonus' => 0,
                'salesBonus' => 0,

                'quantitySubstituteDayWage' => 0,
                'eachSubstituteDayWage' => 50000, // nominal per hari
                'totalSubstituteDayWage' => 0, // total keseluruhan

                'bpjsHealthAllowance' => 0,

                // Pengeluaran / Potongan
                'notComingToWork' => $notComing, // jumlah hari
                'eachNotComingToWork' => 50000, // nominal potongan per hari
                'notComingToWorkTotal' => $notComing * 50000, // nominal potongan total
                'quantityLate' => $late, // jumlah keterlambatan
                'eachLate' => 50000, // jumlah keterlambatan
                'late' => $late * 50000, // jumlah keterlambatan
            ];
        } elseif ($user->jobtitleId == 1) {   //kasir
            $data = [
                // Pendapatan
                'basicIncome' => $user->payAmount,
                'annualIncreaseIncentive' => 300000,
                'attendanceAllowance' => 820000,
                'mealAllowance' => 280000,
                'positionAllowance' => 200000,
                'housingAllowance' => 500000,
                'petshopRevenueIncentive' => 0,
                'revenueAchievementBonus' => 0,
                'memberAchievementBonus' => 0,

                'quantitySubstituteDayWage' => 0,
                'eachSubstituteDayWage' => 0, // nominal per hari
                'totalSubstituteDayWage' => 0, // total keseluruhan

                'bpjsHealthAllowance' => 0,

                // Potongan / Pengeluaran
                'notComingToWork' => $notComing, // jumlah hari
                'eachNotComingToWork' => 50000, // nominal potongan per hari
                'notComingToWorkTotal' => $notComing * 50000, // nominal potongan total
                'quantityLate' => $late, // jumlah keterlambatan
                'eachLate' => 50000, // jumlah keterlambatan
                'late' => $late * 50000, // jumlah keterlambatan
            ];
        } elseif ($user->jobtitleId == 4) {   //paramedis
            $data = [
                // Pendapatan
                'basicIncome' => $user->payAmount,
                'annualIncreaseIncentive' => 0,
                'attendanceAllowance' => 220000,
                'mealAllowance' => 280000,
                'housingAllowance' => 500000,

                'quantityXray' => 0,
                'eachXray' => 5000,
                'labXrayIncentive' => 0,

                'clinicRevenueBonus' => 0,

                'quantityLongShiftSubstituteWage' => 0,
                'eachLongShiftSubstituteWage' => 10000, // nominal per hari
                'totalLongShiftSubstituteWage' => 0, // total keseluruhan

                'quantityFullShiftSubstituteWage' => 0,
                'eachFullShiftSubstituteWage' => 0, // nominal per hari
                'totalFullShiftSubstituteWage' => 0, // total keseluruhan

                'bpjsHealthAllowance' => 0,

                // Potongan / Pengeluaran
                'notComingToWork' => $notComing, // jumlah hari
                'eachNotComingToWork' => 50000, // nominal potongan per hari
                'notComingToWorkTotal' => $notComing * 50000, // nominal potongan total
                'quantityLate' => $late, // jumlah keterlambatan
                'eachLate' => 50000, // jumlah keterlambatan
                'late' => $late * 50000, // jumlah keterlambatan
            ];
        } elseif ($user->jobtitleId == 17) {   //dokter hewan

            $data = [
                // Pemasukan
                'basicIncome' => $user->payAmount,
                'attendanceAllowance' => 500000,
                'mealAllowance' => 50000,

                'quantityPatientIncentive' => 5,
                'eachPatientIncentive' => 50000,
                'PatientIncentive' => 500000, // total keseluruhan

                'quantityXray' => 5,
                'eachXray' => 50000,
                'labXrayIncentive' => 50000,

                'clinicRevenueBonus' => 500000,

                'quantityLongShiftSubstituteWage' => 5,
                'eachLongShiftSubstituteWage' => 50000, // nominal per hari
                'totalLongShiftSubstituteWage' => 500000, // total keseluruhan

                'quantityFullShiftSubstituteWage' => 5,
                'eachFullShiftSubstituteWage' => 50000, // nominal per hari
                'totalFullShiftSubstituteWage' => 500000, // total keseluruhan

                'bpjsHealthAllowance' => 500000,

                // Pengeluaran
                'notComingToWork' => $notComing, // jumlah hari
                'eachNotComingToWork' => 50000, // nominal potongan per hari
                'notComingToWorkTotal' => $notComing * 50000, // nominal potongan total
                'quantityLate' => $late, // jumlah keterlambatan
                'eachLate' => 50000, // jumlah keterlambatan
                'late' => $late * 50000, // jumlah keterlambatan
            ];
        } elseif ($user->jobtitleId == 6 || $user->jobtitleId == 8 || $user->jobtitleId == 9 || $user->jobtitleId == 10 || $user->jobtitleId == 11) {
            //staff
            $data = [
                // Pemasukan
                'basicIncome' => $user->payAmount,
                'annualIncreaseIncentive' => 200000,
                'attendanceAllowance' => 520000,
                'entertainAllowance' => 200000,
                'transportAllowance' => 300000,
                'functionalAllowance' => 0,
                'hardShiftAllowance' => 0,
                'famillyAllowance' => 0,
                'bpjsHealthAllowance' => 0,
                'turnoverAchievementBonus' => 0,

                // Pengeluaran
                'notComingToWork' => $notComing, // jumlah hari
                'eachNotComingToWork' => 50000, // nominal potongan per hari
                'notComingToWorkTotal' => $notComing * 50000, // nominal potongan total
                'quantityLate' => $late, // jumlah keterlambatan
                'eachLate' => 50000, // jumlah keterlambatan
                'late' => $late * 50000, // jumlah keterlambatan
            ];
        } elseif ($user->jobtitleId == 7 || $user->jobtitleId == 12 || $user->jobtitleId == 16 || $user->jobtitleId == 19 || $user->jobtitleId == 20) {
            //manager
            $data = [
                // Pemasukan
                'basicIncome' => $user->payAmount,
                'annualIncreaseIncentive' => 40000,
                'attendanceAllowance' => 400000,
                'entertainAllowance' => 40000,
                'transportAllowance' => 40000,
                'functionalLeaderAllowance' => 40000,
                'hardShiftAllowance' => 400000,
                'famillyAllowance' => 400000,
                'bpjsHealthAllowance' => 500000,

                // Pengeluaran
                'notComingToWork' => $notComing, // jumlah hari
                'eachNotComingToWork' => 50000, // nominal potongan per hari
                'notComingToWorkTotal' => $notComing * 50000, // nominal potongan total
                'quantityLate' => $late, // jumlah keterlambatan
                'eachLate' => 50000, // jumlah keterlambatan
                'late' => $late * 50000, // jumlah keterlambatan
            ];
        } elseif ($user->jobtitleId == 18) {
            //quality control
            $data = [
                // Pemasukan
                'basicIncome' => $user->payAmount,
                'annualIncreaseIncentive' => 0,
                'attendanceAllowance' => 840000,
                'entertainAllowance' => 200000,
                'transportAllowance' => 280000,
                'positionAllowance' => 200000,
                'housingAllowance' => 500000,
                'bpjsHealthAllowance' => 0,
                'turnoverAchievementBonus' => 0,

                // Pengeluaran
                'notComingToWork' => $notComing, // jumlah hari
                'eachNotComingToWork' => 50000, // nominal potongan per hari
                'notComingToWorkTotal' => $notComing * 50000, // nominal potongan total
                'quantityLate' => $late, // jumlah keterlambatan
                'eachLate' => 50000, // jumlah keterlambatan
                'late' => $late * 50000, // jumlah keterlambatan
            ];
        }

        return response()->json([
            'user' => $user,
            'sallary' => $data,
        ]);
    }
}
