<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use App\Models\TransactionPrescription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard/stats
     *
     * Memberikan data agregat komprehensif untuk Dashboard Analytics di aplikasi Android.
     * Mengembalikan KPI, Tren Pendapatan, Distribusi Follow-Up, Pertumbuhan Pelanggan,
     * Distribusi Jenis Lensa, dan Distribusi Kelainan Refraksi.
     */
    public function stats(Request $request)
    {
        $from = $request->query('from', Carbon::now()->startOfMonth()->toDateString());
        $to = $request->query('to', Carbon::now()->toDateString());

        // Validasi format tanggal sederhana
        try {
            $carbonFrom = Carbon::parse($from)->startOfDay();
            $carbonTo = Carbon::parse($to)->endOfDay();
            if ($carbonFrom->gt($carbonTo)) {
                $temp = $carbonFrom;
                $carbonFrom = $carbonTo;
                $carbonTo = $temp;
                $from = $carbonFrom->toDateString();
                $to = $carbonTo->toDateString();
            }
        } catch (\Throwable $e) {
            $carbonFrom = Carbon::now()->startOfMonth()->startOfDay();
            $carbonTo = Carbon::now()->endOfDay();
            $from = $carbonFrom->toDateString();
            $to = $carbonTo->toDateString();
        }

        // Hitung durasi dan periode pembanding sebelumnya (previous period)
        $diffInDays = $carbonFrom->diffInDays($carbonTo) + 1;
        $prevToCarbon = (clone $carbonFrom)->subDay();
        $prevFromCarbon = (clone $prevToCarbon)->subDays($diffInDays - 1)->startOfDay();
        $prevFrom = $prevFromCarbon->toDateString();
        $prevTo = $prevToCarbon->toDateString();

        // 1. KPI Aggregates
        $totalCustomers = Customer::count();

        // Tanggal awal customer menjadi pelanggan (berdasarkan transaksi pertama atau created_at)
        $firstTrxQuery = DB::table('transactions')
            ->select('customer_id', DB::raw('MIN(transaction_date) as acquisition_date'))
            ->groupBy('customer_id');

        $customersAcquisition = DB::table('customers')
            ->leftJoinSub($firstTrxQuery, 'first_trx', function ($join) {
                $join->on('customers.id', '=', 'first_trx.customer_id');
            })
            ->select(
                'customers.id',
                DB::raw('COALESCE(first_trx.acquisition_date, DATE(customers.created_at)) as join_date')
            );

        $newCustomersPeriod = DB::table(DB::raw("({$customersAcquisition->toSql()}) as cust_acq"))
            ->mergeBindings($customersAcquisition)
            ->whereBetween('join_date', [$from, $to])
            ->count();

        $newCustomersPrev = DB::table(DB::raw("({$customersAcquisition->toSql()}) as cust_acq"))
            ->mergeBindings($customersAcquisition)
            ->whereBetween('join_date', [$prevFrom, $prevTo])
            ->count();

        $revenue = (float) Transaction::whereBetween('transaction_date', [$from, $to])->sum('amount');
        $revenuePrev = (float) Transaction::whereBetween('transaction_date', [$prevFrom, $prevTo])->sum('amount');

        $transactionsCount = Transaction::whereBetween('transaction_date', [$from, $to])->count();
        $transactionsCountPrev = Transaction::whereBetween('transaction_date', [$prevFrom, $prevTo])->count();

        $followupSent = FollowUpSchedule::where('status', FollowUpSchedule::STATUS_SENT)
            ->whereDate('scheduled_date', '>=', $from)
            ->whereDate('scheduled_date', '<=', $to)
            ->count();
        $followupTotal = FollowUpSchedule::whereDate('scheduled_date', '>=', $from)
            ->whereDate('scheduled_date', '<=', $to)
            ->count();
        $deliveryRate = $followupTotal > 0 ? round(($followupSent / $followupTotal) * 100, 1) : 0.0;

        // Reachability: pelanggan dengan nomor HP valid
        // Dihitung langsung di level SQL (menghindari memory bomb Customer::all())
        $completeProfilesCount = Customer::whereNotNull('no_hp')
            ->where('no_hp', '!=', '')
            ->where('no_hp', 'not like', 'BPJS-%')
            ->where(function ($q) {
                $q->where('no_hp', 'like', '08%')
                  ->orWhere('no_hp', 'like', '628%')
                  ->orWhere('no_hp', 'like', '+628%');
            })
            ->count();
        $incompleteProfilesCount = $totalCustomers - $completeProfilesCount;
        $reachabilityRate = $totalCustomers > 0 ? round(($completeProfilesCount / $totalCustomers) * 100, 1) : 0.0;

        $kpi = [
            'total_customers'          => $totalCustomers,
            'new_customers_period'     => $newCustomersPeriod,
            'new_customers_prev'       => $newCustomersPrev,
            'revenue'                  => $revenue,
            'revenue_prev'             => $revenuePrev,
            'transactions_count'       => $transactionsCount,
            'transactions_count_prev'  => $transactionsCountPrev,
            'followup_sent'            => $followupSent,
            'followup_total'           => $followupTotal,
            'delivery_rate'            => $deliveryRate,
            'complete_profiles_count'  => $completeProfilesCount,
            'incomplete_profiles_count'=> $incompleteProfilesCount,
            'reachability_rate'        => $reachabilityRate,
        ];

        // 2. Revenue Trend
        $isSqlite = DB::getDriverName() === 'sqlite';
        $periodFormat = $diffInDays <= 31
            ? ($isSqlite ? "%Y-%m-%d" : "%Y-%m-%d")
            : ($isSqlite ? "%Y-%m" : "%Y-%m");

        $periodExpr = $isSqlite
            ? "strftime('{$periodFormat}', transaction_date)"
            : "DATE_FORMAT(transaction_date, '{$periodFormat}')";

        $bpjsPrefix = config('services.bpjs.invoice_prefix', '01150006L');
        $escapedBpjsPrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $bpjsPrefix);

        $trendRows = DB::table('transactions')
            ->selectRaw("{$periodExpr} as period")
            ->selectRaw("SUM(CASE WHEN invoice_number LIKE '%{$escapedBpjsPrefix}%' THEN amount ELSE 0 END) as bpjs")
            ->selectRaw("SUM(CASE WHEN invoice_number NOT LIKE '%{$escapedBpjsPrefix}%' THEN amount ELSE 0 END) as non_bpjs")
            ->selectRaw("SUM(amount) as total")
            ->whereBetween('transaction_date', [$from, $to])
            ->groupBy('period')
            ->orderBy('period', 'asc')
            ->get();

        $revenueTrend = $trendRows->map(function ($row) {
            return [
                'period'   => (string) $row->period,
                'bpjs'     => (float) $row->bpjs,
                'non_bpjs' => (float) $row->non_bpjs,
                'total'    => (float) $row->total,
            ];
        });

        // 3. Follow-Up Distribution (4 statuses)
        $followupDist = FollowUpSchedule::selectRaw('status, COUNT(*) as count')
            ->whereDate('scheduled_date', '>=', $from)
            ->whereDate('scheduled_date', '<=', $to)
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $followupDistribution = [
            [
                'status' => FollowUpSchedule::STATUS_SENT,
                'label'  => 'Terkirim',
                'count'  => $followupDist[FollowUpSchedule::STATUS_SENT] ?? 0,
            ],
            [
                'status' => FollowUpSchedule::STATUS_PENDING,
                'label'  => 'Menunggu (Pending)',
                'count'  => $followupDist[FollowUpSchedule::STATUS_PENDING] ?? 0,
            ],
            [
                'status' => FollowUpSchedule::STATUS_BLOCKED,
                'label'  => 'Profil Belum Lengkap',
                'count'  => $followupDist[FollowUpSchedule::STATUS_BLOCKED] ?? 0,
            ],
            [
                'status' => FollowUpSchedule::STATUS_FAILED,
                'label'  => 'Gagal Terkirim',
                'count'  => $followupDist[FollowUpSchedule::STATUS_FAILED] ?? 0,
            ],
        ];

        // 4. Customer Growth (Grouped by period)
        $joinDateExpr = $isSqlite
            ? "strftime('{$periodFormat}', join_date)"
            : "DATE_FORMAT(join_date, '{$periodFormat}')";

        $growthRows = DB::table(DB::raw("({$customersAcquisition->toSql()}) as cust_acq"))
            ->mergeBindings($customersAcquisition)
            ->selectRaw("{$joinDateExpr} as period, COUNT(*) as count")
            ->whereBetween('join_date', [$from, $to])
            ->groupBy('period')
            ->orderBy('period', 'asc')
            ->get();

        $customerGrowth = $growthRows->map(fn($row) => [
            'period' => (string) $row->period,
            'count'  => (int) $row->count,
        ]);

        // 5. Lens Distribution (Top lens types)
        $lensDistribution = [];
        $lensCounts = DB::table('transaction_prescriptions')
            ->join('transactions', 'transaction_prescriptions.transaction_id', '=', 'transactions.id')
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->whereNotNull('transaction_prescriptions.lens_type')
            ->where('transaction_prescriptions.lens_type', '!=', '')
            ->selectRaw('transaction_prescriptions.lens_type as type, COUNT(*) as count')
            ->groupBy('type')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        if ($lensCounts->isEmpty()) {
            // Fallback ke tabel customers.jenis_lensa jika belum ada transaksi di period
            $lensCounts = Customer::whereNotNull('jenis_lensa')
                ->where('jenis_lensa', '!=', '')
                ->selectRaw('jenis_lensa as type, COUNT(*) as count')
                ->groupBy('type')
                ->orderByDesc('count')
                ->limit(5)
                ->get();
        }

        $totalLens = $lensCounts->sum('count');
        foreach ($lensCounts as $item) {
            $lensDistribution[] = [
                'type'       => (string) $item->type,
                'count'      => (int) $item->count,
                'percentage' => $totalLens > 0 ? round(($item->count / $totalLens) * 100, 1) : 0.0,
            ];
        }

        // 6. Refraction Distribution (Minus Ringan, Sedang, Tinggi, Plus, Plano)
        $prescriptions = DB::table('transaction_prescriptions')
            ->join('transactions', 'transaction_prescriptions.transaction_id', '=', 'transactions.id')
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->pluck('transaction_prescriptions.sphere');

        if ($prescriptions->isEmpty()) {
            // Fallback ke ukuran_kiri dan ukuran_kanan di customers
            $prescriptions = Customer::pluck('ukuran_kiri')->merge(Customer::pluck('ukuran_kanan'))
                ->map(fn($val) => is_numeric($val) ? (float) $val : 0.0);
        }

        $categories = [
            'Minus Ringan (0 s/d -3.00)'  => 0,
            'Minus Sedang (-3.25 s/d -6)' => 0,
            'Minus Tinggi (< -6.00)'      => 0,
            'Plus / Presbyopia (> 0.00)'  => 0,
            'Plano / Normal (0.00)'       => 0,
        ];

        foreach ($prescriptions as $sph) {
            $val = (float) $sph;
            if ($val == 0.0) {
                $categories['Plano / Normal (0.00)']++;
            } elseif ($val > 0.0) {
                $categories['Plus / Presbyopia (> 0.00)']++;
            } elseif ($val >= -3.00) {
                $categories['Minus Ringan (0 s/d -3.00)']++;
            } elseif ($val >= -6.00) {
                $categories['Minus Sedang (-3.25 s/d -6)']++;
            } else {
                $categories['Minus Tinggi (< -6.00)']++;
            }
        }

        $totalRefraction = array_sum($categories);
        $refractionDistribution = [];
        foreach ($categories as $cat => $cnt) {
            $refractionDistribution[] = [
                'category'   => $cat,
                'count'      => $cnt,
                'percentage' => $totalRefraction > 0 ? round(($cnt / $totalRefraction) * 100, 1) : 0.0,
            ];
        }

        return response()->json([
            'date_range'              => [
                'from'      => $from,
                'to'        => $to,
                'prev_from' => $prevFrom,
                'prev_to'   => $prevTo,
            ],
            'kpi'                     => $kpi,
            'revenue_trend'           => $revenueTrend,
            'followup_distribution'   => $followupDistribution,
            'customer_growth'         => $customerGrowth,
            'lens_distribution'       => $lensDistribution,
            'refraction_distribution' => $refractionDistribution,
        ]);
    }

    /**
     * GET /api/customers/search
     *
     * Pencarian pelanggan berdasarkan tanggal transaksi atau rentang tanggal.
     * Query params:
     * - `date`: string (YYYY-MM-DD) single date
     * - `from` & `to`: string (YYYY-MM-DD) date range
     */
    public function searchByDate(Request $request)
    {
        $date = $request->query('date');
        $from = $request->query('from', $date ?? Carbon::now()->toDateString());
        $to = $request->query('to', $date ?? Carbon::now()->toDateString());

        $customers = Customer::whereHas('transactions', function ($q) use ($from, $to) {
            $q->whereBetween('transaction_date', [$from, $to]);
        })
        ->with(['transactions' => function ($q) use ($from, $to) {
            $q->whereBetween('transaction_date', [$from, $to])
              ->with('prescriptions')
              ->orderBy('transaction_date', 'desc');
        }])
        ->orderBy('nama', 'asc')
        ->get();

        return response()->json([
            'date_range' => [
                'from' => $from,
                'to'   => $to,
            ],
            'total'      => $customers->count(),
            'customers'  => $customers,
        ]);
    }

    /**
     * GET /api/dashboard/export
     *
     * Mengunduh data laporan pelanggan & transaksi dalam format CSV.
     */
    public function exportCsv(Request $request)
    {
        $from = $request->query('from', Carbon::now()->startOfMonth()->toDateString());
        $to = $request->query('to', Carbon::now()->toDateString());

        $customers = Customer::whereHas('transactions', function ($q) use ($from, $to) {
            $q->whereBetween('transaction_date', [$from, $to]);
        })
        ->with(['transactions' => function ($q) use ($from, $to) {
            $q->whereBetween('transaction_date', [$from, $to])
              ->with('prescriptions');
        }])
        ->orderBy('nama', 'asc')
        ->get();

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"crm_optik_laporan_{$from}_{$to}.csv\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $callback = function () use ($customers) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // CSV Header
            fputcsv($file, [
                'No',
                'Nama Pelanggan',
                'No HP / WhatsApp',
                'Status Profil',
                'Tanggal Transaksi',
                'No Invoice',
                'Jumlah Transaksi (Rp)',
                'Status Transaksi',
                'Resep Kanan (OD) Sph/Cyl/Ax',
                'Resep Kiri (OS) Sph/Cyl/Ax',
                'Jenis Lensa',
                'Catatan',
            ]);

            $no = 1;
            foreach ($customers as $customer) {
                $statusProfil = $customer->is_profile_complete ? 'Lengkap' : 'Belum Lengkap (Tanpa HP)';
                $noHp = $customer->no_hp ?? '-';

                foreach ($customer->transactions as $trx) {
                    $od = $trx->prescriptions->firstWhere('eye_side', 'OD');
                    $os = $trx->prescriptions->firstWhere('eye_side', 'OS');

                    $odStr = $od ? "Sph: {$od->sphere}, Cyl: {$od->cylinder}, Ax: {$od->axis}" : "-";
                    $osStr = $os ? "Sph: {$os->sphere}, Cyl: {$os->cylinder}, Ax: {$os->axis}" : "-";
                    $lensType = $od->lens_type ?? $os->lens_type ?? $customer->jenis_lensa ?? '-';

                    fputcsv($file, [
                        $no++,
                        $customer->nama,
                        $noHp,
                        $statusProfil,
                        $trx->transaction_date,
                        $trx->invoice_number,
                        number_format($trx->amount, 0, ',', '.'),
                        $trx->status,
                        $odStr,
                        $osStr,
                        $lensType,
                        $trx->notes ?? '-',
                    ]);
                }
            }

            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }
}
