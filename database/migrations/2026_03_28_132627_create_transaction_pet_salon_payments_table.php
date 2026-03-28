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
        Schema::create('transaction_pet_salon_payments', function (Blueprint $table) {
            $table->id();
            $table->integer('transactionId');
            $table->integer('paymentMethodId');
            $table->integer('promoId')->nullable();
            $table->integer('productId')->nullable();
            $table->integer('serviceId')->nullable();
            $table->integer('productBuyId')->nullable();
            $table->integer('productFreeId')->nullable();
            $table->integer('quantity')->nullable();
            $table->integer('quantityBuy')->nullable();
            $table->integer('quantityFree')->nullable();
            $table->string('discountType')->nullable();
            $table->integer('discountAmount')->nullable();
            $table->integer('discountPercent')->nullable();
            $table->decimal('price', $precision = 18, $scale = 2);
            $table->decimal('priceOverall', $precision = 18, $scale = 2);
            $table->boolean('isBundle')->nullable()->default(false);

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
        Schema::dropIfExists('transaction_pet_salon_payments');
    }
};
