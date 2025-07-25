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
        Schema::create('transaction_pet_clinic_recipes', function (Blueprint $table) {
            $table->id();

            $table->integer('transactionPetClinicId');
            $table->integer('productId');
            $table->decimal('dosage', $precision = 18, $scale = 3);
            $table->string('unit');
            $table->integer('frequency');
            $table->string('giveMedicine');

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
        Schema::dropIfExists('transaction_pet_clinic_recipes');
    }
};
