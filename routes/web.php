<?php

use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| Only public / non-API routes remain here.
| All authenticated routes are now served via routes/api.php.
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => response()->json(['app' => config('app.name'), 'status' => 'ok']));

/*
| Fallback si `php artisan storage:link` n’a pas été exécuté (souvent sous Windows) :
| sert les fichiers du disque public depuis storage/app/public.
| En prod, le serveur web peut continuer à servir public/storage en statique en priorité.
*/
Route::get('/storage/{path}', function (string $path) {
    $path = str_replace('\\', '/', $path);
    if (str_contains($path, '..')) {
        abort(404);
    }
    if ($path === '' || ! Storage::disk('public')->exists($path)) {
        abort(404);
    }

    return Storage::disk('public')->response($path);
})->where('path', '.*');

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
