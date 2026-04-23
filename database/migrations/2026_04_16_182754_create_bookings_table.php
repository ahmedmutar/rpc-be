<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            $table->integer('locationId');
            $table->integer('doctorId');
            $table->integer('customerId');
            $table->integer('petId');
            $table->string('serviceType');
            $table->datetime('bookingTime');
            $table->boolean('isCancelled')->default(false);
            $table->string('cancellationReason')->nullable();
            $table->string('canceledByName')->nullable();
            $table->date('cancellationDate')->nullable();

            $table->boolean('isDeleted')->nullable()->default(false);
            $table->integer('userId');
            $table->integer('userUpdateId')->nullable();
            $table->string('deletedBy')->nullable();
            $table->timestamp('deletedAt', 0)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('bookings');
    }
};
