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
        Schema::create('accessControlSchedulesDetail', function (Blueprint $table) {
            $table->id();
            $table->integer('scheduleMasterId');
            $table->integer('masterMenuId');
            $table->integer('listMenuId');
            $table->integer('accessTypeId');
            $table->boolean('giveAccessNow')->nullable()->default(false);
            $table->timestamp('startTime')->nullable();
            $table->timestamp('endTime')->nullable();
            $table->integer('status')->nullable();
            $table->integer('duration')->nullable();
            $table->boolean('isDeleted')->nullable()->default(false);
            $table->integer('userUpdateId')->nullable();
            $table->string('createdBy')->nullable();
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
        Schema::dropIfExists('accessControlSchedulesDetail');
    }
};
