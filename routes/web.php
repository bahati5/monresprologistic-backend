<?php

use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| Only public / non-API routes remain here.
| All authenticated routes are now served via routes/api.php.
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => response()->json(['app' => config('app.name'), 'status' => 'ok']));

// Public tracking page
Route::get('/track/{publicTracking}', [TrackingController::class, 'show'])->name('tracking.show');

// Public newsletter
Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
Route::post('newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');

// PDF downloads (kept on web for browser rendering)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('shipments/{shipment}/pdf/invoice', [PdfController::class, 'shipmentInvoice'])->name('pdf.shipment.invoice');
    Route::get('shipments/{shipment}/pdf/label', [PdfController::class, 'shipmentLabel'])->name('pdf.shipment.label');
    Route::get('shipments/{shipment}/preview/invoice', [PdfController::class, 'previewShipmentInvoice'])->name('pdf.shipment.invoice.preview');
    Route::get('shipments/{shipment}/preview/label', [PdfController::class, 'previewShipmentLabel'])->name('pdf.shipment.label.preview');
    Route::get('shipments/{shipment}/pdf/tracking', [PdfController::class, 'trackingReport'])->name('pdf.shipment.tracking');
    Route::get('packages/{preAlert}/pdf/invoice', [PdfController::class, 'packageInvoice'])->name('pdf.package.invoice');
});
