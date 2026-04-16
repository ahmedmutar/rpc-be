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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->date('transactionDate');
            $table->string('referenceNo');
            $table->integer('vendorId')->nullable();
            $table->integer('locationId')->nullable();
            $table->decimal('subTotal', 18, 2);
            $table->decimal('tax', 18, 2);
            $table->decimal('pph', 18, 2);
            $table->decimal('grandTotal', 18, 2);
            $table->integer('categoryId')->nullable();
            $table->integer('expenseTypeId')->nullable();
            $table->integer('departmentId')->nullable();
            $table->integer('paymentStatusId')->nullable();
            $table->date('dueDate')->nullable();
            $table->integer('paymentMethodId');
            $table->string('description')->nullable();
            $table->string('realImageName')->nullable();
            $table->string('imagePath')->nullable();

            $table->string('statusApproval');
            $table->integer('userApprovalId')->nullable();
            $table->timestamp('approvalAt', 0)->nullable();

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
        Schema::dropIfExists('expenses');
    }
};
