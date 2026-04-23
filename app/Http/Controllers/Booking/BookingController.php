<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\bookings;
use Illuminate\Http\Request;
use Validator;
use App\Models\BookingsPetHotel;
use App\Models\BookingsPetSalon;
use App\Models\BookingsBreeding;
use App\Models\BookingsPetClinic;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $data = DB::table('bookings as e')
            ->join('users as u', 'e.userId', 'u.id')
            ->join('customer as c', 'e.customerId', 'c.id')
            ->join('customerPets as p', 'e.petId', 'p.id')
            ->join('location as l', 'e.locationId', 'l.id')
            ->select(
                'e.id',
                // Perbaikan: Menggunakan DB::raw dengan CONCAT agar title tergabung dengan benar
                DB::raw("CONCAT(e.serviceType, ' - ', c.firstName, ' ', c.lastName, ' (', p.petName, ')') as title"),
                'e.bookingTime as start',
                DB::raw("'' as `end`"), // Gunakan DB::raw untuk string kosong agar konsisten
                DB::raw("0 as allDay"),
                DB::raw("CASE e.serviceType
                WHEN 'PetHotel' THEN '#FF0000'
                WHEN 'PetSalon' THEN '#FFFF00'
                WHEN 'Breeding' THEN '#008000'
                WHEN 'PetClinic' THEN '#0000FF'
                ELSE '#CCCCCC' END as color"),
                DB::raw("CASE e.serviceType
                WHEN 'PetHotel' THEN '#000000'
                WHEN 'PetSalon' THEN '#000000'
                WHEN 'Breeding' THEN '#FFFFFF'
                WHEN 'PetClinic' THEN '#FFFFFF'
                ELSE '#000000' END as textColor"),

                DB::raw("'' as description")
            )
            ->where('e.isDeleted', '=', 0);

        if ($request->filled('monthBooking') && $request->filled('yearBooking')) {
            $data = $data->whereMonth('e.bookingTime', $request->monthBooking)
                ->whereYear('e.bookingTime', $request->yearBooking);
        }
        // Filter Berdasarkan Lokasi
        if ($request->filled('locationId')) {
            $data = $data->where('e.locationId', $request->locationId);
        }

        if ($request->filled('doctorId')) {
            $data = $data->where('e.doctorId', $request->doctorId);
        }

        return response()->json([
            'data' => $data->get(),
        ]);
    }

    public function create(Request $request)
    {
        $baseRules = [
            'locationId'  => 'required|integer',
            'doctorId'  => 'required|integer',
            'customerId'  => 'required|integer',
            'petId'       => 'required|integer',
            'services'    => 'required|in:PetHotel,PetSalon,Breeding,PetClinic',
            'bookingTime' => 'required|date',
        ];

        $service = $request->input('services');

        $extraRules = match ($service) {
            'PetHotel' => [
                'socializationType'   => 'required|string',
                'emergencyContactName'  => 'required|string',
                'inventoryProducts'      => 'required|string',
                'additionalInfo'   => 'nullable|string',
            ],
            'PetSalon' => [
                'furCondition'  => 'required|string',
                'skinSensitivity'      => 'required|string',
                'additionalInfo'   => 'nullable|string',
            ],
            'Breeding' => [
                'stambum'  => 'required|string',
                'healthClearance'     => 'required|string',
                'additionalInfo'   => 'nullable|string',
            ],
            'PetClinic' => [
                'consultationType'     => 'required|string',
                'drugAllergy'         => 'nullable|string',
                'additionalInfo'   => 'nullable|string',
            ],
            default => [],
        };

        $validate = Validator::make($request->all(), array_merge($baseRules, $extraRules));

        if ($validate->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validate->errors()->all(),
            ], 422);
        }

        $booking = bookings::create([
            'locationId'  => $request->locationId,
            'doctorId'  => $request->doctorId,
            'customerId'  => $request->customerId,
            'petId'       => $request->petId,
            'serviceType'    => $request->services,
            'bookingTime' => $request->bookingTime,
            'userId' => $request->user()->id,
        ]);

        if ($request->services === 'PetHotel') {
            BookingsPetHotel::create([
                'bookingId' => $booking->id,
                'socializationType' => $request->socializationType,
                'emergencyContactName' => $request->emergencyContactName,
                'inventoryProducts' => $request->inventoryProducts,
                'additionalInfo' => $request->additionalInfo,
                'userId' => $request->user()->id,
            ]);
        } else if ($request->services === 'PetSalon') {
            BookingsPetSalon::create([
                'bookingId' => $booking->id,
                'furCondition' => $request->furCondition,
                'skinSensitivity' => $request->skinSensitivity,
                'additionalInfo' => $request->additionalInfo,
                'userId' => $request->user()->id,
            ]);
        } else if ($request->services === 'Breeding') {
            BookingsBreeding::create([
                'bookingId' => $booking->id,
                'stambum' => $request->stambum,
                'healthClearance' => $request->healthClearance,
                'additionalInfo' => $request->additionalInfo,
                'userId' => $request->user()->id,
            ]);
        } else if ($request->services === 'PetClinic') {
            BookingsPetClinic::create([
                'bookingId' => $booking->id,
                'consultationType' => $request->consultationType,
                'drugAllergy' => $request->drugAllergy,
                'additionalInfo' => $request->additionalInfo,
                'userId' => $request->user()->id,
            ]);
        }

        return responseCreate();
    }

    public function update(Request $request)
    {
        $baseRules = [
            'id'          => 'required|integer',
            'locationId'  => 'required|integer',
            'doctorId'  => 'required|integer',
            'customerId'  => 'required|integer',
            'petId'       => 'required|integer',
            'services'    => 'required|in:PetHotel,PetSalon,Breeding,PetClinic',
            'bookingTime' => 'required|date',
        ];

        // Use $request->json() consistently everywhere
        $data    = $request->json()->all();
        $service = $data['services'] ?? null;

        $extraRules = match ($service) {
            'PetHotel' => [
                'socializationType'    => 'required|string',
                'emergencyContactName' => 'required|string',
                'inventoryProducts'    => 'required|string',
                'additionalInfo'       => 'nullable|string',
            ],
            'PetSalon' => [
                'furCondition'    => 'required|string',
                'skinSensitivity' => 'required|string',
                'additionalInfo'  => 'nullable|string',
            ],
            'Breeding' => [
                'stambum'        => 'required|string',
                'healthClearance' => 'required|string',
                'additionalInfo'  => 'nullable|string',
            ],
            'PetClinic' => [
                'consultationType' => 'required|string',
                'drugAllergy'      => 'nullable|string',
                'additionalInfo'   => 'nullable|string',
            ],
            default => [],
        };

        $validate = Validator::make($data, array_merge($baseRules, $extraRules));

        if ($validate->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validate->errors()->all(),
            ], 422);
        }

        $booking = bookings::find($data['id']);
        if (!$booking) {
            return response()->json([
                'message' => 'Booking not found.',
            ], 404);
        }

        $booking->update([
            'locationId'   => $data['locationId'],
            'doctorId'   => $data['doctorId'],
            'customerId'   => $data['customerId'],
            'petId'        => $data['petId'],
            'serviceType'  => $data['services'],
            'bookingTime'  => $data['bookingTime'],
            'userUpdateId' => $request->user()->id,
        ]);

        if ($service === 'PetHotel') {
            $bookingDetail = BookingsPetHotel::where('bookingId', $data['id'])->first();
            if ($bookingDetail) {
                $bookingDetail->update([
                    'socializationType'    => $data['socializationType'],
                    'emergencyContactName' => $data['emergencyContactName'],
                    'inventoryProducts'    => $data['inventoryProducts'],
                    'additionalInfo'       => $data['additionalInfo'] ?? null,
                    'userUpdateId'         => $request->user()->id,
                ]);
            }
        } elseif ($service === 'PetSalon') {
            $bookingDetail = BookingsPetSalon::where('bookingId', $data['id'])->first();
            if ($bookingDetail) {
                $bookingDetail->update([
                    'furCondition'    => $data['furCondition'],
                    'skinSensitivity' => $data['skinSensitivity'],
                    'additionalInfo'  => $data['additionalInfo'] ?? null,
                    'userUpdateId'    => $request->user()->id,
                ]);
            }
        } elseif ($service === 'Breeding') {
            $bookingDetail = BookingsBreeding::where('bookingId', $data['id'])->first();
            if ($bookingDetail) {
                $bookingDetail->update([
                    'stambum'         => $data['stambum'],
                    'healthClearance' => $data['healthClearance'],
                    'additionalInfo'  => $data['additionalInfo'] ?? null,
                    'userUpdateId'    => $request->user()->id,
                ]);
            }
        } elseif ($service === 'PetClinic') {
            $bookingDetail = BookingsPetClinic::where('bookingId', $data['id'])->first();
            if ($bookingDetail) {
                $bookingDetail->update([
                    'consultationType' => $data['consultationType'],
                    'drugAllergy'      => $data['drugAllergy'] ?? null,
                    'additionalInfo'   => $data['additionalInfo'] ?? null,
                    'userUpdateId'     => $request->user()->id,
                ]);
            }
        }

        return responseUpdate();
    }

    public function cancelBooking(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id' => 'required|integer',
            'cancellationReason' => 'required|string',
        ]);

        if ($validate->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $validate->errors()->all(),
            ], 422);
        }

        $booking = bookings::find($request->id)->where('isDeleted', false)->first();
        if (!$booking) {
            return response()->json([
                'message' => 'Booking not found.',
            ], 404);
        }

        $booking->update([
            'isCancelled' => true,
            'cancellationReason' => $request->cancellationReason,
            'canceledByName' => $request->user()->name,
            'cancellationDate' => now(),
            'userUpdateId' => $request->user()->id,
        ]);

        return responseUpdate();
    }

    function delete(Request $request)
    {
        if (!$request->id) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['There is no any Data to delete!'],
            ], 422);
        }

        foreach ($request->id as $va) {
            $res = bookings::find($va);

            if (!$res) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['Booking not found!'],
                ], 422);
            }
        }

        foreach ($request->id as $va) {
            $booking = bookings::find($va);
            $booking->isDeleted = true;
            $booking->deletedBy = $request->user()->name;
            $booking->deletedAt = now();
            $booking->save();
        }

        return responseDelete();
    }
}
