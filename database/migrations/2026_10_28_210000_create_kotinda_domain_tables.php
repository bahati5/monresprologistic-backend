<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schéma métier initial, reconstitué à partir des modèles Eloquent (fillable / casts / relations).
 * Les clés étrangères sont indexées mais sans contrainte, pour ne pas imposer d'ordre de création.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_books', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('owner_profile_id')->nullable()->index();
            $table->unsignedBigInteger('contact_profile_id')->nullable()->index();
            $table->string('alias')->nullable();
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('slug')->nullable()->index();
            $table->string('default_currency')->nullable();
            $table->json('exchange_rates')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('contact_phone')->nullable();
            $table->string('contact_phone_secondary')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            $table->unsignedBigInteger('state_id')->nullable()->index();
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('agency_payment_coordinates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('label')->nullable();
            $table->text('details')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('article_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('assisted_purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status', 50)->nullable()->index();
            $table->unsignedBigInteger('operator_id')->nullable()->index();
            $table->text('product_url')->nullable();
            $table->string('article_label')->nullable();
            $table->text('line_notes')->nullable();
            $table->text('notes')->nullable();
            $table->string('size')->nullable();
            $table->string('color')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('price_displayed')->nullable();
            $table->string('price_currency')->nullable();
            $table->decimal('quote_amount', 15, 2)->nullable();
            $table->string('quote_currency')->nullable();
            $table->decimal('service_fee', 15, 2)->nullable();
            $table->decimal('bank_fee_percentage', 15, 2)->nullable();
            $table->text('payment_methods_note')->nullable();
            $table->string('supplier_tracking_number')->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->json('commission_breakdown')->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->unsignedBigInteger('converted_pre_alert_id')->nullable()->index();
            $table->unsignedBigInteger('converted_shipment_id')->nullable()->index();
            $table->string('payment_proof_path')->nullable();
            $table->decimal('estimated_weight_kg', 15, 3)->nullable();
            $table->decimal('hub_received_weight_kg', 15, 3)->nullable();
            $table->string('hub_received_photo_path')->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->integer('quote_version')->default(1);
            $table->timestamp('quote_expires_at')->nullable();
            $table->integer('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->text('refusal_note')->nullable();
            $table->text('clarification_message')->nullable();
            $table->timestamp('clarification_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('assisted_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('assisted_purchase_id')->nullable()->index();
            $table->unsignedBigInteger('merchant_id')->nullable()->index();
            $table->text('url')->nullable();
            $table->string('name')->nullable();
            $table->text('options')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('assisted_purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('assisted_purchase_id')->nullable()->index();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('billing_extras', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('label')->nullable();
            $table->text('calculation_description')->nullable();
            $table->string('type')->nullable();
            $table->decimal('value', 15, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('state_id')->nullable()->index();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            $table->string('country_code')->nullable();
            $table->string('name')->nullable();
            $table->decimal('latitude', 11, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('commentable_type')->nullable();
            $table->unsignedBigInteger('commentable_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable()->index();
            $table->string('iso2')->nullable();
            $table->string('iso3')->nullable();
            $table->string('numeric_code')->nullable();
            $table->string('phonecode')->nullable();
            $table->string('capital')->nullable();
            $table->string('currency')->nullable();
            $table->string('currency_name')->nullable();
            $table->string('currency_symbol')->nullable();
            $table->string('native')->nullable();
            $table->string('nationality')->nullable();
            $table->decimal('latitude', 11, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('emoji')->nullable();
            $table->string('emojiU')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_packages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_code')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('locker_id')->nullable()->index();
            $table->unsignedBigInteger('pre_alert_id')->nullable()->index();
            $table->string('status', 50)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('merchant_name')->nullable();
            $table->decimal('weight_kg', 15, 3)->nullable();
            $table->decimal('length_cm', 15, 2)->nullable();
            $table->decimal('width_cm', 15, 2)->nullable();
            $table->decimal('height_cm', 15, 2)->nullable();
            $table->decimal('declared_value', 15, 2)->nullable();
            $table->string('value_currency')->nullable();
            $table->decimal('shipping_cost', 15, 2)->nullable();
            $table->decimal('total_cost', 15, 2)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('received_by')->nullable()->index();
            $table->text('condition_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('from_currency')->nullable();
            $table->string('to_currency')->nullable();
            $table->decimal('rate', 20, 8)->nullable();
            $table->unsignedBigInteger('set_by')->nullable()->index();
            $table->timestamp('valid_from')->nullable();
            $table->timestamps();
        });

        Schema::create('form_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('form_type', 50)->nullable()->index();
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('frontend_elements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('menu_id')->nullable()->index();
            $table->string('route')->nullable();
            $table->string('icon')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_page')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('display_in_sidebar')->default(true);
            $table->timestamps();
        });

        Schema::create('hubs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->nullable()->index();
            $table->string('name')->nullable();
            $table->decimal('latitude', 11, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_number')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('shipment_id')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('base_amount', 15, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('odoo_exported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_extra_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('billing_extra_id')->nullable()->index();
            $table->string('label')->nullable();
            $table->text('calculation_description')->nullable();
            $table->string('type')->nullable();
            $table->decimal('value', 15, 4)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('type')->nullable();
            $table->text('description')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('lockers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('code')->nullable()->index();
            $table->text('formatted_address')->nullable();
            $table->timestamps();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->json('domains')->nullable();
            $table->text('logo_url')->nullable();
            $table->decimal('commission_rate', 15, 2)->nullable();
            $table->integer('estimated_delivery_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('locale')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type')->nullable();
            $table->string('channel')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug')->nullable()->index();
            $table->string('event_key')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('channel')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->json('channels')->nullable();
            $table->json('sample_variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('packaging_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_billable')->default(false);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('code')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('instructions')->nullable();
            $table->string('currency')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('payment_method_id')->nullable()->index();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('reference')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pickups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('shipment_id')->nullable()->index();
            $table->string('status', 50)->nullable()->index();
            $table->unsignedBigInteger('assigned_driver_id')->nullable()->index();
            $table->decimal('latitude', 11, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->text('address_text')->nullable();
            $table->text('requested_window')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('completion_photo_path')->nullable();
            $table->text('completion_notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('failure_reason_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('pickup_failure_reasons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('label')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pre_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_code')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('locker_id')->nullable()->index();
            $table->string('status', 50)->nullable()->index();
            $table->string('merchant_name')->nullable();
            $table->string('vendor_tracking_number')->nullable();
            $table->string('carrier_name')->nullable();
            $table->text('notes')->nullable();
            $table->text('description')->nullable();
            $table->decimal('declared_value', 15, 2)->nullable();
            $table->decimal('declared_weight_kg', 15, 3)->nullable();
            $table->string('value_currency')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('estimated_arrival_date')->nullable();
            $table->unsignedBigInteger('converted_shipment_id')->nullable()->index();
            $table->unsignedBigInteger('converted_customer_package_id')->nullable()->index();
            $table->boolean('issue_reported')->default(false);
            $table->timestamps();
        });

        Schema::create('pre_alert_issue_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('pre_alert_id')->nullable()->index();
            $table->unsignedBigInteger('reported_by_user_id')->nullable()->index();
            $table->text('message')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->text('formula')->nullable();
            $table->json('conditions')->nullable();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('zone_id')->nullable()->index();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('phone_secondary')->nullable();
            $table->text('address')->nullable();
            $table->string('landmark')->nullable();
            $table->string('zip_code')->nullable();
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->unsignedBigInteger('state_id')->nullable()->index();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_client')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->string('license_number')->nullable();
            $table->date('license_expiry')->nullable();
            $table->string('vehicle_type')->nullable();
            $table->string('vehicle_plate')->nullable();
            $table->string('vehicle_brand')->nullable();
            $table->text('coverage_zone')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
        });

        Schema::create('quote_audit_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('entity_name')->nullable();
            $table->string('action')->nullable();
            $table->json('changes')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable()->index();
            $table->timestamp('performed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_email_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('event')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('variables')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_line_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('internal_code')->nullable();
            $table->text('description')->nullable();
            $table->string('type')->nullable();
            $table->string('calculation_base')->nullable();
            $table->decimal('default_value', 15, 2)->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_visible_to_client')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->string('applies_to')->nullable();
            $table->string('behavior')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('assisted_purchase_id')->nullable()->index();
            $table->integer('version')->default(1);
            $table->json('snapshot_data')->nullable();
            $table->json('articles_data')->nullable();
            $table->decimal('total_primary', 15, 2)->nullable();
            $table->decimal('total_secondary', 15, 0)->nullable();
            $table->string('primary_currency')->nullable();
            $table->string('secondary_currency')->nullable();
            $table->decimal('exchange_rate_used', 15, 4)->nullable();
            $table->text('revision_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->boolean('is_urgent')->default(false);
            $table->decimal('urgency_surcharge_percent', 15, 2)->nullable();
            $table->string('estimated_delivery')->nullable();
            $table->text('staff_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('response_token')->nullable()->index();
            $table->string('client_response')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->text('refusal_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->integer('usage_count')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_template_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('quote_template_id')->nullable()->index();
            $table->unsignedBigInteger('quote_line_template_id')->nullable()->index();
            $table->decimal('custom_value', 15, 2)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_code')->nullable()->index();
            $table->string('refundable_type')->nullable();
            $table->unsignedBigInteger('refundable_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('status', 50)->nullable()->index();
            $table->text('reason')->nullable();
            $table->string('reason_category')->nullable();
            $table->string('payment_method')->nullable();
            $table->json('payment_details')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->unsignedBigInteger('processed_by')->nullable()->index();
            $table->text('rejection_reason')->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('regroupements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('batch_number')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('status', 50)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('sav_quick_replies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('category')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sav_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_code')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('category', 50)->nullable()->index();
            $table->string('priority', 50)->nullable()->index();
            $table->string('status', 50)->nullable()->index();
            $table->string('channel')->nullable();
            $table->string('subject')->nullable();
            $table->text('description')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable()->index();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('sla_deadline_at')->nullable();
            $table->timestamp('sla_due_at')->nullable(); // lu par SuiviDashboard
            $table->string('zendesk_ticket_id')->nullable();
            $table->json('attachments')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('sav_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('ticket_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->text('body')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->string('channel')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });

        Schema::create('ship_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ship_line_countries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('ship_line_id')->nullable()->index();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            $table->string('scope')->nullable();
            $table->timestamps();
        });

        Schema::create('ship_line_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('ship_line_id')->nullable()->index();
            $table->unsignedBigInteger('shipping_mode_id')->nullable()->index();
            $table->string('delivery_label_override')->nullable();
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->string('currency')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('public_tracking')->nullable()->index();
            $table->string('invoice_document_number')->nullable();
            $table->unsignedBigInteger('sender_profile_id')->nullable()->index();
            $table->unsignedBigInteger('recipient_profile_id')->nullable()->index();
            $table->unsignedBigInteger('creator_user_id')->nullable()->index();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('origin_country_id')->nullable()->index();
            $table->unsignedBigInteger('dest_country_id')->nullable()->index();
            $table->string('status', 50)->nullable()->index();
            $table->string('service_flow')->nullable();
            $table->unsignedBigInteger('regroupement_id')->nullable()->index();
            $table->unsignedBigInteger('master_shipment_id')->nullable()->index();
            $table->unsignedBigInteger('pre_alert_id')->nullable()->index();
            $table->unsignedBigInteger('assisted_purchase_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_driver_id')->nullable()->index();
            $table->decimal('weight_kg', 15, 3)->nullable();
            $table->decimal('volumetric_weight_kg', 15, 3)->nullable();
            $table->decimal('length_cm', 15, 2)->nullable();
            $table->decimal('width_cm', 15, 2)->nullable();
            $table->decimal('height_cm', 15, 2)->nullable();
            $table->decimal('declared_value', 15, 2)->nullable();
            $table->decimal('company_coverage_amount', 15, 2)->nullable();
            $table->string('declared_currency')->nullable();
            $table->json('service_options')->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->decimal('calculated_price', 15, 2)->nullable();
            $table->string('currency')->nullable();
            $table->unsignedBigInteger('current_hub_id')->nullable()->index();
            $table->longText('delivery_signature')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->string('delivery_proof_path')->nullable();
            $table->string('payment_status')->nullable();
            $table->decimal('amount_paid', 15, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('signed_form_path')->nullable();
            $table->timestamps();
        });

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('shipment_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('weight_kg', 15, 3)->nullable();
            $table->decimal('length_cm', 15, 2)->nullable();
            $table->decimal('width_cm', 15, 2)->nullable();
            $table->decimal('height_cm', 15, 2)->nullable();
            $table->decimal('value', 15, 2)->nullable();
            $table->unsignedBigInteger('origin_country_id')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('delivery_time_label')->nullable();
            $table->timestamps();
        });

        Schema::create('shipment_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('shipment_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status', 50)->nullable()->index();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('shipment_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('shipment_id')->nullable()->index();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('shipping_modes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->integer('volumetric_divisor')->default(5000);
            $table->string('default_pricing_type')->nullable();
            $table->json('delivery_options')->nullable();
            $table->timestamps();
        });

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('code')->nullable()->index();
            $table->string('iso2')->nullable();
            $table->string('type')->nullable();
            $table->decimal('latitude', 11, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sync_errors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('integration')->nullable();
            $table->string('event_type')->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->longText('stack_trace')->nullable();
            $table->integer('attempt')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->boolean('resolved')->default(false);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();
        });

        Schema::create('transport_companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->json('polygon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('frontend_element_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('frontend_element_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['frontend_element_id', 'permission_id']);
        });

        Schema::create('permission_group_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_group_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['permission_group_id', 'permission_id']);
        });

        Schema::create('role_has_permission_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_group_id');
            $table->primary(['role_id', 'permission_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permission_groups');
        Schema::dropIfExists('permission_group_has_permissions');
        Schema::dropIfExists('frontend_element_has_permissions');
        Schema::dropIfExists('zones');
        Schema::dropIfExists('transport_companies');
        Schema::dropIfExists('sync_errors');
        Schema::dropIfExists('states');
        Schema::dropIfExists('shipping_modes');
        Schema::dropIfExists('shipment_payments');
        Schema::dropIfExists('shipment_logs');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('ship_line_rates');
        Schema::dropIfExists('ship_line_countries');
        Schema::dropIfExists('ship_lines');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('sav_ticket_messages');
        Schema::dropIfExists('sav_tickets');
        Schema::dropIfExists('sav_quick_replies');
        Schema::dropIfExists('regroupements');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('quote_template_lines');
        Schema::dropIfExists('quote_templates');
        Schema::dropIfExists('quote_snapshots');
        Schema::dropIfExists('quote_line_templates');
        Schema::dropIfExists('quote_email_templates');
        Schema::dropIfExists('quote_audit_log');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('pricing_rules');
        Schema::dropIfExists('pre_alert_issue_reports');
        Schema::dropIfExists('pre_alerts');
        Schema::dropIfExists('pickup_failure_reasons');
        Schema::dropIfExists('pickups');
        Schema::dropIfExists('permission_groups');
        Schema::dropIfExists('payment_proofs');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('packaging_types');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('merchants');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('lockers');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('invoice_extra_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('hubs');
        Schema::dropIfExists('frontend_elements');
        Schema::dropIfExists('form_drafts');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('customer_packages');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('billing_extras');
        Schema::dropIfExists('assisted_purchase_payments');
        Schema::dropIfExists('assisted_purchase_items');
        Schema::dropIfExists('assisted_purchases');
        Schema::dropIfExists('article_categories');
        Schema::dropIfExists('agency_payment_coordinates');
        Schema::dropIfExists('agencies');
        Schema::dropIfExists('address_books');
    }
};
