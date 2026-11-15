<?php

declare(strict_types=1);

namespace App\Providers;

use App\Accounting\AccountingRepo;
use App\Accounting\DocumentRepo;
use App\Accounting\PgDocumentRepo;
use App\Accounting\AssetRepo;
use App\Accounting\AutopostRepo;
use App\Accounting\BankRepo;
use App\Accounting\InventoryRepo;
use App\Accounting\PgAccountingRepo;
use App\Accounting\PgAssetRepo;
use App\Accounting\PgAutopostRepo;
use App\Accounting\PgBankRepo;
use App\Accounting\PgInventoryRepo;
use App\Accounting\PgReportRepo;
use App\Accounting\ReportRepo;
use App\Analyst\AnalystEngine;
use App\Analyst\AnalystPicker;
use App\Analyst\AnalystRepo;
use App\Analyst\AnalystService;
use App\Analyst\CatalogAnalystPicker;
use App\Analyst\GatewayAnalystEngine;
use App\Analyst\PgAnalystRepo;
use App\BillScan\BillFileStore;
use App\BillScan\BillScanEngine;
use App\BillScan\BillScanRepo;
use App\BillScan\BillScanService;
use App\BillScan\CatalogVisionPicker;
use App\BillScan\GatewayBillScanEngine;
use App\BillScan\LocalBillFileStore;
use App\BillScan\PgBillScanRepo;
use App\BillScan\VisionPicker;
use App\Chat\CreditPricing;
use App\Credits\CreditLedger;
use App\Audit\AuditRepo;
use App\Audit\PgAuditRepo;
use App\Payroll\PayrollRepo;
use App\Payroll\PgPayrollRepo;
use App\Vat\PgTaxCalRepo;
use App\Vat\PgTaxRepo;
use App\Vat\PgVatRepo;
use App\Vat\TaxCalRepo;
use App\Vat\TaxRepo;
use App\Vat\VatRepo;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountingRepo::class, PgAccountingRepo::class);
        $this->app->bind(DocumentRepo::class, PgDocumentRepo::class);
        $this->app->bind(ReportRepo::class, PgReportRepo::class);
        $this->app->bind(BankRepo::class, PgBankRepo::class);
        $this->app->bind(AssetRepo::class, PgAssetRepo::class);
        $this->app->bind(InventoryRepo::class, PgInventoryRepo::class);
        $this->app->bind(AutopostRepo::class, PgAutopostRepo::class);
        $this->app->bind(BillScanRepo::class, PgBillScanRepo::class);
        $this->app->bind(VatRepo::class, PgVatRepo::class);
        $this->app->bind(TaxRepo::class, PgTaxRepo::class);
        $this->app->bind(TaxCalRepo::class, PgTaxCalRepo::class);
        $this->app->bind(AuditRepo::class, PgAuditRepo::class);
        $this->app->bind(PayrollRepo::class, PgPayrollRepo::class);
        $this->app->bind(AnalystRepo::class, PgAnalystRepo::class);
        $this->app->bind(AnalystEngine::class, GatewayAnalystEngine::class);
        $this->app->bind(AnalystPicker::class, CatalogAnalystPicker::class);
        $this->app->bind(AnalystService::class, fn ($app) => new AnalystService($app->make(AnalystRepo::class), $app->make(AnalystEngine::class), $app->make(AnalystPicker::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
        $this->app->bind(BillScanEngine::class, GatewayBillScanEngine::class);
        $this->app->bind(BillFileStore::class, LocalBillFileStore::class);
        $this->app->bind(VisionPicker::class, CatalogVisionPicker::class);
        $this->app->bind(BillScanService::class, fn ($app) => new BillScanService($app->make(BillScanRepo::class), $app->make(BillScanEngine::class), $app->make(BillFileStore::class), $app->make(VisionPicker::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('accounting:autopost')->everyTenMinutes()->withoutOverlapping(10);
        });
    }
}
