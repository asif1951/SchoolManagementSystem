<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admission payments and dues, transfers, certificates and online applications.
 *
 *  serial_counters       numbers that only ever go up (receipts, certificates, applications): never reused
 *  admission_fees        what an admission costs, per session and class
 *  admission_payments    money received for admission (a voided receipt keeps its number)
 *  student_transfers     a transfer-out request and its trail: requested → cleared → approved → issued
 *  certificates          the certificate register (soft-deleted, never renumbered)
 *  online_applications   applications submitted through the public form, waiting for a decision
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_counters', function (Blueprint $table) {
            $table->string('key', 60)->primary();                        // e.g. receipt:2026 · certificate:transfer:2026
            $table->unsignedBigInteger('last')->default(0);
        });

        Schema::create('admission_fees', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 10);
            $table->string('class_name', 100);
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['academic_year', 'class_name'], 'uniq_admission_fee');
        });

        Schema::create('admission_payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 30)->unique();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();       // the enrollment paid for
            $table->foreignId('profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();
            $table->string('academic_year', 10);
            $table->decimal('amount', 10, 2);
            $table->string('method', 10);                                // cash | bkash | nagad | bank
            $table->string('reference', 100)->nullable();                // transaction id, cheque no …
            $table->string('note', 255)->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['academic_year', 'paid_at'], 'idx_adm_pay_session');
            $table->index('student_id', 'idx_adm_pay_student');
            $table->index('method', 'idx_adm_pay_method');
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('serial', 40)->unique();                      // TC-2026-0001 — never edited, never reused
            $table->string('type', 12);                                  // transfer | testimonial | character | other
            $table->foreignId('profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('students')->nullOnDelete();
            $table->date('issue_date');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks', 500)->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'issue_date'], 'idx_cert_type_date');
            $table->index('profile_id', 'idx_cert_profile');
        });

        Schema::create('student_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained('students')->restrictOnDelete();
            $table->string('status', 12)->default('requested');          // requested | cleared | issued | rejected | cancelled
            $table->string('reason', 500)->nullable();
            $table->string('destination', 255)->nullable();               // the school the child is going to

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();

            $table->decimal('dues_total', 10, 2)->default(0);             // at the last check
            $table->json('dues_detail')->nullable();
            $table->timestamp('cleared_at')->nullable();                  // dues were nil (or overridden)

            $table->string('override_reason', 500)->nullable();           // dues were unpaid and someone allowed it anyway
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('certificate_id')->nullable()->constrained('certificates')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();

            $table->string('decision_remark', 500)->nullable();           // why it was rejected / cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamp('reinstated_at')->nullable();
            $table->foreignId('reinstated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reinstate_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['status', 'requested_at'], 'idx_transfer_status');
            $table->index('enrollment_id', 'idx_transfer_enrollment');
        });

        Schema::create('online_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no', 30)->unique();
            $table->string('academic_year', 10);
            $table->string('class_name', 100);                            // the class applied for
            $table->string('name_en', 255);
            $table->string('name_bn', 255)->nullable();
            $table->string('brn', 17)->nullable()->index();
            $table->date('dob')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('religion', 40)->nullable();
            $table->string('father_name', 255)->nullable();
            $table->string('mother_name', 255)->nullable();
            $table->string('guardian_name', 255)->nullable();
            $table->string('guardian_mobile', 30);
            $table->string('guardian_relation', 50)->nullable();
            $table->text('present_address')->nullable();
            $table->string('previous_school', 255)->nullable();
            $table->string('status', 10)->default('pending');             // pending | approved | rejected
            $table->string('decision_remark', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('profile_id')->nullable()->constrained('student_profiles')->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('submitted_at')->useCurrent();

            $table->index(['status', 'submitted_at'], 'idx_app_status');
            $table->index(['academic_year', 'class_name'], 'idx_app_class');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_applications');
        Schema::dropIfExists('student_transfers');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('admission_payments');
        Schema::dropIfExists('admission_fees');
        Schema::dropIfExists('serial_counters');
    }
};
