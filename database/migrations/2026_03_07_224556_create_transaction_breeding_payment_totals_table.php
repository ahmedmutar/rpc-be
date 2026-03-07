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
        Schema::create('transaction_breeding_payment_totals', function (Blueprint $table) {
            $table->id();
            $table->integer('transactionId');
            $table->integer('paymentMethodId');
            $table->decimal('amount', $precision = 18, $scale = 2);
            $table->boolean('isPayed')->default(false);
            $table->decimal('amountPaid', $precision = 18, $scale = 2);
            $table->string('nota_number')->nullable();
            $table->string('proofOfPayment')->nullable();
            $table->string('originalName')->nullable();
            $table->string('proofRandomName')->nullable();
            $table->date('nextPayment')->nullable();
            $table->string('duration')->nullable();
            $table->integer('tenor')->nullable();

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
        Schema::dropIfExists('transaction_breeding_payment_totals');
    }
};
