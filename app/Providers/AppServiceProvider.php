<?php

namespace App\Providers;

use App\Services\Contracts\WhatsAppServiceInterface;
use App\Services\FonnteService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind WhatsApp service interface ke implementasi Fonnte.
        // Jika di masa depan provider diganti (misal WaBlas, Twilio),
        // cukup ganti binding di sini tanpa mengubah caller manapun.
        $this->app->singleton(WhatsAppServiceInterface::class, FonnteService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
