<?php

declare(strict_types=1);
ob_start();

set_exception_handler(function (Throwable $e) {
    if (ob_get_length()) ob_clean();

    // Deteksi AJAX/JSON request
    $isAjax = isset($_GET['action'])
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo "<pre>";
    echo "ERROR: " . htmlspecialchars($e->getMessage()) . "\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    // Jangan throw untuk warning ringan dari ini_set/library — cukup log
    if (!(error_reporting() & $severity)) {
        return false;
    }
    // Notice & deprecation diabaikan agar tidak break upload
    if ($severity === E_NOTICE || $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED || $severity === E_WARNING) {
        error_log("[PHP $severity] $message in $file:$line");
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
// 1. Tetapkan zona waktu lokal
date_default_timezone_set('Asia/Jakarta');


// 2. Amankan Session Cookie
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);


session_name('performance_dashboard');
session_start();


$baseDir = __DIR__;
$runtimeDir = $baseDir . DIRECTORY_SEPARATOR . 'runtime_cache';
$uploadDir = $runtimeDir . DIRECTORY_SEPARATOR . 'uploads';
$danaCacheFile   = $runtimeDir . DIRECTORY_SEPARATOR . 'dana_cache.json';
$kreditCacheFile = $runtimeDir . DIRECTORY_SEPARATOR . 'kredit_cache.json';
$labarugiCacheFile = $runtimeDir . DIRECTORY_SEPARATOR . 'labarugi_cache.json';
$usersFile = $runtimeDir . DIRECTORY_SEPARATOR . 'users.json';
$activityFile = $runtimeDir . DIRECTORY_SEPARATOR . 'activity.json';
$updateDatesFile = $runtimeDir . DIRECTORY_SEPARATOR . 'update_dates.json';
$gmmDbFile = $runtimeDir . DIRECTORY_SEPARATOR . 'gmm_leaderboard.json';
$marketshareCacheFile = $runtimeDir . DIRECTORY_SEPARATOR . 'marketshare_cache.json';


ensureDirectory($runtimeDir);
bootstrapUserStore($usersFile);


$currentUser = currentUser($usersFile);


if (!isset($_GET['action'])) {
    handleWebRequest($usersFile, $danaCacheFile, $activityFile, $currentUser);
    $currentUser = currentUser($usersFile);
}


if (isset($_GET['action'])) {
    handleAction(
        (string) $_GET['action'],
        $danaCacheFile,
        $kreditCacheFile,
        $labarugiCacheFile,
        $uploadDir,
        $usersFile,
        $activityFile,
        $currentUser,
        $updateDatesFile,
        $gmmDbFile,
        $marketshareCacheFile     // ← TAMBAH
    );
    exit;
}


if ($currentUser !== null) {
    recordActivity($activityFile, $currentUser, isAdmin($currentUser) && (($_GET['page'] ?? 'dashboard') === 'admin') ? 'page_admin' : 'page_dashboard', [
        'page' => (string) ($_GET['page'] ?? 'dashboard'),
    ]);
}


function labarugiWhitelistedSheets(): array
{
    return [
        // ---- Laba Rugi (cumulative YTD per year) ----
        'Revenue',
        'NII',
        'Asset Spread',
        'Liabi Spread',
        'FBI',
        'Cost',
        'OHC',
        'Biaya CKPN',
        'CM',
        'Pendapatan Bersih Asset',
        'Pendapatan Bersih Liabi',
        'Depresiasi',
        'Premi Penjaminan',
        'Net Income',
        'Pendapatan FTP',
        'Biaya FTP',
        'Pendapatan Bunga',
        'Biaya Bunga',
        // ---- Neraca (monthly average) ----
        'AvgBal Kredit',
        'AvgBal DPK',
        'AvgBal CASA',
        // ---- Rasio ----
        'KOL2',
        'NPL',
        'LAR',
        'CoC',
        'YoL',
        'CoF',
        'NIS',
        'CASARATIO',
        'CostEffRatio',
        'FBIRevenueRatio',
        'ProfitMarginRatio',
    ];
}

/** Kategori sheet → kelompok tampilan. */
function labarugiSheetCategory(string $sheet): string
{
    static $map = null;
    if ($map === null) {
        $map = [
            'Revenue' => 'labarugi',
            'NII' => 'labarugi',
            'Asset Spread' => 'labarugi',
            'Liabi Spread' => 'labarugi',
            'FBI' => 'labarugi',
            'Cost' => 'labarugi',
            'OHC' => 'labarugi',
            'Biaya CKPN' => 'labarugi',
            'CM' => 'labarugi',
            'Pendapatan Bersih Asset' => 'labarugi2',
            'Pendapatan Bersih Liabi' => 'labarugi2',
            'Depresiasi' => 'labarugi2',
            'Premi Penjaminan' => 'labarugi2',
            'Net Income' => 'labarugi2',
            'Pendapatan FTP' => 'labarugi2',
            'Biaya FTP' => 'labarugi2',
            'Pendapatan Bunga' => 'labarugi2',
            'Biaya Bunga' => 'labarugi2',
            'AvgBal Kredit' => 'neraca',
            'AvgBal DPK' => 'neraca',
            'AvgBal CASA' => 'neraca',
            'KOL2' => 'rasio',
            'NPL' => 'rasio',
            'LAR' => 'rasio',
            'CoC' => 'rasio',
            'YoL' => 'rasio',
            'CoF' => 'rasio',
            'NIS' => 'rasio',
            'CASARATIO' => 'rasio',
            'CostEffRatio' => 'rasio',
            'FBIRevenueRatio' => 'rasio',
            'ProfitMarginRatio' => 'rasio',
        ];
    }
    return $map[$sheet] ?? 'lainnya';
}

/** Sheet → label tampilan ramah pengguna. */
function labarugiSheetLabel(string $sheet): string
{
    static $map = null;
    if ($map === null) {
        $map = [
            'CM' => 'Contribution Margin',
            'NII' => 'Net Interest Income',
            'FBI' => 'Fee Based Income',
            'OHC' => 'Overhead Cost',
            'Cost' => 'Cost',
            'Biaya CKPN' => 'Beban CKPN',
            'Premi Penjaminan' => 'Premi Penjaminan',
            'Pendapatan Bersih Asset' => 'Pendapatan Bersih Asset',
            'Pendapatan Bersih Liabi' => 'Pendapatan Bersih Liabilitas',
            'AvgBal Kredit' => 'Avg Balance Kredit',
            'AvgBal DPK' => 'Avg Balance DPK',
            'AvgBal CASA' => 'Avg Balance CASA',
            'KOL2' => 'KOL 2',
            'NPL' => 'NPL',
            'LAR' => 'LAR',
            'CoC' => 'Cost of Credit',
            'YoL' => 'Yield of Loan',
            'CoF' => 'Cost of Fund',
            'NIS' => 'Net Interest Spread',
            'CASARATIO' => 'CASA Ratio',
            'CostEffRatio' => 'Cost Efficiency Ratio',
            'FBIRevenueRatio' => 'Fee Based Revenue Ratio',
            'ProfitMarginRatio' => 'Profit Margin Ratio',
        ];
    }
    return $map[$sheet] ?? $sheet;
}

/** Sheet → format tampilan: 'rp' (Rp Juta), 'pct' (persen), 'num' (angka). */
function labarugiSheetFormat(string $sheet): string
{
    if (in_array(labarugiSheetCategory($sheet), ['rasio'], true)) {
        return 'pct';
    }
    return 'rp';
}

/**
 * Sheet → tingkat highlight di tabel ringkasan.
 * 'primary'  = baris kunci (Revenue, CM, Net Income) — bold + bg tegas
 * 'subtotal' = subtotal (NII, Pend Bersih Asset/Liabi) — bg soft
 * 'normal'   = default
 */
function labarugiSheetHighlight(string $sheet): string
{
    static $primary = ['Revenue', 'CM', 'Cost', 'AvgBal DPK', 'CoC', 'CoF', 'YoL'];
    static $subtotal = ['NII', 'FBI', 'OHC', 'AvgBal Kredit', 'KOL2', 'NPL', 'LAR'];

    if (in_array($sheet, $primary, true))  return 'primary';
    if (in_array($sheet, $subtotal, true)) return 'subtotal';
    return 'normal';
}

function labarugiSheetDirection(string $sheet): string
{
    static $negativeMetrics = [
        // Biaya / Cost (P&L)
        'Cost',
        'OHC',
        'Biaya CKPN',
        'Depresiasi',
        'Premi Penjaminan',
        'Biaya FTP',
        'Biaya Bunga',
        // Rasio risiko & efisiensi
        'KOL2',
        'NPL',
        'LAR',
        'CoC',
        'CoF',
        'CostEffRatio',
    ];
    return in_array($sheet, $negativeMetrics, true) ? 'negative' : 'positive';
}

// -----------------------------------------------------------------------------
// PARSER (uses xlsx helpers from the main file)
// -----------------------------------------------------------------------------

function parseLabaRugiWorkbookToCache(string $filePath, string $sourceName): array
{
    set_time_limit(600);
    @ini_set('memory_limit', '1024M');

    $workbook = xlsxReadWorkbook($filePath);
    $whitelist = array_flip(labarugiWhitelistedSheets());

    $cache = [
        'version' => 1,
        'source_file' => $sourceName,
        'stored_file' => basename($filePath),
        'generated_at' => date('c'),
        'meta' => [
            'sheets' => [],
            'branches' => [],
            'months' => [],
            'skipped_sheets' => [],
            'stats' => ['parsed_sheets' => 0, 'records' => 0],
        ],
        'data' => [],
    ];

    $monthSet = [];

    foreach ($workbook['sheets'] as $sheet) {
        $sheetName = trim((string) $sheet['name']);
        if ($sheetName === '' || !isset($whitelist[$sheetName])) {
            $cache['meta']['skipped_sheets'][] = $sheetName;
            continue;
        }

        try {
            $rows = xlsxReadSheetRows($workbook, $sheet);
        } catch (Throwable $e) {
            $cache['meta']['skipped_sheets'][] = $sheetName . ' (read error)';
            continue;
        }

        // Detect header row & date columns
        $header = labarugiDetectHeader($rows);
        if ($header === null) {
            $cache['meta']['skipped_sheets'][] = $sheetName . ' (no header)';
            continue;
        }

        $cache['data'][$sheetName] = [];
        $highestRow = $rows === [] ? 0 : max(array_keys($rows));
        $records = 0;

        for ($r = $header['row'] + 1; $r <= $highestRow; $r++) {
            $kode = cleanCellString(readCellValue($rows, $header['kode_col'], $r, true));
            $nama = cleanCellString(readCellValue($rows, $header['nama_col'], $r, true));
            if ($kode === '' || $nama === '') continue;

            $kelas = $header['kelas_col'] !== null
                ? cleanCellString(readCellValue($rows, $header['kelas_col'], $r, true))
                : '';
            $area = $header['area_col'] !== null
                ? cleanCellString(readCellValue($rows, $header['area_col'], $r, true))
                : '';

            // Register branch in meta (first occurrence wins)
            if (!isset($cache['meta']['branches'][$kode])) {
                $cache['meta']['branches'][$kode] = [
                    'id' => $kode,
                    'name' => $nama,
                    'kelas' => $kelas,
                    'area' => $area,
                ];
            }

            $monthVals = [];
            foreach ($header['date_cols'] as $dateCol) {
                $val = normalizeNumber(readCellValue($rows, $dateCol['col'], $r, false));
                if ($val === null) continue;
                $monthKey = $dateCol['month_key'];
                $monthVals[$monthKey] = $val;
                $monthSet[$monthKey] = true;
                $records++;
            }

            if ($monthVals !== []) {
                $cache['data'][$sheetName][$kode] = $monthVals;
            }
        }

        if ($cache['data'][$sheetName] === []) {
            unset($cache['data'][$sheetName]);
            $cache['meta']['skipped_sheets'][] = $sheetName . ' (empty)';
            continue;
        }

        $cache['meta']['sheets'][] = $sheetName;
        $cache['meta']['stats']['records'] += $records;
        $cache['meta']['stats']['parsed_sheets']++;
        unset($rows);
    }

    if ($cache['meta']['stats']['parsed_sheets'] === 0) {
        throw new RuntimeException('Tidak ada sheet Laba Rugi yang berhasil di-parse. Pastikan file mengandung sheet seperti CM, Revenue, NII, dst.');
    }

    ksort($monthSet, SORT_NATURAL);
    $cache['meta']['months'] = array_keys($monthSet);
    $cache['meta']['stats']['branches'] = count($cache['meta']['branches']);

    return $cache;
}

/**
 * Mendeteksi baris header (Kode/Nama/Kelas/Nama Area + tanggal bulanan).
 */
function labarugiDetectHeader(array $rows): ?array
{
    $highestRow = $rows === [] ? 0 : min(max(array_keys($rows)), 10);

    for ($r = 1; $r <= $highestRow; $r++) {
        if (!isset($rows[$r])) continue;

        $kodeCol = null;
        $namaCol = null;
        $kelasCol = null;
        $areaCol = null;
        $dateCols = [];

        foreach ($rows[$r] as $col => $cell) {
            $val = cleanCellString($cell['value'] ?? null);
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $val));

            if ($kodeCol === null && in_array($normalized, ['kode', 'kodecabang'], true)) {
                $kodeCol = $col;
                continue;
            }
            if ($namaCol === null && in_array($normalized, ['nama', 'namacabang'], true)) {
                $namaCol = $col;
                continue;
            }
            if ($kelasCol === null && in_array($normalized, ['kelas', 'kelascabang'], true)) {
                $kelasCol = $col;
                continue;
            }
            if ($areaCol === null && in_array($normalized, ['namaarea', 'area'], true)) {
                $areaCol = $col;
                continue;
            }

            // Try date
            $dateInfo = parseDateHeader($cell, (int) date('Y'));
            if ($dateInfo !== null) {
                $dateInfo['col'] = $col;
                $dateCols[] = $dateInfo;
            }
        }

        if ($kodeCol !== null && $namaCol !== null && count($dateCols) > 0) {
            return [
                'row' => $r,
                'kode_col' => $kodeCol,
                'nama_col' => $namaCol,
                'kelas_col' => $kelasCol,
                'area_col' => $areaCol,
                'date_cols' => $dateCols,
            ];
        }
    }
    return null;
}

// -----------------------------------------------------------------------------
// UPLOAD / DELETE HANDLERS
// -----------------------------------------------------------------------------

function handleLabaRugiUpload(string $cacheFile, string $uploadDir): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => 'Upload harus menggunakan POST.'], 405);
        return;
    }
    if (!isset($_FILES['excel_file']) || !is_array($_FILES['excel_file'])) {
        jsonResponse(['ok' => false, 'message' => 'File Excel belum dipilih.'], 400);
        return;
    }
    $file = $_FILES['excel_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['ok' => false, 'message' => uploadErrorMessage((int) $file['error'])], 400);
        return;
    }
    // Larger limit for Laba Rugi (workbook ini bisa ~30-50MB)
    if (($file['size'] ?? 0) > 60 * 1024 * 1024) {
        jsonResponse(['ok' => false, 'message' => 'Ukuran file Laba Rugi melebihi batas 60 MB.'], 400);
        return;
    }
    $original = basename((string) ($file['name'] ?? 'labarugi.xlsx'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        jsonResponse(['ok' => false, 'message' => 'Format file harus .xlsx.'], 400);
        return;
    }

    try {
        ensureDirectory($uploadDir);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => 'Gagal membuat folder upload: ' . $e->getMessage()], 500);
        return;
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original) ?: 'labarugi.xlsx';
    $target = $uploadDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_lr_' . bin2hex(random_bytes(4)) . '_' . $safeName;

    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        jsonResponse(['ok' => false, 'message' => 'Gagal menyimpan file upload.'], 500);
        return;
    }

    try {
        $cache = parseLabaRugiWorkbookToCache($target, $original);
        writeCache($cacheFile, $cache);
        jsonResponse([
            'ok' => true,
            'message' => 'Upload dan parsing Laba Rugi selesai.',
            'summary' => [
                'source_file' => $cache['source_file'],
                'sheets' => $cache['meta']['sheets'],
                'branches' => $cache['meta']['stats']['branches'],
                'months' => count($cache['meta']['months']),
                'min_month' => $cache['meta']['months'][0] ?? null,
                'max_month' => end($cache['meta']['months']) ?: null,
                'records' => $cache['meta']['stats']['records'],
            ],
        ]);
    } catch (Throwable $e) {
        @unlink($target);
        jsonResponse([
            'ok' => false,
            'message' => 'Parsing Laba Rugi gagal: ' . $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ], 500);
    }
}

function handleLabaRugiDeleteCache(string $cacheFile): void
{
    if (is_file($cacheFile)) @unlink($cacheFile);
    jsonResponse(['ok' => true, 'message' => 'Cache Laba Rugi berhasil dikosongkan.']);
}

// -----------------------------------------------------------------------------
// META HANDLER
// -----------------------------------------------------------------------------

function handleLabaRugiMeta(string $cacheFile, array $currentUser): void
{
    $cache = loadCache($cacheFile);
    if ($cache === null) {
        jsonResponse([
            'ok' => true,
            'cached' => false,
            'message' => 'Data Laba Rugi belum tersedia. Admin perlu upload file Excel terlebih dahulu.',
        ]);
        return;
    }
    jsonResponse([
        'ok' => true,
        'cached' => true,
        'source_file' => $cache['source_file'] ?? null,
        'generated_at' => $cache['generated_at'] ?? null,
        'sheets' => $cache['meta']['sheets'] ?? [],
        'branches' => array_values($cache['meta']['branches'] ?? []),
        'months' => $cache['meta']['months'] ?? [],
        'stats' => $cache['meta']['stats'] ?? [],
        'user' => publicUser($currentUser),
    ]);
}

// -----------------------------------------------------------------------------
// DATA HANDLER (main endpoint untuk dashboard)
// -----------------------------------------------------------------------------

function handleLabaRugiData(string $cacheFile, string $activityFile, array $currentUser): void
{
    $cache = loadCache($cacheFile);
    if ($cache === null) {
        jsonResponse(['ok' => false, 'message' => 'Data Laba Rugi belum tersedia. Upload dulu.'], 404);
        return;
    }

    // ---- resolve unit (branch) ----
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '' && !isAdmin($currentUser)) {
        $id = (string) ($currentUser['branch_id'] ?? '');
    }
    if ($id === '') {
        // default: regional aggregate (Kode "11") if ada, atau branch pertama
        $branches = $cache['meta']['branches'] ?? [];
        if (isset($branches['11'])) {
            $id = '11';
        } elseif (!empty($branches)) {
            $id = (string) array_key_first($branches);
        }
    }

    $branchMeta = labarugiResolveBranch($cache, $id);
    if ($branchMeta === null) {
        jsonResponse(['ok' => false, 'message' => 'Unit cabang/area tidak ditemukan: ' . $id], 404);
        return;
    }
    $resolvedId = (string) $branchMeta['id'];

    // ---- resolve month ----
    $months = $cache['meta']['months'] ?? [];
    if (empty($months)) {
        jsonResponse(['ok' => false, 'message' => 'Cache tidak memiliki kolom bulan.'], 500);
        return;
    }
    $selectedMonth = trim((string) ($_GET['month'] ?? ''));
    if ($selectedMonth === '' || !in_array($selectedMonth, $months, true)) {
        $selectedMonth = end($months);
    }

    // ---- compute reference months ----
    [$y, $m] = array_map('intval', explode('-', $selectedMonth));
    $prevM = $m - 1;
    $prevY = $y;
    if ($prevM === 0) {
        $prevM = 12;
        $prevY--;
    }
    $mtdRef = sprintf('%04d-%02d', $prevY, $prevM);       // bulan sebelumnya (MtD base)
    $ytdRef = sprintf('%04d-12', $y - 1);                 // Des tahun lalu (YtD base)
    $yoyRef = sprintf('%04d-%02d', $y - 1, $m);           // bulan sama tahun lalu (YoY base)

    // ---- value tree (10 nodes) ----
    $tree = labarugiBuildTree($cache, $resolvedId, $selectedMonth);

    // ---- summary table for all parsed sheets ----
    $tableRows = labarugiBuildTable($cache, $resolvedId, $selectedMonth, $mtdRef, $ytdRef, $yoyRef);

    // ---- chart data: multi-year YoY comparison ----
    // metric param: bisa multi (comma-separated). Default = ['CM']
    $metricsParam = trim((string) ($_GET['metrics'] ?? 'CM'));
    $selectedMetrics = array_filter(array_map('trim', explode(',', $metricsParam)));
    if (empty($selectedMetrics)) $selectedMetrics = ['CM'];

    $chart = labarugiBuildChart($cache, $resolvedId, $selectedMetrics, $months);

    // ---- record activity ----
    recordActivity($activityFile, $currentUser, 'view_labarugi', [
        'id' => $resolvedId,
        'month' => $selectedMonth,
        'metrics' => implode(',', $selectedMetrics),
    ]);

    jsonResponse([
        'ok' => true,
        'group' => 'labarugi',
        'id' => $resolvedId,
        'label' => $branchMeta['name'] . ' (' . $branchMeta['id'] . ')',
        'branch' => $branchMeta,
        'target_month' => $selectedMonth,
        'target_month_label' => labarugiFormatMonthLabel($selectedMonth),
        'ref' => [
            'mtd' => $mtdRef,
            'ytd' => $ytdRef,
            'yoy' => $yoyRef,
            'mtd_label' => labarugiFormatMonthLabel($mtdRef),
            'ytd_label' => labarugiFormatMonthLabel($ytdRef),
            'yoy_label' => labarugiFormatMonthLabel($yoyRef),
        ],
        'available_months' => $months,
        'available_metrics' => $cache['meta']['sheets'] ?? [],
        'available_branches' => array_values($cache['meta']['branches'] ?? []),
        'selected_metrics' => array_values($selectedMetrics),
        'tree' => $tree,
        'table' => $tableRows,
        'chart' => $chart,
        'user' => publicUser($currentUser),
        'source_file' => $cache['source_file'] ?? null,
        'generated_at' => $cache['generated_at'] ?? null,
    ]);
}

// -----------------------------------------------------------------------------
// HELPER LOGIC
// -----------------------------------------------------------------------------

/**
 * Resolve unit cabang/area. Strict match dulu (id atau nama), fallback contains.
 */
function labarugiResolveBranch(array $cache, string $id): ?array
{
    $branches = $cache['meta']['branches'] ?? [];
    if ($id === '') return null;

    // Exact id match
    if (isset($branches[$id])) return $branches[$id];

    // Case-insensitive exact match on id or name
    $needle = strtolower(trim($id));
    foreach ($branches as $b) {
        if (strtolower((string) $b['id']) === $needle || strtolower((string) $b['name']) === $needle) {
            return $b;
        }
    }
    // Contains match
    foreach ($branches as $b) {
        if (str_contains(strtolower((string) $b['id']), $needle) || str_contains(strtolower((string) $b['name']), $needle)) {
            return $b;
        }
    }
    return null;
}

/** Get value untuk satu (sheet, branch, month). */
function labarugiGetValue(array $cache, string $sheet, string $branchId, string $month): ?float
{
    $v = $cache['data'][$sheet][$branchId][$month] ?? null;
    return $v === null ? null : (float) $v;
}

/**
 * Build value tree (10 node + struktur parent-child).
 * CM = Net Income (+) + FBI (+) − OHC (−)
 * Net Income = Pend Bersih Asset (+) + Pend Bersih Liabi (+)
 * Pend Bersih Asset = Asset Spread (+) − Biaya CKPN (−)
 * Pend Bersih Liabi = Liabi Spread (+) − Premi Penjaminan (−)
 */
function labarugiBuildTree(array $cache, string $branchId, string $month): array
{
    $get = static fn(string $sheet) => labarugiGetValue($cache, $sheet, $branchId, $month);

    return [
        // root
        'CM' => [
            'label' => 'CM',
            'full_label' => 'Contribution Margin',
            'value' => $get('CM'),
            'sign' => '+',
            'level' => 0,
        ],
        // level 1
        'NetIncome' => [
            'label' => 'Net Income',
            'full_label' => 'Net Income',
            'value' => $get('Net Income'),
            'sign' => '+',
            'level' => 1,
            'parent' => 'CM',
        ],
        'FBI' => [
            'label' => 'FBI',
            'full_label' => 'Fee Based Income',
            'value' => $get('FBI'),
            'sign' => '+',
            'level' => 1,
            'parent' => 'CM',
            'leaf' => true,
        ],
        'OHC' => [
            'label' => 'OPEX & Depresiasi',
            'full_label' => 'OHC (OPEX + Depresiasi)',
            'value' => $get('OHC'),
            'sign' => '-',
            'level' => 1,
            'parent' => 'CM',
            'leaf' => true,
        ],
        // level 2
        'PendBersihAsset' => [
            'label' => 'Pend. Bersih Asset',
            'full_label' => 'Pendapatan Bersih Asset',
            'value' => $get('Pendapatan Bersih Asset'),
            'sign' => '+',
            'level' => 2,
            'parent' => 'NetIncome',
        ],
        'PendBersihLiabi' => [
            'label' => 'Pend. Bersih Liab',
            'full_label' => 'Pendapatan Bersih Liabilitas',
            'value' => $get('Pendapatan Bersih Liabi'),
            'sign' => '+',
            'level' => 2,
            'parent' => 'NetIncome',
        ],
        // level 3 — Asset
        'AssetSpread' => [
            'label' => 'Asset Spread',
            'full_label' => 'Asset Spread',
            'value' => $get('Asset Spread'),
            'sign' => '+',
            'level' => 3,
            'parent' => 'PendBersihAsset',
            'leaf' => true,
        ],
        'BiayaCKPN' => [
            'label' => 'Beban CKPN',
            'full_label' => 'Biaya CKPN',
            'value' => $get('Biaya CKPN'),
            'sign' => '-',
            'level' => 3,
            'parent' => 'PendBersihAsset',
            'leaf' => true,
        ],
        // level 3 — Liabi
        'LiabiSpread' => [
            'label' => 'Liabi Spread',
            'full_label' => 'Liability Spread',
            'value' => $get('Liabi Spread'),
            'sign' => '+',
            'level' => 3,
            'parent' => 'PendBersihLiabi',
            'leaf' => true,
        ],
        'PremiPenjaminan' => [
            'label' => 'Premi Penjaminan',
            'full_label' => 'Premi Penjaminan LPS',
            'value' => $get('Premi Penjaminan'),
            'sign' => '-',
            'level' => 3,
            'parent' => 'PendBersihLiabi',
            'leaf' => true,
        ],
    ];
}

/**
 * Build summary table untuk semua sheet yang sudah di-parse.
 * Returns array of rows: metric, category, format, yoy_val, ytd_val, mtd_val, today, yoy_pct, yoy_nom.
 */
function labarugiBuildTable(array $cache, string $branchId, string $today, string $mtdRef, string $ytdRef, string $yoyRef): array
{
    $rows = [];
    foreach (($cache['meta']['sheets'] ?? []) as $sheet) {
        $todayVal = labarugiGetValue($cache, $sheet, $branchId, $today);
        $mtdVal = labarugiGetValue($cache, $sheet, $branchId, $mtdRef);
        $ytdVal = labarugiGetValue($cache, $sheet, $branchId, $ytdRef);
        $yoyVal = labarugiGetValue($cache, $sheet, $branchId, $yoyRef);

        $rows[] = [
            'metric' => $sheet,
            'label' => labarugiSheetLabel($sheet),
            'category' => labarugiSheetCategory($sheet),
            'format' => labarugiSheetFormat($sheet),
            'direction' => labarugiSheetDirection($sheet),
            'highlight' => labarugiSheetHighlight($sheet),
            'yoy_value' => $yoyVal,
            'ytd_value' => $ytdVal,
            'mtd_value' => $mtdVal,
            'today' => $todayVal,
            'yoy_pct' => labarugiGrowthPct($todayVal, $yoyVal),
            'yoy_nom' => labarugiGrowthNom($todayVal, $yoyVal),
            'mtd_pct' => labarugiGrowthPct($todayVal, $mtdVal),
            'mtd_nom' => labarugiGrowthNom($todayVal, $mtdVal),
            'ytd_pct' => labarugiGrowthPct($todayVal, $ytdVal),
            'ytd_nom' => labarugiGrowthNom($todayVal, $ytdVal),
        ];
    }
    return $rows;
}

/**
 * Build chart series: comparison-by-year (Jan..Dec per year, multi-series).
 * Each selected metric × each available year → one line series.
 */
function labarugiBuildChart(array $cache, string $branchId, array $metrics, array $allMonths): array
{
    static $MONTHS_LABEL = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    // detect available years
    $years = [];
    foreach ($allMonths as $m) {
        $years[(int) substr($m, 0, 4)] = true;
    }
    ksort($years);
    $years = array_keys($years);

    $series = [];
    foreach ($metrics as $metric) {
        $sheetExists = isset($cache['data'][$metric]);
        if (!$sheetExists) continue;
        $fmt = labarugiSheetFormat($metric);
        foreach ($years as $yr) {
            $row = [];
            $hasData = false;
            for ($mi = 1; $mi <= 12; $mi++) {
                $key = sprintf('%04d-%02d', $yr, $mi);
                $v = $cache['data'][$metric][$branchId][$key] ?? null;
                $row[] = $v === null ? null : (float) $v;
                if ($v !== null) $hasData = true;
            }
            if ($hasData) {
                $series[] = [
                    'name' => labarugiSheetLabel($metric) . ' ' . $yr,
                    'metric' => $metric,
                    'year' => $yr,
                    'format' => $fmt,
                    'category' => labarugiSheetCategory($metric),
                    'data' => $row,
                ];
            }
        }
    }

    return [
        'categories' => $MONTHS_LABEL,
        'series' => $series,
        'years' => $years,
    ];
}

function labarugiGrowthNom(?float $current, ?float $base): ?float
{
    if ($current === null || $base === null) return null;
    return round($current - $base, 6);
}

function labarugiGrowthPct(?float $current, ?float $base): ?float
{
    if ($current === null || $base === null || (float) $base === 0.0) return null;
    return round((($current - $base) / abs($base)) * 100, 2);
}

function labarugiFormatMonthLabel(string $ym): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return $ym;
    static $MONTHS_LABEL = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return ($MONTHS_LABEL[(int) $m[2] - 1] ?? $m[2]) . " '" . substr($m[1], 2, 2);
}

// -----------------------------------------------------------------------------
// HTML / JS EMBED (untuk integrasi langsung di main file)
// -----------------------------------------------------------------------------

/**
 * Mengembalikan markup untuk tab-pane Laba Rugi.
 * Pakai dengan: <?= labarugiHtmlSection() ?> sebagai pengganti div "Under Construction".
 */
function labarugiHtmlSection(): string
{
    ob_start();
?>
    <div id="labarugiWorkspace">

        <!-- ===== Filter Bar ===== -->
        <div class="panel p-3 p-md-4 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="lrEntityInput">Pencarian Unit (Region/Area/Cabang)</label>
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="lrEntityInput" list="lrEntityList" placeholder="Ketik Kode atau Nama Unit..." autocomplete="off">
                        <datalist id="lrEntityList"></datalist>
                        <button class="btn btn-ghost btn-sm" id="lrSearchBtn" type="button"><i class="bi bi-search text-danger"></i></button>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="lrMonthSelect">Bulan Aktif (Today)</label>
                    <select class="form-select form-select-sm" id="lrMonthSelect"></select>
                </div>
                <div class="col-md-5 text-md-end">
                    <div class="small text-secondary" id="lrSourceLabel">-</div>
                    <div class="rajdhani fw-bold" id="lrScopeLabel" style="font-size:1.1rem;line-height:1.2;">-</div>
                </div>
            </div>
        </div>

        <!-- ===== Stat Cards ===== -->
        <div class="row g-2 mb-3" id="lrStatCards">
            <div class="col-6 col-md-3">
                <div class="mini-stat h-100">
                    <div class="stat-icon green"><i class="bi bi-currency-dollar"></i></div>
                    <div class="label">CM (Today)</div>
                    <div class="value" id="lrStatCM">-</div>
                    <div class="sub" id="lrStatCMSub">Bulan aktif</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="mini-stat h-100">
                    <div class="stat-icon blue"><i class="bi bi-arrow-up-right-circle"></i></div>
                    <div class="label">Growth MtD</div>
                    <div class="value" id="lrStatMtD">-</div>
                    <div class="sub" id="lrStatMtDSub">vs bulan sebelumnya</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="mini-stat h-100">
                    <div class="stat-icon amber"><i class="bi bi-calendar-check"></i></div>
                    <div class="label">Growth YtD</div>
                    <div class="value" id="lrStatYtD">-</div>
                    <div class="sub" id="lrStatYtDSub">vs Des tahun lalu</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="mini-stat h-100">
                    <div class="stat-icon purple"><i class="bi bi-calendar2-range-fill"></i></div>
                    <div class="label">Growth YoY</div>
                    <div class="value" id="lrStatYoY">-</div>
                    <div class="sub" id="lrStatYoYSub">vs bulan sama tahun lalu</div>
                </div>
            </div>
        </div>

        <!-- ===== Value Tree (left to right) ===== -->
        <div class="panel p-3 p-md-4 mb-3">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                <div>
                    <div class="form-label mb-0">Value Tree</div>
                    <h5 class="rajdhani fw-bold mb-0">Dekomposisi Contribution Margin</h5>
                    <div class="small text-secondary" id="lrTreeSubtitle">Hover/tap setiap node untuk detail.</div>
                </div>
                <div class="d-flex gap-3 small text-secondary">
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#16a34a;margin-right:4px;"></span>Positif (+)</span>
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#dc2626;margin-right:4px;"></span>Negatif (−)</span>
                </div>
            </div>
            <div id="lrValueTreeContainer" style="overflow-x:auto;">
                <div class="empty-state"><i class="bi bi-diagram-3 fs-1 text-secondary"></i><strong>Pilih unit & bulan untuk menampilkan value tree.</strong></div>
            </div>
        </div>

        <!-- ===== Summary Table ===== -->
        <div class="panel p-3 p-md-4 mb-3">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                <div>
                    <div class="form-label mb-0">Tabel Ringkasan</div>
                    <h5 class="rajdhani fw-bold mb-0">YoY · YtD · MtD · Today</h5>
                    <div class="small text-secondary" id="lrTableSubtitle">Semua sheet Laba Rugi, Neraca, dan Rasio.</div>
                </div>
                <div class="d-flex gap-2 flex-wrap" id="lrTableCatFilter">
                    <button class="group-pill" data-lr-cat="ALL" type="button">Semua</button>
                    <button class="group-pill active" data-lr-cat="labarugi" type="button">Laba Rugi</button>
                    <button class="group-pill" data-lr-cat="neraca" type="button">Neraca</button>
                    <button class="group-pill" data-lr-cat="rasio" type="button">Rasio</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover" id="lrSummaryTable" style="font-size:.82rem;">
                    <thead>
                        <tr style="background:rgba(21,21,30,.95);">
                            <th style="color:#fff;font-weight:700;padding:10px 12px;position:sticky;left:0;z-index:2;background:rgba(21,21,30,.95);min-width:200px;">Metric</th>
                            <th style="color:#94a3b8;font-weight:700;text-align:right;padding:10px 8px;" id="lrTH_YoY">YoY</th>
                            <th style="color:#94a3b8;font-weight:700;text-align:right;padding:10px 8px;" id="lrTH_YtD">YtD</th>
                            <th style="color:#94a3b8;font-weight:700;text-align:right;padding:10px 8px;" id="lrTH_MtD">MtD</th>
                            <th style="color:#60a5fa;font-weight:800;text-align:right;padding:10px 8px;background:rgba(37,99,235,.2);" id="lrTH_Today">Today ✦</th>
                            <th style="color:#fca5a5;font-weight:700;text-align:right;padding:10px 8px;">YoY (%)</th>
                            <th style="color:#fca5a5;font-weight:700;text-align:right;padding:10px 8px;">YoY (#)</th>
                        </tr>
                    </thead>
                    <tbody id="lrSummaryTableBody">
                        <tr>
                            <td colspan="7" class="text-center text-secondary py-4">Memuat data...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ===== Multi-Year Comparison Chart ===== -->
        <div class="panel p-3 p-md-4 mb-3">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                <div>
                    <div class="form-label mb-0">Grafik YoY</div>
                    <h5 class="rajdhani fw-bold mb-0">Perbandingan Bulanan Multi-Tahun</h5>
                    <div class="small text-secondary">Pilih satu atau lebih metric untuk dibandingkan antar tahun (Jan – Des).</div>
                </div>
                <div class="d-flex gap-2 flex-wrap" id="lrChartModeToggle">
                    <button class="group-pill active" data-lr-mode="continuous" type="button" title="Nilai YTD apa adanya">📈 Kumulatif (YTD)</button>
                    <button class="group-pill" data-lr-mode="discontinuous" type="button" title="Selisih bulan ini vs bulan sebelumnya">📊 Per Bulan (MtD)</button>
                </div>
            </div>
            <div class="mb-3">
                <div class="form-label mb-2">Pilih Metric</div>
                <div class="d-flex flex-wrap gap-1" id="lrMetricPills">
                </div>
            </div>
            <div class="chart-shell" style="min-height:430px;">
                <div id="lrChartTarget"></div>
                <div class="empty-state" id="lrChartEmpty" style="display:none;">
                    <i class="bi bi-graph-up-arrow fs-1 text-secondary"></i>
                    <strong>Pilih minimal satu metric untuk menampilkan grafik.</strong>
                </div>
            </div>
        </div>

        <!-- ===== Chart Companion Table ===== -->
        <div class="panel p-3 p-md-4">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                <div>
                    <div class="form-label mb-0">Tabel YoY</div>
                    <h5 class="rajdhani fw-bold mb-0">Detail Bulanan per Metric & Tahun</h5>
                </div>
                <span class="small text-secondary" id="lrChartTableSub">-</span>
            </div>
            <div class="table-responsive">
                <table class="table table-daily table-hover" id="lrChartTable" style="font-size:.72rem;">
                    <thead id="lrChartTableHead"></thead>
                    <tbody id="lrChartTableBody">
                        <tr>
                            <td colspan="13" class="text-center text-secondary py-4">Belum ada metric dipilih.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
<?php
    return ob_get_clean();
}

/**
 * Script block — letakkan di main file sebelum </body>.
 * Pakai dengan: <?= labarugiScript() ?>
 */
function labarugiScript(): string
{
    ob_start();
?>
    <script>
        // ============================================================
        // LABA RUGI MODULE — Frontend
        // ============================================================
        (function() {
            if (!document.getElementById('labarugiWorkspace')) return;

            const apiBase = window.location.pathname;
            const state = {
                meta: null,
                lastData: null,
                entityId: '',
                month: '',
                selectedMetrics: ['CM'],
                tableCatFilter: 'labarugi', // ← ubah ke 'labarugi'
                chartMode: 'continuous',
                chart: null,
            };

            const fmtRp = v => (v === null || v === undefined || isNaN(v)) ? '-' : ((v < 0 ? '-' : '') + 'Rp ' + Math.abs(Math.round(Number(v))).toLocaleString('id-ID') + ' Jt');
            const fmtRpM = v => {
                if (v === null || v === undefined || isNaN(v)) return '-';
                const n = Number(v);
                if (Math.abs(n) < 1) return (n * 1000).toLocaleString('id-ID', {
                    maximumFractionDigits: 1
                }) + ' Jt';
                return n.toLocaleString('id-ID', {
                    maximumFractionDigits: 2
                }) + ' M';
            };
            const fmtPctRaw = v => {
                if (v === null || v === undefined || isNaN(v)) return '-';
                const n = Number(v);
                return (n * 100).toFixed(2) + '%';
            };
            const fmtPctChange = v => {
                if (v === null || v === undefined || isNaN(v)) return '-';
                const n = Number(v);
                return (n >= 0 ? '+' : '') + n.toFixed(2) + '%';
            };
            const fmtNum = v => (v === null || v === undefined || isNaN(v)) ? '-' : Number(v).toLocaleString('id-ID', {
                maximumFractionDigits: 2
            });
            const fmtByKey = (v, fmt) => {
                if (fmt === 'rp') return fmtRpM(v);
                if (fmt === 'pct') return fmtPctRaw(v);
                return fmtNum(v);
            };
            const growthColors = (nominal, direction = 'positive') => {
                if (nominal === null || nominal === undefined) return {
                    col: '#94a3b8',
                    isGood: null
                };
                const n = Number(nominal);
                if (n === 0) return {
                    col: '#64748b',
                    isGood: null
                };
                const isPositive = n > 0;
                const isGood = direction === 'negative' ? !isPositive : isPositive;
                return {
                    col: isGood ? '#15803d' : '#dc2626',
                    isGood
                };
            };
            const fmtGrowth = (nominal, percent, fmt = 'rp', direction = 'positive') => {
                if (nominal === null || nominal === undefined) return '<span style="color:#94a3b8;">-</span>';
                const n = Number(nominal);
                const {
                    col
                } = growthColors(n, direction);
                const sign = n >= 0 ? '+' : '';
                const valStr = fmt === 'pct' ?
                    ((n >= 0 ? '+' : '') + (n * 100).toFixed(2) + ' pts') :
                    (sign + fmtRpM(Math.abs(n)));
                const pctStr = (percent !== null && percent !== undefined) ?
                    `<span style="font-size:.7rem;color:${col};opacity:.8;"> (${sign}${Number(percent).toFixed(1)}%)</span>` :
                    '';
                return `<span style="color:${col};font-weight:800;">${valStr}</span>${pctStr}`;
            };
            const escapeHtmlLR = v => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

            async function apiGetLR(action, params = {}) {
                const q = new URLSearchParams({
                    action,
                    ...params
                });
                const res = await fetch(`${apiBase}?${q}`);
                const payload = await res.json();
                if (!res.ok || !payload.ok) throw new Error(payload.message || 'Request gagal.');
                return payload;
            }

            // ---- Init ----
            async function init() {
                try {
                    const meta = await apiGetLR('labarugi_meta');
                    state.meta = meta;
                    if (!meta.cached) {
                        document.getElementById('labarugiWorkspace').innerHTML =
                            `<div class="panel p-5 text-center"><div class="display-6 mb-3">📥</div>
                        <h4 class="rajdhani fw-bold mb-2">Data Laba Rugi Belum Tersedia</h4>
                        <p class="text-secondary mb-0">${escapeHtmlLR(meta.message || 'Admin perlu upload file Excel Laba Rugi terlebih dahulu.')}</p></div>`;
                        return;
                    }
                    // populate entity datalist
                    const list = document.getElementById('lrEntityList');
                    list.innerHTML = '';
                    (meta.branches || []).forEach(b => {
                        const opt = document.createElement('option');
                        opt.value = b.id;
                        opt.textContent = `${b.name} (${b.id})`;
                        list.appendChild(opt);
                    });
                    // populate month select (latest first)
                    const ms = document.getElementById('lrMonthSelect');
                    ms.innerHTML = '';
                    const monthsRev = [...(meta.months || [])].reverse();
                    monthsRev.forEach(m => {
                        const opt = document.createElement('option');
                        opt.value = m;
                        opt.textContent = m;
                        ms.appendChild(opt);
                    });
                    // default month = latest
                    state.month = monthsRev[0] || '';
                    // default entity from user
                    const u = meta.user || {};
                    state.entityId = u.branch_id || (meta.branches && meta.branches.length ? meta.branches[0].id : '');
                    document.getElementById('lrEntityInput').value = state.entityId;
                    // bind events
                    bindEvents();
                    // first load
                    await loadData();
                } catch (e) {
                    console.error('LabaRugi init failed:', e);
                    document.getElementById('labarugiWorkspace').innerHTML =
                        `<div class="alert alert-danger">Gagal memuat Laba Rugi: ${escapeHtmlLR(e.message)}</div>`;
                }
            }

            function bindEvents() {
                document.getElementById('lrSearchBtn').addEventListener('click', () => {
                    state.entityId = document.getElementById('lrEntityInput').value.trim();
                    loadData();
                });
                document.getElementById('lrEntityInput').addEventListener('keypress', e => {
                    if (e.key === 'Enter') {
                        state.entityId = e.target.value.trim();
                        loadData();
                    }
                });
                document.getElementById('lrMonthSelect').addEventListener('change', e => {
                    state.month = e.target.value;
                    loadData();
                });
                document.querySelectorAll('[data-lr-cat]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('[data-lr-cat]').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        state.tableCatFilter = btn.dataset.lrCat;
                        if (state.lastData) renderTable(state.lastData);
                    });
                });
                document.querySelectorAll('[data-lr-mode]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('[data-lr-mode]').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        state.chartMode = btn.dataset.lrMode;
                        if (state.lastData) {
                            renderChart(state.lastData);
                            renderChartTable(state.lastData);
                        }
                    });
                });
            }

            async function loadData() {
                try {
                    const params = {
                        id: state.entityId,
                        month: state.month,
                        metrics: state.selectedMetrics.join(','),
                    };
                    const data = await apiGetLR('labarugi_data', params);
                    state.lastData = data;
                    state.entityId = data.id;
                    state.month = data.target_month;
                    document.getElementById('lrSourceLabel').textContent =
                        `${data.source_file || ''} · ${(data.generated_at || '').substring(0,10)}`;
                    document.getElementById('lrScopeLabel').textContent = data.label;
                    renderHeader(data);
                    renderTree(data);
                    renderTable(data);
                    renderMetricPills(data);
                    renderChart(data);
                    renderChartTable(data);
                } catch (e) {
                    console.error('LabaRugi loadData failed:', e);
                    alert('Gagal memuat data: ' + e.message);
                }
            }

            // ---- Header stats ----
            function renderHeader(data) {
                const cmRow = (data.table || []).find(r => r.metric === 'CM');
                if (!cmRow) {
                    ['lrStatCM', 'lrStatMtD', 'lrStatYtD', 'lrStatYoY'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.textContent = '-';
                    });
                    return;
                }
                const cmDirection = 'positive'; // CM = makin tinggi makin baik
                document.getElementById('lrStatCM').textContent = fmtRpM(cmRow.today);
                document.getElementById('lrStatCMSub').textContent = `Bulan ${data.target_month_label}`;
                document.getElementById('lrStatMtD').innerHTML = fmtGrowth(cmRow.mtd_nom, cmRow.mtd_pct, 'rp', cmDirection);
                document.getElementById('lrStatMtDSub').textContent = `${data.ref.mtd_label} → ${data.target_month_label}`;
                document.getElementById('lrStatYtD').innerHTML = fmtGrowth(cmRow.ytd_nom, cmRow.ytd_pct, 'rp', cmDirection);
                document.getElementById('lrStatYtDSub').textContent = `${data.ref.ytd_label} → ${data.target_month_label}`;
                document.getElementById('lrStatYoY').innerHTML = fmtGrowth(cmRow.yoy_nom, cmRow.yoy_pct, 'rp', cmDirection);
                document.getElementById('lrStatYoYSub').textContent = `${data.ref.yoy_label} → ${data.target_month_label}`;
            }

            // ---- Value Tree (SVG left-to-right) ----
            function renderTree(data) {
                const t = data.tree || {};
                const table = data.table || [];
                const colorPos = '#16a34a';
                const colorNeg = '#dc2626';
                const colorRoot = '#000000';

                // Helper: cari row table by metric name → return {today, yoy_value, yoy_nom, yoy_pct, format}
                const lookupTable = (metricName) => table.find(r => r.metric === metricName) || null;

                // Map node key → sheet name di table
                const nodeToSheet = {
                    'CM': 'CM',
                    'NetIncome': 'Net Income',
                    'FBI': 'FBI',
                    'OHC': 'OHC',
                    'PendBersihAsset': 'Pendapatan Bersih Asset',
                    'PendBersihLiabi': 'Pendapatan Bersih Liabi',
                    'AssetSpread': 'Asset Spread',
                    'BiayaCKPN': 'Biaya CKPN',
                    'LiabiSpread': 'Liabi Spread',
                    'PremiPenjaminan': 'Premi Penjaminan',
                };

                // Mapping node key → direction (CKPN, OHC, Premi Penjaminan = negative)
                const nodeDirection = {
                    'CM': 'positive',
                    'NetIncome': 'positive',
                    'FBI': 'positive',
                    'OHC': 'negative', // ← biaya
                    'PendBersihAsset': 'positive',
                    'PendBersihLiabi': 'positive',
                    'AssetSpread': 'positive',
                    'BiayaCKPN': 'negative', // ← biaya
                    'LiabiSpread': 'positive',
                    'PremiPenjaminan': 'negative', // ← biaya
                };

                const fmtGrowthCompact = (nom, pct, direction = 'positive') => {
                    if (nom === null || nom === undefined) return '<span style="color:#94a3b8;">-</span>';
                    const n = Number(nom);
                    const {
                        col
                    } = growthColors(n, direction);
                    const sign = n >= 0 ? '+' : '';
                    const pctStr = (pct !== null && pct !== undefined) ?
                        ` (${sign}${Number(pct).toFixed(1)}%)` :
                        '';
                    return `<span style="color:${col};font-weight:800;">${sign}${fmtRpM(Math.abs(n))}${pctStr}</span>`;
                };

                const node = (key, w = 180) => {
                    const n = t[key];
                    if (!n) return '';
                    const isPos = n.sign === '+';
                    const col = key === 'CM' ? colorRoot : (isPos ? colorPos : colorNeg);
                    const bg = key === 'CM' ? 'rgba(0, 19, 225, 0.08)' : (isPos ? 'rgba(22,163,74,.07)' : 'rgba(220,38,38,.07)');
                    const border = key === 'CM' ? 'rgba(0, 225, 225, 0.4)' : (isPos ? 'rgba(22,163,74,.25)' : 'rgba(220,38,38,.25)');

                    // Lookup YoY data from table
                    const sheet = nodeToSheet[key];
                    const tableRow = sheet ? lookupTable(sheet) : null;
                    const yoyVal = tableRow?.yoy_value;
                    const yoyNom = tableRow?.yoy_nom;
                    const yoyPct = tableRow?.yoy_pct;

                    const valStr = fmtRpM(n.value);
                    const yoyValStr = yoyVal !== null && yoyVal !== undefined ? fmtRpM(yoyVal) : '-';
                    const direction = nodeDirection[key] || 'positive';
                    const growthStr = fmtGrowthCompact(yoyNom, yoyPct, direction);

                    return `<div style="background:${bg};border:2px solid ${border};border-radius:12px;padding:10px 12px;min-width:${w}px;text-align:center;flex-shrink:0;box-shadow:0 2px 8px rgba(244, 244, 244, 0.04);">
            <div style="font-size:.62rem;font-weight:800;color:${col};text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">
                ${n.sign} ${escapeHtmlLR(n.label)}
            </div>
            <div style="font-family:'Rajdhani',sans-serif;font-weight:800;font-size:1.05rem;color:#1e293b;line-height:1.1;margin-bottom:5px;">
                ${valStr}
            </div>
            <div style="border-top:1px dashed rgba(200,200,210,.5);padding-top:4px;margin-top:3px;">
                <div style="font-size:.58rem;color:#64748b;font-weight:600;">YoY: ${yoyValStr}</div>
                <div style="font-size:.62rem;margin-top:2px;">${growthStr}</div>
            </div>
        </div>`;
                };

                // Panel kanan: Avg Bal & Rasio
                const sidePanel = () => {
                    const dpk = lookupTable('AvgBal DPK');
                    const kredit = lookupTable('AvgBal Kredit');
                    const yol = lookupTable('YoL');
                    const cof = lookupTable('CoF');

                    const renderMetric = (label, row, fmt, direction = 'positive') => {
                        if (!row) return `<div style="padding:8px 10px;font-size:.7rem;color:#94a3b8;">${label}: -</div>`;
                        const today = row.today;
                        const yoy = row.yoy_value;
                        const nom = row.yoy_nom;
                        const pct = row.yoy_pct;
                        const todayStr = today !== null && today !== undefined ? fmtByKey(today, fmt) : '-';
                        const yoyStr = yoy !== null && yoy !== undefined ? fmtByKey(yoy, fmt) : '-';

                        // Untuk persentase, growth nominal harus ditampilkan beda
                        let growthStr;
                        if (fmt === 'pct' && nom !== null && nom !== undefined) {
                            const n = Number(nom);
                            const {
                                col
                            } = growthColors(n, direction);
                            const sign = n >= 0 ? '+' : '';
                            growthStr = `<span style="color:${col};font-weight:800;">${sign}${(n*100).toFixed(2)} pts</span>`;
                        } else {
                            growthStr = fmtGrowthCompact(nom, pct, direction);
                        }

                        return `<div style="padding:10px 12px;border-bottom:1px solid rgba(229,231,235,.5);">
                <div style="font-size:.62rem;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">${label}</div>
                <div style="font-family:'Rajdhani',sans-serif;font-weight:800;font-size:1rem;color:#1e293b;line-height:1.1;margin-bottom:4px;">${todayStr}</div>
                <div style="font-size:.58rem;color:#64748b;">YoY: ${yoyStr}</div>
                <div style="font-size:.62rem;margin-top:2px;">${growthStr}</div>
            </div>`;
                    };

                    return `<div style="display:flex;flex-direction:column;gap:12px;flex-shrink:0;min-width:200px;">
            <!-- Neraca block -->
            <div style="background:rgba(254,243,199,.4);border:2px solid rgba(217,119,6,.25);border-radius:12px;overflow:hidden;">
                <div style="background:rgba(217,119,6,.15);padding:6px 12px;font-size:.62rem;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:.06em;">📊 Kredit</div>
                ${renderMetric('Avg Bal Kredit', kredit, 'rp', 'positive')}
                ${renderMetric('YoL (Yield of Loan)', yol, 'pct', 'positive')}
            </div>
            <!-- Rasio block -->
            <div style="background:rgba(254,243,199,.4);border:2px solid rgba(217,119,6,.25);border-radius:12px;overflow:hidden;">
                <div style="background:rgba(217,119,6,.15);padding:6px 12px;font-size:.62rem;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:.06em;">📈 Dana</div>
                
                ${renderMetric('Avg Bal DPK', dpk, 'rp', 'positive')}
                ${renderMetric('CoF (Cost of Fund)', cof, 'pct', 'negative')}
            </div>
        </div>`;
                };

                const html = `
    <div style="display:flex;gap:12px;align-items:stretch;padding:8px 4px;min-width:1120px;">
        <!-- Col 0: CM -->
        <div style="display:flex;flex-direction:column;justify-content:center;gap:8px;">
            ${node('CM', 190)}
        </div>
        <!-- Connector -->
        <div style="display:flex;align-items:center;color:#94a3b8;font-size:1.3rem;flex-shrink:0;">→</div>
        <!-- Col 1: FBI / NetIncome / OHC -->
        <div style="display:flex;flex-direction:column;justify-content:space-around;gap:10px;">
            ${node('FBI', 180)}
            ${node('NetIncome', 180)}
            ${node('OHC', 180)}
        </div>
        <!-- Connector -->
        <div style="display:flex;align-items:center;color:#94a3b8;font-size:1.3rem;flex-shrink:0;">→</div>
        <!-- Col 2: PendBersihAsset / PendBersihLiabi -->
        <div style="display:flex;flex-direction:column;justify-content:space-around;gap:34px;padding-top:6px;">
            ${node('PendBersihAsset', 190)}
            ${node('PendBersihLiabi', 190)}
        </div>
        <!-- Connector -->
        <div style="display:flex;align-items:center;color:#94a3b8;font-size:1.3rem;flex-shrink:0;">→</div>
        <!-- Col 3: Leaves -->
        <div style="display:flex;flex-direction:column;justify-content:space-between;gap:8px;">
            ${node('AssetSpread', 170)}
            ${node('BiayaCKPN', 170)}
            <div style="border-top:1px dashed rgba(229,231,235,.7);margin:6px 0;"></div>
            ${node('LiabiSpread', 170)}
            ${node('PremiPenjaminan', 170)}
        </div>
        <!-- Separator -->
        <div style="border-left:2px dashed rgba(200,200,210,.5);margin:0 4px;"></div>
        <!-- Col 4: Side panel (Neraca + Rasio) -->
        ${sidePanel()}
    </div>
    <div style="margin-top:12px;padding:8px 12px;background:rgba(241,245,249,.7);border-radius:8px;font-size:.72rem;color:#475569;">
        <strong>Formula:</strong> CM = (Pend. Bersih Asset + Pend. Bersih Liabi) + FBI − OHC ·
        <strong>Pend. Bersih Asset</strong> = Asset Spread − Beban CKPN ·
        <strong>Pend. Bersih Liabi</strong> = Liabi Spread − Premi Penjaminan ·
        <strong>YoY</strong> = vs ${data.ref?.yoy_label || 'tahun lalu'}
    </div>
    `;

                document.getElementById('lrValueTreeContainer').innerHTML = html;
                document.getElementById('lrTreeSubtitle').textContent = `Per ${data.target_month_label} · ${data.label} · YoY vs ${data.ref?.yoy_label || '-'}`;
            }
            // Map highlight level → style row
            const rowStyles = {
                primary: {
                    // ❌ jangan terlalu kuning
                    bg: 'rgba(212, 175, 55, 0.04)', // lebih subtle
                    bgFirstCol: '#FFFFFF', // clean, biar fokus ke text

                    // ✅ tetap jadi identitas utama
                    border: 'border-left:4px solid #D4AF37;',

                    fw: '800', // 900 terlalu “berat”
                    col: '#111827', // lebih tajam dari abu

                    // ✅ gold jangan terlalu terang
                    labelCol: '#b8962e',

                    accent: '✦ ' // lebih premium dari ✨
                },

                subtotal: {
                    // ✅ samakan tone dengan Today (blue family)
                    bg: 'rgba(37, 99, 235, 0.03)',
                    bgFirstCol: '#FFFFFF',

                    border: 'border-left:3px solid #2563eb;',
                    fw: '700', // turunin dikit biar hierarchy jelas
                    col: '#1e293b',
                    labelCol: '#2563eb',

                    accent: ''
                },

                normal: {
                    // ✅ clean banget (biar napas UI lega)
                    bg: '',
                    bgFirstCol: '#FFFFFF',

                    border: '',
                    fw: '600', // lebih ringan dari sebelumnya
                    col: '#374151', // jangan terlalu gelap biar beda dari primary
                    labelCol: '#374151',

                    accent: ''
                }
            };

            // ---- Summary table ----
            function renderTable(data) {
                const filter = state.tableCatFilter;
                const rows = (data.table || []).filter(r => filter === 'ALL' || r.category === filter);

                // Update header labels
                document.getElementById('lrTH_YoY').innerHTML = `YoY<br><span style="font-size:.62rem;font-weight:600;color:#cbd5e1;">${escapeHtmlLR(data.ref.yoy_label)}</span>`;
                document.getElementById('lrTH_YtD').innerHTML = `YtD<br><span style="font-size:.62rem;font-weight:600;color:#cbd5e1;">${escapeHtmlLR(data.ref.ytd_label)}</span>`;
                document.getElementById('lrTH_MtD').innerHTML = `MtD<br><span style="font-size:.62rem;font-weight:600;color:#cbd5e1;">${escapeHtmlLR(data.ref.mtd_label)}</span>`;
                document.getElementById('lrTH_Today').innerHTML = `Today ✦<br><span style="font-size:.62rem;font-weight:600;color:#bfdbfe;">${escapeHtmlLR(data.target_month_label)}</span>`;

                if (!rows.length) {
                    document.getElementById('lrSummaryTableBody').innerHTML =
                        '<tr><td colspan="7" class="text-center text-secondary py-4">Tidak ada data untuk kategori ini.</td></tr>';
                    return;
                }

                // Group by category for visual separator
                const catLabels = {
                    labarugi: 'LABA RUGI',
                    neraca: 'NERACA (Average)',
                    rasio: 'RASIO',
                    lainnya: 'LAINNYA'
                };
                const customOrder = [
                    // Laba Rugi
                    'Revenue',
                    'NII',
                    'Asset Spread',
                    'Liabi Spread',
                    'FBI',
                    'Cost',
                    'Biaya CKPN',
                    'OHC',
                    'CM',
                    // Neraca
                    'AvgBal Kredit',
                    'AvgBal DPK',
                    'AvgBal CASA',
                    // Rasio
                    'KOL2',
                    'NPL',
                    'LAR',
                    'CoC',
                    'YoL',
                    'CoF',
                    'NIS',
                    'CASARATIO',
                    'CostEffRatio',
                    'FBIRevenueRatio',
                    'ProfitMarginRatio',
                ];

                // Sort rows berdasarkan customOrder
                rows.sort((a, b) => {
                    const ia = customOrder.indexOf(a.metric);
                    const ib = customOrder.indexOf(b.metric);
                    // Yang tidak ada di list, taruh paling akhir
                    const va = ia === -1 ? 9999 : ia;
                    const vb = ib === -1 ? 9999 : ib;
                    return va - vb;
                });
                let html = '';
                let prevCat = null;
                rows.forEach(r => {
                    if (r.category !== prevCat) {
                        html += `<tr><td colspan="7" style="background:rgba(241,245,249,.95);padding:6px 12px;font-weight:800;font-size:.7rem;color:#475569;letter-spacing:.05em;text-transform:uppercase;border-top:2px solid rgba(203,213,225,.6);">${catLabels[r.category] || r.category}</td></tr>`;
                        prevCat = r.category;
                    }
                    const fmt = r.format || 'rp';
                    const st = rowStyles[r.highlight] || rowStyles.normal;

                    html += `<tr style="background:${st.bg};${st.border}">
    <td style="font-weight:${st.fw};color:${st.labelCol};position:sticky;left:0;background:${st.bgFirstCol};z-index:1;">${st.accent}${escapeHtmlLR(r.label || r.metric)}</td>
    <td style="text-align:right;color:#64748b;font-weight:${st.fw==='900'?'700':'400'};">${fmtByKey(r.yoy_value, fmt)}</td>
    <td style="text-align:right;color:#64748b;font-weight:${st.fw==='900'?'700':'400'};">${fmtByKey(r.ytd_value, fmt)}</td>
    <td style="text-align:right;color:#64748b;font-weight:${st.fw==='900'?'700':'400'};">${fmtByKey(r.mtd_value, fmt)}</td>
    <td style="text-align:right;font-weight:${st.fw};color:${st.col};background:rgba(37,99,235,.05);">${fmtByKey(r.today, fmt)}</td>
    <td style="text-align:right;">${fmtGrowthPct(r.yoy_pct, r.direction)}</td>
    <td style="text-align:right;">${fmtGrowthNomCell(r.yoy_nom, fmt, r.direction)}</td>
</tr>`;
                });
                document.getElementById('lrSummaryTableBody').innerHTML = html;
            }


            function fmtGrowthPct(v, direction = 'positive') {
                if (v === null || v === undefined) return '<span style="color:#94a3b8;">-</span>';
                const n = Number(v);
                const {
                    col
                } = growthColors(n, direction);
                return `<span style="color:${col};font-weight:800;">${n>=0?'+':''}${n.toFixed(2)}%</span>`;
            }

            function fmtGrowthNomCell(v, fmt, direction = 'positive') {
                if (v === null || v === undefined) return '<span style="color:#94a3b8;">-</span>';
                const n = Number(v);
                const {
                    col
                } = growthColors(n, direction);
                const valStr = fmt === 'pct' ? ((n >= 0 ? '+' : '') + (n * 100).toFixed(2) + ' pts') : ((n >= 0 ? '+' : '-') + fmtRpM(Math.abs(n)));
                return `<span style="color:${col};font-weight:700;">${valStr}</span>`;
            }

            // ---- Metric pills (checkbox-style) ----
            function renderMetricPills(data) {
                const container = document.getElementById('lrMetricPills');
                const metrics = data.available_metrics || [];
                // Group by category
                const cats = {
                    labarugi: [],
                    neraca: [],
                    rasio: []
                };
                (data.table || []).forEach(r => {
                    if (cats[r.category]) cats[r.category].push({
                        metric: r.metric,
                        label: r.label
                    });
                });
                const catLabels = {
                    labarugi: '💰 Laba Rugi',
                    neraca: '🏦 Neraca',
                    rasio: '📊 Rasio'
                };
                let html = '';
                ['labarugi', 'neraca', 'rasio'].forEach(cat => {
                    if (!cats[cat] || !cats[cat].length) return;
                    html += `<div style="width:100%;font-size:.65rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-top:6px;margin-bottom:3px;">${catLabels[cat]}</div>`;
                    html += '<div class="d-flex flex-wrap gap-1" style="width:100%;margin-bottom:4px;">';
                    cats[cat].forEach(m => {
                        const checked = state.selectedMetrics.includes(m.metric);
                        html += `<button type="button" onclick="window.lrToggleMetric('${escapeHtmlLR(m.metric)}')" style="padding:4px 10px;border-radius:999px;font-size:.72rem;font-weight:700;cursor:pointer;
                        border:1.5px solid ${checked?'#E10600':'rgba(200,200,210,.6)'};
                        background:${checked?'#E10600':'rgba(255,255,255,.8)'};
                        color:${checked?'#fff':'#475569'};white-space:nowrap;">
                        ${checked?'✓ ':''}${escapeHtmlLR(m.label || m.metric)}</button>`;
                    });
                    html += '</div>';
                });
                container.innerHTML = html;
            }

            window.lrToggleMetric = (metric) => {
                const idx = state.selectedMetrics.indexOf(metric);
                if (idx >= 0) state.selectedMetrics.splice(idx, 1);
                else state.selectedMetrics.push(metric);
                if (state.selectedMetrics.length === 0) state.selectedMetrics = ['CM'];
                loadData();
            };

            // Konversi data YTD kumulatif → per-bulan (MtD).
            // Hanya untuk kategori P&L. Selisih ke bulan kosong di-skip (tetap null).
            function transformSeriesData(rawData, category, mode) {
                if (mode !== 'discontinuous') return rawData.slice();
                // Hanya P&L yang kumulatif
                if (category !== 'labarugi' && category !== 'labarugi2') return rawData.slice();

                const out = new Array(rawData.length).fill(null);
                let prev = null;
                for (let i = 0; i < rawData.length; i++) {
                    const v = rawData[i];
                    if (v === null || v === undefined) {
                        // Bulan kosong: jangan bocorkan ke selisih bulan berikutnya
                        prev = null;
                        continue;
                    }
                    if (i === 0 || prev === null) {
                        // Bulan pertama (atau setelah gap): nilainya = nilainya sendiri
                        out[i] = v;
                    } else {
                        out[i] = v - prev;
                    }
                    prev = v;
                }
                return out;
            }

            // ---- Chart ----
            function renderChart(data) {
                const target = document.getElementById('lrChartTarget');
                const empty = document.getElementById('lrChartEmpty');
                if (state.chart) {
                    state.chart.destroy();
                    state.chart = null;
                }
                const series = (data.chart?.series || []).map(s => ({
                    name: s.name,
                    data: transformSeriesData(s.data, s.category, state.chartMode),
                    _format: s.format,
                    _category: s.category,
                    _metric: s.metric, // ← TAMBAH
                    _year: s.year, // ← TAMBAH
                }));
                const fmtDelta = (val, fmt) => {
                    if (val === null || val === undefined) return '-';
                    const sign = val >= 0 ? '+' : '';
                    if (fmt === 'pct') return sign + (val * 100).toFixed(2) + ' pts';
                    return sign + fmtRpM(Math.abs(val));
                };
                if (!series.length) {
                    target.innerHTML = '';
                    empty.style.display = 'flex';
                    return;
                }
                empty.style.display = 'none';

                // Use mixed yaxis when both rp and pct series exist
                const hasPct = series.some(s => s._format === 'pct');
                const hasRp = series.some(s => s._format === 'rp');
                const yaxis = [];
                if (hasRp) {
                    yaxis.push({
                        seriesName: series.find(s => s._format === 'rp').name,
                        title: {
                            text: 'Nominal (M)',
                            style: {
                                color: '#64748b',
                                fontSize: '11px'
                            }
                        },
                        labels: {
                            formatter: v => fmtRpM(v),
                            style: {
                                colors: '#64748b'
                            }
                        },
                    });
                    // bind subsequent rp series to same axis
                    series.forEach((s, i) => {
                        if (i > 0 && s._format === 'rp') {
                            yaxis.push({
                                seriesName: series.find(x => x._format === 'rp').name,
                                show: false
                            });
                        }
                    });
                }
                if (hasPct) {
                    yaxis.push({
                        seriesName: series.find(s => s._format === 'pct').name,
                        opposite: hasRp,
                        title: {
                            text: 'Rasio (%)',
                            style: {
                                color: '#64748b',
                                fontSize: '11px'
                            }
                        },
                        labels: {
                            formatter: v => fmtPctRaw(v),
                            style: {
                                colors: '#64748b'
                            }
                        },
                    });
                }

                // Build year lookup per metric (utk cross-year delta di continuous mode)
                const yearLookup = {};
                series.forEach(s => {
                    if (!yearLookup[s._metric]) yearLookup[s._metric] = {};
                    yearLookup[s._metric][s._year] = s.data;
                });

                const pointAnnotations = [];

                if (state.chartMode === 'continuous') {
                    const byMetric = {};
                    series.forEach((s, idx) => {
                        if (!byMetric[s._metric]) byMetric[s._metric] = [];
                        byMetric[s._metric].push({
                            s,
                            idx
                        });
                    });

                    Object.values(byMetric).forEach(arr => {
                        // Tahun terbaru di atas
                        arr.sort((a, b) => b.s._year - a.s._year);

                        // 🔥 Auto-detect bulan terkini dari tahun terbaru
                        const latestSeries = arr[0].s;
                        let currentIdx = -1;
                        for (let j = latestSeries.data.length - 1; j >= 0; j--) {
                            if (latestSeries.data[j] !== null && latestSeries.data[j] !== undefined) {
                                currentIdx = j;
                                break;
                            }
                        }

                        // Bangun targets dinamis: bulan terkini + Des
                        // Kalau bulan terkini kebetulan Des, skip duplikat
                        const targets = [];
                        if (currentIdx >= 0 && currentIdx !== 11) {
                            targets.push({
                                pIdx: currentIdx,
                                symbol: '🔥'
                            }); // bulan terkini
                        }
                        targets.push({
                            pIdx: 11,
                            symbol: '🎯'
                        }); // Des

                        targets.forEach(({
                            pIdx,
                            symbol
                        }) => {
                            // Anchor marker: tahun terbaru yang punya data di pIdx
                            const anchor = arr.find(({
                                    s
                                }) =>
                                s.data[pIdx] !== null && s.data[pIdx] !== undefined
                            );
                            if (!anchor) return;

                            // Header bulan + baris nilai per tahun (skip yg null)
                            const lines = [
                                `${symbol} ${data.chart.categories[pIdx]} '${String(anchor.s._year).slice(2)}`,
                            ];
                            arr.forEach(({
                                s
                            }) => {
                                const val = s.data[pIdx];
                                if (val !== null && val !== undefined) {
                                    lines.push(`${s._year}: ${fmtByKey(val, s._format)}`);
                                }
                            });

                            pointAnnotations.push({
                                x: data.chart.categories[pIdx],
                                y: anchor.s.data[pIdx],
                                seriesIndex: anchor.idx,
                                marker: {
                                    size: 7,
                                    fillColor: '#fff',
                                    strokeColor: '#E10600',
                                    radius: 2,
                                    strokeWidth: 2
                                },
                                label: {
                                    text: lines,
                                    borderColor: '#e5e7eb',
                                    textAnchor: 'start',
                                    offsetX: -12,
                                    offsetY: 0,
                                    style: {
                                        background: '#fff',
                                        color: '#0f172a',
                                        fontSize: '11px',
                                        fontWeight: 600,
                                        padding: {
                                            left: 10,
                                            right: 10,
                                            top: 6,
                                            bottom: 6
                                        }
                                    }
                                }
                            });
                        });
                    });
                }

                if (state.chartMode === 'discontinuous') {
                    // Per series (tiap metric × tahun): annotate ending + bottom
                    series.forEach((s, sIdx) => {
                        let endIdx = -1,
                            endVal = null;
                        let botIdx = -1,
                            botVal = null;
                        s.data.forEach((v, i) => {
                            if (v === null || v === undefined) return;
                            if (i > endIdx) {
                                endIdx = i;
                                endVal = v;
                            }
                            if (botVal === null || v < botVal) {
                                botVal = v;
                                botIdx = i;
                            }
                        });

                        // Ending
                        if (endIdx >= 0) {
                            pointAnnotations.push({
                                x: data.chart.categories[endIdx],
                                y: endVal,
                                seriesIndex: sIdx,
                                marker: {
                                    size: 5,
                                    fillColor: '#fff',
                                    strokeColor: '#0f172a',
                                    radius: 2,
                                    strokeWidth: 2
                                },
                                label: {
                                    text: [`End '${String(s._year).slice(2)}`, fmtByKey(endVal, s._format)],
                                    borderColor: '#d8dee8',
                                    offsetY: -8,
                                    style: {
                                        background: '#fff',
                                        color: '#0f172a',
                                        fontSize: '9px',
                                        fontWeight: 700,
                                        padding: {
                                            left: 5,
                                            right: 5,
                                            top: 3,
                                            bottom: 3
                                        }
                                    }
                                }
                            });
                        }
                        // Bottom — skip kalau sama dgn ending (mis. series cuma 1 titik)
                        if (botIdx >= 0 && botIdx !== endIdx) {
                            pointAnnotations.push({
                                x: data.chart.categories[botIdx],
                                y: botVal,
                                seriesIndex: sIdx,
                                marker: {
                                    size: 5,
                                    fillColor: '#fff',
                                    strokeColor: '#E10600',
                                    radius: 2,
                                    strokeWidth: 2
                                },
                                label: {
                                    text: [`Bot '${String(s._year).slice(2)}`, fmtByKey(botVal, s._format)],
                                    borderColor: '#fecdd3',
                                    offsetY: 22,
                                    style: {
                                        background: '#fff1f2',
                                        color: '#b91c1c',
                                        fontSize: '9px',
                                        fontWeight: 700,
                                        padding: {
                                            left: 5,
                                            right: 5,
                                            top: 3,
                                            bottom: 3
                                        }
                                    }
                                }
                            });
                        }
                    });
                }

                const colors = ['#E10600', '#0f172a', '#2563eb', '#16a34a', '#f97316', '#7c3aed', '#0891b2', '#db2777', '#ca8a04', '#475569', '#be123c', '#65a30d'];

                const options = {
                    series: series.map(({
                        name,
                        data
                    }) => ({
                        name,
                        data
                    })),
                    chart: {
                        type: 'line',
                        height: 420,
                        toolbar: {
                            show: true,
                            tools: {
                                download: true,
                                pan: false,
                                reset: true,
                                zoom: true,
                                zoomin: true,
                                zoomout: true
                            }
                        },
                        fontFamily: 'Inter, sans-serif',
                        animations: {
                            enabled: true,
                            speed: 600
                        },
                        background: 'transparent',
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 3
                    },
                    colors,
                    markers: {
                        size: 4,
                        hover: {
                            size: 6
                        }
                    },
                    xaxis: {
                        categories: data.chart.categories,
                        labels: {
                            style: {
                                colors: '#64748b',
                                fontWeight: 700
                            }
                        },
                        axisBorder: {
                            show: false
                        },
                        axisTicks: {
                            show: false
                        },
                    },
                    yaxis: yaxis.length ? yaxis : [{
                        labels: {
                            formatter: v => fmtRpM(v)
                        }
                    }],
                    grid: {
                        borderColor: 'rgba(229,231,235,.4)',
                        strokeDashArray: 4
                    },
                    tooltip: {
                        shared: true,
                        intersect: false,
                        y: {
                            formatter: function(val, opts) {
                                const s = series[opts.seriesIndex];
                                return s && s._format === 'pct' ? fmtPctRaw(val) : fmtRpM(val);
                            }
                        },
                    },
                    legend: {
                        position: 'bottom',
                        horizontalAlign: 'center',
                        fontWeight: 700,
                        fontSize: '12px'
                    },
                    annotations: {
                        points: pointAnnotations
                    },
                    dataLabels: {
                        enabled: false
                    },
                };

                state.chart = new ApexCharts(target, options);
                state.chart.render();
            }
            // ---- Chart companion table (Jan-Des per metric per year) ----
            function renderChartTable(data) {
                const rawSeries = data.chart?.series || [];
                const cats = data.chart?.categories || [];

                if (!rawSeries.length) {
                    document.getElementById('lrChartTableHead').innerHTML = '';
                    document.getElementById('lrChartTableBody').innerHTML =
                        '<tr><td colspan="13" class="text-center text-secondary py-4">Belum ada metric dipilih.</td></tr>';
                    document.getElementById('lrChartTableSub').textContent = '-';
                    return;
                }

                // Apply same transformation untuk tabel (continuous/discontinuous)
                const series = rawSeries.map(s => ({
                    ...s,
                    data: transformSeriesData(s.data, s.category, state.chartMode),
                }));

                // Header
                let head = '<tr><th style="text-align:left;min-width:170px;position:sticky;left:0;z-index:2;background:rgba(21,21,30,.95);">Metric / Tahun</th>';
                cats.forEach(c => {
                    head += `<th style="min-width:90px;">${escapeHtmlLR(c)}</th>`;
                });
                head += '</tr>';
                document.getElementById('lrChartTableHead').innerHTML = head;

                // Build lookup: { metricKey: { year: [12 values] } }
                const lookup = {};
                series.forEach(s => {
                    if (!lookup[s.metric]) lookup[s.metric] = {};
                    lookup[s.metric][s.year] = s.data;
                });

                // Helper: format growth % dengan warna (kompak)
                const fmtPctMini = (curr, prev, isPctMetric) => {
                    if (curr === null || curr === undefined || prev === null || prev === undefined) return '';
                    if (isPctMetric) {
                        const diff = (curr - prev) * 100;
                        if (Math.abs(diff) < 0.01) return '';
                        const col = diff >= 0 ? '#15803d' : '#dc2626';
                        const sign = diff >= 0 ? '+' : '';
                        return `<div style="font-size:.6rem;font-weight:700;color:${col};line-height:1;margin-top:1px;">${sign}${diff.toFixed(2)}pt</div>`;
                    }
                    if (prev === 0) return '';
                    const pct = ((curr - prev) / Math.abs(prev)) * 100;
                    if (Math.abs(pct) < 0.05) return '';
                    const col = pct >= 0 ? '#15803d' : '#dc2626';
                    const sign = pct >= 0 ? '+' : '';
                    return `<div style="font-size:.6rem;font-weight:700;color:${col};line-height:1;margin-top:1px;">${sign}${pct.toFixed(1)}%</div>`;
                };

                // Build body
                let body = '';
                let prevMetric = null;

                series.forEach((s, sIdx) => {
                    if (s.metric !== prevMetric) {
                        if (prevMetric !== null) {
                            body += `<tr><td colspan="${cats.length+1}" style="padding:1px 0;background:rgba(226,232,240,.5);"></td></tr>`;
                        }
                        prevMetric = s.metric;
                    }

                    const isPctMetric = s.format === 'pct';
                    const prevYearData = lookup[s.metric]?.[s.year - 1] || null;

                    // Row utama
                    body += `<tr><td style="font-weight:700;color:#1e293b;position:sticky;left:0;background:rgba(255,255,255,.95);z-index:1;padding:6px 10px;">${escapeHtmlLR(s.name)}</td>`;
                    s.data.forEach(v => {
                        body += v !== null ?
                            `<td style="padding:6px 8px;"><div style="font-weight:600;">${fmtByKey(v, s.format)}</div></td>` :
                            `<td style="padding:6px 8px;"><span style="color:#cbd5e1;">-</span></td>`;
                    });
                    body += '</tr>';

                    // Row YoY
                    if (prevYearData) {
                        body += `<tr style="background:rgba(254,243,199,.35);">
                <td style="font-size:.62rem;color:#92400e;font-weight:700;position:sticky;left:0;background:rgba(254,243,199,.85);z-index:1;padding:4px 10px;">↳ % YoY</td>`;
                        s.data.forEach((v, i) => {
                            const prevV = prevYearData[i];
                            if (v === null || prevV === null) {
                                body += `<td style="padding:3px 8px;"><span style="color:#cbd5e1;font-size:.65rem;">-</span></td>`;
                            } else {
                                const growth = fmtPctMini(v, prevV, isPctMetric);
                                body += `<td style="padding:3px 8px;text-align:center;">${growth || '<span style="color:#cbd5e1;font-size:.65rem;">-</span>'}</td>`;
                            }
                        });
                        body += '</tr>';
                    }
                });

                document.getElementById('lrChartTableBody').innerHTML = body;

                // Sub label — set sekali saja, dengan mode
                const modeLabel = state.chartMode === 'discontinuous' ? 'MtD (selisih bulanan)' : 'YTD (kumulatif)';
                document.getElementById('lrChartTableSub').textContent =
                    `${state.selectedMetrics.length} metric × ${data.chart.years.length} tahun · ${cats.length} bulan · Mode: ${modeLabel}`;
            }

            // ---- Boot when Laba Rugi tab is shown ----
            const plTab = document.getElementById('pl-tab-button');
            if (plTab) {
                plTab.addEventListener('shown.bs.tab', () => {
                    if (!state.meta) init();
                });
            }
            // If user lands directly on Laba Rugi tab or it's already visible
            if (document.querySelector('#pl-tab.show.active')) {
                init();
            }
        })();
    </script>
<?php
    return ob_get_clean();
}

function marketshareWhitelistedProducts(): array
{
    return [
        'TABUNGAN',
        'GIRO',
        'DEPOSITO',
        'KREDITRETAIL',
        'SME',
        'KPR',
        'KSM',
        'KUMBLEND',
    ];
}

/** Label tampilan untuk tiap produk. */
function marketshareProductLabel(string $product): string
{
    static $map = [
        'TABUNGAN'      => 'Tabungan',
        'GIRO'          => 'Giro',
        'DEPOSITO'      => 'Deposito',
        'KREDITRETAIL'  => 'Kredit Retail',
        'SME'           => 'SME',
        'KPR'           => 'KPR',
        'KSM'           => 'KSM',
        'KUMBLEND'      => 'KUM Blended',
    ];
    return $map[strtoupper($product)] ?? $product;
}

/** Grup produk untuk tab filter di UI. */
function marketshareProductGroups(): array
{
    return [
        'DPK'    => ['TABUNGAN', 'GIRO', 'DEPOSITO'],
        'CASA'   => ['TABUNGAN', 'GIRO'],
        'KREDIT' => ['KREDITRETAIL', 'SME', 'KPR', 'KSM', 'KUMBLEND'],
    ];
}

// =============================================================================
//  PARSER (memakai helper xlsx dari file utama)
// =============================================================================

/**
 * Parse workbook Market Share menjadi cache JSON.
 * Hanya sheet bermula BMRI_ atau MARKET_ (dengan sufiks dari whitelist) yang
 * diproses. Sheet MS_* diabaikan supaya MS dihitung ulang dari BMRI/MARKET.
 */
function parseMarketShareWorkbookToCache(string $filePath, string $sourceName): array
{
    set_time_limit(600);
    @ini_set('memory_limit', '1024M');

    $workbook  = xlsxReadWorkbook($filePath);
    $whitelist = array_flip(marketshareWhitelistedProducts());

    $cache = [
        'version'      => 1,
        'source_file'  => $sourceName,
        'stored_file'  => basename($filePath),
        'generated_at' => date('c'),
        'meta'         => [
            'products'        => [],
            'product_groups'  => marketshareProductGroups(),
            'branches'        => [],
            'pulau'           => [],
            'provinsi'        => [],
            'area'            => [],
            'kabupaten'       => [],
            'months'          => [],
            'skipped_sheets'  => [],
            'stats'           => ['parsed_sheets' => 0, 'records' => 0],
        ],
        'data'         => [],   // data[SHEETNAME][branchId][YYYY-MM] = value
    ];

    $monthSet      = [];
    $productSet    = [];

    foreach ($workbook['sheets'] as $sheet) {
        $sheetName = trim((string) $sheet['name']);
        if ($sheetName === '') continue;

        $upper = strtoupper($sheetName);

        // Tentukan tipe & produk
        $type = null;
        $product = null;
        if (str_starts_with($upper, 'BMRI_')) {
            $type    = 'BMRI';
            $product = substr($upper, 5);
        } elseif (str_starts_with($upper, 'MARKET_')) {
            $type    = 'MARKET';
            $product = substr($upper, 7);
        } else {
            // MS_* atau sheet lain → skip
            $cache['meta']['skipped_sheets'][] = $sheetName;
            continue;
        }

        if (!isset($whitelist[$product])) {
            $cache['meta']['skipped_sheets'][] = $sheetName . ' (produk tidak dikenal)';
            continue;
        }

        try {
            $rows = xlsxReadSheetRows($workbook, $sheet);
        } catch (Throwable $e) {
            $cache['meta']['skipped_sheets'][] = $sheetName . ' (read error: ' . $e->getMessage() . ')';
            continue;
        }

        $header = marketshareDetectHeader($rows);
        if ($header === null) {
            $cache['meta']['skipped_sheets'][] = $sheetName . ' (header tidak ditemukan)';
            continue;
        }

        $canonicalKey = $type . '_' . $product;   // canonical key in cache
        $cache['data'][$canonicalKey] = [];
        $records     = 0;
        $highestRow  = $rows === [] ? 0 : max(array_keys($rows));

        for ($r = $header['row'] + 1; $r <= $highestRow; $r++) {
            $kode = cleanCellString(readCellValue($rows, $header['kode_col'], $r, true));
            $nama = cleanCellString(readCellValue($rows, $header['nama_col'], $r, true));
            if ($kode === '' || $nama === '') continue;

            // Ambil metadata cabang (sekali, dari sheet pertama yang lihat kode itu)
            $kelas      = $header['kelas_col']     !== null ? cleanCellString(readCellValue($rows, $header['kelas_col'], $r, true))     : '';
            $provinsi   = $header['provinsi_col']  !== null ? cleanCellString(readCellValue($rows, $header['provinsi_col'], $r, true))  : '';
            $pulau      = $header['pulau_col']     !== null ? cleanCellString(readCellValue($rows, $header['pulau_col'], $r, true))     : '';
            $area       = $header['area_col']      !== null ? cleanCellString(readCellValue($rows, $header['area_col'], $r, true))      : '';
            $kabupaten  = $header['kabupaten_col'] !== null ? cleanCellString(readCellValue($rows, $header['kabupaten_col'], $r, true)) : '';
            [$lat, $lng] = $header['latlong_col'] !== null
                ? marketshareParseLatLong(cleanCellString(readCellValue($rows, $header['latlong_col'], $r, true)))
                : [null, null];

            if (!isset($cache['meta']['branches'][$kode])) {
                $cache['meta']['branches'][$kode] = [
                    'id'         => $kode,
                    'name'       => $nama,
                    'kelas'      => $kelas,
                    'provinsi'   => $provinsi,
                    'pulau'      => $pulau,
                    'area'       => $area,
                    'kabupaten'  => $kabupaten,
                    'lat'        => $lat,
                    'lng'        => $lng,
                ];
            } else {
                // Update jika sebelumnya kosong
                $b = &$cache['meta']['branches'][$kode];
                if ($b['name'] === '' && $nama !== '') $b['name'] = $nama;
                if ($b['kelas'] === '' && $kelas !== '') $b['kelas'] = $kelas;
                if ($b['provinsi'] === '' && $provinsi !== '') $b['provinsi'] = $provinsi;
                if ($b['pulau'] === '' && $pulau !== '') $b['pulau'] = $pulau;
                if ($b['area'] === '' && $area !== '') $b['area'] = $area;
                if ($b['kabupaten'] === '' && $kabupaten !== '') $b['kabupaten'] = $kabupaten;
                if (($b['lat'] === null || $b['lng'] === null) && $lat !== null && $lng !== null) {
                    $b['lat'] = $lat;
                    $b['lng'] = $lng;
                }
                unset($b);
            }

            if ($provinsi  !== '') $cache['meta']['provinsi'][$provinsi]   = true;
            if ($pulau     !== '') $cache['meta']['pulau'][$pulau]         = true;
            if ($area      !== '') $cache['meta']['area'][$area]           = true;
            if ($kabupaten !== '') $cache['meta']['kabupaten'][$kabupaten] = true;

            $monthVals = [];
            foreach ($header['date_cols'] as $dateCol) {
                $val = normalizeNumber(readCellValue($rows, $dateCol['col'], $r, false));
                if ($val === null) continue;
                $mk = $dateCol['month_key'];
                $monthVals[$mk] = (float) $val;
                $monthSet[$mk]  = true;
                $records++;
            }

            if ($monthVals !== []) {
                $cache['data'][$canonicalKey][$kode] = $monthVals;
            }
        }

        if ($cache['data'][$canonicalKey] === []) {
            unset($cache['data'][$canonicalKey]);
            $cache['meta']['skipped_sheets'][] = $sheetName . ' (kosong)';
            continue;
        }

        $productSet[$product] = true;
        $cache['meta']['stats']['records'] += $records;
        $cache['meta']['stats']['parsed_sheets']++;
        unset($rows);
    }

    // Hanya produk yang punya BOTH BMRI_* dan MARKET_* yang valid
    $validProducts = [];
    foreach (array_keys($productSet) as $p) {
        if (isset($cache['data']['BMRI_' . $p]) && isset($cache['data']['MARKET_' . $p])) {
            $validProducts[] = $p;
        } else {
            $cache['meta']['skipped_sheets'][] = $p . ' (pasangan BMRI/MARKET tidak lengkap)';
        }
    }

    if ($validProducts === []) {
        throw new RuntimeException('Tidak ada produk valid. Pastikan setiap produk punya pasangan sheet BMRI_<PRODUK> dan MARKET_<PRODUK>.');
    }

    sort($validProducts, SORT_NATURAL);
    $cache['meta']['products'] = $validProducts;

    ksort($monthSet, SORT_NATURAL);
    $cache['meta']['months'] = array_keys($monthSet);

    foreach (['pulau', 'provinsi', 'area', 'kabupaten'] as $key) {
        $list = array_keys($cache['meta'][$key]);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
        $cache['meta'][$key] = $list;
    }

    $cache['meta']['stats']['branches'] = count($cache['meta']['branches']);
    $cache['meta']['stats']['products'] = count($validProducts);

    return $cache;
}

/** Deteksi baris header (cari Kode + Nama + minimal 1 kolom tanggal). */
function marketshareDetectHeader(array $rows): ?array
{
    $highestRow = $rows === [] ? 0 : min(max(array_keys($rows)), 12);

    for ($r = 1; $r <= $highestRow; $r++) {
        if (!isset($rows[$r])) continue;

        $kodeCol      = null;
        $namaCol      = null;
        $kelasCol     = null;
        $provinsiCol  = null;
        $pulauCol     = null;
        $areaCol      = null;
        $kabupatenCol = null;
        $latlongCol   = null;
        $dateCols     = [];

        foreach ($rows[$r] as $col => $cell) {
            $raw  = cleanCellString($cell['value'] ?? null);
            $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $raw));

            if ($kodeCol      === null && in_array($norm, ['kode', 'kodecabang'], true)) {
                $kodeCol = $col;
                continue;
            }
            if ($namaCol      === null && in_array($norm, ['nama', 'namacabang'], true)) {
                $namaCol = $col;
                continue;
            }
            if ($kelasCol     === null && in_array($norm, ['kelas', 'kelascabang'], true)) {
                $kelasCol = $col;
                continue;
            }
            if ($provinsiCol  === null && in_array($norm, ['provinsi', 'namaprovinsi'], true)) {
                $provinsiCol = $col;
                continue;
            }
            if ($pulauCol     === null && in_array($norm, ['pulau', 'namapulau'], true)) {
                $pulauCol = $col;
                continue;
            }
            if ($areaCol      === null && in_array($norm, ['area', 'namaarea'], true)) {
                $areaCol = $col;
                continue;
            }
            if ($kabupatenCol === null && in_array($norm, ['kabupaten', 'namakabupaten', 'kabkota'], true)) {
                $kabupatenCol = $col;
                continue;
            }

            // Tambahan alias agar "Lon_Lat" terbaca
            if ($latlongCol   === null && in_array($norm, [
                'latlong',
                'latlng',
                'koordinat',
                'lokasi',
                'lonlat',
                'longlat',
                'longitudeLatitude',
                'lonlatkoordinat'
            ], true)) {
                $latlongCol = $col;
                continue;
            }

            $dateInfo = parseDateHeader($cell, (int) date('Y'));
            if ($dateInfo !== null) {
                $dateInfo['col'] = $col;
                $dateCols[] = $dateInfo;
            }
        }

        if ($kodeCol !== null && $namaCol !== null && count($dateCols) > 0) {
            return [
                'row'           => $r,
                'kode_col'      => $kodeCol,
                'nama_col'      => $namaCol,
                'kelas_col'     => $kelasCol,
                'provinsi_col'  => $provinsiCol,
                'pulau_col'     => $pulauCol,
                'area_col'      => $areaCol,
                'kabupaten_col' => $kabupatenCol,
                'latlong_col'   => $latlongCol,
                'date_cols'     => $dateCols,
            ];
        }
    }
    return null;
}

/** Parse string "-8.5,115.3" → [-8.5, 115.3] atau [null, null]. */
function marketshareParseLatLong(string $value): array
{
    if ($value === '') return [null, null];
    // Pisahkan dengan koma atau titik koma
    $parts = preg_split('/[,;]\s*/', $value);
    if (!is_array($parts) || count($parts) < 2) return [null, null];
    $lat = filter_var(trim($parts[0]), FILTER_VALIDATE_FLOAT);
    $lng = filter_var(trim($parts[1]), FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false) return [null, null];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return [null, null];
    return [(float) $lat, (float) $lng];
}

// =============================================================================
//  ACCESS SCOPE (RESTRICT VISITOR)
// =============================================================================

/**
 * Tentukan scope cabang yang boleh dilihat user.
 * Return: ['allow_all' => bool, 'branch_id' => ?string, 'area_prefix' => ?string]
 */
function marketshareUserScope(array $currentUser): array
{
    if (isAdmin($currentUser)) {
        return ['allow_all' => true, 'branch_id' => null, 'area_prefix' => null];
    }
    $bid = (string) ($currentUser['branch_id'] ?? '');
    // "11" = Regional Office → boleh semua
    if ($bid === '' || $bid === '11') {
        return ['allow_all' => true, 'branch_id' => null, 'area_prefix' => null];
    }
    // 3 digit = Area code (e.g. "145" untuk Denpasar) → cabang dengan prefix kode tsb
    if (strlen($bid) === 3) {
        return ['allow_all' => false, 'branch_id' => null, 'area_prefix' => $bid];
    }
    // 5+ digit = cabang spesifik
    return ['allow_all' => false, 'branch_id' => $bid, 'area_prefix' => null];
}

/** Filter daftar cabang berdasarkan scope user. */
function marketshareApplyUserScope(array $branches, array $scope): array
{
    if ($scope['allow_all']) return $branches;
    $filtered = [];
    foreach ($branches as $id => $b) {
        $idStr = (string) $id;
        if ($scope['branch_id']   !== null && $idStr === $scope['branch_id'])                  $filtered[$id] = $b;
        elseif ($scope['area_prefix'] !== null && str_starts_with($idStr, $scope['area_prefix'])) $filtered[$id] = $b;
    }
    return $filtered;
}

// =============================================================================
//  AGGREGATION
// =============================================================================

/**
 * Resolve daftar branch IDs yang lolos filter UI.
 * $filter = ['pulau' => [...], 'provinsi' => [...], 'area' => [...],
 *            'kabupaten' => [...], 'cabang' => [...]]
 * Filter kosong / null = tidak diaplikasikan untuk level itu.
 */
function marketshareResolveBranches(array $cache, array $filter, array $userScope): array
{
    $all = marketshareApplyUserScope($cache['meta']['branches'] ?? [], $userScope);

    $selected = [];
    foreach ($all as $id => $b) {
        if (!empty($filter['pulau'])     && !in_array($b['pulau'],     $filter['pulau'],     true)) continue;
        if (!empty($filter['provinsi'])  && !in_array($b['provinsi'],  $filter['provinsi'],  true)) continue;
        if (!empty($filter['area'])      && !in_array($b['area'],      $filter['area'],      true)) continue;
        if (!empty($filter['kabupaten']) && !in_array($b['kabupaten'], $filter['kabupaten'], true)) continue;
        if (!empty($filter['cabang'])    && !in_array($b['id'],        $filter['cabang'],    true)) continue;
        $selected[$id] = $b;
    }
    return $selected;
}

/**
 * Sum nilai untuk daftar cabang + 1 produk + 1 tipe (BMRI/MARKET) + 1 bulan.
 * Return null jika TIDAK ADA satupun cabang yang punya data (untuk hindari
 * salah menampilkan 0 sebagai "data".
 */
function marketshareSum(array $cache, array $branchIds, string $product, string $type, string $month): ?float
{
    $key = strtoupper($type) . '_' . strtoupper($product);
    if (!isset($cache['data'][$key])) return null;

    $sheet     = $cache['data'][$key];
    $typeUpper = strtoupper($type);

    // MARKET: angka industri ditulis ulang per cabang dalam 1 kabupaten.
    // Dedup -> ambil 1 nilai per kabupaten, lalu jumlahkan antar kabupaten.
    if ($typeUpper === 'MARKET') {
        $branchesMeta = $cache['meta']['branches'] ?? [];
        $perKab = [];
        $found  = false;
        foreach ($branchIds as $id) {
            $idStr = (string) $id;
            if (!isset($sheet[$idStr][$month])) continue;
            $val   = (float) $sheet[$idStr][$month];
            $found = true;
            $meta  = $branchesMeta[$idStr] ?? [];
            $kab   = trim((string) ($meta['kabupaten'] ?? ''));
            $prov  = trim((string) ($meta['provinsi']  ?? ''));
            $gk    = $kab !== '' ? 'KAB::' . $prov . '::' . $kab : 'BR::' . $idStr;
            if (!isset($perKab[$gk]) || $val > $perKab[$gk]) $perKab[$gk] = $val;
        }
        return $found ? array_sum($perKab) : null;
    }

    // BMRI: nilai milik tiap cabang -> SUM biasa.
    $sum = 0.0;
    $found = false;
    foreach ($branchIds as $id) {
        $idStr = (string) $id;
        if (isset($sheet[$idStr][$month])) {
            $sum += (float) $sheet[$idStr][$month];
            $found = true;
        }
    }
    return $found ? $sum : null;
}

/** MS% = BMRI / MARKET × 100. Null-safe & zero-safe. */
function marketshareRatio(?float $bmri, ?float $market): ?float
{
    if ($bmri === null || $market === null) return null;
    if (abs($market) < 1e-9) return null;
    return ($bmri / $market) * 100.0;
}

/** YoY persen = (curr - base) / |base| × 100. */
function marketshareYoyPct(?float $curr, ?float $base): ?float
{
    if ($curr === null || $base === null) return null;
    if (abs($base) < 1e-9) return null;
    return (($curr - $base) / abs($base)) * 100.0;
}

/** YoY MS dalam point = curr_ms - base_ms (bukan persentase, tapi selisih). */
function marketshareYoyPoint(?float $currMs, ?float $baseMs): ?float
{
    if ($currMs === null || $baseMs === null) return null;
    return $currMs - $baseMs;
}

/** Format bulan YYYY-MM untuk header / label. */
function marketshareFormatMonthLabel(string $ym): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) return $ym;
    static $MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return ($MONTHS[(int) $m[2] - 1] ?? $m[2]) . " '" . substr($m[1], 2, 2);
}

/** Hitung referensi bulan YoY (tahun sebelumnya, bulan sama). */
function marketshareYoyRef(string $month): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) return $month;
    return sprintf('%04d-%02d', ((int) $m[1]) - 1, (int) $m[2]);
}

// =============================================================================
//  HANDLERS — UPLOAD / DELETE / META / DATA
// =============================================================================

function handleMarketShareUpload(string $cacheFile, string $uploadDir): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => 'Upload harus menggunakan POST.'], 405);
        return;
    }
    if (!isset($_FILES['excel_file']) || !is_array($_FILES['excel_file'])) {
        jsonResponse(['ok' => false, 'message' => 'File Excel belum dipilih.'], 400);
        return;
    }
    $file = $_FILES['excel_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['ok' => false, 'message' => uploadErrorMessage((int) $file['error'])], 400);
        return;
    }
    if (($file['size'] ?? 0) > 60 * 1024 * 1024) {
        jsonResponse(['ok' => false, 'message' => 'Ukuran file melebihi batas 60 MB.'], 400);
        return;
    }
    $original = basename((string) ($file['name'] ?? 'marketshare.xlsx'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        jsonResponse(['ok' => false, 'message' => 'Format file harus .xlsx.'], 400);
        return;
    }

    try {
        ensureDirectory($uploadDir);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => 'Gagal membuat folder upload: ' . $e->getMessage()], 500);
        return;
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original) ?: 'marketshare.xlsx';
    $target = $uploadDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_ms_' . bin2hex(random_bytes(4)) . '_' . $safeName;

    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        jsonResponse(['ok' => false, 'message' => 'Gagal menyimpan file upload.'], 500);
        return;
    }

    try {
        $cache = parseMarketShareWorkbookToCache($target, $original);
        writeCache($cacheFile, $cache);
        jsonResponse([
            'ok'      => true,
            'message' => 'Upload dan parsing Market Share selesai.',
            'summary' => [
                'source_file' => $cache['source_file'],
                'products'    => $cache['meta']['products'],
                'branches'    => $cache['meta']['stats']['branches'],
                'months'      => count($cache['meta']['months']),
                'min_month'   => $cache['meta']['months'][0]                        ?? null,
                'max_month'   => end($cache['meta']['months'])                      ?: null,
                'records'     => $cache['meta']['stats']['records'],
                'pulau'       => count($cache['meta']['pulau']),
                'provinsi'    => count($cache['meta']['provinsi']),
                'area'        => count($cache['meta']['area']),
                'kabupaten'   => count($cache['meta']['kabupaten']),
            ],
        ]);
    } catch (Throwable $e) {
        @unlink($target);
        jsonResponse([
            'ok'      => false,
            'message' => 'Parsing Market Share gagal: ' . $e->getMessage(),
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
        ], 500);
    }
}

function handleMarketShareDeleteCache(string $cacheFile): void
{
    if (is_file($cacheFile)) @unlink($cacheFile);
    jsonResponse(['ok' => true, 'message' => 'Cache Market Share berhasil dikosongkan.']);
}

function handleMarketShareMeta(string $cacheFile, array $currentUser): void
{
    $cache = loadCache($cacheFile);
    if ($cache === null) {
        jsonResponse([
            'ok'      => true,
            'cached'  => false,
            'message' => 'Data Market Share belum tersedia. Admin perlu upload file Excel terlebih dahulu.',
            'user'    => publicUser($currentUser),
        ]);
        return;
    }

    $userScope = marketshareUserScope($currentUser);
    $visibleBranches = marketshareApplyUserScope($cache['meta']['branches'] ?? [], $userScope);

    // Hanya ekspos pulau/provinsi/area/kabupaten yang relevan dengan scope user
    $pulau = $provinsi = $area = $kabupaten = [];
    foreach ($visibleBranches as $b) {
        if ($b['pulau']     !== '') $pulau[$b['pulau']]         = true;
        if ($b['provinsi']  !== '') $provinsi[$b['provinsi']]   = true;
        if ($b['area']      !== '') $area[$b['area']]           = true;
        if ($b['kabupaten'] !== '') $kabupaten[$b['kabupaten']] = true;
    }
    $sortNat = static function (array $a): array {
        sort($a, SORT_NATURAL | SORT_FLAG_CASE);
        return $a;
    };

    jsonResponse([
        'ok'             => true,
        'cached'         => true,
        'source_file'    => $cache['source_file']  ?? null,
        'generated_at'   => $cache['generated_at'] ?? null,
        'products'       => $cache['meta']['products']       ?? [],
        'product_groups' => $cache['meta']['product_groups'] ?? marketshareProductGroups(),
        'product_labels' => array_combine(
            $cache['meta']['products'] ?? [],
            array_map('marketshareProductLabel', $cache['meta']['products'] ?? [])
        ),
        'months'         => $cache['meta']['months'] ?? [],
        'branches'       => array_values($visibleBranches),
        'pulau'          => $sortNat(array_keys($pulau)),
        'provinsi'       => $sortNat(array_keys($provinsi)),
        'area'           => $sortNat(array_keys($area)),
        'kabupaten'      => $sortNat(array_keys($kabupaten)),
        'stats'          => $cache['meta']['stats'] ?? [],
        'user'           => publicUser($currentUser),
        'access_scope'   => $userScope,
    ]);
}

/**
 * Build dataset peta untuk level tertentu.
 * Supported levels: pulau, provinsi, area, kabupaten, cabang
 */
function marketshareBuildMapAggregation(array $cache, array $branches, array $products, string $month, string $level, ?string $yoyMonth = null): array
{
    $supported = ['pulau', 'provinsi', 'area', 'kabupaten', 'cabang'];
    if (!in_array($level, $supported, true)) return [];

    $groups = [];
    foreach ($branches as $b) {
        $groupValue = $level === 'cabang'
            ? (($b['id'] ?? '') !== '' ? (string) $b['id'] : '(Tanpa Cabang)')
            : (($b[$level] ?? '') !== '' ? (string) $b[$level] : '(Tanpa ' . ucfirst($level) . ')');

        if (!isset($groups[$groupValue])) {
            $groups[$groupValue] = [
                'name'         => $groupValue,
                'pulau'        => (string) ($b['pulau'] ?? ''),
                'provinsi'     => (string) ($b['provinsi'] ?? ''),
                'area'         => (string) ($b['area'] ?? ''),
                'kabupaten'    => (string) ($b['kabupaten'] ?? ''),
                'cabang'       => (string) ($b['id'] ?? ''),
                'cabang_name'  => (string) ($b['name'] ?? ''),
                'kelas'        => (string) ($b['kelas'] ?? ''),
                'branch_count' => 0,
                'branches'     => [],
                'lat_acc'      => 0.0,
                'lng_acc' => 0.0,
                'coord_cnt' => 0,
            ];
        }
        $groups[$groupValue]['branches'][] = $b['id'];
        $groups[$groupValue]['branch_count']++;
        if (($b['lat'] ?? null) !== null && ($b['lng'] ?? null) !== null) {
            $groups[$groupValue]['lat_acc'] += (float) $b['lat'];
            $groups[$groupValue]['lng_acc'] += (float) $b['lng'];
            $groups[$groupValue]['coord_cnt']++;
        }
    }

    $rows = [];
    foreach ($groups as $groupValue => $info) {
        $ids = $info['branches'];

        $mSum = 0.0;
        $mHas = false;
        $bSum = 0.0;
        $bHas = false;
        $myS  = 0.0;
        $myH  = false;
        $byS  = 0.0;
        $byH  = false;

        foreach ($products as $p) {
            $mv = marketshareSum($cache, $ids, $p, 'MARKET', $month);
            $bv = marketshareSum($cache, $ids, $p, 'BMRI',   $month);
            if ($mv !== null) {
                $mSum += $mv;
                $mHas = true;
            }
            if ($bv !== null) {
                $bSum += $bv;
                $bHas = true;
            }
            if ($yoyMonth !== null) {
                $myv = marketshareSum($cache, $ids, $p, 'MARKET', $yoyMonth);
                $byv = marketshareSum($cache, $ids, $p, 'BMRI',   $yoyMonth);
                if ($myv !== null) {
                    $myS += $myv;
                    $myH = true;
                }
                if ($byv !== null) {
                    $byS += $byv;
                    $byH = true;
                }
            }
        }

        $market  = $mHas ? $mSum : null;
        $bmri    = $bHas ? $bSum : null;
        $marketY = $myH  ? $myS  : null;
        $bmriY   = $byH  ? $byS  : null;
        $ms      = marketshareRatio($bmri, $market);
        $msY     = marketshareRatio($bmriY, $marketY);

        $rows[] = [
            'mode'           => $level,
            'name'           => $groupValue,
            'pulau'          => $info['pulau'],
            'provinsi'       => $info['provinsi'],
            'area'           => $info['area'],
            'kabupaten'      => $info['kabupaten'],
            'cabang'         => $info['cabang'],
            'cabang_name'    => $info['cabang_name'],
            'kelas'          => $info['kelas'],
            'branch_count'   => $info['branch_count'],
            'lat'            => $info['coord_cnt'] > 0 ? $info['lat_acc'] / $info['coord_cnt'] : null,
            'lng'            => $info['coord_cnt'] > 0 ? $info['lng_acc'] / $info['coord_cnt'] : null,
            'market'         => $market,
            'bmri'           => $bmri,
            'market_share'   => $ms,
            'market_yoy_pct' => marketshareYoyPct($market, $marketY),
            'bmri_yoy_pct'   => marketshareYoyPct($bmri,   $bmriY),
            'ms_yoy_point'   => marketshareYoyPoint($ms,    $msY),
        ];
    }

    usort($rows, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $rows;
}

function marketshareBuildTrend(array $cache, array $branchIds, array $products, array $allMonths, string $currentMonth, int $window = 6): array
{
    $idx = array_search($currentMonth, $allMonths, true);
    if ($idx === false) $idx = count($allMonths) - 1;

    // Perpanjang window agar mencakup data tahun sebelumnya (untuk YoY chart).
    // Range = Jan tahun (currentYear-1) sampai currentMonth.
    if (preg_match('/^(\d{4})-(\d{2})$/', $currentMonth, $m)) {
        $curYear  = (int) $m[1];
        $startYM  = sprintf('%04d-01', $curYear - 1);
        $startIdx = array_search($startYM, $allMonths, true);
        if ($startIdx === false) {
            // Kalau Jan tahun lalu tidak ada di dataset, ambil bulan paling awal yang tersedia
            $startIdx = 0;
            foreach ($allMonths as $i => $mk) {
                if ($mk >= $startYM) {
                    $startIdx = $i;
                    break;
                }
            }
        }
        $start = min($startIdx, $idx);
    } else {
        $start = max(0, $idx - $window + 1);
    }

    $slice = array_slice($allMonths, $start, $idx - $start + 1);

    $out = [];
    foreach ($slice as $m) {
        $market = 0.0;
        $hasM = false;
        $bmri   = 0.0;
        $hasB = false;
        foreach ($products as $p) {
            $mv = marketshareSum($cache, $branchIds, $p, 'MARKET', $m);
            $bv = marketshareSum($cache, $branchIds, $p, 'BMRI',   $m);
            if ($mv !== null) {
                $market += $mv;
                $hasM = true;
            }
            if ($bv !== null) {
                $bmri   += $bv;
                $hasB = true;
            }
        }
        $mVal = $hasM ? $market : null;
        $bVal = $hasB ? $bmri   : null;
        $out[] = [
            'month'  => $m,
            'label'  => marketshareFormatMonthLabel($m),
            'market' => $mVal,
            'bmri'   => $bVal,
            'ms'     => marketshareRatio($bVal, $mVal),
        ];
    }
    return $out;
}

/**
 * Hitung ranking dinamis sesuai level filter terdalam yang aktif.
 * - kabupaten selected → rank antar kabupaten dalam scope di atasnya
 * - area selected      → rank antar area dalam scope di atasnya
 * - provinsi selected  → rank antar provinsi dalam scope di atasnya
 * - pulau selected     → rank antar pulau (global user scope)
 * - tidak ada filter   → null
 */
function marketshareComputeDynamicRank(array $cache, array $userScope, array $filter, array $products, string $month): ?array
{
    // Cari level terdalam yang punya tepat 1 nilai filter
    $levelChain = ['kabupaten', 'area', 'provinsi', 'pulau'];
    $targetLevel = null;
    $targetValue = null;
    foreach ($levelChain as $lv) {
        if (!empty($filter[$lv]) && count($filter[$lv]) === 1) {
            $targetLevel = $lv;
            $targetValue = (string) $filter[$lv][0];
            break;
        }
    }
    if ($targetLevel === null) return null;

    // Parent scope = filter di atas target (kecuali target itu sendiri & cabang)
    $parentFilter = $filter;
    $parentFilter[$targetLevel] = [];
    $parentFilter['cabang']     = [];

    $allBranches = marketshareApplyUserScope($cache['meta']['branches'] ?? [], $userScope);
    $scopedBranches = [];
    foreach ($allBranches as $id => $b) {
        if (!empty($parentFilter['pulau'])     && !in_array($b['pulau'],     $parentFilter['pulau'],     true)) continue;
        if (!empty($parentFilter['provinsi'])  && !in_array($b['provinsi'],  $parentFilter['provinsi'],  true)) continue;
        if (!empty($parentFilter['area'])      && !in_array($b['area'],      $parentFilter['area'],      true)) continue;
        if (!empty($parentFilter['kabupaten']) && !in_array($b['kabupaten'], $parentFilter['kabupaten'], true)) continue;
        $scopedBranches[$id] = $b;
    }

    $rows = marketshareBuildMapAggregation($cache, $scopedBranches, $products, $month, $targetLevel, null);
    $ranked = array_values(array_filter($rows, static fn($r) => $r['market_share'] !== null));
    usort($ranked, static fn($a, $b) => ($b['market_share'] <=> $a['market_share']));

    $rank = null;
    foreach ($ranked as $i => $r) {
        if (strcasecmp((string) ($r['name'] ?? ''), $targetValue) === 0) {
            $rank = $i + 1;
            break;
        }
    }

    static $labels = [
        'pulau'     => 'Pulau',
        'provinsi'  => 'Provinsi',
        'area'      => 'Area',
        'kabupaten' => 'Kab/Kota',
    ];

    return [
        'rank'        => $rank,
        'total'       => count($ranked),
        'level'       => $targetLevel,
        'level_label' => $labels[$targetLevel] ?? ucfirst($targetLevel),
        'value'       => $targetValue,
    ];
}

/**
 * Bila scope terkunci ke 1 cabang spesifik, hitung breakdown SEMUA produk
 * yang tersedia di workbook (bukan hanya yang dipilih user).
 * Return null kalau bukan single-cabang.
 */
function marketshareBuildCabangProductBreakdown(
    array $cache,
    array $filter,
    array $branches,
    string $month,
    ?string $yoyMonth = null
): ?array {
    // Cek: tepat 1 cabang dipilih
    if (empty($filter['cabang']) || count($filter['cabang']) !== 1) return null;
    if (count($branches) !== 1) return null;

    $cabangId   = (string) array_key_first($branches);
    $cabangMeta = $branches[$cabangId];
    $allProducts = $cache['meta']['products'] ?? [];

    $rows = [];
    foreach ($allProducts as $p) {
        $mC = marketshareSum($cache, [$cabangId], $p, 'MARKET', $month);
        $bC = marketshareSum($cache, [$cabangId], $p, 'BMRI',   $month);
        $mY = $yoyMonth ? marketshareSum($cache, [$cabangId], $p, 'MARKET', $yoyMonth) : null;
        $bY = $yoyMonth ? marketshareSum($cache, [$cabangId], $p, 'BMRI',   $yoyMonth) : null;
        $msC = marketshareRatio($bC, $mC);
        $msY = marketshareRatio($bY, $mY);
        $rows[] = [
            'product'        => $p,
            'label'          => marketshareProductLabel($p),
            'market'         => $mC,
            'bmri'           => $bC,
            'ms'             => $msC,
            'market_yoy_val' => $mY,
            'bmri_yoy_val'   => $bY,
            'ms_yoy_val'     => $msY,
            'market_yoy_pct' => marketshareYoyPct($mC, $mY),
            'bmri_yoy_pct'   => marketshareYoyPct($bC, $bY),
            'ms_yoy_point'   => marketshareYoyPoint($msC, $msY),
        ];
    }

    return [
        'cabang_id'    => $cabangId,
        'cabang_name'  => $cabangMeta['name']      ?? $cabangId,
        'kabupaten'    => $cabangMeta['kabupaten'] ?? '',
        'provinsi'     => $cabangMeta['provinsi']  ?? '',
        'kelas'        => $cabangMeta['kelas']     ?? '',
        'products'     => $rows,
    ];
}
/**
 * Breakdown SEMUA produk untuk scope aktif (bisa multi cabang / kabupaten / area).
 * Selalu return array (bukan null), jadi tabel ini tampil untuk scope apapun.
 */
function marketshareBuildScopeProductBreakdown(
    array $cache,
    array $branches,
    string $month,
    ?string $yoyMonth = null
): array {
    $branchIds   = array_keys($branches);
    $allProducts = $cache['meta']['products'] ?? [];

    $rows = [];
    foreach ($allProducts as $p) {
        $mC = marketshareSum($cache, $branchIds, $p, 'MARKET', $month);
        $bC = marketshareSum($cache, $branchIds, $p, 'BMRI',   $month);
        $mY = $yoyMonth ? marketshareSum($cache, $branchIds, $p, 'MARKET', $yoyMonth) : null;
        $bY = $yoyMonth ? marketshareSum($cache, $branchIds, $p, 'BMRI',   $yoyMonth) : null;
        $msC = marketshareRatio($bC, $mC);
        $msY = marketshareRatio($bY, $mY);
        $rows[] = [
            'product'        => $p,
            'label'          => marketshareProductLabel($p),
            'market'         => $mC,
            'bmri'           => $bC,
            'ms'             => $msC,
            'market_yoy_val' => $mY,
            'bmri_yoy_val'   => $bY,
            'ms_yoy_val'     => $msY,
            'market_yoy_pct' => marketshareYoyPct($mC, $mY),
            'bmri_yoy_pct'   => marketshareYoyPct($bC, $bY),
            'ms_yoy_point'   => marketshareYoyPoint($msC, $msY),
        ];
    }
    return $rows;
}

/**
 * Endpoint utama: hitung KPI agregat, tabel per produk, dan data map per kabupaten.
 * Query params:
 *   - products[]     : daftar kode produk (TABUNGAN, GIRO, dst). Wajib ≥ 1.
 *   - month          : YYYY-MM. Default = bulan terbaru.
 *   - pulau[], provinsi[], area[], kabupaten[], cabang[] : filter cascading
 */
function handleMarketShareData(string $cacheFile, string $activityFile, array $currentUser): void
{
    $cache = loadCache($cacheFile);
    if ($cache === null) {
        jsonResponse(['ok' => false, 'message' => 'Data Market Share belum tersedia.'], 404);
        return;
    }

    // products
    $availableProducts = $cache['meta']['products'] ?? [];
    $requested = $_GET['products'] ?? [];
    if (is_string($requested)) $requested = array_filter(array_map('trim', explode(',', $requested)));
    $requested = array_map('strtoupper', (array) $requested);
    $products  = array_values(array_intersect($availableProducts, $requested));
    if ($products === []) $products = $availableProducts;

    // month
    $months = $cache['meta']['months'] ?? [];
    if (empty($months)) {
        jsonResponse(['ok' => false, 'message' => 'Cache tidak memiliki bulan apapun.'], 500);
        return;
    }
    $month = trim((string) ($_GET['month'] ?? ''));
    if ($month === '' || !in_array($month, $months, true)) $month = end($months);
    $yoyMonth     = marketshareYoyRef($month);
    $yoyAvailable = in_array($yoyMonth, $months, true);
    $yoyArg       = $yoyAvailable ? $yoyMonth : null;

    // filter cascading
    $filter = [
        'pulau'     => marketshareReadList($_GET['pulau']     ?? null),
        'provinsi'  => marketshareReadList($_GET['provinsi']  ?? null),
        'area'      => marketshareReadList($_GET['area']      ?? null),
        'kabupaten' => marketshareReadList($_GET['kabupaten'] ?? null),
        'cabang'    => marketshareReadList($_GET['cabang']    ?? null),
    ];

    $userScope = marketshareUserScope($currentUser);
    $branches  = marketshareResolveBranches($cache, $filter, $userScope);
    $branchIds = array_keys($branches);

    // KPI agregat + tabel per produk
    $sumMC = 0.0;
    $hMC = false;
    $sumBC = 0.0;
    $hBC = false;
    $sumMY = 0.0;
    $hMY = false;
    $sumBY = 0.0;
    $hBY = false;
    $tableRows = [];
    foreach ($products as $p) {
        $mC = marketshareSum($cache, $branchIds, $p, 'MARKET', $month);
        $bC = marketshareSum($cache, $branchIds, $p, 'BMRI',   $month);
        $mY = $yoyAvailable ? marketshareSum($cache, $branchIds, $p, 'MARKET', $yoyMonth) : null;
        $bY = $yoyAvailable ? marketshareSum($cache, $branchIds, $p, 'BMRI',   $yoyMonth) : null;
        $msC = marketshareRatio($bC, $mC);
        $msY = marketshareRatio($bY, $mY);
        $tableRows[] = [
            'product'        => $p,
            'label'          => marketshareProductLabel($p),
            'market'         => $mC,
            'market_yoy_val' => $mY,
            'market_yoy_pct' => marketshareYoyPct($mC, $mY),
            'bmri'           => $bC,
            'bmri_yoy_val'   => $bY,
            'bmri_yoy_pct'   => marketshareYoyPct($bC, $bY),
            'ms'             => $msC,
            'ms_yoy_val'    => $msY,
            'ms_yoy_point'   => marketshareYoyPoint($msC, $msY),
        ];
        if ($mC !== null) {
            $sumMC += $mC;
            $hMC = true;
        }
        if ($bC !== null) {
            $sumBC += $bC;
            $hBC = true;
        }
        if ($mY !== null) {
            $sumMY += $mY;
            $hMY = true;
        }
        if ($bY !== null) {
            $sumBY += $bY;
            $hBY = true;
        }
    }
    $marketTotal = $hMC ? $sumMC : null;
    $bmriTotal   = $hBC ? $sumBC : null;
    $marketY     = $hMY ? $sumMY : null;
    $bmriY       = $hBY ? $sumBY : null;
    $msTotal     = marketshareRatio($bmriTotal, $marketTotal);
    $msTotalY    = marketshareRatio($bmriY, $marketY);

    $kpi = [
        'market'         => $marketTotal,
        'bmri'           => $bmriTotal,
        'market_share'   => $msTotal,
        'market_yoy_pct' => marketshareYoyPct($marketTotal, $marketY),
        'bmri_yoy_pct'   => marketshareYoyPct($bmriTotal,   $bmriY),
        'ms_yoy_point'   => marketshareYoyPoint($msTotal,   $msTotalY),
        'market_yoy_val' => $marketY,
        'bmri_yoy_val'   => $bmriY,
        'ms_yoy_val'     => $msTotalY,
    ];

    // map levels (dengan YoY)
    $mapLevels = [
        'pulau'     => marketshareBuildMapAggregation($cache, $branches, $products, $month, 'pulau',     $yoyArg),
        'provinsi'  => marketshareBuildMapAggregation($cache, $branches, $products, $month, 'provinsi',  $yoyArg),
        'area'      => marketshareBuildMapAggregation($cache, $branches, $products, $month, 'area',      $yoyArg),
        'kabupaten' => marketshareBuildMapAggregation($cache, $branches, $products, $month, 'kabupaten', $yoyArg),
        'cabang'    => marketshareBuildMapAggregation($cache, $branches, $products, $month, 'cabang',    $yoyArg),
    ];
    $mapRows = $mapLevels['kabupaten'];

    // trend 6 bulan untuk scope aktif
    $trend = marketshareBuildTrend($cache, $branchIds, $products, $months, $month, 6);

    // ranking provinsi (di antara provinsi yang ada di dataset, dalam user scope)
    // ranking dinamis sesuai level filter terdalam
    $rankInfo = marketshareComputeDynamicRank($cache, $userScope, $filter, $products, $month);
    $selectedProv  = $filter['provinsi'][0] ?? '';
    $provinceRank  = ($rankInfo && $rankInfo['level'] === 'provinsi') ? $rankInfo['rank']  : null;
    $provinceTotal = ($rankInfo && $rankInfo['level'] === 'provinsi') ? $rankInfo['total'] : 0;
    $cabangProducts = marketshareBuildCabangProductBreakdown($cache, $filter, $branches, $month, $yoyArg);
    $scopeProducts = marketshareBuildScopeProductBreakdown($cache, $branches, $month, $yoyArg);

    recordActivity($activityFile, $currentUser, 'view_marketshare', [
        'products' => implode(',', $products),
        'month'    => $month,
        'branches' => count($branchIds),
    ]);

    jsonResponse([
        'ok'                 => true,
        'group'              => 'marketshare',
        'month'              => $month,
        'month_label'        => marketshareFormatMonthLabel($month),
        'yoy_month'          => $yoyMonth,
        'yoy_month_label'    => marketshareFormatMonthLabel($yoyMonth),
        'yoy_available'      => $yoyAvailable,
        'available_months'   => $months,
        'selected_products'  => $products,
        'available_products' => $availableProducts,
        'product_labels'     => array_combine($availableProducts, array_map('marketshareProductLabel', $availableProducts)),
        'product_groups'     => $cache['meta']['product_groups'] ?? marketshareProductGroups(),
        'applied_filter'     => $filter,
        'selected_provinsi'  => $selectedProv,
        'selected_kabupaten' => $filter['kabupaten'][0] ?? '',
        'scope_branch_count' => count($branchIds),
        'scope_label'        => marketshareDescribeScope($filter, $branches),
        'kpi'                => $kpi,
        'table'              => $tableRows,
        'map'                => $mapRows,
        'map_levels'         => $mapLevels,
        'trend'              => $trend,
        'province_rank'      => $provinceRank,
        'province_total'     => $provinceTotal,
        'rank_info'           => $rankInfo,

        'source_file'        => $cache['source_file']  ?? null,
        'generated_at'       => $cache['generated_at'] ?? null,
        'user'               => publicUser($currentUser),
        'cabang_products'    => $cabangProducts,
        'scope_products'     => $scopeProducts,
    ]);
}

/** Helper: baca list dari query string (array atau comma-separated). */
function marketshareReadList(mixed $value): array
{
    if ($value === null || $value === '') return [];
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', array_map('strval', $value)), static fn($v) => $v !== ''));
    }
    return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn($v) => $v !== ''));
}

/** Helper: deskripsi scope user untuk header UI. */
function marketshareDescribeScope(array $filter, array $branches): string
{
    $parts = [];
    if (!empty($filter['cabang']))    $parts[] = count($filter['cabang']) . ' Cabang';
    elseif (!empty($filter['kabupaten'])) $parts[] = count($filter['kabupaten']) . ' Kabupaten';
    elseif (!empty($filter['area']))      $parts[] = count($filter['area']) . ' Area';
    elseif (!empty($filter['provinsi']))  $parts[] = count($filter['provinsi']) . ' Provinsi';
    elseif (!empty($filter['pulau']))     $parts[] = count($filter['pulau']) . ' Pulau';
    else $parts[] = 'Region XI';

    $parts[] = count($branches) . ' cabang tergabung';
    return implode(' · ', $parts);
}

// =============================================================================
//  HTML SECTION
// =============================================================================

/**
 * Render markup untuk halaman Market Share.
 * Diapit oleh layout halaman utama (Anda yang membungkus header/menu).
 */
function marketshareHtmlSection(): string
{
    ob_start();
?>
    <div id="msWorkspace" class="ms2-root">

        <!-- Header -->
        <div class="ms2-head">
            <div>
                <h3 class="ms2-title">Market Share &amp; Kinerja</h3>
                <div class="ms2-sub" id="msSourceLabel">Market Share (CASA) dan Kinerja BMRI</div>
            </div>
            <div class="ms2-head-actions">
                <select id="msMonthSelect" class="ms2-input"></select>
                <button class="ms2-btn ghost" id="msResetBtn" type="button"><i class="bi bi-arrow-counterclockwise"></i> Reset Filter</button>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="ms2-filterbar">
            <div class="ms2-field">
                <label>Produk</label>
                <div class="ms2-pills" id="msProductPills"></div>
            </div>
            <div class="ms2-field"><label>Pilih Pulau</label><select class="ms2-input" data-ms-select="pulau" id="msPulauSelect"></select></div>
            <div class="ms2-field"><label>Pilih Area</label><select class="ms2-input" data-ms-select="area" id="msAreaSelect"></select></div>
            <div class="ms2-field"><label>Pilih Provinsi</label><select class="ms2-input" data-ms-select="provinsi" id="msProvinsiSelect"></select></div>
            <div class="ms2-field"><label>Pilih Kab/Kota</label><select class="ms2-input" data-ms-select="kabupaten" id="msKabupatenSelect"></select></div>
            <div class="ms2-field"><label>Pilih Cabang</label><select class="ms2-input" data-ms-select="cabang" id="msCabangSelect"></select></div>
        </div>

        <!-- KPI cards -->
        <div class="ms2-kpis">
            <div class="ms2-card">
                <div class="ms2-ic blue"><i class="bi bi-globe2"></i></div>
                <div class="ms2-k-label">Total Market</div>
                <div class="ms2-k-val" id="msKpiMarket">-</div>
                <div class="ms2-k-sub" id="msKpiMarketYoy">-</div>
            </div>
            <div class="ms2-card">
                <div class="ms2-ic green"><i class="bi bi-bank2"></i></div>
                <div class="ms2-k-label">Total BMRI</div>
                <div class="ms2-k-val" id="msKpiBmri">-</div>
                <div class="ms2-k-sub" id="msKpiBmriYoy">-</div>
            </div>
            <div class="ms2-card">
                <div class="ms2-ic teal"><i class="bi bi-pie-chart-fill"></i></div>
                <div class="ms2-k-label">Market Share</div>
                <div class="ms2-k-val" id="msKpiMs">-</div>
                <div class="ms2-k-sub" id="msKpiMsYoy">-</div>
            </div>
            <div class="ms2-card">
                <div class="ms2-ic green"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="ms2-k-label">YoY Market Share</div>
                <div class="ms2-k-val" id="msKpiMsYoyBig">-</div>
                <div class="ms2-k-sub" id="msKpiMsYoyBigSub">vs tahun lalu</div>
            </div>
            <div class="ms2-card">
                <div class="ms2-ic slate"><i class="bi bi-buildings"></i></div>
                <div class="ms2-k-label">Jumlah Cabang</div>
                <div class="ms2-k-val" id="msKpiBranches">-</div>
                <div class="ms2-k-sub">di filter terpilih</div>
            </div>
            <div class="ms2-card">
                <div class="ms2-ic purple"><i class="bi bi-trophy"></i></div>
                <div class="ms2-k-label">Ranking Provinsi</div>
                <div class="ms2-k-val" id="msKpiRank">-</div>
                <div class="ms2-k-sub" id="msKpiRankSub">-</div>
            </div>
        </div>

        <!-- Map + Detail -->
        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-7">
                <div class="ms2-panel">
                    <div class="ms2-panel-head">
                        <div>
                            <h5 class="ms2-panel-title" id="msMapTitle">Market Share (CASA) per Kabupaten/Kota</h5>
                            <div class="ms2-panel-sub" id="msMapSubtitle">Klik wilayah untuk lihat detail.</div>
                        </div>
                        <div class="ms2-toggle" id="msMetricToggle">
                            <button data-ms-metric="market" type="button">Market</button>
                            <button data-ms-metric="bmri" type="button">BMRI</button>
                            <button data-ms-metric="ms" class="active" type="button">Market Share</button>
                        </div>
                    </div>
                    <div id="msMap" class="ms2-map"></div>
                    <div class="ms2-legend" id="msLegend"></div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="ms2-panel">
                    <div class="ms2-panel-head">
                        <div>
                            <div class="ms2-panel-eyebrow">Detail Wilayah</div>
                            <h5 class="ms2-panel-title" id="msDetailTitle">-</h5>
                        </div>
                        <button class="ms2-btn ghost sm" id="msBackBtn" type="button" style="display:none;"><i class="bi bi-arrow-left"></i> Kembali ke Peta</button>
                    </div>
                    <div class="ms2-detail-cards">
                        <div class="ms2-dcard">
                            <div class="ms2-dc-label">Market</div>
                            <div class="ms2-dc-val" id="msDetMarket">-</div>
                            <div class="ms2-dc-sub" id="msDetMarketYoy">-</div>
                        </div>
                        <div class="ms2-dcard">
                            <div class="ms2-dc-label">BMRI</div>
                            <div class="ms2-dc-val" id="msDetBmri">-</div>
                            <div class="ms2-dc-sub" id="msDetBmriYoy">-</div>
                        </div>
                        <div class="ms2-dcard">
                            <div class="ms2-dc-label">Market Share</div>
                            <div class="ms2-dc-val" id="msDetMs">-</div>
                            <div class="ms2-dc-sub" id="msDetMsYoy">-</div>
                        </div>
                        <div class="ms2-dcard">
                            <div class="ms2-dc-label">Jumlah Cabang</div>
                            <div class="ms2-dc-val" id="msDetBranches">-</div>
                            <div class="ms2-dc-sub">Cabang</div>
                        </div>
                    </div>
                    <div class="ms2-trend-head">
                        <div class="ms2-panel-title" id="msTrendTitle" style="font-size:.95rem;">Tren</div>
                        <div class="ms2-trend-controls">
                            <div class="ms2-mini-toggle primary" id="msTrendMetric">
                                <button data-tm="market" type="button">Market</button>
                                <button data-tm="bmri" type="button">BMRI</button>
                                <button data-tm="ms" class="active" type="button">Market Share</button>
                            </div>
                            <div class="ms2-mini-toggle" id="msTrendView">
                                <button data-tv="continuous" class="active" type="button">📈 Timeline</button>
                                <button data-tv="annual" type="button">📊 Komparasi</button>
                            </div>
                        </div>
                    </div>
                    <div id="msTrendChart" style="min-height:230px;"></div>

                    <!-- ===== Product Breakdown Table ===== -->
                    <div style="margin-top:18px;padding-top:14px;border-top:1px dashed rgba(203,213,225,.6);">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="ms2-panel-title" id="msProdTitle" style="font-size:.92rem;">Breakdown Produk</div>
                            <div class="d-flex gap-1">
                                <button class="ms2-tabbtn active" data-prod-group="ALL" type="button">Semua</button>
                                <button class="ms2-tabbtn" data-prod-group="DPK" type="button">DPK</button>
                                <button class="ms2-tabbtn" data-prod-group="KREDIT" type="button">Kredit</button>
                            </div>
                        </div>
                        <div class="ms2-prodtbl-wrap" id="msProdTableWrap">
                            <div style="padding:24px;text-align:center;color:#94a3b8;font-size:.78rem;">Memuat...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tables -->
        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <div class="ms2-panel">
                    <div class="ms2-panel-head">
                        <h5 class="ms2-panel-title" id="msKabTableTitle">Daftar Kabupaten/Kota</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="ms2-table" id="msKabTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th style="text-align:left;">Kabupaten/Kota</th>
                                    <th>Market (M)</th>
                                    <th>YoY</th>
                                    <th>BMRI (M)</th>
                                    <th>YoY</th>
                                    <th>MS (%)</th>
                                    <th>YoY (%)</th>
                                    <th>Cabang</th>
                                    <th>Rank</th>
                                </tr>
                            </thead>
                            <tbody id="msKabTableBody">
                                <tr>
                                    <td colspan="10" class="ms2-empty-cell">Memuat…</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="ms2-panel">
                    <div class="ms2-panel-head">
                        <h5 class="ms2-panel-title" id="msCabTableTitle">Daftar Cabang</h5>
                        <input type="text" class="ms2-input sm" id="msCabSearch" placeholder="Cari cabang…" style="max-width:160px;">
                    </div>
                    <div class="table-responsive">
                        <table class="ms2-table" id="msCabTable">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th style="text-align:left;">Nama Cabang</th>
                                    <th>Jenis</th>
                                    <th>BMRI (M)</th>
                                    <th>YoY</th>
                                    <th>MS (%)</th>
                                    <th>YoY (%)</th>
                                </tr>
                            </thead>
                            <tbody id="msCabTableBody">
                                <tr>
                                    <td colspan="7" class="ms2-empty-cell">Memuat…</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div id="msEmptyOverlay" class="ms2-overlay" style="display:none;">
            <div class="display-6 mb-2">📥</div>
            <h4 class="ms2-title" style="font-size:1.2rem;">Data Market Share Belum Tersedia</h4>
            <p id="msEmptyMessage" class="ms2-sub">Admin perlu upload file Excel Market Share terlebih dahulu.</p>
        </div>
    </div>

    <style>
        .ms2-root {
            color: #1e293b;
        }

        .ms2-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        /* Product breakdown table — ringkas, premium look */
        .ms2-prodtbl {
            width: 100%;
            border-collapse: collapse;
            font-size: .76rem;
        }

        .ms2-prodtbl thead th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 800;
            font-size: .64rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 7px 8px;
            text-align: right;
            border-bottom: 1px solid #eef2f6;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .ms2-prodtbl thead th:first-child {
            text-align: left;
        }

        .ms2-prodtbl tbody td {
            padding: 6px 8px;
            text-align: right;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            white-space: nowrap;
        }

        .ms2-prodtbl tbody td:first-child {
            text-align: left;
            font-weight: 700;
            color: #1e293b;
        }

        .ms2-prodtbl tbody tr.section td {
            background: rgba(248, 250, 252, .9);
            font-size: .62rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 6px 10px;
            text-align: left;
            border-left: 3px solid currentColor;
        }

        .ms2-prodtbl tbody tr.subtotal td {
            background: rgba(241, 245, 249, .85);
            font-weight: 800;
            border-top: 1.5px solid rgba(203, 213, 225, .7);
        }

        .ms2-prodtbl-wrap {
            max-height: 320px;
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid #eef2f6;
        }

        .ms2-tabbtn {
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 8px;
            padding: 4px 12px;
            font-size: .72rem;
            font-weight: 700;
            color: #475569;
            cursor: pointer;
            transition: all .15s;
        }

        .ms2-tabbtn.active {
            background: #0f172a;
            color: #fff;
            border-color: #0f172a;
        }

        .ms2-title {
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            margin: 0;
            color: #0f172a;
        }

        .ms2-sub {
            color: #64748b;
            font-size: .82rem;
            font-weight: 600;
        }

        .ms2-head-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .ms2-input {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 7px 12px;
            font-size: .82rem;
            font-weight: 600;
            background: #fff;
            color: #334155;
        }

        .ms2-input.sm {
            padding: 5px 10px;
            font-size: .78rem;
        }

        .ms2-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
        }

        .ms2-btn {
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 10px;
            padding: 7px 14px;
            font-size: .82rem;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
        }

        .ms2-btn.sm {
            padding: 5px 10px;
            font-size: .76rem;
        }

        .ms2-btn.ghost:hover {
            background: #f8fafc;
        }

        .ms2-btn.primary {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }

        .ms2-filterbar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            background: #fff;
            border: 1px solid #eef2f6;
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 14px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .03);
        }

        .ms2-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 150px;
            flex: 1;
        }

        .ms2-field>label {
            font-size: .68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #94a3b8;
        }

        .ms2-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .ms2-pill {
            padding: 5px 11px;
            border-radius: 999px;
            font-size: .74rem;
            font-weight: 700;
            cursor: pointer;
            border: 1.5px solid #e2e8f0;
            background: #fff;
            color: #475569;
            white-space: nowrap;
        }

        .ms2-pill.on {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        .ms2-kpis {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 14px;
        }

        @media (max-width:1100px) {
            .ms2-kpis {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width:560px) {
            .ms2-kpis {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .ms2-card {
            background: #fff;
            border: 1px solid #eef2f6;
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, .03);
        }

        .ms2-ic {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .95rem;
            margin-bottom: 8px;
        }

        .ms2-ic.blue {
            background: #dbeafe;
            color: #2563eb;
        }

        .ms2-ic.green {
            background: #dcfce7;
            color: #16a34a;
        }

        .ms2-ic.teal {
            background: #ccfbf1;
            color: #0d9488;
        }

        .ms2-ic.slate {
            background: #f1f5f9;
            color: #475569;
        }

        .ms2-ic.purple {
            background: #f3e8ff;
            color: #7c3aed;
        }

        .ms2-k-label {
            font-size: .68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #94a3b8;
        }

        .ms2-k-val {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.15;
        }

        .ms2-k-sub {
            font-size: .74rem;
            font-weight: 700;
            color: #64748b;
            margin-top: 2px;
        }

        .ms2-panel {
            background: #fff;
            border: 1px solid #eef2f6;
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: 0 6px 22px rgba(15, 23, 42, .04);
            height: 100%;
        }

        .ms2-panel-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .ms2-panel-eyebrow {
            font-size: .68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #94a3b8;
        }

        .ms2-panel-title {
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            margin: 0;
            color: #0f172a;
            font-size: 1.05rem;
        }

        .ms2-panel-sub {
            font-size: .76rem;
            color: #94a3b8;
        }

        .ms2-toggle {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 999px;
            padding: 3px;
            gap: 2px;
        }

        .ms2-toggle button {
            border: none;
            background: transparent;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: .76rem;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
        }

        .ms2-toggle button.active {
            background: #2563eb;
            color: #fff;
            box-shadow: 0 2px 6px rgba(37, 99, 235, .3);
        }

        .ms2-map {
            height: 460px;
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
            background: #eef2f6;
        }

        .ms2-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
            font-size: .7rem;
            color: #64748b;
            font-weight: 700;
        }

        .ms2-legend .sw {
            display: inline-block;
            width: 11px;
            height: 11px;
            border-radius: 3px;
            margin-right: 4px;
            vertical-align: middle;
        }

        .ms2-detail-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }

        .ms2-dcard {
            background: #f8fafc;
            border: 1px solid #eef2f6;
            border-radius: 12px;
            padding: 11px 13px;
        }

        .ms2-dc-label {
            font-size: .66rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #94a3b8;
        }

        .ms2-dc-val {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.1;
        }

        .ms2-dc-sub {
            font-size: .7rem;
            font-weight: 700;
            color: #64748b;
            margin-top: 1px;
        }

        .ms2-trend-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .ms2-trend-legend {
            font-size: .72rem;
            color: #64748b;
            font-weight: 700;
        }

        .ms2-trend-legend .dot {
            display: inline-block;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            margin: 0 4px 0 8px;
            vertical-align: middle;
        }

        .ms2-trend-legend .dot.gray {
            background: #94a3b8;
        }

        .ms2-trend-legend .dot.blue {
            background: #2563eb;
        }

        .ms2-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .78rem;
        }

        .ms2-table thead th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 800;
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            padding: 9px 8px;
            text-align: right;
            border-bottom: 1px solid #eef2f6;
            white-space: nowrap;
        }

        .ms2-table thead th:nth-child(2) {
            text-align: left;
        }

        .ms2-table tbody td {
            padding: 8px;
            text-align: right;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            white-space: nowrap;
        }

        .ms2-table tbody td:nth-child(2) {
            text-align: left;
            font-weight: 700;
            color: #1e293b;
        }

        .ms2-table tbody tr.sel {
            background: #eff6ff;
        }

        .ms2-table tbody tr.total td {
            font-weight: 800;
            background: #f8fafc;
            border-top: 2px solid #e2e8f0;
        }

        .ms2-table tbody tr.clk {
            cursor: pointer;
        }

        .ms2-table tbody tr.clk:hover {
            background: #f8fafc;
        }

        .ms2-empty-cell {
            text-align: center;
            color: #94a3b8;
            padding: 18px 0;
        }

        .ms2-overlay {
            background: #fff;
            border: 1px solid #eef2f6;
            border-radius: 16px;
            padding: 48px 16px;
            text-align: center;
        }

        #msMap .leaflet-tooltip.ms-map-label {
            background: transparent;
            border: 0;
            box-shadow: none;
            color: #0f172a;
            font-family: 'Inter', sans-serif;
            font-weight: 800;
            text-align: center;
            text-shadow: 0 1px 2px rgba(255, 255, 255, .95);
            padding: 0;
        }

        #msMap .ms-map-label .name {
            font-size: .66rem;
            line-height: 1.05;
        }

        #msMap .ms-map-label .val {
            font-size: .92rem;
            line-height: 1.05;
        }
    </style>
<?php
    return ob_get_clean();
}

// =============================================================================
//  SCRIPT (frontend)
// =============================================================================

/**
 * Render <script> block untuk halaman Market Share.
 * Letakkan sebelum </body> pada halaman yang menyertakan marketshareHtmlSection().
 */


// -----------------------------------------------------------------------------
// 3) marketshareScript — pin lokasi untuk cabang, toggle metrik & periode tren
// -----------------------------------------------------------------------------
function marketshareScript(): string
{
    ob_start();
?>
    <script>
        (function() {
            if (!document.getElementById('msWorkspace')) return;

            const MS_GEOJSON_URLS = [
                'runtime_cache/geojson/indonesia-cities.geojson',
                'https://raw.githubusercontent.com/fahadh4ilyas/indonesia-geojson-archive/master/Indonesia_cities.geojson',
                'https://cdn.jsdelivr.net/gh/superpikar/indonesia-geojson@master/indonesia-en.geojson',
                'https://raw.githubusercontent.com/superpikar/indonesia-geojson/master/indonesia-en.geojson',
            ];
            const MS_GEOJSON_NAME_KEYS = ['NAME_2', 'KABKOT', 'kabkot', 'kab_kota', 'NAMA_KAB', 'NAMOBJ', 'nama_kabupaten', 'name_2', 'name', 'NAME'];

            const apiBase = window.location.pathname;
            const $ = id => document.getElementById(id);

            const state = {
                meta: null,
                data: null,
                month: '',
                selectedProducts: new Set(),
                filter: {
                    pulau: new Set(),
                    provinsi: new Set(),
                    area: new Set(),
                    kabupaten: new Set(),
                    cabang: new Set()
                },
                metric: 'ms',
                selectedKab: '',
                showCabang: true,
                showChoropleth: true,
                trendMetric: 'ms', // 'market' | 'bmri' | 'ms'
                trendView: 'continuous', // 'continuous' | 'annual'  ← GANTI dari trendPeriod
                prodGroup: 'ALL',
                leafletReady: false,
                map: null,
                choroplethLayer: null,
                markerLayer: null,
                geoJsonData: null,
                trendChart: null,
                cabSearch: '',
            };

            // ---------- helpers ----------
            const esc = v => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const fmtM = v => (v === null || v === undefined || isNaN(v)) ? '-' : Number(v).toLocaleString('id-ID', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            });
            const fmtMU = v => (v === null || v === undefined || isNaN(v)) ? '-' : fmtM(v) + ' M';
            const fmtPct = v => (v === null || v === undefined || isNaN(v)) ? '-' : Number(v).toFixed(1) + '%';
            const fmtChg = v => (v === null || v === undefined || isNaN(v)) ? '-' : (Number(v) >= 0 ? '+' : '') + Number(v).toFixed(1) + '%';
            const fmtPpt = v => (v === null || v === undefined || isNaN(v)) ? '-' : (Number(v) >= 0 ? '+' : '') + Number(v).toFixed(1) + ' %';
            const colChg = (v, inv = false) => {
                if (v === null || v === undefined || isNaN(v)) return '#94a3b8';
                const n = Number(v);
                if (n === 0) return '#64748b';
                const good = inv ? n < 0 : n > 0;
                return good ? '#16a34a' : '#dc2626';
            };
            const arrow = v => {
                if (v === null || v === undefined || isNaN(v)) return '';
                const n = Number(v);
                if (n === 0) return '';
                return n > 0 ? '▲' : '▼';
            };
            const normalizeName = s => String(s || '').toLowerCase().replace(/^(kabupaten|kab\.?|kota)\s+/i, '').replace(/[^a-z0-9]/g, '');
            const shortKab = s => String(s || '').replace(/^Kabupaten\s+/i, '').replace(/^Kab\.\s+/i, '').replace(/^Kota\s+/i, '');
            // Color bands sesuai conditional formatting Excel
            // <=5% merah, 5-10% orange, 10-15% kuning, 15-20% hijau muda, >20% hijau tua
            const colorMsBucket = p => {
                if (p === null || p === undefined || isNaN(p)) return '#e5e7eb';
                p = Number(p);
                if (p > 20) return '#166534'; // dark green
                if (p > 15) return '#86efac'; // light green
                if (p > 10) return '#fde047'; // yellow
                if (p > 5) return '#f59e0b'; // orange
                return '#dc2626'; // red (<=5%)
            };
            const rampBlue = t => {
                t = Math.max(0, Math.min(1, t));
                const a = [224, 236, 255],
                    b = [29, 78, 216];
                return `rgb(${Math.round(a[0]+(b[0]-a[0])*t)},${Math.round(a[1]+(b[1]-a[1])*t)},${Math.round(a[2]+(b[2]-a[2])*t)})`;
            };
            const jenisOf = r => {
                const k = String(r.kelas || '').toUpperCase();
                if (k) return k;
                const n = String(r.cabang_name || r.name || '');
                const m = n.match(/^(KCP|KC|KK|KLS|UMK)\b/i);
                return m ? m[1].toUpperCase() : '-';
            };
            const sortNat = arr => Array.from(arr).sort((a, b) => String(a).localeCompare(String(b), 'id', {
                sensitivity: 'base'
            }));

            const MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            const yrOf = s => +String(s || '').slice(0, 4);
            const moOf = s => +String(s || '').slice(5, 7);
            const metricNameOf = m => ({
                market: 'Market',
                bmri: 'BMRI',
                ms: 'Market Share'
            })[m] || m;
            const periodNameOf = p => ({
                m3: '3 Bulan Terakhir',
                ytd: 'YtD',
                yoy: 'YoY'
            })[p] || p;

            // ----- branch pin (teardrop, red, with white target center) -----
            const BRANCH_PIN_SVG = `
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="28" viewBox="0 0 22 28" aria-hidden="true">
                    <path d="M11 0.5C5.2 0.5 0.5 4.9 0.5 10.5c0 7.4 10.5 17 10.5 17s10.5-9.6 10.5-17C21.5 4.9 16.8 0.5 11 0.5z"
                          fill="#dc2626" stroke="#ffffff" stroke-width="1.6" stroke-linejoin="round"/>
                    <circle cx="11" cy="10.5" r="3.8" fill="#ffffff"/>
                    <circle cx="11" cy="10.5" r="1.6" fill="#dc2626"/>
                </svg>`;

            async function apiGet(action, params = {}) {
                const usp = new URLSearchParams();
                usp.set('action', action);
                for (const [k, v] of Object.entries(params)) {
                    if (v === null || v === undefined || v === '') continue;
                    if (Array.isArray(v) || v instanceof Set) {
                        for (const it of v) usp.append(k + '[]', it);
                    } else usp.set(k, v);
                }
                const res = await fetch(`${apiBase}?${usp}`);
                const p = await res.json();
                if (!res.ok || !p.ok) throw new Error(p.message || 'Request gagal.');
                return p;
            }

            async function ensureLeaflet() {
                if (state.leafletReady) return;
                if (!document.querySelector('link[data-ms-leaflet]')) {
                    const l = document.createElement('link');
                    l.rel = 'stylesheet';
                    l.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    l.setAttribute('data-ms-leaflet', '1');
                    document.head.appendChild(l);
                }
                if (typeof window.L !== 'undefined') {
                    state.leafletReady = true;
                    return;
                }
                await new Promise((res, rej) => {
                    const s = document.createElement('script');
                    s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    s.async = true;
                    s.onload = res;
                    s.onerror = () => rej(new Error('Gagal memuat Leaflet.'));
                    document.head.appendChild(s);
                });
                state.leafletReady = true;
            }

            // ---------- init ----------
            async function init() {
                try {
                    injectExtraStyles();
                    const meta = await apiGet('marketshare_meta');
                    state.meta = meta;
                    if (!meta.cached) {
                        showEmpty(meta.message || 'Data belum tersedia.');
                        return;
                    }
                    renderMonths(meta);
                    renderProductPills(meta);
                    bindEvents();
                    injectCabToggle();
                    injectChoroplethToggle();
                    injectTrendControls();

                    const groups = meta.product_groups || {};
                    const casa = (groups.CASA || []).filter(p => (meta.products || []).includes(p));
                    (casa.length ? casa : (meta.products || [])).forEach(p => state.selectedProducts.add(p));
                    syncProductPills();

                    state.month = (meta.months && meta.months.length) ? meta.months[meta.months.length - 1] : '';
                    $('msMonthSelect').value = state.month;

                    if ((meta.provinsi || []).length) state.filter.provinsi.add(meta.provinsi[0]);
                    refreshGeoFilters();

                    await ensureLeaflet();
                    initMap();
                    await loadData();
                } catch (e) {
                    console.error('[MS] init', e);
                    showEmpty('Gagal memuat: ' + e.message);
                }
            }

            function injectExtraStyles() {
                if (document.getElementById('ms2-extra-style')) return;
                const st = document.createElement('style');
                st.id = 'ms2-extra-style';
                st.textContent = `
                    .ms-cab-pin { background:transparent !important; border:0 !important; }
                    .ms-cab-pin svg { filter: drop-shadow(0 2px 3px rgba(2,6,23,.45)); transition: transform .15s; }
                    .ms-cab-pin:hover svg { transform: scale(1.18) translateY(-1px); }
                    .ms2-mini-toggle { display:inline-flex; background:#f1f5f9; border-radius:999px; padding:2px; gap:2px; }
                    .ms2-mini-toggle button { border:none; background:transparent; padding:4px 11px; border-radius:999px; font-size:.72rem; font-weight:700; color:#64748b; cursor:pointer; }
                    .ms2-mini-toggle button.active { background:#fff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,.12); }
                    .ms2-mini-toggle.primary button.active { background:#2563eb; color:#fff; }
                    .ms2-trend-controls { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
                `;
                document.head.appendChild(st);
            }

            function showEmpty(msg) {
                $('msWorkspace').querySelectorAll(':scope > .row, :scope > .ms2-kpis, :scope > .ms2-filterbar, :scope > .ms2-head').forEach(el => el.style.display = 'none');
                $('msEmptyMessage').textContent = msg;
                $('msEmptyOverlay').style.display = 'block';
            }

            function renderMonths(meta) {
                const sel = $('msMonthSelect');
                sel.innerHTML = '';
                (meta.months || []).slice().reverse().forEach(m => {
                    const o = document.createElement('option');
                    o.value = m;
                    o.textContent = m;
                    sel.appendChild(o);
                });
            }

            function renderProductPills(meta) {
                const c = $('msProductPills');
                c.innerHTML = '';
                const labels = meta.product_labels || {};
                (meta.products || []).forEach(p => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'ms2-pill';
                    b.dataset.msProduct = p;
                    b.textContent = labels[p] || p;
                    c.appendChild(b);
                });
            }

            function syncProductPills() {
                document.querySelectorAll('[data-ms-product]').forEach(b => b.classList.toggle('on', state.selectedProducts.has(b.dataset.msProduct)));
            }

            // ---------- cascading filters ----------
            function fillSelectPairs(id, key, placeholder, pairs) {
                const el = $(id);
                if (!el) return;
                const cur = state.filter[key].size ? Array.from(state.filter[key])[0] : '';
                let html = `<option value="">${esc(placeholder)}</option>`;
                let valid = (cur === '');
                pairs.forEach(([v, l]) => {
                    const seld = String(v) === String(cur);
                    if (seld) valid = true;
                    html += `<option value="${esc(v)}" ${seld?'selected':''}>${esc(l)}</option>`;
                });
                el.innerHTML = html;
                if (!valid && cur !== '') {
                    state.filter[key].clear();
                    el.value = '';
                }
            }

            function refreshGeoFilters() {
                const branches = state.meta?.branches || [];
                const f = state.filter;
                const matchExcept = (b, exceptKey) => {
                    for (const key of ['pulau', 'provinsi', 'area', 'kabupaten', 'cabang']) {
                        if (key === exceptKey) continue;
                        if (f[key].size) {
                            const val = key === 'cabang' ? String(b.id) : (b[key] || '');
                            if (!f[key].has(val)) return false;
                        }
                    }
                    return true;
                };
                const opt = {
                    pulau: new Set(),
                    provinsi: new Set(),
                    area: new Set(),
                    kabupaten: new Set()
                };
                const cab = [];
                const seen = new Set();
                branches.forEach(b => {
                    if (b.pulau && matchExcept(b, 'pulau')) opt.pulau.add(b.pulau);
                    if (b.provinsi && matchExcept(b, 'provinsi')) opt.provinsi.add(b.provinsi);
                    if (b.area && matchExcept(b, 'area')) opt.area.add(b.area);
                    if (b.kabupaten && matchExcept(b, 'kabupaten')) opt.kabupaten.add(b.kabupaten);
                    if (matchExcept(b, 'cabang') && !seen.has(String(b.id))) {
                        seen.add(String(b.id));
                        cab.push(b);
                    }
                });
                cab.sort((a, b) => String(a.name).localeCompare(String(b.name), 'id'));

                fillSelectPairs('msPulauSelect', 'pulau', 'Semua Pulau', sortNat(opt.pulau).map(v => [v, v]));
                fillSelectPairs('msAreaSelect', 'area', 'Semua Area', sortNat(opt.area).map(v => [v, v]));
                fillSelectPairs('msProvinsiSelect', 'provinsi', 'Semua Provinsi', sortNat(opt.provinsi).map(v => [v, v]));
                fillSelectPairs('msKabupatenSelect', 'kabupaten', 'Semua Kab/Kota', sortNat(opt.kabupaten).map(v => [v, v]));
                fillSelectPairs('msCabangSelect', 'cabang', 'Semua Cabang', cab.map(b => [b.id, `${b.name} (${b.id})`]));
            }

            function injectCabToggle() {
                const tog = $('msMetricToggle');
                if (!tog || $('msCabPinBtn')) return;
                const b = document.createElement('button');
                b.id = 'msCabPinBtn';
                b.type = 'button';
                b.style.cssText = 'margin-left:8px;border:1px solid #e2e8f0;background:#dc2626;color:#fff;border-radius:999px;padding:5px 12px;font-size:.76rem;font-weight:700;cursor:pointer;';
                b.innerHTML = '<i class="bi bi-geo-alt-fill"></i> Cabang';
                tog.parentElement.appendChild(b);
                b.addEventListener('click', () => {
                    state.showCabang = !state.showCabang;
                    b.style.background = state.showCabang ? '#dc2626' : '#fff';
                    b.style.color = state.showCabang ? '#fff' : '#64748b';
                    if (state.data) renderMap(state.data, false);
                });
            }

            function injectChoroplethToggle() {
                const tog = $('msMetricToggle');
                if (!tog || $('msChoroplethBtn')) return;
                const b = document.createElement('button');
                b.id = 'msChoroplethBtn';
                b.type = 'button';
                b.style.cssText = 'margin-left:6px;border:1px solid #e2e8f0;background:#2563eb;color:#fff;border-radius:999px;padding:5px 12px;font-size:.76rem;font-weight:700;cursor:pointer;';
                b.innerHTML = '<i class="bi bi-map-fill"></i> Wilayah';
                tog.parentElement.appendChild(b);
                b.addEventListener('click', () => {
                    state.showChoropleth = !state.showChoropleth;
                    b.style.background = state.showChoropleth ? '#2563eb' : '#fff';
                    b.style.color = state.showChoropleth ? '#fff' : '#64748b';
                    if (state.data) renderMap(state.data, false);
                });
            }

            function injectTrendControls() {
                const head = document.querySelector('#msWorkspace .ms2-trend-head');
                if (!head || head.dataset.enhanced) return;
                head.dataset.enhanced = '1';

                const metricToggle = document.getElementById('msTrendMetric');
                if (metricToggle) {
                    metricToggle.addEventListener('click', e => {
                        const b = e.target.closest('[data-tm]');
                        if (!b) return;
                        state.trendMetric = b.dataset.tm;
                        metricToggle.querySelectorAll('[data-tm]').forEach(x => x.classList.toggle('active', x === b));
                        if (state.data) renderTrend(state.data);
                    });
                }
                const viewToggle = document.getElementById('msTrendView');
                if (viewToggle) {
                    viewToggle.addEventListener('click', e => {
                        const b = e.target.closest('[data-tv]');
                        if (!b) return;
                        state.trendView = b.dataset.tv;
                        viewToggle.querySelectorAll('[data-tv]').forEach(x => x.classList.toggle('active', x === b));
                        if (state.data) renderTrend(state.data);
                    });
                }
                // Product group filter (Semua / DPK / Kredit)
                document.querySelectorAll('[data-prod-group]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('[data-prod-group]').forEach(b => b.classList.toggle('active', b === btn));
                        state.prodGroup = btn.dataset.prodGroup;
                        if (state.data) renderProductTable(state.data);
                    });
                });
            }

            function bindEvents() {
                $('msMonthSelect').addEventListener('change', e => {
                    state.month = e.target.value;
                    loadData();
                });
                $('msResetBtn').addEventListener('click', () => {
                    Object.keys(state.filter).forEach(k => state.filter[k].clear());
                    state.selectedKab = '';
                    if ((state.meta.provinsi || []).length) state.filter.provinsi.add(state.meta.provinsi[0]);
                    refreshGeoFilters();
                    loadData();
                });
                $('msProductPills').addEventListener('click', e => {
                    const b = e.target.closest('[data-ms-product]');
                    if (!b) return;
                    const p = b.dataset.msProduct;
                    if (state.selectedProducts.has(p)) state.selectedProducts.delete(p);
                    else state.selectedProducts.add(p);
                    if (state.selectedProducts.size === 0 && (state.meta.products || []).length) state.selectedProducts.add(state.meta.products[0]);
                    syncProductPills();
                    loadData();
                });
                document.querySelectorAll('[data-ms-select]').forEach(sel => sel.addEventListener('change', e => {
                    const key = e.target.dataset.msSelect;
                    state.filter[key].clear();
                    if (e.target.value) state.filter[key].add(e.target.value);
                    const order = ['pulau', 'provinsi', 'area', 'kabupaten', 'cabang'];
                    for (let i = order.indexOf(key) + 1; i < order.length; i++) state.filter[order[i]].clear();
                    state.selectedKab = (key === 'kabupaten') ? e.target.value : (['pulau', 'provinsi', 'area'].includes(key) ? '' : state.selectedKab);
                    refreshGeoFilters();
                    loadData();
                }));
                $('msMetricToggle').addEventListener('click', e => {
                    const b = e.target.closest('[data-ms-metric]');
                    if (!b) return;
                    state.metric = b.dataset.msMetric;
                    document.querySelectorAll('[data-ms-metric]').forEach(x => x.classList.toggle('active', x === b));
                    if (state.data) {
                        renderMap(state.data, false);
                        renderLegend();
                    }
                });
                $('msBackBtn').addEventListener('click', () => {
                    state.filter.kabupaten.clear();
                    state.filter.cabang.clear();
                    state.selectedKab = '';
                    refreshGeoFilters();
                    loadData();
                });
                $('msCabSearch').addEventListener('input', e => {
                    state.cabSearch = e.target.value.toLowerCase();
                    if (state.data) renderCabTable(state.data);
                });
            }

            async function loadData() {
                if (state.selectedProducts.size === 0) return;
                try {
                    const params = {
                        products: Array.from(state.selectedProducts),
                        month: state.month,
                        pulau: Array.from(state.filter.pulau),
                        provinsi: Array.from(state.filter.provinsi),
                        area: Array.from(state.filter.area),
                        kabupaten: Array.from(state.filter.kabupaten),
                        cabang: Array.from(state.filter.cabang),
                    };
                    const data = await apiGet('marketshare_data', params);
                    state.data = data;
                    state.selectedKab = data.selected_kabupaten || state.selectedKab;
                    $('msSourceLabel').textContent = `Market Share dan Kinerja BMRI · ${data.month_label} · YoY ref ${data.yoy_month_label}`;
                    renderKpi(data);
                    renderMap(data, true);
                    renderLegend();
                    renderDetail(data);
                    renderTrend(data);
                    renderKabTable(data);
                    renderCabTable(data);
                } catch (e) {
                    console.error('[MS] loadData', e);
                    $('msKabTableBody').innerHTML = `<tr><td colspan="10" class="ms2-empty-cell" style="color:#dc2626;">${esc(e.message)}</td></tr>`;
                }
            }

            // ---------- KPI ----------
            function renderKpi(d) {
                const k = d.kpi || {};
                $('msKpiMarket').textContent = fmtMU(k.market);
                $('msKpiBmri').textContent = fmtMU(k.bmri);
                $('msKpiMs').textContent = fmtPct(k.market_share);
                $('msKpiBranches').textContent = (d.scope_branch_count || 0).toLocaleString('id-ID');
                $('msKpiMarketYoy').innerHTML = k.market_yoy_pct === null ? '-' : `<span style="color:${colChg(k.market_yoy_pct)}">${arrow(k.market_yoy_pct)} ${fmtChg(k.market_yoy_pct)}</span>`;
                $('msKpiBmriYoy').innerHTML = k.bmri_yoy_pct === null ? '-' : `<span style="color:${colChg(k.bmri_yoy_pct)}">${arrow(k.bmri_yoy_pct)} ${fmtChg(k.bmri_yoy_pct)}</span>`;
                $('msKpiMsYoy').innerHTML = k.ms_yoy_point === null ? '-' : `<span style="color:${colChg(k.ms_yoy_point)}">${arrow(k.ms_yoy_point)} ${fmtPpt(k.ms_yoy_point)}</span>`;
                $('msKpiMsYoyBig').innerHTML = k.ms_yoy_point === null ? '-' : `<span style="color:${colChg(k.ms_yoy_point)}">${fmtPpt(k.ms_yoy_point)}</span>`;
                // Ranking dinamis: ikut level filter terdalam yg aktif
                const ri = d.rank_info;
                const rankCard = $('msKpiRank').closest('.ms2-card');
                const rankLabelEl = rankCard ? rankCard.querySelector('.ms2-k-label') : null;
                if (ri && ri.rank) {
                    $('msKpiRank').textContent = '#' + ri.rank;
                    $('msKpiRankSub').textContent = `dari ${ri.total} ${String(ri.level_label||'').toLowerCase()} (data tersedia)`;
                    if (rankLabelEl) rankLabelEl.textContent = 'Ranking ' + ri.level_label;
                } else if (ri && ri.level) {
                    $('msKpiRank').textContent = '-';
                    $('msKpiRankSub').textContent = `tidak ada data ${String(ri.level_label||'').toLowerCase()}`;
                    if (rankLabelEl) rankLabelEl.textContent = 'Ranking ' + ri.level_label;
                } else {
                    $('msKpiRank').textContent = '-';
                    $('msKpiRankSub').textContent = 'pilih satu wilayah';
                    if (rankLabelEl) rankLabelEl.textContent = 'Ranking Wilayah';
                }
            }

            // ---------- Map ----------
            function initMap() {
                if (!window.L || state.map) return;
                state.map = L.map($('msMap'), {
                    zoomControl: true,
                    scrollWheelZoom: true
                }).setView([-8.6, 117.5], 7);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap',
                    maxZoom: 18
                }).addTo(state.map);
                // Pane khusus untuk pin cabang agar selalu di atas polygon dan label.
                if (!state.map.getPane('msCabPane')) {
                    state.map.createPane('msCabPane');
                    state.map.getPane('msCabPane').style.zIndex = 680;
                }
            }

            function clearMap() {
                if (state.choroplethLayer) {
                    state.map.removeLayer(state.choroplethLayer);
                    state.choroplethLayer = null;
                }
                if (state.markerLayer) {
                    state.map.removeLayer(state.markerLayer);
                    state.markerLayer = null;
                }
            }
            async function loadGeoOnce() {
                if (state.geoJsonData) return state.geoJsonData;
                for (const url of MS_GEOJSON_URLS) {
                    try {
                        const r = await fetch(url);
                        if (!r.ok) continue;
                        state.geoJsonData = await r.json();
                        return state.geoJsonData;
                    } catch (_) {}
                }
                return null;
            }

            const metricVal = r => state.metric === 'market' ? r.market : state.metric === 'bmri' ? r.bmri : r.market_share;
            const metricLabel = r => state.metric === 'ms' ? fmtPct(r.market_share) : fmtMU(metricVal(r));

            async function renderMap(d, fit = true) {
                if (!state.map) return;
                clearMap();
                const rows = (d.map_levels?.kabupaten || d.map || []).filter(r => metricVal(r) !== null);
                if (!rows.length) {
                    $('msMapSubtitle').textContent = 'Tidak ada data untuk scope ini.';
                    return;
                }
                await loadGeoOnce();

                let mn = Infinity,
                    mx = -Infinity;
                if (state.metric !== 'ms') rows.forEach(r => {
                    const v = metricVal(r);
                    if (v != null) {
                        mn = Math.min(mn, v);
                        mx = Math.max(mx, v);
                    }
                });
                const colorFor = r => {
                    if (state.metric === 'ms') return colorMsBucket(r.market_share);
                    const v = metricVal(r);
                    if (v == null) return '#e5e7eb';
                    const t = mx > mn ? (v - mn) / (mx - mn) : .5;
                    return rampBlue(t);
                };
                const byName = {};
                rows.forEach(r => byName[normalizeName(r.name || r.kabupaten)] = r);
                const isSel = r => state.selectedKab && String(r.name) === String(state.selectedKab);

                const focus = L.latLngBounds([]);

                // choropleth
                if (state.showChoropleth && state.geoJsonData) {
                    const g = JSON.parse(JSON.stringify(state.geoJsonData));
                    (g.features || []).forEach(f => {
                        const pr = f.properties || {};
                        let dr = null;
                        for (const k of MS_GEOJSON_NAME_KEYS) {
                            if (!pr[k]) continue;
                            const n = normalizeName(pr[k]);
                            if (byName[n]) {
                                dr = byName[n];
                                break;
                            }
                        }
                        f.properties.__d = dr;
                    });
                    state.choroplethLayer = L.geoJSON(g, {
                        style: f => {
                            const r = f.properties.__d;
                            return {
                                fillColor: r ? colorFor(r) : '#eef2f6',
                                weight: r && isSel(r) ? 2.5 : 1,
                                color: r && isSel(r) ? '#0f172a' : '#fff',
                                fillOpacity: r ? 0.82 : 0.18
                            };
                        },
                        onEachFeature: (f, layer) => {
                            const r = f.properties.__d;
                            if (!r) return;
                            try {
                                focus.extend(layer.getBounds());
                            } catch (_) {}
                            layer.bindTooltip(`<div class="name">${esc(shortKab(r.name))}</div><div class="val">${metricLabel(r)}</div>`, {
                                permanent: true,
                                direction: 'center',
                                className: 'ms-map-label',
                                interactive: false,
                                opacity: 1
                            });
                            layer.bindPopup(`<strong>${esc(shortKab(r.name))}</strong><br>MS: <strong>${fmtPct(r.market_share)}</strong><br>BMRI: ${fmtMU(r.bmri)} · Market: ${fmtMU(r.market)}<br><span style="color:#64748b;font-size:.72rem;">${r.branch_count} cabang</span>`);
                            layer.on('mouseover', () => {
                                if (!isSel(r)) layer.setStyle({
                                    weight: 2.2,
                                    color: '#2563eb'
                                });
                            });
                            layer.on('mouseout', () => {
                                if (!isSel(r)) layer.setStyle({
                                    weight: 1,
                                    color: '#fff'
                                });
                            });
                            layer.on('click', () => selectKab(r));
                        }
                    }).addTo(state.map);
                }

                // branch pins (teardrop, uniform color)
                const cab = (d.map_levels?.cabang || []).filter(r => r.lat != null && r.lng != null);
                cab.forEach(r => focus.extend([r.lat, r.lng]));
                if (state.showCabang && cab.length) {
                    const pinIcon = L.divIcon({
                        className: 'ms-cab-pin',
                        html: BRANCH_PIN_SVG,
                        iconSize: [22, 28],
                        iconAnchor: [11, 28],
                        popupAnchor: [0, -24]
                    });
                    const mg = L.featureGroup();
                    cab.forEach(r => {
                        const mk = L.marker([r.lat, r.lng], {
                            icon: pinIcon,
                            riseOnHover: true,
                            pane: 'msCabPane'
                        });
                        mk.bindTooltip(`<strong>${esc(r.cabang_name||r.name)}</strong> · ${esc(jenisOf(r))}<br>BMRI: ${fmtMU(r.bmri)} · MS: ${fmtPct(r.market_share)}`, {
                            direction: 'top',
                            offset: [0, -6]
                        });
                        mk.bindPopup(`<strong>${esc(r.cabang_name||r.name)}</strong> (${esc(r.cabang)})<br>Jenis: ${esc(jenisOf(r))}<br>Kab/Kota: ${esc(shortKab(r.kabupaten))}<br>BMRI: ${fmtMU(r.bmri)} · Market: ${fmtMU(r.market)}<br>MS: <strong>${fmtPct(r.market_share)}</strong>`);
                        mg.addLayer(mk);
                    });
                    mg.addTo(state.map);
                    state.markerLayer = mg;
                }

                if (fit && focus.isValid()) {
                    state.map.fitBounds(focus.pad(0.12), {
                        maxZoom: state.selectedKab ? 13 : 11,
                        padding: [18, 18]
                    });
                }

                $('msMapSubtitle').textContent = `Periode: ${d.month_label} · ${rows.length} kab/kota · ${cab.length} cabang berkoordinat`;
                $('msMapTitle').textContent = `${state.metric==='ms'?'Market Share':state.metric==='market'?'Total Market':'Total BMRI'} per Kabupaten/Kota`;
                setTimeout(() => state.map.invalidateSize(), 120);
            }

            function renderLegend() {
                const el = $('msLegend');
                let html;
                if (state.metric === 'ms') {
                    const items = [
                        ['> 20%', '#166534'],
                        ['15 – 20%', '#86efac'],
                        ['10 – 15%', '#fde047'],
                        ['5 – 10%', '#f59e0b'],
                        ['≤ 5%', '#dc2626']
                    ];
                    html = '<strong style="color:#475569;">Market Share (%)</strong>' +
                        items.map(([l, c]) => `<span><span class="sw" style="background:${c}"></span>${l}</span>`).join('');
                } else {
                    html = `<strong style="color:#475569;">${state.metric==='market'?'Total Market':'Total BMRI'} (M)</strong><span><span class="sw" style="background:${rampBlue(0)}"></span>Rendah</span><span><span class="sw" style="background:${rampBlue(.5)}"></span>Menengah</span><span><span class="sw" style="background:${rampBlue(1)}"></span>Tinggi</span>`;
                }
                html += `<span style="margin-left:auto;display:inline-flex;align-items:center;gap:5px;"><span style="display:inline-block;width:11px;height:14px;background:#dc2626;clip-path:path('M5.5 0C2.5 0 0 2.2 0 5.2c0 3.7 5.5 8.8 5.5 8.8s5.5-5.1 5.5-8.8C11 2.2 8.5 0 5.5 0z');"></span>Lokasi Cabang</span>`;
                el.innerHTML = html;
            }

            function selectKab(r) {
                state.filter.kabupaten.clear();
                state.filter.kabupaten.add(r.name);
                state.filter.cabang.clear();
                state.selectedKab = r.name;
                refreshGeoFilters();
                loadData();
            }

            // ---------- Detail ----------
            function renderDetail(d) {
                const k = d.kpi || {};
                const hasKab = !!d.selected_kabupaten;
                $('msBackBtn').style.display = hasKab ? 'inline-flex' : 'none';
                const prov = d.selected_provinsi || '';
                $('msDetailTitle').textContent = hasKab ? `${shortKab(d.selected_kabupaten)}${prov?', Prov. '+prov:''}` : (prov ? `Provinsi ${prov}` : 'Region XI');
                $('msDetMarket').textContent = fmtMU(k.market);
                $('msDetBmri').textContent = fmtMU(k.bmri);
                $('msDetMs').textContent = fmtPct(k.market_share);
                $('msDetBranches').textContent = (d.scope_branch_count || 0).toLocaleString('id-ID');
                $('msDetMarketYoy').innerHTML = k.market_yoy_pct === null ? '' : `<span style="color:${colChg(k.market_yoy_pct)}">${arrow(k.market_yoy_pct)} ${fmtChg(k.market_yoy_pct)}</span>`;
                $('msDetBmriYoy').innerHTML = k.bmri_yoy_pct === null ? '' : `<span style="color:${colChg(k.bmri_yoy_pct)}">${arrow(k.bmri_yoy_pct)} ${fmtChg(k.bmri_yoy_pct)}</span>`;
                $('msDetMsYoy').innerHTML = k.ms_yoy_point === null ? '' : `<span style="color:${colChg(k.ms_yoy_point)}">${arrow(k.ms_yoy_point)} ${fmtPpt(k.ms_yoy_point)}</span>`;
            }

            // ---------- Trend (metric + period selectable) ----------
            function renderTrend(d) {
                const T = d.trend || [];
                const chartHost = $('msTrendChart');

                // Update header title
                const titleEl = $('msTrendTitle');
                if (titleEl) {
                    const viewLbl = state.trendView === 'annual' ? 'Komparasi Tahunan' : 'Timeline Lanjut';
                    titleEl.textContent = `Tren — ${metricNameOf(state.trendMetric)} (${viewLbl})`;
                }

                if (state.trendChart) {
                    state.trendChart.destroy();
                    state.trendChart = null;
                }

                if (!T.length || typeof ApexCharts === 'undefined') {
                    chartHost.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px 0;">Tidak ada data tren.</div>';
                    renderProductTable(d);
                    return;
                }

                const metric = state.trendMetric;
                const view = state.trendView;
                const mainColor = metric === 'ms' ? '#0d9488' : metric === 'bmri' ? '#2563eb' : '#64748b';
                const isPct = (metric === 'ms');
                const yFmt = isPct ? (v => v == null ? '-' : Number(v).toFixed(1) + '%') : (v => v == null ? '-' : fmtMU(v));
                const dlFmt = isPct ? (v => v == null ? '' : Number(v).toFixed(1)) : (v => v == null ? '' : fmtM(v));
                const ttFmt = yFmt;

                let categories = [],
                    series = [],
                    colors = [mainColor];

                if (view === 'continuous') {
                    categories = T.map(x => x.label);
                    series = [{
                        name: metricNameOf(metric),
                        data: T.map(x => x[metric])
                    }];
                } else {
                    const yearsSet = {};
                    T.forEach(x => {
                        yearsSet[yrOf(x.month)] = true;
                    });
                    const years = Object.keys(yearsSet).map(Number).sort();
                    const latest = T[T.length - 1];
                    const curYear = latest ? yrOf(latest.month) : null;
                    const curMo = latest ? moOf(latest.month) : 12;
                    categories = MONTH_SHORT.slice(0, 12);

                    const palette = ['#cbd5e1', '#94a3b8', mainColor, '#0f172a', '#E10600'];
                    series = [];
                    colors = [];
                    years.forEach((yr, idx) => {
                        const data = [];
                        for (let m = 1; m <= 12; m++) {
                            if (yr === curYear && m > curMo) {
                                data.push(null);
                                continue;
                            }
                            const found = T.find(x => yrOf(x.month) === yr && moOf(x.month) === m);
                            data.push(found ? found[metric] : null);
                        }
                        series.push({
                            name: `${metricNameOf(metric)} ${yr}`,
                            data
                        });
                        colors.push(palette[idx] || palette[palette.length - 1]);
                    });
                    if (series.length) colors[colors.length - 1] = mainColor;
                }

                const opts = {
                    series,
                    chart: {
                        type: 'line',
                        height: 240,
                        toolbar: {
                            show: false
                        },
                        fontFamily: 'Inter, sans-serif',
                        background: 'transparent',
                        animations: {
                            enabled: true,
                            speed: 450
                        }
                    },
                    colors,
                    stroke: {
                        curve: 'smooth',
                        width: series.length > 1 ?
                            series.map((_, i) => i === series.length - 1 ? 3.2 : 2.2) : 3.2
                    },
                    markers: {
                        size: 4,
                        hover: {
                            size: 6
                        }
                    },
                    dataLabels: {
                        enabled: true,
                        formatter: dlFmt,
                        style: {
                            fontSize: '9px',
                            fontWeight: 700
                        },
                        background: {
                            enabled: true,
                            borderRadius: 3,
                            padding: 2,
                            opacity: .85,
                            borderWidth: 0
                        }
                    },
                    xaxis: {
                        categories,
                        labels: {
                            style: {
                                colors: '#64748b',
                                fontWeight: 700,
                                fontSize: '11px'
                            }
                        },
                        axisBorder: {
                            show: false
                        },
                        axisTicks: {
                            show: false
                        }
                    },
                    yaxis: {
                        labels: {
                            formatter: yFmt,
                            style: {
                                colors: '#64748b'
                            }
                        }
                    },
                    grid: {
                        borderColor: 'rgba(229,231,235,.5)',
                        strokeDashArray: 4
                    },
                    legend: {
                        show: series.length > 1,
                        position: 'top',
                        horizontalAlign: 'right',
                        fontWeight: 700,
                        markers: {
                            width: 10,
                            height: 10
                        }
                    },
                    tooltip: {
                        shared: true,
                        intersect: false,
                        y: {
                            formatter: ttFmt
                        }
                    },
                };
                state.trendChart = new ApexCharts(chartHost, opts);
                state.trendChart.render();

                // Render product table di bawahnya
                renderProductTable(d);
            }

            function renderProductTable(d) {
                const host = $('msProdTableWrap');
                const titleEl = $('msProdTitle');

                // Pilih data source: prioritas cabang_products kalau ada (single cabang),
                // selain itu pakai scope_products (semua scope lain).
                const isSingleCabang = !!(d.cabang_products && d.cabang_products.products);
                const rows = isSingleCabang ? d.cabang_products.products : (d.scope_products || []);

                // Update title sesuai konteks
                if (titleEl) {
                    if (isSingleCabang) {
                        titleEl.innerHTML = `Breakdown Produk — <span style="color:#2563eb;">${esc(d.cabang_products.cabang_name)}</span>`;
                    } else {
                        titleEl.innerHTML = `Breakdown Produk — <span style="color:#2563eb;">${esc(d.scope_label || 'Scope Aktif')}</span>`;
                    }
                }

                if (!rows.length) {
                    host.innerHTML = '<div style="padding:24px;text-align:center;color:#94a3b8;font-size:.78rem;">Tidak ada data produk.</div>';
                    return;
                }

                // Group: DPK vs KREDIT
                const dpkSet = new Set(['TABUNGAN', 'GIRO', 'DEPOSITO']);
                const dpkRows = rows.filter(p => dpkSet.has(String(p.product).toUpperCase()));
                const krRows = rows.filter(p => !dpkSet.has(String(p.product).toUpperCase()));

                const sumGroup = arr => arr.reduce((acc, r) => ({
                    market: (acc.market || 0) + (r.market || 0),
                    bmri: (acc.bmri || 0) + (r.bmri || 0),
                }), {
                    market: 0,
                    bmri: 0
                });

                const dpkTotal = sumGroup(dpkRows);
                const krTotal = sumGroup(krRows);
                dpkTotal.ms = dpkTotal.market > 0 ? (dpkTotal.bmri / dpkTotal.market) * 100 : null;
                krTotal.ms = krTotal.market > 0 ? (krTotal.bmri / krTotal.market) * 100 : null;

                // Filter sesuai prod group toggle
                const grp = state.prodGroup || 'ALL';

                // Color band 5-tier
                const msColor = ms => {
                    if (ms == null) return '#94a3b8';
                    if (ms > 20) return '#166534';
                    if (ms > 15) return '#15803d';
                    if (ms > 10) return '#a16207';
                    if (ms > 5) return '#c2410c';
                    return '#dc2626';
                };

                const buildRow = (r, isTotal = false) => {
                    const yoyHtml = !isTotal && r.ms_yoy_point != null ?
                        `<span style="color:${colChg(r.ms_yoy_point)};font-weight:700;font-size:.68rem;">${arrow(r.ms_yoy_point)} ${fmtPpt(r.ms_yoy_point)}</span>` :
                        '';
                    const cls = isTotal ? 'subtotal' : '';
                    return `<tr class="${cls}">
            <td>${esc(r.label)}</td>
            <td>${fmtM(r.market)}</td>
            <td>${fmtM(r.bmri)}</td>
            <td style="font-weight:800;color:${msColor(r.ms)};">${r.ms != null ? fmtPct(r.ms) : '-'}</td>
            <td>${yoyHtml || '<span style="color:#cbd5e1;">-</span>'}</td>
        </tr>`;
                };

                const sectionRow = (title, color) => `<tr class="section" style="color:${color};">
        <td colspan="5">${title}</td>
    </tr>`;

                let html = `<table class="ms2-prodtbl">
        <thead>
            <tr>
                <th>Produk</th>
                <th>Market (M)</th>
                <th>BMRI (M)</th>
                <th>MS%</th>
                <th>YoY MS</th>
            </tr>
        </thead>
        <tbody>`;

                if ((grp === 'ALL' || grp === 'DPK') && dpkRows.length) {
                    html += sectionRow('💰 Dana Pihak Ketiga', '#2563eb');
                    dpkRows.forEach(r => html += buildRow(r));
                    html += buildRow({
                        label: 'Subtotal DPK',
                        market: dpkTotal.market,
                        bmri: dpkTotal.bmri,
                        ms: dpkTotal.ms
                    }, true);
                }
                if ((grp === 'ALL' || grp === 'KREDIT') && krRows.length) {
                    html += sectionRow('💳 Kredit', '#dc2626');
                    krRows.forEach(r => html += buildRow(r));
                    html += buildRow({
                        label: 'Subtotal Kredit',
                        market: krTotal.market,
                        bmri: krTotal.bmri,
                        ms: krTotal.ms
                    }, true);
                }

                html += `</tbody></table>`;
                host.innerHTML = html;
            }
            // ---------- Kabupaten table ----------
            function renderKabTable(d) {
                const rows = (d.map_levels?.kabupaten || []).filter(r => r.market_share !== null);
                $('msKabTableTitle').textContent = `Daftar Kabupaten/Kota${d.selected_provinsi?` di Provinsi ${d.selected_provinsi}`:''}`;
                if (!rows.length) {
                    $('msKabTableBody').innerHTML = '<tr><td colspan="10" class="ms2-empty-cell">Tidak ada data.</td></tr>';
                    return;
                }
                const ranked = [...rows].sort((a, b) => (b.market_share) - (a.market_share));
                const rankMap = {};
                ranked.forEach((r, i) => rankMap[r.name] = i + 1);
                const ordered = [...rows].sort((a, b) => b.bmri - a.bmri);
                let tM = 0,
                    tB = 0;
                rows.forEach(r => {
                    tM += r.market || 0;
                    tB += r.bmri || 0;
                });
                const tMs = tM > 0 ? (tB / tM) * 100 : null;
                let h = '';
                ordered.forEach((r, i) => {
                    const sel = String(r.name) === String(d.selected_kabupaten);
                    h += `<tr class="clk ${sel?'sel':''}" data-ms-kab="${esc(r.name)}">
                        <td>${i+1}</td><td>${esc(shortKab(r.name))}</td>
                        <td>${fmtM(r.market)}</td><td style="color:${colChg(r.market_yoy_pct)};font-weight:700;">${fmtChg(r.market_yoy_pct)}</td>
                        <td>${fmtM(r.bmri)}</td><td style="color:${colChg(r.bmri_yoy_pct)};font-weight:700;">${fmtChg(r.bmri_yoy_pct)}</td>
                        <td style="font-weight:800;color:${r.market_share>=50?'#15803d':r.market_share>=30?'#a16207':'#dc2626'};">${fmtPct(r.market_share)}</td>
                        <td style="color:${colChg(r.ms_yoy_point)};font-weight:700;">${fmtPpt(r.ms_yoy_point)}</td>
                        <td>${r.branch_count||0}</td>
                        <td><span style="display:inline-block;min-width:20px;padding:1px 6px;border-radius:6px;background:#f1f5f9;font-weight:800;">${rankMap[r.name]||'-'}</span></td>
                    </tr>`;
                });
                h += `<tr class="total"><td></td><td>TOTAL</td><td>${fmtM(tM)}</td><td></td><td>${fmtM(tB)}</td><td></td><td>${fmtPct(tMs)}</td><td></td><td>${rows.reduce((s,r)=>s+(r.branch_count||0),0)}</td><td></td></tr>`;
                $('msKabTableBody').innerHTML = h;
                $('msKabTableBody').querySelectorAll('[data-ms-kab]').forEach(tr => tr.addEventListener('click', () => {
                    const name = tr.dataset.msKab;
                    const row = rows.find(x => String(x.name) === String(name));
                    if (row) selectKab(row);
                }));
            }

            // ---------- Cabang table ----------
            function renderCabTable(d) {
                let rows = (d.map_levels?.cabang || []).filter(r => r.bmri !== null);
                if (state.selectedKab) rows = rows.filter(r => String(r.kabupaten) === String(state.selectedKab));
                if (state.cabSearch) rows = rows.filter(r => (`${r.cabang_name} ${r.cabang}`).toLowerCase().includes(state.cabSearch));
                $('msCabTableTitle').textContent = `Daftar Cabang${state.selectedKab?` di ${shortKab(state.selectedKab)}`:''}`;
                if (!rows.length) {
                    $('msCabTableBody').innerHTML = '<tr><td colspan="7" class="ms2-empty-cell">Tidak ada cabang.</td></tr>';
                    return;
                }
                rows.sort((a, b) => b.bmri - a.bmri);
                let h = '';
                rows.forEach((r, i) => {
                    h += `<tr>
                        <td>${i+1}</td><td>${esc(r.cabang_name||r.name)}</td>
                        <td><span style="padding:1px 7px;border-radius:6px;background:#eef2ff;color:#3730a3;font-weight:800;font-size:.7rem;">${esc(jenisOf(r))}</span></td>
                        <td>${fmtM(r.bmri)}</td><td style="color:${colChg(r.bmri_yoy_pct)};font-weight:700;">${fmtChg(r.bmri_yoy_pct)}</td>
                        <td style="font-weight:800;color:${r.market_share>=50?'#15803d':r.market_share>=30?'#a16207':'#dc2626'};">${fmtPct(r.market_share)}</td>
                        <td style="color:${colChg(r.ms_yoy_point)};font-weight:700;">${fmtPpt(r.ms_yoy_point)}</td>
                    </tr>`;
                });
                $('msCabTableBody').innerHTML = h;
            }

            // ---------- boot ----------
            let started = false;

            function bootOnce() {
                if (started) return;
                started = true;
                init();
            }
            const tabBtn = document.getElementById('ms-tab-button');
            if (tabBtn) tabBtn.addEventListener('shown.bs.tab', () => {
                bootOnce();
                setTimeout(() => {
                    if (state.map) state.map.invalidateSize();
                }, 120);
            });
            const pane = document.getElementById('ms-tab');
            if (pane && pane.classList.contains('active') && pane.classList.contains('show')) bootOnce();
            if (!tabBtn) bootOnce();
        })();
    </script>
<?php
    return ob_get_clean();
}

function handleAction(
    string $action,
    string $danaCacheFile,
    string $kreditCacheFile,
    string $labarugiCacheFile,
    string $uploadDir,
    string $usersFile,
    string $activityFile,
    ?array $currentUser,
    string $updateDatesFile = '',
    string $gmmDbFile = '',
    string $marketshareCacheFile = ''     // ← TAMBAH
): void {
    try {
        if ($action === 'logout') {
            logoutUser();
            jsonResponse(['ok' => true, 'redirect' => currentPageUrl()]);
            return;
        }


        if ($action === 'meta') {
            requireLoginJson($currentUser);
            handleMeta($danaCacheFile, $kreditCacheFile, $currentUser);
            return;
        }


        if ($action === 'data') {
            requireLoginJson($currentUser);
            handleData($danaCacheFile, $kreditCacheFile, $activityFile, $currentUser);
            return;
        }


        if ($action === 'upload') {
            requireAdminJson($currentUser);

            $type = strtolower(trim((string)($_POST['type'] ?? '')));

            if ($type === 'kredit') {
                handleUpload($kreditCacheFile, $uploadDir, $usersFile);
            } else {
                handleUpload($danaCacheFile, $uploadDir, $usersFile);
            }

            return;
        }


        if ($action === 'delete_cache') {
            requireAdminJson($currentUser);

            $type = strtolower(trim((string)($_POST['type'] ?? '')));

            if ($type === 'kredit') {
                handleDeleteCache($kreditCacheFile);
            } else {
                handleDeleteCache($danaCacheFile);
            }

            return;
        }


        if ($action === 'admin_data') {
            requireAdminJson($currentUser);
            handleAdminData($usersFile, $activityFile);
            return;
        }


        if ($action === 'save_user') {
            requireAdminJson($currentUser);
            handleUserSaveApi($usersFile);
            return;
        }


        if ($action === 'delete_user') {
            requireAdminJson($currentUser);
            handleUserDeleteApi($usersFile, $currentUser);
            return;
        }


        if ($action === 'login') {
            handleLoginApi($usersFile, $activityFile);
            return;
        }


        if ($action === 'get_update_dates') {
            requireLoginJson($currentUser);
            $dates = loadJsonFile($updateDatesFile, ['produk_dana' => '', 'produk_kredit' => '', 'gmm' => '']);
            jsonResponse(['ok' => true, 'dates' => $dates]);
            return;
        }


        if ($action === 'save_update_dates') {
            requireAdminJson($currentUser);
            $dates = [
                'produk_dana' => trim((string)($_POST['produk_dana'] ?? '')),
                'produk_kredit' => trim((string)($_POST['produk_kredit'] ?? '')),
                'gmm' => trim((string)($_POST['gmm'] ?? '')),
            ];
            writeJsonFile($updateDatesFile, $dates);
            jsonResponse(['ok' => true, 'message' => 'Tanggal update berhasil disimpan.', 'dates' => $dates]);
            return;
        }


        if ($action === 'gmm_upload') {
            requireAdminJson($currentUser);
            handleGmmUpload($gmmDbFile);
            return;
        }


        if ($action === 'gmm_data') {
            requireLoginJson($currentUser);
            handleGmmData($gmmDbFile, $currentUser);
            return;
        }


        if ($action === 'gmm_reset') {
            requireAdminJson($currentUser);
            if (is_file($gmmDbFile)) @unlink($gmmDbFile);
            jsonResponse(['ok' => true, 'message' => 'Database GMM berhasil direset.']);
            return;
        }
        if ($action === 'kredit_summary') {
            requireLoginJson($currentUser);
            handleKreditSummaryAll($kreditCacheFile, $currentUser);
            return;
        }
        // === Laba Rugi actions ===
        if ($action === 'labarugi_meta') {
            handleLabaRugiMeta($labarugiCacheFile, publicUser($currentUser));
            return;
        }
        if ($action === 'labarugi_data') {
            handleLabaRugiData($labarugiCacheFile, $activityFile, $currentUser);
            return;
        }
        if ($action === 'labarugi_upload') {
            if (!isAdmin($currentUser)) {
                jsonResponse(['error' => 'Hanya admin yang dapat upload'], 403);
                return;
            }
            handleLabaRugiUpload($labarugiCacheFile, $uploadDir);
            return;
        }
        if ($action === 'labarugi_delete_cache') {
            if (!isAdmin($currentUser)) {
                jsonResponse(['error' => 'Hanya admin'], 403);
                return;
            }
            handleLabaRugiDeleteCache($labarugiCacheFile);
            return;
        }

        // === Market Share actions ===
        if ($action === 'marketshare_meta') {
            requireLoginJson($currentUser);
            handleMarketShareMeta($marketshareCacheFile, $currentUser);
            return;
        }
        if ($action === 'marketshare_data') {
            requireLoginJson($currentUser);
            handleMarketShareData($marketshareCacheFile, $activityFile, $currentUser);
            return;
        }
        if ($action === 'marketshare_upload') {
            requireAdminJson($currentUser);
            handleMarketShareUpload($marketshareCacheFile, $uploadDir);
            return;
        }
        if ($action === 'marketshare_delete_cache') {
            requireAdminJson($currentUser);
            handleMarketShareDeleteCache($marketshareCacheFile);
            return;
        }


        jsonResponse(['ok' => false, 'message' => 'Action tidak dikenal.'], 404);
    } catch (Throwable $exception) {
        jsonResponse([
            'ok' => false,
            'message' => $exception->getMessage(),
        ], 500);
    }
}


function handleWebRequest(string $usersFile, string $danacacheFile, string $activityFile, ?array $currentUser): void
{
    if (isset($_GET['logout'])) {
        logoutUser();
        redirectTo(currentPageUrl());
    }


    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }


    $formAction = (string) ($_POST['form_action'] ?? '');


    if ($formAction === 'login') {
        $nip = trim((string) ($_POST['nip'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $user = authenticateUser($usersFile, $nip, $password);
        if ($user === null) {
            setFlash('danger', 'Login gagal. Periksa NIP dan password Anda.');
            redirectTo(currentPageUrl());
        }


        loginUser($user);
        recordActivity($activityFile, $user, 'login', ['source' => 'form']);
        redirectTo(currentPageUrl(['page' => 'hub']));
    }


    if ($formAction === 'signup') {
        try {
            handleSignup($usersFile, $_POST);
            setFlash('success', 'Registrasi berhasil. Silakan login menggunakan NIP Anda.');
            redirectTo(currentPageUrl());
        } catch (Throwable $e) {
            setFlash('danger', $e->getMessage());
            redirectTo(currentPageUrl());
        }
    }


    if ($formAction === 'logout') {
        logoutUser();
        redirectTo(currentPageUrl());
    }


    if ($currentUser === null || !isAdmin($currentUser)) {
        setFlash('danger', 'Akses ditolak.');
        redirectTo(currentPageUrl());
    }


    try {
        if ($formAction === 'save_user') {
            saveUserFromRequest($usersFile, $currentUser);
            redirectTo(currentPageUrl(['page' => 'admin']));
        }


        if ($formAction === 'delete_user') {
            deleteUserFromRequest($usersFile, $currentUser);
            redirectTo(currentPageUrl(['page' => 'admin']));
        }
    } catch (Throwable $exception) {
        setFlash('danger', $exception->getMessage());
        redirectTo(currentPageUrl(['page' => 'admin']));
    }
}


function handleSignup(string $usersFile, array $post): void
{
    $store = loadUserStore($usersFile);
    $nip = trim((string) ($post['nip'] ?? ''));
    $name = trim((string) ($post['name'] ?? ''));
    $jabatan = trim((string) ($post['jabatan'] ?? ''));
    $branchCombo = trim((string) ($post['branch_combo'] ?? ''));
    $password = (string) ($post['password'] ?? '');
    $passwordConfirm = (string) ($post['password_confirm'] ?? '');
    $privacy = (string) ($post['privacy_agreement'] ?? '');


    if ($nip === '' || $name === '' || $password === '' || $jabatan === '' || $branchCombo === '') {
        throw new RuntimeException('Semua kolom wajib diisi.');
    }


    if (!preg_match('/^\d{10}$/', $nip)) {
        throw new RuntimeException('Format NIP tidak valid. NIP harus berupa 10 digit angka.');
    }


    if ($password !== $passwordConfirm) {
        throw new RuntimeException('Verifikasi password tidak cocok. Silakan ketik ulang.');
    }


    if (empty($privacy)) {
        throw new RuntimeException('Anda harus menyetujui pernyataan privasi data.');
    }


    foreach ($store['users'] as $u) {
        if ((string) ($u['nip'] ?? '') === $nip || (string) $u['id'] === $nip) {
            throw new RuntimeException('NIP sudah terdaftar.');
        }
    }


    $parts = explode('-', $branchCombo, 2);
    $branchId = trim($parts[0]);
    $branchName = isset($parts[1]) ? trim($parts[1]) : $branchId;


    $userId = $nip;
    $now = date('c');


    $store['users'][$userId] = [
        'id' => $userId,
        'nip' => $nip,
        'name' => $name,
        'jabatan' => $jabatan,
        'role' => 'Visitor',
        'branch_id' => $branchId,
        'branch_name' => $branchName,
        'area' => '',
        'status' => 'Active',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => $now,
        'updated_at' => $now,
        'source' => 'signup',
    ];
    saveUserStore($usersFile, $store);
}


function handleMeta(string $danaCacheFile, string $kreditCacheFile,  array $currentUser): void
{
    $cache = loadCache($danaCacheFile);
    $setup = nativeXlsxSetupStatus();


    if ($cache === null) {
        jsonResponse([
            'ok' => true,
            'cached' => false,
            'reader' => 'native-xlsx',
            'has_dependency' => $setup['ready'],
            'missing_extensions' => $setup['missing_extensions'],
            'setup_ready' => $setup['ready'],
            'setup_message' => $setup['message'],
            'products' => [],
            'product_groups' => buildProductGroupMeta([]),
            'branches' => [],
            'months' => [],
            'user' => publicUser($currentUser),
        ]);
        return;
    }


    $filteredCache = filterCacheForUser($cache, $currentUser);


    jsonResponse([
        'ok' => true,
        'cached' => true,
        'reader' => $filteredCache['reader'] ?? 'native-xlsx',
        'has_dependency' => $setup['ready'],
        'missing_extensions' => $setup['missing_extensions'],
        'setup_ready' => $setup['ready'],
        'setup_message' => $setup['message'],
        'source_file' => $filteredCache['source_file'] ?? null,
        'generated_at' => $filteredCache['generated_at'] ?? null,
        'year' => $filteredCache['year'] ?? (int) date('Y'),
        'products' => $filteredCache['meta']['products'] ?? [],
        'product_groups' => buildProductGroupMeta($filteredCache['meta']['products'] ?? []),
        'branches' => array_values($filteredCache['meta']['branches'] ?? []),
        'months' => $filteredCache['meta']['months'] ?? [],
        'min_date' => $filteredCache['meta']['min_date'] ?? null,
        'max_date' => $filteredCache['meta']['max_date'] ?? null,
        'stats' => $filteredCache['meta']['stats'] ?? [],
        'sheets' => $filteredCache['meta']['sheets'] ?? [],
        'skipped_sheets' => $filteredCache['meta']['skipped_sheets'] ?? [],
        'user' => publicUser($currentUser),
    ]);
}


function handleData(
    string $danaCacheFile,
    string $kreditCacheFile, // 🔥 TAMBAH
    string $activityFile,
    array $currentUser
): void {
    $group = trim((string) ($_GET['group'] ?? ''));

    // 👉 kalau kredit, lempar ke handler kredit
    if ($group === 'kredit') {
        handleKreditData($kreditCacheFile, $activityFile, $currentUser);
        return;
    }

    $cache = loadCache($danaCacheFile);

    if ($cache === null) {
        jsonResponse([
            'ok' => false,
            'message' => 'Data DANA belum tersedia. Upload dulu.'
        ], 404);
        return;
    }


    $cache = filterCacheForUser($cache, $currentUser);
    $products = $cache['meta']['products'] ?? [];
    $product = trim((string) ($_GET['product'] ?? ''));
    if ($product === '' && count($products) > 0) {
        $product = (string) $products[0];
    }


    if ($product === '' || !isset($cache['index'][$product])) {
        jsonResponse(['ok' => false, 'message' => 'Produk tidak ditemukan di cache.'], 404);
        return;
    }


    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '' && !isAdmin($currentUser)) {
        $id = (string) ($currentUser['branch_id'] ?? '');
    }


    $period = strtoupper(trim((string) ($_GET['period'] ?? 'MTD')));
    if (!in_array($period, ['MTD', 'MOM', '3M', '3MD', 'YTD', 'YOY'], true)) {
        $period = 'MTD';
    }


    $selectedMonth = trim((string) ($_GET['month'] ?? ''));


    $productIndex = $cache['index'][$product];
    $selectedBranches = selectBranches($productIndex, $id);
    if ($selectedBranches === []) {
        jsonResponse([
            'ok' => false,
            'message' => 'Entitas (Cabang/Area/Region) tidak ditemukan pada data Excel.',
        ], 404);
        return;
    }


    $allMonths = collectProductMonths($productIndex);
    if ($selectedMonth !== '') {
        $filteredAllMonths = [];
        foreach ($allMonths as $m) {
            if ($m <= $selectedMonth) {
                $filteredAllMonths[] = $m;
            }
        }
        if ($filteredAllMonths !== []) {
            $allMonths = $filteredAllMonths;
        }
    }
    $allSeries = buildMonthlySeries($selectedBranches, $allMonths);
    $summary = summarizeSeries($allSeries);


    $chartMonths = selectPeriodMonths($allMonths, $period);
    $series = array_values(array_filter($allSeries, static fn($s) => in_array($s['month_key'], $chartMonths, true)));
    $latestBalance = getLatestBalance($series);


    $comparisonSeries = [];
    $currentMonthKey = end($chartMonths);
    // Di handleData(), setelah $series difilter periode:
    $maxBalanceInPeriod = null;
    foreach ($series as $s) {
        foreach ($s['data'] as $val) {
            if ($val !== null && ($maxBalanceInPeriod === null || $val > $maxBalanceInPeriod)) {
                $maxBalanceInPeriod = $val;
            }
        }
    }

    // Tambahkan ke summary:
    $summary['max_balance'] = $maxBalanceInPeriod;
    $allSeriesSummary = array_map(static fn($s) => [
        'month_key'    => $s['month_key'],
        'name'         => $s['name'],
        'end_value'    => $s['end_value'],
        'bottom_value' => $s['bottom_value'],
    ], $allSeries);


    foreach ($cache['index'] as $sheetName => $sheetData) {
        if (str_starts_with(strtoupper($sheetName), 'X') && stripos($sheetName, $product) !== false) {
            $compBranches = selectBranches($sheetData, $id);
            if ($compBranches !== []) {
                $compAllMonths = collectProductMonths($sheetData);
                $compAllSeries = buildMonthlySeries($compBranches, $compAllMonths);


                foreach ($compAllSeries as $compS) {
                    if ($compS['month_key'] === $currentMonthKey) {
                        $comparisonSeries[] = [
                            'name' => $sheetName,
                            'type' => 'column',
                            'data' => $compS['data']
                        ];
                        break;
                    }
                }
            }
        }
    }


    recordActivity($activityFile, $currentUser, 'view_data', [
        'product' => $product,
        'period' => $period,
        'id' => $id,
    ]);


    jsonResponse([
        'ok'               => true,
        'product'          => $product,
        'group'            => detectProductGroup($product),
        'period'           => $period,
        'id'               => $id,
        'label'            => buildSelectionLabel($id, $selectedBranches),
        'months'           => $chartMonths,
        'series'           => $series,
        'all_series_summary' => $allSeriesSummary,   // TAMBAH
        'comparison_series'  => $comparisonSeries,
        'latest_balance'     => $latestBalance,
        'summary'            => $summary,
        'source_file'        => $cache['source_file'] ?? null,
        'generated_at'       => $cache['generated_at'] ?? null,
        'user'               => publicUser($currentUser),
    ]);
}


function handleKreditData(string $kreditCacheFile, string $activityFile, array $currentUser): void
{
    $cache = loadCache($kreditCacheFile);

    if ($cache === null) {
        jsonResponse([
            'ok' => false,
            'message' => 'Data KREDIT belum tersedia. Upload dulu.'
        ], 404);
        return;
    }


    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '' && !isAdmin($currentUser)) {
        $id = (string) ($currentUser['branch_id'] ?? '');
    }


    $productsParam = trim((string)($_GET['products'] ?? ''));
    $modesParam = trim((string)($_GET['modes'] ?? ''));
    $viewMode = trim((string)($_GET['view_mode'] ?? 'continuous')); // 'continuous' or 'annual'


    if ($productsParam === '') {
        jsonResponse(['ok' => false, 'message' => 'Produk belum dipilih.'], 400);
        return;
    }


    $selectedProducts = explode(',', $productsParam);
    $selectedModes = $modesParam !== '' ? explode(',', $modesParam) : ['endbal'];


    $series = [];
    $categories = [];
    $allMonths = [];
    $dataMatrix = [];
    $anySelectedBranches = []; // For building label


    foreach ($selectedProducts as $prod) {
        foreach ($selectedModes as $mode) {
            $sheetName = $prod;
            $labelSuffix = 'Endbal';
            if ($mode === 'kol') {
                $sheetName = "X" . $prod . "_1";
                $labelSuffix = 'KOL';
            } elseif ($mode === 'npl') {
                $sheetName = "X" . $prod . "_2";
                $labelSuffix = 'NPL';
            }


            $actualSheet = null;
            foreach (array_keys($cache['index']) as $cSheet) {
                if (strcasecmp($cSheet, $sheetName) === 0) {
                    $actualSheet = $cSheet;
                    break;
                }
            }


            if ($actualSheet !== null) {
                $sheetData = $cache['index'][$actualSheet];
                $branches = selectBranches($sheetData, $id);
                if ($branches !== []) {
                    if (empty($anySelectedBranches)) {
                        $anySelectedBranches = $branches;
                    }


                    $monthToMaxDate = [];
                    foreach ($branches as $branch) {
                        foreach (($branch['dates'] ?? []) as $date => $val) {
                            $ym = substr((string)$date, 0, 7);
                            if (!isset($monthToMaxDate[$ym]) || $date > $monthToMaxDate[$ym]) {
                                $monthToMaxDate[$ym] = $date;
                            }
                        }
                    }


                    $monthlySums = [];
                    foreach ($monthToMaxDate as $ym => $maxDate) {
                        $sum = 0;
                        foreach ($branches as $branch) {
                            if (isset($branch['dates'][$maxDate])) {
                                $sum += (float) $branch['dates'][$maxDate];
                            }
                        }
                        $monthlySums[$ym] = normalizeOutputNumber($sum);
                        $allMonths[$ym] = $ym;
                    }


                    $seriesName = $prod . ' - ' . $labelSuffix;
                    $dataMatrix[$seriesName] = $monthlySums;
                }
            }
        }
    }


    ksort($allMonths, SORT_NATURAL);


    // Build Summary calculation
    $totalMonthly = [];
    foreach ($allMonths as $ym) {
        $sum = 0;
        foreach ($dataMatrix as $sName => $sData) {
            if (isset($sData[$ym])) {
                $sum += $sData[$ym];
            }
        }
        $totalMonthly[$ym] = $sum;
    }


    $latestYm = end($allMonths);
    $summary = [];
    if ($latestYm !== false) {
        $year = (int) substr($latestYm, 0, 4);
        $month = (int) substr($latestYm, 5, 2);


        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth === 0) {
            $prevMonth = 12;
            $prevYear--;
        }
        $prevYm = sprintf('%04d-%02d', $prevYear, $prevMonth);
        $ytdYm = sprintf('%04d-12', $year - 1);
        $yoyYm = sprintf('%04d-%02d', $year - 1, $month);


        $currentVal = $totalMonthly[$latestYm] ?? null;
        $prevVal = $totalMonthly[$prevYm] ?? null;
        $ytdVal = $totalMonthly[$ytdYm] ?? null;
        $yoyVal = $totalMonthly[$yoyYm] ?? null;


        $summary = [
            'current_month' => formatYearMonth($latestYm),
            'previous_month' => formatYearMonth($prevYm),
            'end_balance' => $currentVal,
            'growth_mtd_nominal' => growthNominal($currentVal, $prevVal),
            'growth_mtd_percent' => growthPercent($currentVal, $prevVal),
            'growth_ytd_nominal' => growthNominal($currentVal, $ytdVal),
            'growth_ytd_percent' => growthPercent($currentVal, $ytdVal),
            'growth_yoy_nominal' => growthNominal($currentVal, $yoyVal),
            'growth_yoy_percent' => growthPercent($currentVal, $yoyVal),
        ];
    }


    if ($viewMode === 'continuous') {
        foreach ($allMonths as $ym) {
            $categories[] = formatYearMonth($ym);
        }
        foreach ($dataMatrix as $sName => $sData) {
            $dataArr = [];
            foreach ($allMonths as $ym) {
                $dataArr[] = $sData[$ym] ?? null;
            }
            $series[] = [
                'name' => $sName,
                'type' => str_contains($sName, 'Endbal') ? 'line' : 'line',
                'data' => $dataArr
            ];
        }
    } else {
        $monthsMap = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu', '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'];
        $categories = array_values($monthsMap);
        $years = [];
        foreach ($allMonths as $ym) {
            $years[substr($ym, 0, 4)] = true;
        }
        ksort($years);


        foreach ($dataMatrix as $sName => $sData) {
            foreach (array_keys($years) as $year) {
                $dataArr = [];
                $hasData = false;
                foreach ($monthsMap as $mNum => $mLabel) {
                    $ym = $year . '-' . $mNum;
                    $val = $sData[$ym] ?? null;
                    $dataArr[] = $val;
                    if ($val !== null) $hasData = true;
                }
                if ($hasData) {
                    $series[] = [
                        'name' => $sName . ' (' . $year . ')',
                        'type' => str_contains($sName, 'Endbal') ? 'line' : 'line',
                        'data' => $dataArr
                    ];
                }
            }
        }
    }


    recordActivity($activityFile, $currentUser, 'view_kredit', [
        'products' => $productsParam,
        'modes' => $modesParam,
        'view_mode' => $viewMode,
        'id' => $id,
    ]);


    jsonResponse([
        'ok' => true,
        'group' => 'kredit',
        'id' => $id,
        'label' => buildSelectionLabel($id, $anySelectedBranches),
        'categories' => $categories,
        'series' => $series,
        'summary' => $summary,
        'user' => publicUser($currentUser)
    ]);
}

function handleKreditSummaryAll(string $kreditCacheFile, array $currentUser): void
{
    $cache = loadCache($kreditCacheFile);
    if ($cache === null) {
        jsonResponse(['ok' => false, 'message' => 'Data KREDIT belum tersedia. Upload dulu.'], 404);
        return;
    }

    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '' && !isAdmin($currentUser)) {
        $id = (string)($currentUser['branch_id'] ?? '');
    }

    // Urutan produk + label tampilan (sesuai gambar)
    $productConfig = [
        ['key' => 'KreditRetail',    'label' => 'Kredit Retail',   'bold' => true],
        ['key' => 'SME',             'label' => 'SME',              'bold' => false],
        ['key' => 'ConsumerBanking', 'label' => 'Kredit Cons Bank', 'bold' => true],
        ['key' => 'ConsumerLoan',    'label' => 'Consumer Loan',    'bold' => false],
        ['key' => 'KKB',             'label' => 'Auto Loan',        'bold' => false],
        ['key' => 'CreditCard',      'label' => 'Credit Card',      'bold' => false],
        ['key' => 'Micro',           'label' => 'Micro',            'bold' => false],
        ['key' => 'KSM',             'label' => 'New KSM',          'bold' => false],
        ['key' => 'KUMBlend',        'label' => 'KUM Blended',      'bold' => true],
        ['key' => 'KUM',             'label' => 'KUM',              'bold' => false],
        ['key' => 'KUR',             'label' => 'KUR',              'bold' => false],
    ];

    $products = array_keys($cache['index'] ?? []);

    // Normalisasi nama sheet untuk matching fleksibel (abaikan spasi & huruf besar/kecil)
    $normalizeKey = static fn(string $s): string => strtolower(preg_replace('/[^a-z0-9]/i', '', $s));

    // Cari tanggal terbaru dari semua produk
    $globalLatestYm = null;
    foreach ($products as $p) {
        if (str_starts_with(strtoupper($p), 'X')) continue;
        foreach ($cache['index'][$p] ?? [] as $branch) {
            foreach (array_keys($branch['dates'] ?? []) as $date) {
                $ym = substr($date, 0, 7);
                if ($globalLatestYm === null || $ym > $globalLatestYm) $globalLatestYm = $ym;
            }
        }
    }

    if ($globalLatestYm === null) {
        jsonResponse(['ok' => false, 'message' => 'Tidak ada data kredit ditemukan.'], 404);
        return;
    }

    // Hitung bulan-bulan referensi
    $year  = (int) substr((string) $globalLatestYm, 0, 4);
    $month = (int) substr((string) $globalLatestYm, 5, 2);
    $prevM = $month - 1;
    $prevY = $year;
    if ($prevM === 0) {
        $prevM = 12;
        $prevY--;
    }
    $prevYm = sprintf('%04d-%02d', $prevY, $prevM);   // MtD reference
    $ytdYm  = sprintf('%04d-12', $year - 1);           // YtD reference (Des tahun lalu)
    $yoyYm  = sprintf('%04d-%02d', $year - 1, $month); // YoY reference (tahun lalu bulan sama)

    $rows = [];
    $labelStr = '';

    foreach ($productConfig as $cfg) {
        $cfgNorm = $normalizeKey($cfg['key']);
        $actualProduct = null;

        // Cari sheet yang cocok (exact normalized match)
        foreach ($products as $p) {
            if (str_starts_with(strtoupper($p), 'X')) continue;
            if ($normalizeKey($p) === $cfgNorm) {
                $actualProduct = $p;
                break;
            }
        }
        if ($actualProduct === null) continue;

        $sheetData = $cache['index'][$actualProduct] ?? [];
        $branches  = $id !== '' ? selectBranches($sheetData, $id) : [];
        if (empty($branches)) continue;

        if ($labelStr === '') $labelStr = buildSelectionLabel($id, $branches);

        // Hitung nilai per bulan (sum semua cabang, ambil tanggal terbesar per bulan)
        $monthToMaxDate = [];
        foreach ($branches as $branch) {
            foreach (($branch['dates'] ?? []) as $date => $val) {
                $ym = substr($date, 0, 7);
                if (!isset($monthToMaxDate[$ym]) || $date > $monthToMaxDate[$ym]) {
                    $monthToMaxDate[$ym] = $date;
                }
            }
        }
        $monthlySums = [];
        foreach ($monthToMaxDate as $ym => $maxDate) {
            $sum = 0;
            foreach ($branches as $branch) {
                $sum += (float)($branch['dates'][$maxDate] ?? 0);
            }
            $monthlySums[$ym] = normalizeOutputNumber($sum);
        }

        $curVal  = $monthlySums[$globalLatestYm] ?? null;
        $prevVal = $monthlySums[$prevYm] ?? null;
        $ytdVal  = $monthlySums[$ytdYm]  ?? null;
        $yoyVal  = $monthlySums[$yoyYm]  ?? null;

        $rows[] = [
            'key'         => $cfg['key'],
            'label'       => $cfg['label'],
            'bold'        => $cfg['bold'],
            'yoy_ym'      => formatYearMonth($yoyYm),   // kolom 1: tahun lalu same month
            'ytd_ym'      => formatYearMonth($ytdYm),   // kolom 2: Des tahun lalu
            'prev_ym'     => formatYearMonth($prevYm),  // kolom 3: bulan sebelumnya
            'current_ym'  => formatYearMonth($globalLatestYm), // kolom 4: bulan aktif (biru)
            'yoy_val'     => $yoyVal,
            'ytd_val'     => $ytdVal,
            'prev_val'    => $prevVal,
            'current_val' => $curVal,
            'mtd_nominal' => growthNominal($curVal, $prevVal),
            'mtd_percent' => growthPercent($curVal, $prevVal),
            'ytd_nominal' => growthNominal($curVal, $ytdVal),
            'ytd_percent' => growthPercent($curVal, $ytdVal),
            'yoy_nominal' => growthNominal($curVal, $yoyVal),
            'yoy_percent' => growthPercent($curVal, $yoyVal),
        ];
    }

    jsonResponse(['ok' => true, 'id' => $id, 'label' => $labelStr ?: $id, 'rows' => $rows]);
}


function formatYearMonth(string $ym): string
{
    $monthsMap = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu', '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'];
    $parts = explode('-', $ym);
    if (count($parts) === 2) {
        return ($monthsMap[$parts[1]] ?? $parts[1]) . "'" . substr($parts[0], 2, 2);
    }
    return $ym;
}


function handleDeleteCache(string $danacacheFile): void
{
    if (is_file($danacacheFile)) {
        @unlink($danacacheFile);
    }
    jsonResponse(['ok' => true, 'message' => 'Data financial (Excel) berhasil dikosongkan. Data user tetap aman.']);
}


function handleUpload(string $danacacheFile, string $uploadDir, string $usersFile): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => 'Upload harus menggunakan POST.'], 405);
        return;
    }

    $setup = nativeXlsxSetupStatus();
    if (!$setup['ready']) {
        jsonResponse([
            'ok' => false,
            'message' => $setup['message'],
            'missing_extensions' => $setup['missing_extensions'],
        ], 500);
        return;
    }

    if (!isset($_FILES['excel_file']) || !is_array($_FILES['excel_file'])) {
        jsonResponse(['ok' => false, 'message' => 'File Excel belum dipilih.'], 400);
        return;
    }

    $file = $_FILES['excel_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['ok' => false, 'message' => uploadErrorMessage((int) $file['error'])], 400);
        return;
    }

    if (($file['size'] ?? 0) > 10485760) {
        jsonResponse(['ok' => false, 'message' => 'Ukuran file melebihi batas 10 MB. Silakan kompres atau perkecil file Excel Anda.'], 400);
        return;
    }

    $originalName = basename((string) ($file['name'] ?? 'daily-balance.xlsx'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'xlsx') {
        jsonResponse(['ok' => false, 'message' => 'Format file harus .xlsx.'], 400);
        return;
    }

    try {
        ensureDirectory($uploadDir);
    } catch (Throwable $e) {
        jsonResponse(['ok' => false, 'message' => 'Gagal membuat folder upload: ' . $e->getMessage()], 500);
        return;
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: 'daily-balance.xlsx';
    $targetFile = $uploadDir . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;

    if (!move_uploaded_file((string) $file['tmp_name'], $targetFile)) {
        jsonResponse(['ok' => false, 'message' => 'Gagal menyimpan file upload. Periksa permission folder uploads.'], 500);
        return;
    }

    try {
        $year = (int) date('Y');
        $cache = parseWorkbookToCache($targetFile, $originalName, $year);
        writeCache($danacacheFile, $cache);

        jsonResponse([
            'ok' => true,
            'message' => 'Upload dan parsing Excel selesai.',
            'summary' => [
                'source_file' => $cache['source_file'],
                'year' => $cache['year'],
                'products' => $cache['meta']['products'],
                'records' => $cache['meta']['stats']['records'],
                'branches' => $cache['meta']['stats']['branches'],
                'skipped_sheets' => $cache['meta']['skipped_sheets'],
            ],
        ]);
    } catch (Throwable $e) {
        // Hapus file yang sudah ke-upload kalau parsing gagal
        @unlink($targetFile);
        jsonResponse([
            'ok' => false,
            'message' => 'Parsing Excel gagal: ' . $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ], 500);
    }
}


function parseWorkbookToCache(string $filePath, string $sourceName, int $year): array
{
    set_time_limit(300);
    ini_set('memory_limit', '1024M'); // ← dari 102400M jadi 1024M (1GB, realistic)

    $workbook = xlsxReadWorkbook($filePath);

    $cache = [
        'version' => 1,
        'reader' => 'native-xlsx',
        'source_file' => $sourceName,
        'stored_file' => basename($filePath),
        'generated_at' => date('c'),
        'year' => $year,
        'meta' => [
            'products' => [],
            'branches' => [],
            'months' => [],
            'min_date' => null,
            'max_date' => null,
            'stats' => ['records' => 0, 'branches' => 0, 'products' => 0],
            'sheets' => [],
            'skipped_sheets' => [],
        ],
        'index' => [],
    ];

    foreach ($workbook['sheets'] as $sheet) {
        $product = trim((string) $sheet['name']);

        try {
            $rows = xlsxReadSheetRows($workbook, $sheet);
        } catch (Throwable $exception) {
            $cache['meta']['skipped_sheets'][] = [
                'sheet' => $product === '' ? '(tanpa nama)' : $product,
                'reason' => 'Sheet gagal dibaca: ' . $exception->getMessage(),
            ];
            continue;
        }

        $header = detectHeader($rows, $year);

        if ($product === '' || $header === null) {
            $cache['meta']['skipped_sheets'][] = [
                'sheet' => $product === '' ? '(tanpa nama)' : $product,
                'reason' => 'Header metadata/tanggal tidak ditemukan.',
            ];
            continue;
        }

        $cache['index'][$product] = [];
        $sheetRecords = 0;
        $sheetRows = 0;
        $highestRow = $rows === [] ? 0 : max(array_keys($rows));

        for ($row = $header['row'] + 1; $row <= $highestRow; $row++) {
            $branchId = cleanCellString(readCellValue($rows, $header['branch_id_col'], $row, true));
            $branchName = cleanCellString(readCellValue($rows, $header['branch_name_col'], $row, true));

            if ($branchId === '' || $branchName === '') continue;

            if (!isset($cache['index'][$product][$branchId])) {
                $cache['index'][$product][$branchId] = [
                    'branch_id' => $branchId,
                    'branch_name' => $branchName,
                    'dates' => [],
                ];
            }

            $cache['meta']['branches'][$branchId] = ['id' => $branchId, 'name' => $branchName];

            $hasAnyValue = false;
            foreach ($header['date_cols'] as $dateColumn) {
                $value = normalizeNumber(readCellValue($rows, $dateColumn['col'], $row, false));
                if ($value === null) continue;

                $dateKey = $dateColumn['date'];
                $currentValue = $cache['index'][$product][$branchId]['dates'][$dateKey] ?? 0;
                $cache['index'][$product][$branchId]['dates'][$dateKey] = normalizeOutputNumber($currentValue + $value);

                $cache['meta']['months'][$dateColumn['month_key']] = $dateColumn['month_key'];
                $cache['meta']['min_date'] = minDate($cache['meta']['min_date'], $dateKey);
                $cache['meta']['max_date'] = maxDate($cache['meta']['max_date'], $dateKey);
                $sheetRecords++;
                $hasAnyValue = true;
            }

            if ($hasAnyValue) $sheetRows++;
        }

        if ($sheetRecords === 0) {
            unset($cache['index'][$product]);
            $cache['meta']['skipped_sheets'][] = [
                'sheet' => $product,
                'reason' => 'Tidak ada nilai numerik pada kolom tanggal.',
            ];
            continue;
        }

        $cache['meta']['products'][] = $product;
        $cache['meta']['sheets'][$product] = [
            'header_row' => $header['row'],
            'rows' => $sheetRows,
            'records' => $sheetRecords,
            'date_columns' => count($header['date_cols']),
        ];
        $cache['meta']['stats']['records'] += $sheetRecords;

        unset($rows);
    }

    sort($cache['meta']['products'], SORT_NATURAL | SORT_FLAG_CASE);
    ksort($cache['meta']['branches'], SORT_NATURAL);
    ksort($cache['meta']['months'], SORT_NATURAL);
    $cache['meta']['months'] = array_values($cache['meta']['months']);
    $cache['meta']['stats']['branches'] = count($cache['meta']['branches']);
    $cache['meta']['stats']['products'] = count($cache['meta']['products']);

    if ($cache['meta']['stats']['products'] === 0) {
        throw new RuntimeException('Workbook tidak memiliki sheet dengan format Kode/Nama/Nama Area dan kolom tanggal.');
    }

    return $cache;
}


function detectHeader(array $rows, int $fallbackYear): ?array
{
    $highestRow = $rows === [] ? 0 : min(max(array_keys($rows)), 25);


    for ($row = 1; $row <= $highestRow; $row++) {
        if (!isset($rows[$row])) {
            continue;
        }


        $branchIdCol = null;
        $branchNameCol = null;
        $dateCols = [];


        foreach ($rows[$row] as $col => $cell) {
            $raw = $cell['value'] ?? null;
            $formatted = cleanCellString($raw);
            $normalized = normalizeHeaderName($formatted);


            if ($branchIdCol === null && in_array($normalized, ['kode', 'kodecabang', 'branchid', 'koderegion', 'kodearea'], true)) {
                $branchIdCol = $col;
                continue;
            }


            if ($branchNameCol === null && in_array($normalized, ['nama', 'namacabang', 'branchname', 'namaregion', 'namaarea'], true)) {
                $branchNameCol = $col;
                continue;
            }


            $dateInfo = parseDateHeader($cell, $fallbackYear);
            if ($dateInfo !== null) {
                $dateInfo['col'] = $col;
                $dateCols[] = $dateInfo;
            }
        }


        if ($branchIdCol !== null && $branchNameCol !== null && count($dateCols) > 0) {
            return [
                'row' => $row,
                'branch_id_col' => $branchIdCol,
                'branch_name_col' => $branchNameCol,
                'date_cols' => $dateCols,
            ];
        }
    }


    return null;
}


function parseDateHeader(array $cell, int $fallbackYear): ?array
{
    $raw = $cell['raw'] ?? null;
    $value = $cell['value'] ?? null;


    if (($cell['is_date'] ?? false) && is_numeric($raw)) {
        $dateText = (string) $value;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateText, $matches)) {
            return buildDateInfo((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }
    }


    $candidates = [
        cleanCellString($value),
        cleanCellString($raw),
    ];


    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }


        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $candidate, $matches)) {
            return buildDateInfo((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }


        if (preg_match('/^(\d{1,2})[\/.](\d{1,2})(?:[\/.](\d{2,4}))?$/', $candidate, $matches)) {
            $year = isset($matches[3]) && $matches[3] !== ''
                ? parseYearToken($matches[3], $fallbackYear)
                : $fallbackYear;
            return buildDateInfo($year, (int) $matches[2], (int) $matches[1]);
        }


        $candidate = str_replace(["\xc2\xa0", ','], [' ', ' '], $candidate);
        if (!preg_match('/^(\d{1,2})[\s.\-\/]+([A-Za-z]+)(?:[\s.\-\/]+(\d{2,4}))?$/', $candidate, $matches)) {
            continue;
        }


        $day = (int) $matches[1];
        $month = monthNumber($matches[2]);
        if ($month === null) {
            continue;
        }


        $year = $fallbackYear;
        if (isset($matches[3]) && $matches[3] !== '') {
            $year = parseYearToken($matches[3], $fallbackYear);
        }


        return buildDateInfo($year, $month, $day);
    }


    return null;
}


function xlsxReadWorkbook(string $filePath): array
{
    $zip = xlsxOpenZip($filePath);
    $workbookXml = xlsxLoadXml($zip, 'xl/workbook.xml');
    $relsXml = xlsxLoadXml($zip, 'xl/_rels/workbook.xml.rels');


    $workbookXml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $relationships = xlsxRelationshipMap($relsXml, 'xl/workbook.xml');


    $date1904 = false;
    $workbookProps = $workbookXml->xpath('/m:workbook/m:workbookPr');
    if ($workbookProps !== false && isset($workbookProps[0])) {
        $attributes = $workbookProps[0]->attributes();
        $date1904 = isset($attributes['date1904']) && in_array((string) $attributes['date1904'], ['1', 'true', 'TRUE'], true);
    }


    $sheets = [];
    $sheetNodes = $workbookXml->xpath('/m:workbook/m:sheets/m:sheet');
    foreach ($sheetNodes === false ? [] : $sheetNodes as $sheetNode) {
        $attributes = $sheetNode->attributes();
        $relationshipAttributes = $sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
        $targetPath = $relationships[$relationshipId] ?? '';


        if ($targetPath === '') {
            continue;
        }


        $sheets[] = [
            'name' => (string) ($attributes['name'] ?? 'Sheet'),
            'path' => $targetPath,
        ];
    }


    if ($sheets === []) {
        throw new RuntimeException('Workbook tidak memiliki sheet yang bisa dibaca.');
    }


    return [
        'zip' => $zip,
        'date1904' => $date1904,
        'shared_strings' => xlsxReadSharedStrings($zip),
        'date_styles' => xlsxReadDateStyles($zip),
        'sheets' => $sheets,
    ];
}


function xlsxReadSheetRows(array $workbook, array $sheet): array
{
    $worksheetXml = xlsxLoadXml($workbook['zip'], (string) $sheet['path']);
    $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rows = [];


    $sheetData = $worksheetXml->children($namespace)->sheetData;
    if (!isset($sheetData)) {
        return [];
    }


    $runningRow = 0;
    foreach ($sheetData->children($namespace)->row as $rowNode) {
        $rowAttributes = $rowNode->attributes();
        $rowIndex = isset($rowAttributes['r']) ? (int) $rowAttributes['r'] : $runningRow + 1;
        $runningRow = $rowIndex;
        $runningColumn = 0;


        foreach ($rowNode->children($namespace)->c as $cellNode) {
            $cellAttributes = $cellNode->attributes();
            $cellReference = (string) ($cellAttributes['r'] ?? '');
            $columnIndex = $cellReference !== ''
                ? xlsxColumnIndexFromCellReference($cellReference)
                : $runningColumn + 1;


            if ($columnIndex < 1) {
                continue;
            }


            $rows[$rowIndex][$columnIndex] = xlsxCellValue(
                $cellNode,
                $workbook['shared_strings'],
                $workbook['date_styles'],
                (bool) $workbook['date1904']
            );
            $runningColumn = $columnIndex;
        }
    }


    ksort($rows, SORT_NUMERIC);
    return $rows;
}


function xlsxCellValue(\SimpleXMLElement $cellNode, array $sharedStrings, array $dateStyles, bool $date1904): array
{
    $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $attributes = $cellNode->attributes();
    $type = (string) ($attributes['t'] ?? '');
    $styleIndex = isset($attributes['s']) ? (int) $attributes['s'] : null;
    $isDate = $styleIndex !== null && ($dateStyles[$styleIndex] ?? false);
    $children = $cellNode->children($namespace);
    $raw = isset($children->v) ? trim((string) $children->v) : '';


    if ($type === 'inlineStr') {
        $value = isset($children->is) ? xlsxRichText($children->is) : '';
        return ['value' => $value, 'raw' => $value, 'is_date' => false];
    }


    if ($raw === '') {
        return ['value' => null, 'raw' => null, 'is_date' => $isDate];
    }


    if ($type === 's') {
        $index = (int) $raw;
        $value = $sharedStrings[$index] ?? '';
        return ['value' => $value, 'raw' => $raw, 'is_date' => false];
    }


    if ($type === 'b') {
        return ['value' => $raw === '1' ? 'TRUE' : 'FALSE', 'raw' => $raw, 'is_date' => false];
    }


    if ($type === 'e') {
        return ['value' => null, 'raw' => $raw, 'is_date' => false];
    }


    if ($isDate && is_numeric($raw)) {
        return ['value' => xlsxExcelSerialToDate((float) $raw, $date1904), 'raw' => $raw, 'is_date' => true];
    }


    $value = is_numeric($raw) ? normalizeOutputNumber((float) $raw) : $raw;
    return ['value' => $value, 'raw' => $raw, 'is_date' => false];
}


function xlsxReadSharedStrings(array $zip): array
{
    if (!isset($zip['entries']['xl/sharedStrings.xml'])) {
        return [];
    }


    $xml = xlsxLoadXml($zip, 'xl/sharedStrings.xml');
    $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $strings = [];


    foreach ($xml->children($namespace)->si as $sharedString) {
        $strings[] = xlsxRichText($sharedString);
    }


    return $strings;
}


function xlsxReadDateStyles(array $zip): array
{
    if (!isset($zip['entries']['xl/styles.xml'])) {
        return [];
    }


    $xml = xlsxLoadXml($zip, 'xl/styles.xml');
    $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $customFormats = [];


    if (isset($xml->children($namespace)->numFmts)) {
        foreach ($xml->children($namespace)->numFmts->children($namespace)->numFmt as $formatNode) {
            $attributes = $formatNode->attributes();
            $customFormats[(int) $attributes['numFmtId']] = (string) $attributes['formatCode'];
        }
    }


    $styles = [];
    if (isset($xml->children($namespace)->cellXfs)) {
        $styleIndex = 0;
        foreach ($xml->children($namespace)->cellXfs->children($namespace)->xf as $xfNode) {
            $attributes = $xfNode->attributes();
            $numFmtId = (int) ($attributes['numFmtId'] ?? 0);
            $styles[$styleIndex] = xlsxIsDateFormat($numFmtId, $customFormats[$numFmtId] ?? '');
            $styleIndex++;
        }
    }


    return $styles;
}


function xlsxIsDateFormat(int $numFmtId, string $formatCode): bool
{
    $builtInDateFormats = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];
    if (in_array($numFmtId, $builtInDateFormats, true)) {
        return true;
    }


    $clean = strtolower($formatCode);
    $clean = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $clean);
    return $clean !== '' && preg_match('/[dy]/', $clean) === 1 && preg_match('/[m]/', $clean) === 1;
}


function xlsxRichText(\SimpleXMLElement $node): string
{
    $node->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $textNodes = $node->xpath('.//m:t');


    if ($textNodes === false || $textNodes === []) {
        return trim((string) $node);
    }


    $text = '';
    foreach ($textNodes as $textNode) {
        $text .= (string) $textNode;
    }


    return $text;
}


function xlsxRelationshipMap(\SimpleXMLElement $relsXml, string $sourcePath): array
{
    $relsXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $relationships = [];
    $relationshipNodes = $relsXml->xpath('/r:Relationships/r:Relationship');


    foreach ($relationshipNodes === false ? [] : $relationshipNodes as $relationshipNode) {
        $attributes = $relationshipNode->attributes();
        $id = (string) ($attributes['Id'] ?? '');
        $target = (string) ($attributes['Target'] ?? '');
        if ($id !== '' && $target !== '') {
            $relationships[$id] = xlsxResolvePath($sourcePath, $target);
        }
    }


    return $relationships;
}


function xlsxLoadXml(array $zip, string $path): \SimpleXMLElement
{
    $content = xlsxZipExtract($zip, $path);
    if ($content === null) {
        throw new RuntimeException('File internal Excel tidak ditemukan: ' . $path);
    }


    $previous = libxml_use_internal_errors(true);
    $xml = \simplexml_load_string($content);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);


    if (!$xml instanceof \SimpleXMLElement) {
        $message = isset($errors[0]) ? trim($errors[0]->message) : 'XML tidak valid.';
        throw new RuntimeException('XML Excel gagal dibaca (' . $path . '): ' . $message);
    }


    return $xml;
}


function xlsxOpenZip(string $filePath): array
{
    if (!is_file($filePath)) {
        throw new RuntimeException('File Excel tidak ditemukan.');
    }


    $fileSize = filesize($filePath);
    if ($fileSize === false || $fileSize < 22) {
        throw new RuntimeException('File Excel tidak valid atau kosong.');
    }


    $tailSize = min($fileSize, 65557);
    $tail = file_get_contents($filePath, false, null, $fileSize - $tailSize, $tailSize);
    if ($tail === false) {
        throw new RuntimeException('File Excel gagal dibaca.');
    }


    $eocdPosition = strrpos($tail, "PK\x05\x06");
    if ($eocdPosition === false) {
        throw new RuntimeException('File .xlsx tidak valid. Pastikan file berasal dari Excel/LibreOffice dan bukan .xls lama.');
    }


    $eocd = substr($tail, $eocdPosition + 4, 18);
    $fields = unpack('vdisk/vcentralDisk/ventriesDisk/ventries/VcentralSize/VcentralOffset/vcommentLength', $eocd);
    if (($fields['centralOffset'] ?? 0) === 0xFFFFFFFF || ($fields['centralSize'] ?? 0) === 0xFFFFFFFF) {
        throw new RuntimeException('File Excel memakai ZIP64 dan belum didukung oleh parser native sederhana ini.');
    }


    $centralDirectory = file_get_contents($filePath, false, null, (int) $fields['centralOffset'], (int) $fields['centralSize']);
    if ($centralDirectory === false) {
        throw new RuntimeException('Daftar isi file Excel gagal dibaca.');
    }


    $entries = [];
    $offset = 0;
    $length = strlen($centralDirectory);


    while ($offset + 46 <= $length) {
        if (substr($centralDirectory, $offset, 4) !== "PK\x01\x02") {
            break;
        }


        $header = substr($centralDirectory, $offset + 4, 42);
        $entry = unpack(
            'vversionMade/vversionNeeded/vflags/vmethod/vmodTime/vmodDate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttrs/VexternalAttrs/VlocalOffset',
            $header
        );


        $nameStart = $offset + 46;
        $name = substr($centralDirectory, $nameStart, (int) $entry['nameLength']);
        $name = xlsxNormalizeZipPath($name);


        $entries[$name] = [
            'method' => (int) $entry['method'],
            'flags' => (int) $entry['flags'],
            'compressed_size' => (int) $entry['compressedSize'],
            'uncompressed_size' => (int) $entry['uncompressedSize'],
            'local_offset' => (int) $entry['localOffset'],
        ];


        $offset += 46 + (int) $entry['nameLength'] + (int) $entry['extraLength'] + (int) $entry['commentLength'];
    }


    if (!isset($entries['xl/workbook.xml'])) {
        throw new RuntimeException('Struktur .xlsx tidak lengkap: xl/workbook.xml tidak ditemukan.');
    }


    return ['path' => $filePath, 'entries' => $entries];
}


function xlsxZipExtract(array $zip, string $path): ?string
{
    $path = xlsxNormalizeZipPath($path);
    if (!isset($zip['entries'][$path])) {
        return null;
    }


    $entry = $zip['entries'][$path];
    if (($entry['flags'] & 1) === 1) {
        throw new RuntimeException('File Excel terenkripsi/password protected belum didukung.');
    }


    $localHeader = file_get_contents((string) $zip['path'], false, null, (int) $entry['local_offset'], 30);
    if ($localHeader === false || strlen($localHeader) < 30 || substr($localHeader, 0, 4) !== "PK\x03\x04") {
        throw new RuntimeException('Local header ZIP rusak untuk: ' . $path);
    }


    $local = unpack('vversion/vflags/vmethod/vmodTime/vmodDate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($localHeader, 4));
    $dataOffset = (int) $entry['local_offset'] + 30 + (int) $local['nameLength'] + (int) $local['extraLength'];
    $compressed = file_get_contents((string) $zip['path'], false, null, $dataOffset, (int) $entry['compressed_size']);
    if ($compressed === false) {
        throw new RuntimeException('Konten ZIP gagal dibaca untuk: ' . $path);
    }


    if ((int) $entry['method'] === 0) {
        return $compressed;
    }


    if ((int) $entry['method'] === 8) {
        $uncompressed = gzinflate($compressed);
        if ($uncompressed === false) {
            throw new RuntimeException('Konten ZIP gagal diekstrak untuk: ' . $path);
        }
        return $uncompressed;
    }


    throw new RuntimeException('Metode kompresi ZIP tidak didukung untuk: ' . $path);
}


function xlsxResolvePath(string $sourcePath, string $target): string
{
    if (str_starts_with($target, '/')) {
        return xlsxNormalizeZipPath(ltrim($target, '/'));
    }


    return xlsxNormalizeZipPath(dirname($sourcePath) . '/' . $target);
}


function xlsxNormalizeZipPath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $parts = [];


    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }


        if ($part === '..') {
            array_pop($parts);
            continue;
        }


        $parts[] = $part;
    }


    return implode('/', $parts);
}


function xlsxColumnIndexFromCellReference(string $reference): int
{
    if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
        return 0;
    }


    $letters = strtoupper($matches[1]);
    $index = 0;
    for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }


    return $index;
}


function xlsxExcelSerialToDate(float $serial, bool $date1904): string
{
    $days = (int) floor($serial);
    $base = $date1904 ? new DateTimeImmutable('1904-01-01') : new DateTimeImmutable('1899-12-30');
    return $base->modify('+' . $days . ' days')->format('Y-m-d');
}


function buildDateInfo(int $year, int $month, int $day): ?array
{
    if (!checkdate($month, $day, $year)) {
        return null;
    }


    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);


    return [
        'date' => $date,
        'day' => $day,
        'month' => monthLabel($month),
        'month_key' => sprintf('%04d-%02d', $year, $month),
    ];
}


function monthNumber(string $name): ?int
{
    $key = strtolower(preg_replace('/[^a-zA-Z]+/', '', $name));
    $months = [
        'jan' => 1,
        'januari' => 1,
        'january' => 1,
        'feb' => 2,
        'februari' => 2,
        'february' => 2,
        'mar' => 3,
        'maret' => 3,
        'march' => 3,
        'apr' => 4,
        'april' => 4,
        'may' => 5,
        'mei' => 5,
        'jun' => 6,
        'juni' => 6,
        'june' => 6,
        'jul' => 7,
        'juli' => 7,
        'july' => 7,
        'aug' => 8,
        'agu' => 8,
        'ags' => 8,
        'agustus' => 8,
        'august' => 8,
        'sep' => 9,
        'sept' => 9,
        'september' => 9,
        'oct' => 10,
        'okt' => 10,
        'oktober' => 10,
        'october' => 10,
        'nov' => 11,
        'november' => 11,
        'dec' => 12,
        'des' => 12,
        'desember' => 12,
        'december' => 12,
    ];


    return $months[$key] ?? null;
}


function parseYearToken(string $token, int $fallbackYear): int
{
    $year = (int) $token;
    if (strlen($token) === 2) {
        return $year <= 69 ? 2000 + $year : 1900 + $year;
    }


    return $year > 0 ? $year : $fallbackYear;
}


function readCellValue(array $rows, int $col, int $row, bool $formatted): mixed
{
    if (!isset($rows[$row][$col])) {
        return null;
    }


    return $rows[$row][$col]['value'] ?? null;
}


function normalizeHeaderName(string $value): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', trim($value)));
}


function cleanCellString(mixed $value): string
{
    if ($value === null) {
        return '';
    }


    if (is_float($value) && floor($value) === $value) {
        $value = (int) $value;
    }


    return trim((string) $value);
}


function normalizeNumber(mixed $value): int|float|null
{
    if ($value === null || $value === '') {
        return null;
    }


    if (is_int($value) || is_float($value)) {
        return normalizeOutputNumber((float) $value);
    }


    $text = trim((string) $value);
    if ($text === '' || $text === '-' || strtolower($text) === 'null') {
        return null;
    }


    $negative = false;
    if (preg_match('/^\((.*)\)$/', $text, $matches)) {
        $negative = true;
        $text = $matches[1];
    }


    $text = str_ireplace(['rp', 'idr'], '', $text);
    $text = str_replace(["\xc2\xa0", ' '], '', $text);
    $text = preg_replace('/[^0-9,.\-]/', '', $text);


    if ($text === '' || $text === '-') {
        return null;
    }


    $lastComma = strrpos($text, ',');
    $lastDot = strrpos($text, '.');


    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } else {
            $text = str_replace(',', '', $text);
        }
    } elseif ($lastComma !== false) {
        $decimalDigits = strlen($text) - $lastComma - 1;
        $text = $decimalDigits === 3 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
    } elseif ($lastDot !== false) {
        $decimalDigits = strlen($text) - $lastDot - 1;
        if ($decimalDigits === 3 && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $text)) {
            $text = str_replace('.', '', $text);
        }
    }


    if (!is_numeric($text)) {
        return null;
    }


    $number = (float) $text;
    if ($negative) {
        $number *= -1;
    }


    return normalizeOutputNumber($number);
}


function normalizeOutputNumber(int|float $value): int|float
{
    return abs($value - round($value)) < 0.000001 ? (int) round($value) : round($value, 6);
}


function loadCache(string $danacacheFile): ?array
{
    if (!is_file($danacacheFile)) {
        return null;
    }


    $json = file_get_contents($danacacheFile);
    if ($json === false || $json === '') {
        return null;
    }


    $cache = json_decode($json, true);
    return is_array($cache) ? $cache : null;
}


function writeCache(string $danacacheFile, array $cache): void
{
    ensureDirectory(dirname($danacacheFile));
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Gagal encode cache JSON.');
    }


    $temporaryFile = $danacacheFile . '.tmp';
    if (@file_put_contents($temporaryFile, $json, LOCK_EX) === false) {
        throw new RuntimeException('Gagal menulis cache JSON. Periksa permission folder server.');
    }


    if (!@rename($temporaryFile, $danacacheFile)) {
        @unlink($temporaryFile);
        throw new RuntimeException('Gagal menyimpan cache JSON. Periksa permission folder server.');
    }
}


function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Gagal membuat folder runtime: ' . $directory);
    }
}


function selectBranches(array $productIndex, string $id): array
{
    if ($id === '') {
        return [];
    }


    $keyword = strtolower(trim($id));
    $selected = [];


    foreach ($productIndex as $branchId => $branch) {
        if (strtolower((string) $branchId) === $keyword || strtolower((string) ($branch['branch_name'] ?? '')) === $keyword) {
            $selected[] = $branch;
            return $selected;
        }
    }


    foreach ($productIndex as $branchId => $branch) {
        if (str_contains(strtolower((string) $branchId), $keyword) || str_contains(strtolower((string) ($branch['branch_name'] ?? '')), $keyword)) {
            $selected[] = $branch;
        }
    }


    return $selected;
}


function collectProductMonths(array $productIndex): array
{
    $months = [];
    foreach ($productIndex as $branch) {
        foreach (($branch['dates'] ?? []) as $date => $_value) {
            $months[substr((string) $date, 0, 7)] = substr((string) $date, 0, 7);
        }
    }


    ksort($months, SORT_NATURAL);
    return array_values($months);
}


function selectPeriodMonths(array $months, string $period): array
{
    if ($months === []) {
        return [];
    }


    $latestMonthKey = end($months);
    $latestYear = (int) substr($latestMonthKey, 0, 4);
    $latestMonthNum = substr($latestMonthKey, 5, 2);


    if ($period === 'YTD') {
        $decemberPrevYear = sprintf('%04d-12', $latestYear - 1);
        return array_values(array_filter($months, static function (string $m) use ($latestYear, $decemberPrevYear): bool {
            $year = (int) substr($m, 0, 4);
            return $year === $latestYear || $m === $decemberPrevYear;
        }));
    }


    if ($period === 'YOY') {
        $targetKeys = [
            sprintf('%04d-%s', $latestYear - 2, $latestMonthNum),
            sprintf('%04d-%s', $latestYear - 1, $latestMonthNum),
            $latestMonthKey
        ];
        return array_values(array_filter($months, static fn(string $m): bool => in_array($m, $targetKeys, true)));
    }


    if ($period === '3M' || $period === '3MD') {
        return array_slice($months, -3);
    }


    return array_slice($months, -2);
}


function buildMonthlySeries(array $branches, array $months): array
{
    $series = [];


    foreach ($months as $monthKey) {
        [$year, $month] = array_map('intval', explode('-', $monthKey));
        $data = array_fill(0, 31, null);


        for ($day = 1; $day <= 31; $day++) {
            if (!checkdate($month, $day, $year)) {
                continue;
            }


            $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $sum = 0;
            $hasValue = false;


            foreach ($branches as $branch) {
                if (array_key_exists($dateKey, $branch['dates'] ?? [])) {
                    $sum += (float) $branch['dates'][$dateKey];
                    $hasValue = true;
                }
            }


            if ($hasValue) {
                $data[$day - 1] = normalizeOutputNumber($sum);
            }
        }


        $bottom = findBottom($data);
        $end = findEndPoint($data);
        $series[] = [
            'name' => monthLabel($month) . ' ' . $year,
            'month' => monthLabel($month),
            'month_key' => $monthKey,
            'data' => $data,
            'bottom_index' => $bottom['index'],
            'bottom_value' => $bottom['value'],
            'end_index' => $end['index'],
            'end_value' => $end['value'],
        ];
    }


    return $series;
}


function findBottom(array $data): array
{
    $minValue = null;
    $minIndex = -1;


    foreach ($data as $index => $value) {
        if ($value === null) {
            continue;
        }


        if ($minValue === null || $value < $minValue) {
            $minValue = $value;
            $minIndex = $index;
        }
    }


    return ['index' => $minIndex, 'value' => $minValue];
}


function getLatestBalance(array $series): int|float|null
{
    for ($seriesIndex = count($series) - 1; $seriesIndex >= 0; $seriesIndex--) {
        $data = $series[$seriesIndex]['data'] ?? [];
        for ($dayIndex = count($data) - 1; $dayIndex >= 0; $dayIndex--) {
            if ($data[$dayIndex] !== null) {
                return $data[$dayIndex];
            }
        }
    }


    return null;
}


function findEndPoint(array $data): array
{
    for ($index = count($data) - 1; $index >= 0; $index--) {
        if ($data[$index] !== null) {
            return ['index' => $index, 'value' => $data[$index]];
        }
    }


    return ['index' => -1, 'value' => null];
}


function buildSelectionLabel(string $id, array $branches): string
{
    $branch = $branches[0] ?? null;
    if ($branch === null) {
        return strtoupper($id);
    }


    return ($branch['branch_name'] ?? $id) . ' (' . ($branch['branch_id'] ?? $id) . ')';
}


function monthLabel(int $month): string
{
    $labels = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
    return $labels[$month] ?? (string) $month;
}


function minDate(?string $current, string $candidate): string
{
    return $current === null || $candidate < $current ? $candidate : $current;
}


function maxDate(?string $current, string $candidate): string
{
    return $current === null || $candidate > $current ? $candidate : $current;
}


function uploadErrorMessage(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas upload server.',
        UPLOAD_ERR_PARTIAL => 'Upload file tidak lengkap.',
        UPLOAD_ERR_NO_FILE => 'File Excel belum dipilih.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary upload tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file upload.',
        UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP.',
        default => 'Upload gagal.',
    };
}


function requireLoginJson(?array $currentUser): void
{
    if ($currentUser !== null) {
        return;
    }


    jsonResponse([
        'ok' => false,
        'message' => 'Sesi login Anda sudah berakhir. Silakan login kembali.',
        'redirect' => currentPageUrl(),
    ], 401);
    exit;
}


function requireAdminJson(?array $currentUser): void
{
    if ($currentUser !== null && isAdmin($currentUser)) {
        return;
    }


    jsonResponse([
        'ok' => false,
        'message' => 'Akses admin diperlukan untuk aksi ini.',
    ], 403);
    exit;
}


function bootstrapUserStore(string $usersFile): void
{
    if (is_file($usersFile)) {
        return;
    }


    $now = date('c');
    $data = [
        'version' => 1,
        'users' => [
            'admin' => [
                'id' => 'admin',
                'nip' => 'admin',
                'name' => 'Administrator',
                'jabatan' => 'System Admin',
                'role' => 'Admin',
                'branch_id' => '',
                'branch_name' => '',
                'area' => '',
                'status' => 'Active',
                'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
                'created_at' => $now,
                'updated_at' => $now,
                'source' => 'system',
            ],
        ],
    ];


    writeJsonFile($usersFile, $data);
}


function loadUserStore(string $usersFile): array
{
    $data = loadJsonFile($usersFile, ['version' => 1, 'users' => []]);
    if (!isset($data['users']) || !is_array($data['users'])) {
        $data['users'] = [];
    }


    return $data;
}


function saveUserStore(string $usersFile, array $data): void
{
    writeJsonFile($usersFile, $data);
}


function currentUser(string $usersFile): ?array
{
    $userId = trim((string) ($_SESSION['user_id'] ?? ''));
    if ($userId === '') {
        return null;
    }


    $store = loadUserStore($usersFile);
    $user = $store['users'][$userId] ?? null;
    if (!is_array($user) || (($user['status'] ?? 'Active') !== 'Active')) {
        unset($_SESSION['user_id']);
        return null;
    }


    return $user;
}


function authenticateUser(string $usersFile, string $nip, string $password): ?array
{
    if ($nip === '' || $password === '') {
        return null;
    }


    $store = loadUserStore($usersFile);
    $foundUser = null;


    foreach ($store['users'] as $u) {
        if (((string) ($u['nip'] ?? '') === $nip) || ((string) ($u['id'] ?? '') === $nip)) {
            $foundUser = $u;
            break;
        }
    }


    if ($foundUser === null || (($foundUser['status'] ?? 'Active') !== 'Active')) {
        return null;
    }


    if (!password_verify($password, (string) ($foundUser['password_hash'] ?? ''))) {
        return null;
    }


    return $foundUser;
}


function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (string) $user['id'];
}


function logoutUser(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}


function isAdmin(array $user): bool
{
    return strtoupper((string) ($user['role'] ?? '')) === 'ADMIN';
}


function publicUser(array $user): array
{
    return [
        'id' => (string) ($user['id'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'branch_id' => (string) ($user['branch_id'] ?? ''),
        'branch_name' => (string) ($user['branch_name'] ?? ''),
        'area' => (string) ($user['area'] ?? ''),
    ];
}


function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


function setFlash(string $tone, string $message): void
{
    $_SESSION['flash'] = [
        'tone' => $tone,
        'message' => $message,
    ];
}


function consumeFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}


function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}


function currentPageUrl(array $mergeQuery = []): string
{
    $query = $_GET;
    foreach ($mergeQuery as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }


    $queryString = http_build_query($query);
    return basename((string) ($_SERVER['PHP_SELF'] ?? 'performancev1.php')) . ($queryString !== '' ? '?' . $queryString : '');
}


function loadJsonFile(string $file, array $default): array
{
    if (!is_file($file)) {
        return $default;
    }


    $json = file_get_contents($file);
    if ($json === false || trim($json) === '') {
        return $default;
    }


    $data = json_decode($json, true);
    return is_array($data) ? $data : $default;
}


function writeJsonFile(string $file, array $data): void
{
    ensureDirectory(dirname($file));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Gagal menulis file JSON.');
    }


    $tmpFile = $file . '.tmp';
    if (@file_put_contents($tmpFile, $json, LOCK_EX) === false) {
        throw new RuntimeException('Gagal menulis file sementara JSON. Periksa permission folder server.');
    }


    if (!@rename($tmpFile, $file)) {
        @unlink($tmpFile);
        throw new RuntimeException('Gagal menyimpan file JSON. Periksa permission folder server.');
    }
}


function loadActivityStore(string $activityFile): array
{
    $data = loadJsonFile($activityFile, ['summary' => [], 'events' => []]);
    if (!isset($data['summary']) || !is_array($data['summary'])) {
        $data['summary'] = [];
    }
    if (!isset($data['events']) || !is_array($data['events'])) {
        $data['events'] = [];
    }


    return $data;
}


function saveActivityStore(string $activityFile, array $store): void
{
    writeJsonFile($activityFile, $store);
}


function recordActivity(string $activityFile, array $user, string $event, array $meta = []): void
{
    $store = loadActivityStore($activityFile);
    $userId = (string) ($user['id'] ?? '');
    if ($userId === '') {
        return;
    }


    $now = date('c');
    if (!isset($store['summary'][$userId])) {
        $store['summary'][$userId] = [
            'user_id' => $userId,
            'name' => (string) ($user['name'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'branch_id' => (string) ($user['branch_id'] ?? ''),
            'branch_name' => (string) ($user['branch_name'] ?? ''),
            'login_count' => 0,
            'view_count' => 0,
            'last_login' => null,
            'last_view' => null,
            'last_page' => null,
            'last_event' => null,
        ];
    }


    $summary = &$store['summary'][$userId];
    $summary['name'] = (string) ($user['name'] ?? '');
    $summary['role'] = (string) ($user['role'] ?? '');
    $summary['branch_id'] = (string) ($user['branch_id'] ?? '');
    $summary['branch_name'] = (string) ($user['branch_name'] ?? '');
    $summary['last_event'] = $event;


    if ($event === 'login') {
        $summary['login_count']++;
        $summary['last_login'] = $now;
    }


    if ($event === 'view_data' || $event === 'view_kredit') {
        $summary['view_count']++;
        $summary['last_view'] = $now;
    }


    if (str_starts_with($event, 'page_')) {
        $summary['last_page'] = $event;
    }


    $store['events'][] = [
        'time' => $now,
        'user_id' => $userId,
        'name' => (string) ($user['name'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'event' => $event,
        'meta' => $meta,
    ];


    if (count($store['events']) > 500) {
        $store['events'] = array_slice($store['events'], -500);
    }


    saveActivityStore($activityFile, $store);
}


function formatAdminTimestamp(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        $date = new DateTime($value);
        $bulanId = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        return $date->format('H:i') . ' · ' . $date->format('j') . ' ' . $bulanId[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
    } catch (Throwable) {
        return (string) $value;
    }
}


function formatAdminEventLabel(string $event): string
{
    return match ($event) {
        'login' => 'Login',
        'view_data' => 'View Dana',
        'view_kredit' => 'View Kredit',
        'page_admin' => 'Admin Panel',
        'page_dashboard' => 'Dashboard',
        default => ucwords(str_replace('_', ' ', $event)),
    };
}


function formatAdminEventDescription(string $event, array $meta = []): string
{
    if ($event === 'view_data') {
        $parts = [];
        if (!empty($meta['product'])) {
            $parts[] = 'Produk ' . (string) $meta['product'];
        }
        if (!empty($meta['period'])) {
            $parts[] = 'Periode ' . (string) $meta['period'];
        }
        if (!empty($meta['id'])) {
            $parts[] = 'Unit ' . (string) $meta['id'];
        }
        return $parts !== [] ? implode(' · ', $parts) : 'Membuka dashboard dana.';
    }

    if ($event === 'view_kredit') {
        $parts = [];
        if (!empty($meta['products'])) {
            $parts[] = 'Produk ' . implode(', ', array_slice(explode(',', (string) $meta['products']), 0, 3));
        }
        if (!empty($meta['view_mode'])) {
            $parts[] = 'Mode ' . (string) $meta['view_mode'];
        }
        if (!empty($meta['id'])) {
            $parts[] = 'Unit ' . (string) $meta['id'];
        }
        return $parts !== [] ? implode(' · ', $parts) : 'Membuka dashboard kredit.';
    }

    if ($event === 'login') {
        return !empty($meta['source']) ? 'Login via ' . (string) $meta['source'] : 'Login ke sistem.';
    }

    if (str_starts_with($event, 'page_')) {
        return 'Membuka halaman ' . strtoupper(str_replace('page_', '', $event)) . '.';
    }

    return ucwords(str_replace('_', ' ', $event));
}


function buildAdminMonitoringInsights(array $users, array $activitySummary, array $events): array
{
    $userIndex = [];
    foreach ($users as $user) {
        $userId = (string) ($user['id'] ?? '');
        if ($userId !== '') {
            $userIndex[$userId] = $user;
        }
    }

    $summaryIndex = [];
    foreach ($activitySummary as $row) {
        $userId = (string) ($row['user_id'] ?? '');
        if ($userId !== '') {
            $summaryIndex[$userId] = $row;
        }
    }

    $cohort = array_values(array_filter($users, static function (array $user): bool {
        return strtolower((string) ($user['status'] ?? '')) === 'active'
            && stripos((string) ($user['jabatan'] ?? ''), 'branch manager') !== false;
    }));
    $cohortLabel = 'Branch Manager';

    if ($cohort === []) {
        $cohort = array_values(array_filter($users, static function (array $user): bool {
            return strtolower((string) ($user['status'] ?? '')) === 'active'
                && strtolower((string) ($user['role'] ?? '')) !== 'admin';
        }));
        $cohortLabel = 'Visitor Aktif';
    }

    $cohortIds = [];
    foreach ($cohort as $user) {
        $cohortIds[(string) ($user['id'] ?? '')] = true;
    }

    $today = date('Y-m-d');
    $last7Keys = [];
    for ($i = 6; $i >= 0; $i--) {
        $key = date('Y-m-d', strtotime("-{$i} days"));
        $last7Keys[] = $key;
    }

    $hourlyLogins = array_fill(0, 24, 0);
    $dailyLogins = array_fill_keys($last7Keys, 0);
    $dailyViews = array_fill_keys($last7Keys, 0);
    $eventMix = [
        'login' => 0,
        'view_data' => 0,
        'view_kredit' => 0,
        'page_admin' => 0,
        'page_dashboard' => 0,
        'other' => 0,
    ];
    $perUser = [];

    foreach ($cohort as $user) {
        $userId = (string) ($user['id'] ?? '');
        $summary = $summaryIndex[$userId] ?? [];
        $lastActivity = (string) ($summary['last_view'] ?? $summary['last_login'] ?? '');
        $perUser[$userId] = [
            'user' => $user,
            'summary' => $summary,
            'login_today' => 0,
            'view_today' => 0,
            'active_days' => [],
            'events' => 0,
            'last_activity' => $lastActivity,
            'monitoring_score' => 0,
            'event_mix' => [
                'login' => 0,
                'view_data' => 0,
                'view_kredit' => 0,
                'page_admin' => 0,
                'page_dashboard' => 0,
                'other' => 0,
            ],
        ];
    }

    foreach ($events as $event) {
        $userId = (string) ($event['user_id'] ?? '');
        if (!isset($cohortIds[$userId])) {
            continue;
        }

        $time = (string) ($event['time'] ?? '');
        $dateKey = substr((string) $time, 0, 10);
        $hourKey = (int) substr((string) $time, 11, 2);
        $eventName = (string) ($event['event'] ?? 'other');
        if (!isset($perUser[$userId])) {
            continue;
        }

        $perUser[$userId]['events']++;
        if ($dateKey !== '') {
            $perUser[$userId]['active_days'][$dateKey] = true;
            $perUser[$userId]['last_activity'] = max((string) $perUser[$userId]['last_activity'], $time);
        }

        if ($eventName === 'login') {
            $hourlyLogins[$hourKey] = ($hourlyLogins[$hourKey] ?? 0) + 1;
            if (isset($dailyLogins[$dateKey])) {
                $dailyLogins[$dateKey]++;
            }
            if ($dateKey === $today) {
                $perUser[$userId]['login_today']++;
            }
        } elseif ($eventName === 'view_data' || $eventName === 'view_kredit') {
            if (isset($dailyViews[$dateKey])) {
                $dailyViews[$dateKey]++;
            }
            if ($dateKey === $today) {
                $perUser[$userId]['view_today']++;
            }
        }

        if (!isset($eventMix[$eventName])) {
            $eventName = 'other';
        }
        $eventMix[$eventName]++;
        $perUser[$userId]['event_mix'][$eventName]++;
    }

    $totalLoginToday = 0;
    $totalViewToday = 0;
    $activeToday = 0;
    $inactiveOver7Days = [];
    $topManagers = [];
    $mostActiveManager = null;
    $bestScore = -1;

    foreach ($perUser as $userId => &$row) {
        $summary = $row['summary'];
        $loginCount = (int) ($summary['login_count'] ?? 0);
        $viewCount = (int) ($summary['view_count'] ?? 0);
        $activeDays = count($row['active_days']);
        $lastActivityDate = $row['last_activity'] !== '' ? substr((string) $row['last_activity'], 0, 10) : '';
        $isActiveToday = $lastActivityDate === $today || $row['login_today'] > 0 || $row['view_today'] > 0;
        $row['monitoring_score'] = ($viewCount * 3) + ($loginCount * 2) + ($activeDays * 4) + ($row['view_today'] * 3) + ($row['login_today'] * 2) + ($isActiveToday ? 5 : 0);
        $row['active_day_count'] = $activeDays;

        $topManagers[] = [
            'user_id' => $userId,
            'id' => $userId,
            'name' => (string) ($row['user']['name'] ?? $summary['name'] ?? $userId),
            'role' => (string) ($row['user']['role'] ?? $summary['role'] ?? 'Visitor'),
            'branch_name' => (string) ($row['user']['branch_name'] ?? $summary['branch_name'] ?? '-'),
            'jabatan' => (string) ($row['user']['jabatan'] ?? '-'),
            'login_count' => $loginCount,
            'view_count' => $viewCount,
            'login_today' => $row['login_today'],
            'view_today' => $row['view_today'],
            'active_days' => $activeDays,
            'last_view' => (string) ($summary['last_view'] ?? ''),
            'last_activity' => (string) $row['last_activity'],
            'monitoring_score' => $row['monitoring_score'],
        ];

        $totalLoginToday += $row['login_today'];
        $totalViewToday += $row['view_today'];
        if ($isActiveToday) {
            $activeToday++;
        }
        if ($activeDays === 0) {
            $inactiveOver7Days[] = [
                'id' => $userId,
                'name' => (string) ($row['user']['name'] ?? $userId),
                'branch_name' => (string) ($row['user']['branch_name'] ?? '-'),
            ];
        }
        if ($row['monitoring_score'] > $bestScore) {
            $bestScore = $row['monitoring_score'];
            $mostActiveManager = [
                'name' => (string) ($row['user']['name'] ?? $userId),
                'branch_name' => (string) ($row['user']['branch_name'] ?? '-'),
                'score' => $row['monitoring_score'],
            ];
        }
    }
    unset($row);

    usort($topManagers, static fn(array $a, array $b): int => $b['monitoring_score'] <=> $a['monitoring_score']);
    $topManagers = array_slice($topManagers, 0, 7);

    $peakHour = 0;
    $peakHourCount = -1;
    foreach ($hourlyLogins as $hour => $count) {
        if ($count > $peakHourCount) {
            $peakHourCount = $count;
            $peakHour = $hour;
        }
    }

    $hourlyCategories = [];
    $hourlySeries = [];
    foreach ($hourlyLogins as $hour => $count) {
        $hourlyCategories[] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . '.00';
        $hourlySeries[] = $count;
    }

    $dailyCategories = [];
    $dailyLoginSeries = [];
    $dailyViewSeries = [];
    foreach ($last7Keys as $key) {
        $dailyCategories[] = date('d M', strtotime($key));
        $dailyLoginSeries[] = $dailyLogins[$key] ?? 0;
        $dailyViewSeries[] = $dailyViews[$key] ?? 0;
    }

    return [
        'cohort_label' => $cohortLabel,
        'cohort_count' => count($cohort),
        'active_today_count' => $activeToday,
        'total_login_today' => $totalLoginToday,
        'total_view_today' => $totalViewToday,
        'avg_view_per_user' => count($cohort) > 0 ? round($totalViewToday / count($cohort), 1) : 0,
        'peak_hour_label' => str_pad((string) $peakHour, 2, '0', STR_PAD_LEFT) . '.00 - ' . str_pad((string) (($peakHour + 1) % 24), 2, '0', STR_PAD_LEFT) . '.00',
        'peak_hour_count' => max(0, $peakHourCount),
        'most_active_manager' => $mostActiveManager,
        'inactive_over_7_days' => array_slice($inactiveOver7Days, 0, 5),
        'top_managers' => $topManagers,
        'charts' => [
            'hourly_categories' => $hourlyCategories,
            'hourly_logins' => $hourlySeries,
            'daily_categories' => $dailyCategories,
            'daily_logins' => $dailyLoginSeries,
            'daily_views' => $dailyViewSeries,
            'event_mix_labels' => ['Login', 'View Dana', 'View Kredit', 'Admin Panel', 'Dashboard', 'Lainnya'],
            'event_mix_series' => [
                (int) ($eventMix['login'] ?? 0),
                (int) ($eventMix['view_data'] ?? 0),
                (int) ($eventMix['view_kredit'] ?? 0),
                (int) ($eventMix['page_admin'] ?? 0),
                (int) ($eventMix['page_dashboard'] ?? 0),
                (int) ($eventMix['other'] ?? 0),
            ],
        ],
    ];
}


function handleAdminData(string $usersFile, string $activityFile): void
{
    $users = array_values(loadUserStore($usersFile)['users']);
    usort($users, static fn(array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));


    $activity = loadActivityStore($activityFile);
    $summary = array_values($activity['summary']);
    usort($summary, static fn(array $a, array $b): int => strcmp((string) ($b['last_view'] ?? $b['last_login'] ?? ''), (string) ($a['last_view'] ?? $a['last_login'] ?? '')));


    $events = array_reverse($activity['events']);


    jsonResponse([
        'ok' => true,
        'users' => $users,
        'activity_summary' => $summary,
        'activity_events' => array_slice($events, 0, 80),
    ]);
}


function handleUserSaveApi(string $usersFile): void
{
    saveUserFromRequest($usersFile, currentUser($usersFile) ?? []);
    jsonResponse(['ok' => true, 'message' => 'Data user berhasil disimpan.']);
}


function handleUserDeleteApi(string $usersFile, array $currentUser): void
{
    deleteUserFromRequest($usersFile, $currentUser);
    jsonResponse(['ok' => true, 'message' => 'User berhasil dihapus.']);
}


function handleLoginApi(string $usersFile, string $activityFile): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => 'Login harus menggunakan POST.'], 405);
        return;
    }


    $userId = trim((string) ($_POST['user_id'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $user = authenticateUser($usersFile, $userId, $password);


    if ($user === null) {
        jsonResponse(['ok' => false, 'message' => 'ID atau password salah.'], 401);
        return;
    }


    loginUser($user);
    recordActivity($activityFile, $user, 'login', ['source' => 'api']);
    jsonResponse([
        'ok' => true,
        'message' => 'Login berhasil.',
        'redirect' => isAdmin($user) ? currentPageUrl(['page' => 'admin']) : currentPageUrl(['page' => null]),
    ]);
}


function saveUserFromRequest(string $usersFile, array $currentUser): void
{
    $store = loadUserStore($usersFile);
    $userId = trim((string) ($_POST['user_id'] ?? ''));
    $nip = trim((string) ($_POST['nip'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $jabatan = trim((string) ($_POST['jabatan'] ?? ''));
    $role = trim((string) ($_POST['role'] ?? 'Visitor'));
    $status = trim((string) ($_POST['status'] ?? 'Active'));
    $password = (string) ($_POST['password'] ?? '');
    $editingId = trim((string) ($_POST['editing_id'] ?? ''));


    $branchCombo = trim((string) ($_POST['branch_combo'] ?? ''));
    if ($branchCombo !== '') {
        $parts = explode('-', $branchCombo, 2);
        $branchId = trim($parts[0]);
        $branchName = isset($parts[1]) ? trim($parts[1]) : $branchId;
    } else {
        $branchId = trim((string) ($_POST['branch_id'] ?? ''));
        $branchName = trim((string) ($_POST['branch_name'] ?? ''));
    }


    if ($userId === '' || $name === '') {
        throw new RuntimeException('ID/NIP dan nama user wajib diisi.');
    }


    if ($nip !== '' && !preg_match('/^\d{10}$/', $nip)) {
        throw new RuntimeException('Format NIP tidak valid. NIP harus berupa 10 digit angka.');
    }


    $role = strtoupper($role) === 'ADMIN' ? 'Admin' : 'Visitor';
    $status = strtoupper($status) === 'INACTIVE' ? 'Inactive' : 'Active';


    if ($editingId !== '' && $editingId !== $userId && isset($store['users'][$userId])) {
        throw new RuntimeException('ID user baru sudah dipakai.');
    }


    if ($editingId === '' && isset($store['users'][$userId])) {
        throw new RuntimeException('ID user sudah ada.');
    }


    $existing = $editingId !== '' ? ($store['users'][$editingId] ?? null) : null;
    $now = date('c');
    $record = [
        'id' => $userId,
        'nip' => $nip !== '' ? $nip : $userId,
        'name' => $name,
        'jabatan' => $jabatan,
        'role' => $role,
        'branch_id' => $branchId,
        'branch_name' => $branchName,
        'area' => '',
        'status' => $status,
        'password_hash' => is_array($existing) ? (string) ($existing['password_hash'] ?? '') : '',
        'created_at' => is_array($existing) ? (string) ($existing['created_at'] ?? $now) : $now,
        'updated_at' => $now,
        'source' => is_array($existing) ? (string) ($existing['source'] ?? 'manual') : 'manual',
    ];


    if ($password !== '') {
        $record['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    } elseif (!is_array($existing)) {
        $defaultPassword = $nip !== '' ? $nip : $userId;
        $record['password_hash'] = password_hash($defaultPassword, PASSWORD_DEFAULT);
    }


    if ($record['password_hash'] === '') {
        throw new RuntimeException('Password user tidak valid.');
    }


    if ($editingId !== '' && $editingId !== $userId) {
        unset($store['users'][$editingId]);
    }


    if (($currentUser['id'] ?? '') === $editingId && $status !== 'Active') {
        throw new RuntimeException('Admin yang sedang login tidak bisa menonaktifkan dirinya sendiri.');
    }


    $store['users'][$userId] = $record;
    saveUserStore($usersFile, $store);
    setFlash('success', 'Data user berhasil disimpan.');
}


function deleteUserFromRequest(string $usersFile, array $currentUser): void
{
    $userId = trim((string) ($_POST['delete_user_id'] ?? ''));
    if ($userId === '') {
        throw new RuntimeException('User yang akan dihapus tidak ditemukan.');
    }


    if ($userId === (string) ($currentUser['id'] ?? '')) {
        throw new RuntimeException('Admin yang sedang login tidak bisa menghapus dirinya sendiri.');
    }


    $store = loadUserStore($usersFile);
    if (!isset($store['users'][$userId])) {
        throw new RuntimeException('User tidak ditemukan.');
    }


    unset($store['users'][$userId]);
    saveUserStore($usersFile, $store);
    setFlash('success', 'User berhasil dihapus.');
}


function productGroups(): array
{
    return [
        'dana' => [
            'Tabungan' => 'Tabungan',
            'Giro' => 'Giro',
            'GiroRetail' => 'Giro Retail',
            'Deposito' => 'Deposito',
            'CASA' => 'CASA',
            'DPK' => 'DPK',
        ],
        'kredit' => [
            'KreditRetail' => 'Kredit Retail',
            'SME' => 'SME',
            'ConsumerBanking' => 'Consumer Banking',
            'ConsumerLoan' => 'Consumer Loan',
            'CreditCard' => 'Credit Card',
            'KUMBlend' => 'KUM Blend',
            'KKB' => 'KKB',
            'Micro' => 'Micro',
            'KSM' => 'KSM',
            'KUM' => 'KUM',
            'KUR' => 'KUR',
            'KPR' => 'KPR',
        ],
    ];
}


function detectProductGroup(string $product): string
{
    $productLower = strtolower($product);
    foreach (productGroups() as $group => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($productLower, strtolower($keyword))) {
                return $group;
            }
        }
    }
    return 'dana';
}


function buildProductGroupMeta(array $products): array
{
    $groups = ['dana' => [], 'kredit' => []];


    foreach ($products as $product) {
        $groupKey = detectProductGroup($product);
        if (isset($groups[$groupKey])) {
            $groups[$groupKey][] = $product;
        }
    }


    return [
        'dana' => [
            'key' => 'dana',
            'label' => 'Produk Dana',
            'products' => $groups['dana'],
            'under_construction' => false,
        ],
        'kredit' => [
            'key' => 'kredit',
            'label' => 'Produk Kredit',
            'products' => $groups['kredit'],
            'under_construction' => false,
        ]
    ];
}


function filterCacheForUser(array $cache, array $currentUser): array
{
    return $cache;
}


function summarizeSeries(array $allSeries): array
{
    if ($allSeries === []) {
        return [
            'current_month' => null,
            'previous_month' => null,
            'end_balance' => null,
            'bottom_balance' => null,
            'max_balance' => null,
            'growth_end_nominal' => null,
            'growth_end_percent' => null,
            'growth_bottom_nominal' => null,
            'growth_bottom_percent' => null,
            'growth_ytd_nominal' => null,
            'growth_ytd_percent' => null,
            'growth_yoy_nominal' => null,
            'growth_yoy_percent' => null,
        ];
    }

    $seriesMap = [];
    foreach ($allSeries as $s) {
        $seriesMap[$s['month_key']] = $s;
    }

    $current = end($allSeries);
    $currentMonthKey = $current['month_key'];
    $year = (int) substr($currentMonthKey, 0, 4);
    $month = (int) substr($currentMonthKey, 5, 2);

    $prevMonth = $month - 1;
    $prevYear = $year;
    if ($prevMonth === 0) {
        $prevMonth = 12;
        $prevYear--;
    }
    $prevMonthKey = sprintf('%04d-%02d', $prevYear, $prevMonth);
    $ytdBaseKey   = sprintf('%04d-12', $year - 1);
    $yoyBaseKey   = sprintf('%04d-%02d', $year - 1, $month);

    $previous = $seriesMap[$prevMonthKey] ?? (count($allSeries) > 1 ? $allSeries[count($allSeries) - 2] : null);
    $ytdBase  = $seriesMap[$ytdBaseKey] ?? null;
    $yoyBase  = $seriesMap[$yoyBaseKey] ?? null;

    $endBalance    = $current['end_value'] ?? null;
    $bottomBalance = $current['bottom_value'] ?? null;
    $prevEnd       = $previous['end_value'] ?? null;
    $prevBottom    = $previous['bottom_value'] ?? null;
    $ytdEnd        = $ytdBase['end_value'] ?? null;
    $yoyEnd        = $yoyBase['end_value'] ?? null;

    return [
        'current_month'         => $current['name'] ?? null,
        'previous_month'        => $previous['name'] ?? null,
        'end_balance'           => $endBalance,
        'bottom_balance'        => $bottomBalance,
        'max_balance'           => null, // dihitung di handleData() dari $series filtered
        'growth_end_nominal'    => growthNominal($endBalance, $prevEnd),
        'growth_end_percent'    => growthPercent($endBalance, $prevEnd),
        'growth_bottom_nominal' => growthNominal($bottomBalance, $prevBottom),
        'growth_bottom_percent' => growthPercent($bottomBalance, $prevBottom),
        'growth_ytd_nominal'    => growthNominal($endBalance, $ytdEnd),
        'growth_ytd_percent'    => growthPercent($endBalance, $ytdEnd),
        'growth_yoy_nominal'    => growthNominal($endBalance, $yoyEnd),
        'growth_yoy_percent'    => growthPercent($endBalance, $yoyEnd),
    ];
}


function growthNominal(int|float|null $current, int|float|null $previous): int|float|null
{
    if ($current === null || $previous === null) {
        return null;
    }


    return normalizeOutputNumber($current - $previous);
}


function growthPercent(int|float|null $current, int|float|null $previous): int|float|null
{
    if ($current === null || $previous === null || (float) $previous === 0.0) {
        return null;
    }


    return round((($current - $previous) / abs($previous)) * 100, 2);
}


function nativeXlsxSetupStatus(): array
{
    $missing = [];


    return [
        'ready' => empty($missing),
        'missing_extensions' => $missing,
        'message' => empty($missing)
            ? 'Siap digunakan'
            : 'Ekstensi belum lengkap: ' . implode(', ', $missing),
    ];
}


// ======================== GMM BACKEND ========================


function loadGmmDb(string $dbFile): array
{
    if (!is_file($dbFile)) {
        return ['cabang' => [], 'pegawai' => []];
    }
    $json = file_get_contents($dbFile);
    if ($json === false) return ['cabang' => [], 'pegawai' => []];
    $data = json_decode($json, true);
    if (!is_array($data)) return ['cabang' => [], 'pegawai' => []];
    return [
        'cabang' => is_array($data['cabang'] ?? null) ? $data['cabang'] : [],
        'pegawai' => is_array($data['pegawai'] ?? null) ? $data['pegawai'] : []
    ];
}


function saveGmmDb(string $dbFile, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) return;
    $tmp = $dbFile . '.tmp';
    @file_put_contents($tmp, $json, LOCK_EX);
    @rename($tmp, $dbFile);
}


function gmmNormalizeVal($x): float
{
    if ($x === null || $x === '') return 0;
    $s = str_replace(',', '.', trim((string)$x));
    $s = preg_replace('/[^\d.\-]/', '', $s);
    return is_numeric($s) ? (float)$s : 0;
}


function handleGmmUpload(string $dbFile): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => 'POST required.'], 405);
        return;
    }
    if (!isset($_FILES['gmm_file'])) {
        jsonResponse(['ok' => false, 'message' => 'File GMM belum dipilih.'], 400);
        return;
    }
    $file = $_FILES['gmm_file'];
    if (($file['error'] ?? 4) !== 0) {
        jsonResponse([
            'ok' => false,
            'message' => uploadErrorMessage((int)$file['error']),
            'code' => $file['error']
        ], 400);
        return;
    }


    $uploadType = trim((string)($_POST['upload_type'] ?? 'current'));
    $isBase = ($uploadType === 'baseline');


    $tmpPath = (string)$file['tmp_name'];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        jsonResponse(['ok' => false, 'message' => 'Format harus .xlsx'], 400);
        return;
    }


    set_time_limit(300);
    $workbook = xlsxReadWorkbook($tmpPath);
    $masterData = [];


    // Parse GMM LIVIN sheet
    foreach ($workbook['sheets'] as $sheet) {
        $sheetName = strtoupper(trim((string)$sheet['name']));
        try {
            $rows = xlsxReadSheetRows($workbook, $sheet);
        } catch (\Throwable $e) {
            continue;
        }
        if (empty($rows)) continue;


        // Find header row (first row with data)
        $headerRow = null;
        $headers = [];
        foreach ($rows as $rIdx => $rCols) {
            $vals = array_map(fn($c) => strtolower(trim((string)($c['value'] ?? ''))), $rCols);
            if (in_array('nip', $vals, true)) {
                $headerRow = $rIdx;
                $headers = $rCols;
                break;
            }
        }
        if ($headerRow === null) continue;


        $hMap = [];
        foreach ($headers as $ci => $cell) {
            $hMap[$ci] = strtolower(trim((string)($cell['value'] ?? '')));
        }


        $findH = function (array $aliases) use ($hMap): ?int {
            // Exact match first
            foreach ($aliases as $a) {
                $aLower = strtolower(preg_replace('/[_\\s]+/', ' ', $a));
                foreach ($hMap as $i => $h) {
                    $hNorm = strtolower(preg_replace('/[_\\s]+/', ' ', $h));
                    if ($hNorm === $aLower) return $i;
                }
            }
            // Partial/normalized match fallback
            foreach ($aliases as $a) {
                $aClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $a));
                foreach ($hMap as $i => $h) {
                    $hClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $h));
                    if ($hClean === $aClean || str_contains($hClean, $aClean)) return $i;
                }
            }
            return null;
        };


        $getVal = function ($row, ?int $col) {
            return $col !== null && isset($row[$col]) ? gmmNormalizeVal($row[$col]['value'] ?? null) : 0;
        };
        $getStr = function ($row, ?int $col): string {
            return $col !== null && isset($row[$col]) ? trim((string)($row[$col]['value'] ?? '')) : '';
        };


        if (str_contains($sheetName, 'LIVIN') || str_contains($sheetName, 'GMM LIVIN')) {
            $cNip = $findH(['nip']);
            $cNama = $findH(['nama', 'nama pegawai', 'employee name']);
            $cKode = $findH(['kode cabang', 'kode_cabang']);
            $cUnit = $findH(['nama cabang', 'nama_cabang', 'cabang', 'unit']);
            $cArea = $findH(['area', 'wilayah']);
            $cKelas = $findH(['kelas cabang', 'kelas']);
            $cPosisi = $findH(['posisi', 'unit kerja']);
            $cEB = $findH(['end_balance', 'end balance']);
            $cCA = $findH(['cif akuisisi', 'cif']);
            $cCS = $findH(['cif setor']);
            $cRR = $findH(['rata-rata', 'rata rata']);
            $cCT = $findH(['cif_sudah_transaksi', 'cif sudah transaksi']);
            $cFC = $findH(['frek dari cif akuisisi', 'frek dari cif yang diakuisisi']);
            if ($cNip === null) continue;
            foreach ($rows as $ri => $row) {
                if ($ri <= $headerRow) continue;
                $nip = $getStr($row, $cNip);
                if ($nip === '' || $nip === 'nan') continue;
                if (!isset($masterData[$nip])) $masterData[$nip] = ['nip' => $nip];
                if ($cNama !== null && empty($masterData[$nip]['nama'])) $masterData[$nip]['nama'] = $getStr($row, $cNama);
                if ($cKode !== null && empty($masterData[$nip]['kode_cabang'])) $masterData[$nip]['kode_cabang'] = $getStr($row, $cKode);
                if ($cUnit !== null && empty($masterData[$nip]['unit'])) $masterData[$nip]['unit'] = $getStr($row, $cUnit);
                if ($cArea !== null && empty($masterData[$nip]['area'])) $masterData[$nip]['area'] = $getStr($row, $cArea);
                if ($cKelas !== null && empty($masterData[$nip]['kelas_cabang'])) $masterData[$nip]['kelas_cabang'] = $getStr($row, $cKelas);
                if ($cPosisi !== null && empty($masterData[$nip]['posisi'])) $masterData[$nip]['posisi'] = $getStr($row, $cPosisi);
                $masterData[$nip]['end_balance'] = $getVal($row, $cEB);
                $masterData[$nip]['cif_akuisisi'] = $getVal($row, $cCA);
                $masterData[$nip]['cif_setor'] = $getVal($row, $cCS);
                $masterData[$nip]['rata_rata'] = $getVal($row, $cRR);
                $masterData[$nip]['cif_sudah_transaksi'] = $getVal($row, $cCT);
                $masterData[$nip]['frek_dari_cif_akuisisi'] = $getVal($row, $cFC);
            }
        } elseif (str_contains($sheetName, 'MERCHANT') || str_contains($sheetName, 'GMM MERCHANT')) {
            $cNip = $findH(['nip']);
            $cNama = $findH(['nama', 'nama pegawai', 'employee name']);
            $cKode = $findH(['kode cabang', 'kode_cabang']);
            $cUnit = $findH(['nama cabang', 'nama_cabang', 'cabang', 'unit']);
            $cArea = $findH(['area', 'wilayah']);
            $cKelas = $findH(['kelas cabang', 'kelas']);
            $cPosisi = $findH(['posisi', 'unit kerja']);
            $cRL = $findH(['total referral livin']);
            $cRE = $findH(['total referral edc']);
            if ($cNip === null) continue;
            foreach ($rows as $ri => $row) {
                if ($ri <= $headerRow) continue;
                $nip = $getStr($row, $cNip);
                if ($nip === '' || $nip === 'nan') continue;
                if (!isset($masterData[$nip])) $masterData[$nip] = ['nip' => $nip];
                if ($cNama !== null && empty($masterData[$nip]['nama'])) $masterData[$nip]['nama'] = $getStr($row, $cNama);
                if ($cKode !== null && empty($masterData[$nip]['kode_cabang'])) $masterData[$nip]['kode_cabang'] = $getStr($row, $cKode);
                if ($cUnit !== null && empty($masterData[$nip]['unit'])) $masterData[$nip]['unit'] = $getStr($row, $cUnit);
                if ($cArea !== null && empty($masterData[$nip]['area'])) $masterData[$nip]['area'] = $getStr($row, $cArea);
                if ($cKelas !== null && empty($masterData[$nip]['kelas_cabang'])) $masterData[$nip]['kelas_cabang'] = $getStr($row, $cKelas);
                if ($cPosisi !== null && empty($masterData[$nip]['posisi'])) $masterData[$nip]['posisi'] = $getStr($row, $cPosisi);
                $masterData[$nip]['total_referral_livin'] = $getVal($row, $cRL);
                $masterData[$nip]['total_referral_edc'] = $getVal($row, $cRE);
            }
        } elseif (str_contains($sheetName, 'TRANSAKSI') || str_contains($sheetName, 'GMM TRANSAKSI')) {
            $cNip = $findH(['nip']);
            $cNama = $findH(['nama', 'nama pegawai', 'employee name']);
            $cKode = $findH(['kode cabang', 'kode_cabang']);
            $cUnit = $findH(['nama cabang', 'nama_cabang', 'cabang', 'unit']);
            $cArea = $findH(['area', 'wilayah']);
            $cKelas = $findH(['kelas cabang', 'kelas']);
            $cPosisi = $findH(['posisi', 'unit kerja']);
            $cTP = $findH(['total poin transaksi']);
            $cPO = $findH(['poin on us']);
            $cPF = $findH(['poin off us']);
            $cFO = $findH(['frek on us']);
            $cFF = $findH(['frek off us']);
            $cPC = $findH(['pct on us']);
            if ($cNip === null) continue;
            foreach ($rows as $ri => $row) {
                if ($ri <= $headerRow) continue;
                $nip = $getStr($row, $cNip);
                if ($nip === '' || $nip === 'nan') continue;
                if (!isset($masterData[$nip])) $masterData[$nip] = ['nip' => $nip];
                if ($cNama !== null && empty($masterData[$nip]['nama'])) $masterData[$nip]['nama'] = $getStr($row, $cNama);
                if ($cKode !== null && empty($masterData[$nip]['kode_cabang'])) $masterData[$nip]['kode_cabang'] = $getStr($row, $cKode);
                if ($cUnit !== null && empty($masterData[$nip]['unit'])) $masterData[$nip]['unit'] = $getStr($row, $cUnit);
                if ($cArea !== null && empty($masterData[$nip]['area'])) $masterData[$nip]['area'] = $getStr($row, $cArea);
                if ($cKelas !== null && empty($masterData[$nip]['kelas_cabang'])) $masterData[$nip]['kelas_cabang'] = $getStr($row, $cKelas);
                if ($cPosisi !== null && empty($masterData[$nip]['posisi'])) $masterData[$nip]['posisi'] = $getStr($row, $cPosisi);
                $masterData[$nip]['total_poin_transaksi'] = $getVal($row, $cTP);
                $masterData[$nip]['poin_on_us'] = $getVal($row, $cPO);
                $masterData[$nip]['poin_off_us'] = $getVal($row, $cPF);
                $masterData[$nip]['frek_on_us'] = $getVal($row, $cFO);
                $masterData[$nip]['frek_off_us'] = $getVal($row, $cFF);
                $masterData[$nip]['pct_on_us'] = $getVal($row, $cPC);
            }
        }
    }


    if (empty($masterData)) {
        jsonResponse(['ok' => false, 'message' => 'Tidak ada data GMM ditemukan di file.'], 400);
        return;
    }


    $db = loadGmmDb($dbFile);


    if (!$isBase) {
        foreach ($db['pegawai'] as &$p) {
            $p['is_active'] = 0;
        }
        unset($p);
    }


    $inserted = 0;
    $cols = [
        'end_balance',
        'cif_akuisisi',
        'cif_setor',
        'cif_sudah_transaksi',
        'rata_rata',
        'frek_dari_cif_akuisisi',
        'total_referral_livin',
        'total_referral_edc',
        'total_poin_transaksi',
        'poin_on_us',
        'poin_off_us',
        'frek_on_us',
        'frek_off_us',
        'pct_on_us'
    ];


    foreach ($masterData as $nip => $d) {
        $d = array_merge(['kode_cabang' => '', 'unit' => '', 'area' => '', 'kelas_cabang' => '', 'posisi' => '', 'nama' => ''], $d);
        foreach ($cols as $c) {
            if (!isset($d[$c])) $d[$c] = 0;
        }


        if (!empty($d['kode_cabang'])) {
            $db['cabang'][$d['kode_cabang']] = [
                'kode_cabang' => $d['kode_cabang'],
                'unit' => $d['unit'],
                'area' => $d['area'],
                'kelas_cabang' => $d['kelas_cabang']
            ];
        }


        if (!isset($db['pegawai'][$nip])) {
            $db['pegawai'][$nip] = ['nip' => $nip];
            foreach ($cols as $c) {
                $db['pegawai'][$nip][$c] = 0;
                $db['pegawai'][$nip][$c . '_base'] = 0;
            }
        }


        $p = &$db['pegawai'][$nip];
        $p['nama'] = $d['nama'];
        $p['kode_cabang'] = $d['kode_cabang'];
        $p['unit'] = $d['unit'];
        $p['area'] = $d['area'];
        $p['posisi'] = $d['posisi'];


        foreach ($cols as $c) {
            if ($isBase) {
                $p[$c . '_base'] = (float)$d[$c];
            } else {
                $p[$c] = (float)$d[$c];
            }
        }


        if (!$isBase) {
            $p['is_active'] = 1;
        }
        unset($p);


        $inserted++;
    }
    saveGmmDb($dbFile, $db);


    jsonResponse(['ok' => true, 'message' => "GMM " . ($isBase ? 'Baseline' : 'Current') . " berhasil diproses. $inserted pegawai diupdate."]);
}


function handleGmmData(string $dbFile, ?array $currentUser = null): void
{
    $db = loadGmmDb($dbFile);
    if (empty($db['pegawai'])) {
        jsonResponse(['ok' => true, 'has_data' => false, 'message' => 'Database GMM belum tersedia.']);
        return;
    }


    $view = trim((string)($_GET['view'] ?? 'dashboard'));
    $kategori = strtoupper(trim((string)($_GET['kategori'] ?? 'LIVIN')));
    $filter = trim((string)($_GET['filter'] ?? 'ALL'));
    $search = trim((string)($_GET['search'] ?? ''));
    $page = max(1, (int)($_GET['p'] ?? 1));
    $pageSize = 50;


    $activePegawai = array_filter($db['pegawai'], fn($p) => ($p['is_active'] ?? 0) == 1);
    $totalPegawai = count($activePegawai);
    if ($totalPegawai === 0) {
        jsonResponse(['ok' => true, 'has_data' => false]);
        return;
    }


    $areaMapping = ['145' => 'DENPASAR', '161' => 'MATARAM', '175' => 'KUTA', '181' => 'KUPANG'];
    $areas = [];
    foreach ($activePegawai as $p) {
        if (!empty($p['area']) && !in_array($p['area'], $areas)) $areas[] = $p['area'];
    }
    sort($areas);


    $cabangList = [];
    foreach ($db['cabang'] as $kc => $c) {
        $cabangList[] = ['kode_cabang' => $kc, 'unit' => $c['unit'] ?? $kc];
    }
    usort($cabangList, fn($a, $b) => strcmp($a['unit'], $b['unit']));


    $mapPegawaiSearchRow = static fn(array $p): array => [
        'nip' => $p['nip'] ?? '',
        'nama' => $p['nama'] ?? '',
        'unit' => $p['unit'] ?? '',
        'kode_cabang' => $p['kode_cabang'] ?? '',
        'kelas_cabang' => $p['kelas_cabang'] ?? '',
        'posisi' => $p['posisi'] ?? '',
        'area' => $p['area'] ?? '',
        'end_balance' => $p['end_balance'] ?? 0,
        'cif_akuisisi' => $p['cif_akuisisi'] ?? 0,
        'cif_setor' => $p['cif_setor'] ?? 0,
        'rata_rata' => $p['rata_rata'] ?? 0,
        'cif_sudah_transaksi' => $p['cif_sudah_transaksi'] ?? 0,
        'frek_dari_cif_akuisisi' => $p['frek_dari_cif_akuisisi'] ?? 0,
        'total_referral_edc' => $p['total_referral_edc'] ?? 0,
        'total_referral_livin' => $p['total_referral_livin'] ?? 0,
        'pct_on_us' => $p['pct_on_us'] ?? 0,
        'total_poin_transaksi' => $p['total_poin_transaksi'] ?? 0,
        'poin_on_us' => $p['poin_on_us'] ?? 0,
        'poin_off_us' => $p['poin_off_us'] ?? 0,
        'frek_on_us' => $p['frek_on_us'] ?? 0,
        'frek_off_us' => $p['frek_off_us'] ?? 0,
    ];
    $buildCabangSearchRows = static function (array $pegawaiRows, array $cabangRows): array {
        $stats = [];
        foreach ($cabangRows as $kc => $c) {
            $stats[$kc] = [
                'kode_cabang' => $kc,
                'unit' => $c['unit'] ?? $kc,
                'area' => $c['area'] ?? '',
                'kelas_cabang' => $c['kelas_cabang'] ?? '',
                'jml' => 0,
                'sum_eb' => 0,
                'sum_ca' => 0,
                'sum_cs' => 0,
                'sum_rr' => 0,
                'sum_cst' => 0,
                'sum_fca' => 0,
                'sum_re' => 0,
                'sum_rl' => 0,
                'sum_tp' => 0,
                'sum_po' => 0,
                'sum_pf' => 0,
                'sum_fo' => 0,
                'sum_ff' => 0,
                'avg_rr' => 0,
                'pct_on_us' => 0,
            ];
        }
        foreach ($pegawaiRows as $p) {
            $kc = (string)($p['kode_cabang'] ?? '');
            if ($kc === '') {
                continue;
            }
            if (!isset($stats[$kc])) {
                $stats[$kc] = [
                    'kode_cabang' => $kc,
                    'unit' => $p['unit'] ?? $kc,
                    'area' => $p['area'] ?? '',
                    'kelas_cabang' => $p['kelas_cabang'] ?? '',
                    'jml' => 0,
                    'sum_eb' => 0,
                    'sum_ca' => 0,
                    'sum_cs' => 0,
                    'sum_rr' => 0,
                    'sum_cst' => 0,
                    'sum_fca' => 0,
                    'sum_re' => 0,
                    'sum_rl' => 0,
                    'sum_tp' => 0,
                    'sum_po' => 0,
                    'sum_pf' => 0,
                    'sum_fo' => 0,
                    'sum_ff' => 0,
                    'avg_rr' => 0,
                    'pct_on_us' => 0,
                ];
            }
            $stats[$kc]['jml']++;
            $stats[$kc]['sum_eb'] += $p['end_balance'] ?? 0;
            $stats[$kc]['sum_ca'] += $p['cif_akuisisi'] ?? 0;
            $stats[$kc]['sum_cs'] += $p['cif_setor'] ?? 0;
            $stats[$kc]['sum_rr'] += $p['rata_rata'] ?? 0;
            $stats[$kc]['sum_cst'] += $p['cif_sudah_transaksi'] ?? 0;
            $stats[$kc]['sum_fca'] += $p['frek_dari_cif_akuisisi'] ?? 0;
            $stats[$kc]['sum_re'] += $p['total_referral_edc'] ?? 0;
            $stats[$kc]['sum_rl'] += $p['total_referral_livin'] ?? 0;
            $stats[$kc]['sum_tp'] += $p['total_poin_transaksi'] ?? 0;
            $stats[$kc]['sum_po'] += $p['poin_on_us'] ?? 0;
            $stats[$kc]['sum_pf'] += $p['poin_off_us'] ?? 0;
            $stats[$kc]['sum_fo'] += $p['frek_on_us'] ?? 0;
            $stats[$kc]['sum_ff'] += $p['frek_off_us'] ?? 0;
        }
        foreach ($stats as &$row) {
            $row['avg_rr'] = ($row['jml'] ?? 0) > 0 ? (($row['sum_rr'] ?? 0) / $row['jml']) : 0;
            $totalFreq = ($row['sum_fo'] ?? 0) + ($row['sum_ff'] ?? 0);
            $row['pct_on_us'] = $totalFreq > 0 ? (($row['sum_fo'] ?? 0) / $totalFreq) : 0;
        }
        unset($row);
        $list = array_values($stats);
        usort($list, fn($a, $b) => strcmp((string)($a['unit'] ?? ''), (string)($b['unit'] ?? '')));
        return $list;
    };

    if ($view === 'search') {
        $pegawaiResults = array_map($mapPegawaiSearchRow, array_values($activePegawai));
        $cabangResults = $buildCabangSearchRows($pegawaiResults, $db['cabang']);
        $positions = [];
        $kelasCabang = [];
        foreach ($pegawaiResults as $row) {
            $posisi = trim((string)($row['posisi'] ?? ''));
            if ($posisi !== '' && !in_array($posisi, $positions, true)) {
                $positions[] = $posisi;
            }
            $kelas = trim((string)($row['kelas_cabang'] ?? ''));
            if ($kelas !== '' && !in_array($kelas, $kelasCabang, true)) {
                $kelasCabang[] = $kelas;
            }
        }
        sort($positions, SORT_NATURAL | SORT_FLAG_CASE);
        sort($kelasCabang, SORT_NATURAL | SORT_FLAG_CASE);
        jsonResponse([
            'ok' => true,
            'has_data' => true,
            'view' => 'search',
            'pegawai_results' => $pegawaiResults,
            'cabang_results' => $cabangResults,
            'filters' => [
                'areas' => $areas,
                'positions' => $positions,
                'kelas_cabang' => $kelasCabang,
            ],
        ]);
        return;
    }


    // Allowed sort columns per category
    $allowedCols = [
        'LIVIN'      => ['end_balance', 'cif_akuisisi', 'cif_setor', 'rata_rata', 'cif_sudah_transaksi', 'frek_dari_cif_akuisisi'],
        'MERCHANT'   => ['total_referral_edc', 'total_referral_livin'],
        'TRANSAKSI'  => ['pct_on_us', 'total_poin_transaksi', 'frek_on_us', 'frek_off_us', 'poin_on_us', 'poin_off_us'],
    ];
    $requestedSort = trim((string)($_GET['sort_col'] ?? ''));
    $requestedSortDir = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc')));
    if (!in_array($requestedSortDir, ['asc', 'desc'], true)) {
        $requestedSortDir = 'desc';
    }
    $defaultSortCols = [
        'LIVIN' => 'end_balance',
        'MERCHANT' => 'total_referral_edc',
        'TRANSAKSI' => 'pct_on_us',
    ];
    $dashboardSorts = [];
    foreach ($defaultSortCols as $katKey => $defaultCol) {
        $queryKey = 'dash_sort_' . strtolower($katKey);
        $dashSort = trim((string)($_GET[$queryKey] ?? ''));
        $dashboardSorts[$katKey] = in_array($dashSort, $allowedCols[$katKey] ?? [], true) ? $dashSort : $defaultCol;
    }
    $resolveCabangScore = static function (array $stats, string $sortCol): float|int {
        return match ($sortCol) {
            'end_balance' => $stats['sum_eb'] ?? 0,
            'cif_akuisisi' => $stats['sum_ca'] ?? 0,
            'cif_setor' => $stats['sum_cs'] ?? 0,
            'rata_rata' => $stats['avg_rr'] ?? 0,
            'cif_sudah_transaksi' => $stats['sum_cst'] ?? 0,
            'frek_dari_cif_akuisisi' => $stats['sum_fca'] ?? 0,
            'total_referral_edc' => $stats['sum_re'] ?? 0,
            'total_referral_livin' => $stats['sum_rl'] ?? 0,
            'pct_on_us' => $stats['pct_on_us'] ?? 0,
            'total_poin_transaksi' => $stats['sum_tp'] ?? 0,
            'frek_on_us' => $stats['sum_fo'] ?? 0,
            'frek_off_us' => $stats['sum_ff'] ?? 0,
            'poin_on_us' => $stats['sum_po'] ?? 0,
            'poin_off_us' => $stats['sum_pf'] ?? 0,
            default => 0,
        };
    };

    $scoreCol = 'end_balance';
    $secCol = 'cif_akuisisi';
    $scoreBaseCol = 'end_balance_base';
    $secBaseCol = 'cif_akuisisi_base';
    if ($kategori === 'MERCHANT') {
        $scoreCol = 'total_referral_edc';
        $secCol = 'total_referral_livin';
        $scoreBaseCol = 'total_referral_edc_base';
        $secBaseCol = 'total_referral_livin_base';
    } elseif ($kategori === 'TRANSAKSI') {
        $scoreCol = 'pct_on_us';
        $secCol = 'total_poin_transaksi';
        $scoreBaseCol = 'pct_on_us_base';
        $secBaseCol = 'total_poin_transaksi_base';
    }
    // Override sort if explicitly requested and allowed
    if ($requestedSort !== '' && in_array($requestedSort, $allowedCols[$kategori] ?? [], true)) {
        $scoreCol = $requestedSort;
    }


    $userBranchId = $currentUser ? trim((string)($currentUser['branch_id'] ?? '')) : '';
    $branchLen = strlen($userBranchId);


    if ($view === 'dashboard') {
        if ($branchLen > 3) {
            $branchPegawai = array_filter($activePegawai, fn($p) => ($p['kode_cabang'] ?? '') === $userBranchId);
            $listOut = array_map(fn($p) => [
                'nip' => $p['nip'],
                'nama' => $p['nama'],
                'cif_akuisisi' => $p['cif_akuisisi'] ?? 0,
                'end_balance' => $p['end_balance'] ?? 0,
                'referral_edc' => $p['total_referral_edc'] ?? 0,
                'referral_lvm' => $p['total_referral_livin'] ?? 0,
                'pct_on_us' => $p['pct_on_us'] ?? 0,
                'total_poin' => $p['total_poin_transaksi'] ?? 0,
            ], $branchPegawai);
            usort($listOut, fn($a, $b) => $b['end_balance'] <=> $a['end_balance']);
            jsonResponse(['ok' => true, 'has_data' => true, 'view' => 'dashboard', 'dashboard_type' => 'branch', 'data' => array_values($listOut)]);
            return;
        }


        if ($branchLen === 3) {
            $activePegawai = array_filter($activePegawai, fn($p) => ($p['area'] ?? '') === $userBranchId || str_starts_with((string)($p['kode_cabang'] ?? ''), $userBranchId));
        }


        $result = [];
        foreach (['LIVIN', 'MERCHANT', 'TRANSAKSI'] as $kat) {
            $sc = $dashboardSorts[$kat] ?? ($defaultSortCols[$kat] ?? 'end_balance');
            $pList = $activePegawai;
            usort($pList, fn($a, $b) => ($b[$sc] ?? 0) <=> ($a[$sc] ?? 0));
            $topP = array_slice($pList, 0, 10);
            $pListOut = array_map(fn($p) => [
                'nip' => $p['nip'],
                'nama' => $p['nama'],
                'unit' => $p['unit'],
                'area' => $p['area'],
                'score' => $p[$sc] ?? 0,
            ], $topP);


            $cabangStats = [];
            foreach ($db['cabang'] as $kc => $c) {
                $cabangStats[$kc] = [
                    'kode_cabang' => $kc,
                    'unit' => $c['unit'] ?? $kc,
                    'area' => $c['area'] ?? '',
                    'jml' => 0,
                    'sum_eb' => 0,
                    'sum_ca' => 0,
                    'sum_cs' => 0,
                    'sum_rr' => 0,
                    'sum_cst' => 0,
                    'sum_fca' => 0,
                    'sum_re' => 0,
                    'sum_rl' => 0,
                    'sum_tp' => 0,
                    'sum_fo' => 0,
                    'sum_ff' => 0,
                    'sum_po' => 0,
                    'sum_pf' => 0,
                    'avg_rr' => 0,
                    'pct_on_us' => 0,
                    'score' => 0,
                ];
            }
            foreach ($activePegawai as $p) {
                $kc = $p['kode_cabang'];
                if (isset($cabangStats[$kc])) {
                    $cabangStats[$kc]['jml']++;
                    $cabangStats[$kc]['sum_eb'] += ($p['end_balance'] ?? 0);
                    $cabangStats[$kc]['sum_ca'] += ($p['cif_akuisisi'] ?? 0);
                    $cabangStats[$kc]['sum_cs'] += ($p['cif_setor'] ?? 0);
                    $cabangStats[$kc]['sum_rr'] += ($p['rata_rata'] ?? 0);
                    $cabangStats[$kc]['sum_cst'] += ($p['cif_sudah_transaksi'] ?? 0);
                    $cabangStats[$kc]['sum_fca'] += ($p['frek_dari_cif_akuisisi'] ?? 0);
                    $cabangStats[$kc]['sum_re'] += ($p['total_referral_edc'] ?? 0);
                    $cabangStats[$kc]['sum_rl'] += ($p['total_referral_livin'] ?? 0);
                    $cabangStats[$kc]['sum_tp'] += ($p['total_poin_transaksi'] ?? 0);
                    $cabangStats[$kc]['sum_fo'] += ($p['frek_on_us'] ?? 0);
                    $cabangStats[$kc]['sum_ff'] += ($p['frek_off_us'] ?? 0);
                    $cabangStats[$kc]['sum_po'] += ($p['poin_on_us'] ?? 0);
                    $cabangStats[$kc]['sum_pf'] += ($p['poin_off_us'] ?? 0);
                }
            }
            foreach ($cabangStats as &$cs) {
                $cs['avg_rr'] = ($cs['jml'] ?? 0) > 0 ? (($cs['sum_rr'] ?? 0) / $cs['jml']) : 0;
                $totalFreq = ($cs['sum_fo'] ?? 0) + ($cs['sum_ff'] ?? 0);
                $cs['pct_on_us'] = $totalFreq > 0 ? (($cs['sum_fo'] ?? 0) / $totalFreq) : 0;
                $cs['score'] = $resolveCabangScore($cs, $sc);
            }
            unset($cs);
            $cList = array_values($cabangStats);
            usort($cList, fn($a, $b) => $b['score'] <=> $a['score']);
            $topC = array_slice($cList, 0, 10);
            $cListOut = array_map(fn($c) => ['kode_cabang' => $c['kode_cabang'], 'unit' => $c['unit'], 'area' => $c['area'], 'score' => $c['score']], $topC);


            $result[$kat] = ['pegawai' => $pListOut, 'cabang' => $cListOut];
        }
        jsonResponse(['ok' => true, 'has_data' => true, 'view' => 'dashboard', 'data' => $result, 'areas' => $areas, 'cabang_list' => $cabangList, 'area_mapping' => $areaMapping]);
        return;
    }


    $filteredPegawai = $activePegawai;
    if ($filter !== 'ALL' && $filter !== '') {
        if (strlen($filter) <= 3) {
            $filteredPegawai = array_filter($filteredPegawai, fn($p) => ($p['area'] ?? '') === $filter);
        } else {
            $filteredPegawai = array_filter($filteredPegawai, fn($p) => ($p['kode_cabang'] ?? '') === $filter);
        }
    }


    if ($view === 'pegawai' || $view === 'detail_pegawai') {
        if ($view === 'detail_pegawai') {
            $nip = trim((string)($_GET['nip'] ?? ''));
            jsonResponse(['ok' => true, 'has_data' => true, 'view' => 'detail_pegawai', 'pegawai' => $db['pegawai'][$nip] ?? null]);
            return;
        }


        usort($filteredPegawai, function ($a, $b) use ($scoreCol, $requestedSortDir) {
            $va = $a[$scoreCol] ?? 0;
            $vb = $b[$scoreCol] ?? 0;
            return $requestedSortDir === 'asc' ? ($va <=> $vb) : ($vb <=> $va);
        });
        $total = count($filteredPegawai);
        $offset = ($page - 1) * $pageSize;
        $pageData = array_slice($filteredPegawai, $offset, $pageSize);
        $list = array_map(fn($p) => [
            'nip' => $p['nip'],
            'nama' => $p['nama'],
            'unit' => $p['unit'],
            'area' => $p['area'],
            'posisi' => $p['posisi'],
            'kode_cabang' => $p['kode_cabang'],
            'score' => $p[$scoreCol] ?? 0,
            'score_base' => $p[$scoreBaseCol] ?? 0,
            'sec_score' => $p[$secCol] ?? 0,
            'sec_base' => $p[$secBaseCol] ?? 0,
            // All metric columns for multi-sort display
            'end_balance' => $p['end_balance'] ?? 0,
            'cif_akuisisi' => $p['cif_akuisisi'] ?? 0,
            'cif_setor' => $p['cif_setor'] ?? 0,
            'rata_rata' => $p['rata_rata'] ?? 0,
            'cif_sudah_transaksi' => $p['cif_sudah_transaksi'] ?? 0,
            'frek_dari_cif_akuisisi' => $p['frek_dari_cif_akuisisi'] ?? 0,
            'total_referral_edc' => $p['total_referral_edc'] ?? 0,
            'total_referral_livin' => $p['total_referral_livin'] ?? 0,
            'pct_on_us' => $p['pct_on_us'] ?? 0,
            'total_poin_transaksi' => $p['total_poin_transaksi'] ?? 0,
            'poin_on_us' => $p['poin_on_us'] ?? 0,
            'poin_off_us' => $p['poin_off_us'] ?? 0,
            'frek_on_us' => $p['frek_on_us'] ?? 0,
            'frek_off_us' => $p['frek_off_us'] ?? 0,
        ], $pageData);
        jsonResponse(['ok' => true, 'has_data' => true, 'view' => 'pegawai', 'list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $pageSize, 'kategori' => $kategori, 'sort_col' => $scoreCol, 'sort_dir' => $requestedSortDir]);
        return;
    }


    if ($view === 'cabang' || $view === 'detail_cabang') {
        if ($view === 'detail_cabang') {
            $kc = trim((string)($_GET['kode'] ?? ''));
            $cRow = $db['cabang'][$kc] ?? null;
            if ($cRow) {
                $cRow['jml'] = 0;
                $sumCols = ['end_balance', 'end_balance_base', 'cif_akuisisi', 'total_referral_edc', 'total_referral_livin', 'total_poin_transaksi', 'frek_on_us', 'frek_off_us', 'poin_on_us', 'poin_off_us'];
                foreach ($sumCols as $sc) {
                    $cRow['sum_' . (str_replace(['end_balance_base', 'end_balance', 'cif_akuisisi', 'total_referral_edc', 'total_referral_livin', 'total_poin_transaksi', 'frek_on_us', 'frek_off_us', 'poin_on_us', 'poin_off_us'], ['eb_b', 'eb', 'ca', 're', 'rl', 'tp', 'fo', 'ff', 'po', 'pf'], $sc))] = 0;
                }
                foreach ($activePegawai as $p) {
                    if ($p['kode_cabang'] === $kc) {
                        $cRow['jml']++;
                        $cRow['sum_eb'] += ($p['end_balance'] ?? 0);
                        $cRow['sum_eb_b'] += ($p['end_balance_base'] ?? 0);
                        $cRow['sum_ca'] += ($p['cif_akuisisi'] ?? 0);
                        $cRow['sum_re'] += ($p['total_referral_edc'] ?? 0);
                        $cRow['sum_rl'] += ($p['total_referral_livin'] ?? 0);
                        $cRow['sum_tp'] += ($p['total_poin_transaksi'] ?? 0);
                        $cRow['sum_fo'] += ($p['frek_on_us'] ?? 0);
                        $cRow['sum_ff'] += ($p['frek_off_us'] ?? 0);
                        $cRow['sum_po'] += ($p['poin_on_us'] ?? 0);
                        $cRow['sum_pf'] += ($p['poin_off_us'] ?? 0);
                    }
                }
                jsonResponse(['ok' => true, 'has_data' => true, 'view' => 'detail_cabang', 'cabang' => $cRow]);
            } else {
                jsonResponse(['ok' => true, 'has_data' => true, 'view' => 'detail_cabang', 'cabang' => null]);
            }
            return;
        }


        $cabangStats = [];
        foreach ($db['cabang'] as $kc => $c) {
            $cabangStats[$kc] = [
                'kode_cabang' => $kc,
                'unit' => $c['unit'] ?? $kc,
                'area' => $c['area'] ?? '',
                'kelas_cabang' => $c['kelas_cabang'] ?? '',
                'jml' => 0,
                'sum_eb' => 0,
                'sum_ca' => 0,
                'sum_cs' => 0,
                'sum_rr' => 0,
                'sum_cst' => 0,
                'sum_fca' => 0,
                'sum_fo' => 0,
                'sum_ff' => 0,
                'sum_re' => 0,
                'sum_rl' => 0,
                'sum_tp' => 0,
                'sum_po' => 0,
                'sum_pf' => 0,
                'avg_rr' => 0,
                'pct_on_us' => 0,
                'score' => 0,
            ];
        }
        foreach ($activePegawai as $p) {
            $kc = $p['kode_cabang'];
            if (isset($cabangStats[$kc])) {
                $cabangStats[$kc]['jml']++;
                $cabangStats[$kc]['sum_eb'] += ($p['end_balance'] ?? 0);
                $cabangStats[$kc]['sum_ca'] += ($p['cif_akuisisi'] ?? 0);
                $cabangStats[$kc]['sum_cs'] += ($p['cif_setor'] ?? 0);
                $cabangStats[$kc]['sum_rr'] += ($p['rata_rata'] ?? 0);
                $cabangStats[$kc]['sum_cst'] += ($p['cif_sudah_transaksi'] ?? 0);
                $cabangStats[$kc]['sum_fca'] += ($p['frek_dari_cif_akuisisi'] ?? 0);
                $cabangStats[$kc]['sum_re'] += ($p['total_referral_edc'] ?? 0);
                $cabangStats[$kc]['sum_rl'] += ($p['total_referral_livin'] ?? 0);
                $cabangStats[$kc]['sum_tp'] += ($p['total_poin_transaksi'] ?? 0);
                $cabangStats[$kc]['sum_fo'] += ($p['frek_on_us'] ?? 0);
                $cabangStats[$kc]['sum_ff'] += ($p['frek_off_us'] ?? 0);
                $cabangStats[$kc]['sum_po'] += ($p['poin_on_us'] ?? 0);
                $cabangStats[$kc]['sum_pf'] += ($p['poin_off_us'] ?? 0);
            }
        }
        foreach ($cabangStats as &$cs) {
            $cs['avg_rr'] = ($cs['jml'] ?? 0) > 0 ? (($cs['sum_rr'] ?? 0) / $cs['jml']) : 0;
            $tf = ($cs['sum_fo'] ?? 0) + ($cs['sum_ff'] ?? 0);
            $cs['pct_on_us'] = $tf > 0 ? (($cs['sum_fo'] ?? 0) / $tf) : 0;
            $activeCabangSort = $requestedSort !== '' ? $requestedSort : ($defaultSortCols[$kategori] ?? 'end_balance');
            $cs['score'] = $resolveCabangScore($cs, $activeCabangSort);
        }
        unset($cs);
        $list = array_values($cabangStats);
        usort($list, function ($a, $b) use ($requestedSortDir) {
            return $requestedSortDir === 'asc'
                ? (($a['score'] ?? 0) <=> ($b['score'] ?? 0))
                : (($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        });
        jsonResponse(['ok' => true, 'has_data' => true, 'view' => 'cabang', 'list' => $list, 'kategori' => $kategori, 'sort_col' => $scoreCol, 'sort_dir' => $requestedSortDir]);
        return;
    }


    jsonResponse(['ok' => true, 'has_data' => true, 'view' => $view]);
}


// ======================== END GMM BACKEND ========================


function jsonResponse(array $payload, int $statusCode = 200): void
{
    if (ob_get_length()) ob_clean();
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


$flash = consumeFlash();
$isAuthenticated = $currentUser !== null;
$currentPage = (string)($_GET['page'] ?? '');
$isAdminPage = $isAuthenticated && isAdmin($currentUser) && $currentPage === 'admin';
$isGmmPage = $isAuthenticated && $currentPage === 'gmm';
$isHubPage = $isAuthenticated && ($currentPage === '' || $currentPage === 'hub');
$isDashboardPage = $isAuthenticated && $currentPage === 'dashboard';
$adminUsers = [];
$adminActivitySummary = [];
$adminEvents = [];
$adminSummaryIndex = [];
$adminUserIndex = [];
$adminMonitoringInsights = [
    'cohort_label' => 'Branch Manager',
    'cohort_count' => 0,
    'active_today_count' => 0,
    'total_login_today' => 0,
    'total_view_today' => 0,
    'avg_view_per_user' => 0,
    'peak_hour_label' => '-',
    'peak_hour_count' => 0,
    'most_active_manager' => null,
    'inactive_over_7_days' => [],
    'top_managers' => [],
    'charts' => [
        'hourly_categories' => [],
        'hourly_logins' => [],
        'daily_categories' => [],
        'daily_logins' => [],
        'daily_views' => [],
        'event_mix_labels' => [],
        'event_mix_series' => [],
    ],
];
$editingUser = null;
$cachedDashboard = loadCache($danaCacheFile);
$cachedSourceLabel = $cachedDashboard['source_file'] ?? 'Belum ada cache';
$cachedGeneratedLabel = isset($cachedDashboard['generated_at']) ? date('d M Y H:i', strtotime((string) $cachedDashboard['generated_at'])) : 'Upload workbook .xlsx untuk mulai.';
$updateDates = loadJsonFile($updateDatesFile, ['produk_dana' => '', 'produk_kredit' => '', 'gmm' => '']);


if ($isAdminPage) {
    $adminUsers = array_values(loadUserStore($usersFile)['users']);
    usort($adminUsers, static fn(array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
    foreach ($adminUsers as $userRow) {
        $adminUserIndex[(string) ($userRow['id'] ?? '')] = $userRow;
    }


    $activityStore = loadActivityStore($activityFile);
    $adminActivitySummary = array_values($activityStore['summary']);
    usort($adminActivitySummary, static fn(array $a, array $b): int => strcmp((string) ($b['last_view'] ?? $b['last_login'] ?? ''), (string) ($a['last_view'] ?? $a['last_login'] ?? '')));
    foreach ($adminActivitySummary as $summaryRow) {
        $adminSummaryIndex[(string) ($summaryRow['user_id'] ?? '')] = $summaryRow;
    }
    $adminEvents = array_slice(array_reverse($activityStore['events']), 0, 80);
    $adminMonitoringInsights = buildAdminMonitoringInsights($adminUsers, $adminActivitySummary, $activityStore['events']);


    $editingId = trim((string) ($_GET['edit_user'] ?? ''));
    foreach ($adminUsers as $userRow) {
        if ((string) ($userRow['id'] ?? '') === $editingId) {
            $editingUser = $userRow;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">


<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Cabang Region XI</title>
    <link rel="icon" type="image/png" href="https://regionsebelas.com/assets/GPTIcon.png">


    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Rajdhani:wght@600;700&display=swap" rel="stylesheet">


    <style>
        :root {
            --f1-red: #E10600;
            --f1-dark: #15151e;
            --ink: #1f2937;
            --muted: #6b7280;
            --line: rgba(229, 231, 235, 0.5);
            --soft: #f5f6f8;
            --blue: #2563eb;
            --green: #16a34a;
        }


        body {
            background:
                radial-gradient(circle at top right, rgba(225, 6, 0, 0.05), transparent 40%),
                linear-gradient(135deg, #f8f9fa 0%, #eef2f6 100%);
            color: var(--ink);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }


        h1,
        h2,
        h3,
        h4,
        h5,
        h6,
        .rajdhani {
            font-family: 'Rajdhani', sans-serif;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }


        /* Glassmorphism Styles */
        .top-shell {
            background: rgba(255, 255, 255, 0.65);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.03);
            position: sticky;
            top: 0;
            z-index: 1020;
        }


        .panel {
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.05);
            transition: all 0.3s ease;
        }


        .panel-tight {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }


        .mini-stat {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(229, 231, 235, 0.6);
            border-radius: 14px;
            padding: 16px 18px;
            min-height: 90px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .mini-stat:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(225, 6, 0, 0.06);
            border-color: rgba(225, 6, 0, 0.15);
        }

        /* Login page layout */
        .login-body {
            display: flex;
            align-items: center;
            min-height: 100vh;
        }

        .login-wrap {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .login-card {
            max-width: 1100px;
            margin: 0 auto;
            width: 100%;
        }

        /* Monthly Summary Tables */
        #monthlySummaryTables .table-daily th:first-child,
        #monthlySummaryTables .table-daily td:first-child {
            text-align: left;
            min-width: 90px;
        }

        #monthlySummaryTables .table-daily td,
        #monthlySummaryTables .table-daily th {
            min-width: 52px;
            padding: 8px 5px;
        }

        #monthlySummaryTables .panel {
            overflow: hidden;
        }

        .mini-stat .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 6px;
            flex-shrink: 0;
        }

        .mini-stat .stat-icon.blue {
            background: #dbeafe;
            color: #2563eb;
        }

        .mini-stat .stat-icon.green {
            background: #dcfce7;
            color: #16a34a;
        }

        .mini-stat .stat-icon.red {
            background: #fee2e2;
            color: #dc2626;
        }

        .mini-stat .stat-icon.purple {
            background: #f3e8ff;
            color: #7c3aed;
        }

        .mini-stat .stat-icon.amber {
            background: #fef3c7;
            color: #d97706;
        }

        .mini-stat .stat-icon.slate {
            background: #f1f5f9;
            color: #475569;
        }

        /* sidebar scope card */
        .scope-card {
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(229, 231, 235, 0.6);
            border-radius: 14px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .scope-card .scope-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: linear-gradient(135deg, #fee2e2, #fecdd3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }


        /* Elements */
        .brand-mark {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--f1-dark), #2a2a35);
            color: #fff;
            box-shadow: 0 8px 20px rgba(21, 21, 30, 0.2);
        }


        .redline {
            height: 4px;
            width: 80px;
            border-radius: 99px;
            background: var(--f1-red);
            box-shadow: 0 2px 8px rgba(225, 6, 0, 0.4);
        }


        /* Buttons & Navs */
        .nav-pills {
            gap: 8px;
        }


        .nav-pills .nav-link {
            color: #64748b;
            font-weight: 700;
            border-radius: 10px;
            padding: 10px 20px;
            transition: all 0.2s;
        }


        .nav-pills .nav-link.active {
            background: var(--f1-red);
            color: #fff;
            box-shadow: 0 6px 15px rgba(225, 6, 0, 0.25);
        }


        .btn-f1 {
            background: linear-gradient(135deg, var(--f1-red), #b90500);
            border: none;
            color: #fff;
            border-radius: 10px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(225, 6, 0, 0.2);
            transition: transform 0.1s, box-shadow 0.2s;
        }


        .btn-f1:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(225, 6, 0, 0.3);
            color: #fff;
        }


        .btn-ghost {
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(200, 200, 200, 0.5);
            color: #334155;
            border-radius: 10px;
            font-weight: 700;
        }


        .btn-ghost:hover {
            background: rgba(255, 255, 255, 0.9);
        }


        @keyframes gmmFadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }


            to {
                opacity: 1;
                transform: translateY(0);
            }
        }


        .gmm-fade-in {
            animation: gmmFadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }


        /* Forms */
        .form-control,
        .form-select {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(200, 200, 210, 0.6);
            border-radius: 10px;
            font-weight: 600;
            backdrop-filter: blur(4px);
        }


        .form-control:focus,
        .form-select:focus {
            background: #fff;
            border-color: var(--f1-red);
            box-shadow: 0 0 0 4px rgba(225, 6, 0, 0.1);
        }


        .form-label {
            color: #475569;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }


        /* Custom Pill Checkboxes (Kredit Tab) */
        .pill-check-label {
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid rgba(200, 200, 200, 0.6);
            color: #475569;
            border-radius: 999px;
            padding: 6px 16px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            display: inline-block;
        }


        .btn-check:checked+.pill-check-label {
            background: var(--f1-dark);
            color: #fff;
            border-color: var(--f1-dark);
            box-shadow: 0 4px 10px rgba(21, 21, 30, 0.2);
        }


        .btn-check:focus+.pill-check-label {
            box-shadow: 0 0 0 0.25rem rgba(21, 21, 30, 0.25);
        }


        /* Typography & Utilities */
        .mini-stat .value {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.65rem;
            font-weight: 700;
            color: var(--f1-dark);
            line-height: 1.15;
        }

        .mini-stat .value.red {
            color: #dc2626;
        }

        .mini-stat .value.green {
            color: #16a34a;
        }

        .mini-stat .label {
            color: #64748b;
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .mini-stat .sub {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 2px;
            font-weight: 500;
        }


        .delta-up {
            color: #15803d !important;
        }


        .delta-down {
            color: #dc2626 !important;
        }


        .delta-flat {
            color: #64748b !important;
        }


        .group-pills {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 8px;
        }


        .group-pill {
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid rgba(200, 200, 200, 0.5);
            backdrop-filter: blur(5px);
            color: #475569;
            border-radius: 999px;
            padding: 8px 18px;
            font-weight: 700;
            white-space: nowrap;
            transition: all 0.2s;
        }


        .group-pill.active {
            background: var(--f1-dark);
            color: #fff;
            border-color: var(--f1-dark);
            box-shadow: 0 4px 12px rgba(21, 21, 30, 0.2);
        }


        /* Tables */
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.6);
        }


        .table {
            margin-bottom: 0;
            --bs-table-bg: transparent;
        }


        .table-daily {
            font-size: 0.7rem;
            margin-bottom: 0;
            white-space: nowrap;
        }


        .table-daily th {
            background: rgba(21, 21, 30, 0.95) !important;
            color: #fff !important;
            font-weight: 600;
            text-align: center;
            border-bottom: none;
            padding: 12px 8px;
        }


        .table-daily td {
            border-color: rgba(200, 200, 210, 0.3);
            color: #334155;
            padding: 10px 8px;
            text-align: center;
            vertical-align: middle;
            font-weight: 500;
        }


        .table-daily th:first-child,
        .table-daily td:first-child {
            left: 0;
            position: sticky;
            z-index: 2;
        }


        .table-daily td:first-child {
            background: rgba(255, 255, 255, 0.95) !important;
            color: var(--f1-dark);
            font-weight: 700;
            text-align: left;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.03);
            border-right: 1px solid rgba(200, 200, 210, 0.3);
        }


        .cell-bottom {
            background-color: rgba(225, 6, 0, 0.08) !important;
            color: #b90500 !important;
            font-weight: 800;
        }


        .cell-empty {
            color: #cbd5e1 !important;
        }


        /* Custom UI Elements */
        .user-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 700;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }


        .status-strip {
            border-left: 4px solid var(--f1-red);
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.04);
        }


        .upload-zone {
            border: 2px dashed rgba(225, 6, 0, 0.3);
            background: rgba(225, 6, 0, 0.02);
            border-radius: 12px;
            transition: background 0.2s;
        }


        .upload-zone:hover {
            background: rgba(225, 6, 0, 0.04);
        }


        .loader-backdrop {
            align-items: center;
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(8px);
            display: none;
            inset: 0;
            justify-content: center;
            position: fixed;
            z-index: 2000;
        }


        .loader-backdrop.show {
            display: flex;
        }


        .chart-shell {
            min-height: 410px;
            position: relative;
        }


        .empty-state {
            align-items: center;
            color: #64748b;
            display: flex;
            flex-direction: column;
            gap: 8px;
            justify-content: center;
            min-height: 280px;
            text-align: center;
        }

        /* Calendar view styles */
        .cal-holiday {
            background: rgba(254, 226, 226, 0.7) !important;
        }

        .cal-weekend {
            background: rgba(254, 226, 226, 0.4) !important;
        }

        .cal-bottom {
            background: rgba(225, 6, 0, 0.1) !important;
            border: 1.5px solid rgba(225, 6, 0, 0.4) !important;
        }

        .cal-end {
            background: rgba(22, 163, 74, 0.1) !important;
            border: 1.5px solid rgba(22, 163, 74, 0.35) !important;
        }


        @media (max-width: 767px) {
            .nav-pills .nav-link {
                padding: 10px 12px;
            }


            .top-actions {
                justify-content: stretch;
                flex-wrap: wrap;
            }


            .top-actions>* {
                flex: 1 1 auto;
            }
        }

        /* Admin detail row toggle */
        .detail-row-content {
            animation: slideDown 0.2s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Activity filter bar */
        #actFilterFrom,
        #actFilterTo,
        #actFilterUser,
        #actFilterEvent {
            font-size: 0.78rem;
        }

        .insight-panel {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at top right, rgba(225, 6, 0, 0.1), transparent 34%),
                linear-gradient(135deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.94));
        }

        .insight-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .insight-card {
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.88);
            padding: 16px 18px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
        }

        .insight-card .kicker {
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 8px;
        }

        .insight-card .headline {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.55rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1;
            margin-bottom: 8px;
        }

        .insight-card .caption {
            color: #475569;
            font-size: 0.84rem;
            line-height: 1.45;
        }

        .chart-panel {
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.9);
            padding: 16px;
            height: 100%;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }

        .chart-panel .chart-title {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 2px;
        }

        .chart-panel .chart-subtitle {
            color: #64748b;
            font-size: 0.8rem;
            margin-bottom: 10px;
        }

        .chart-target {
            min-height: 260px;
        }

        .admin-table-wrap {
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 20px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.05);
        }

        /* Admin table compact */
        .admin-table td,
        .admin-table th,
        #viewerSummaryTable td,
        #viewerSummaryTable th {
            vertical-align: middle;
            font-size: 0.82rem;
        }

        .admin-table thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.9);
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.98), rgba(241, 245, 249, 0.96));
            color: #334155;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.72rem;
        }

        #viewerSummaryTable thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.9);
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.98), rgba(241, 245, 249, 0.96));
            color: #334155;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.72rem;
        }

        .admin-table tbody tr {
            border-color: rgba(226, 232, 240, 0.7);
        }

        #viewerSummaryTable tbody tr {
            border-color: rgba(226, 232, 240, 0.7);
        }

        .admin-table tbody tr:hover {
            background: rgba(248, 250, 252, 0.9);
        }

        #viewerSummaryTable tbody tr:hover {
            background: rgba(248, 250, 252, 0.9);
        }

        .metric-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(225, 6, 0, 0.08);
            color: #b91c1c;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .soft-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.06);
            color: #334155;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .score-bar {
            position: relative;
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: rgba(226, 232, 240, 0.9);
            overflow: hidden;
        }

        .score-bar>span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #E10600, #fb7185);
        }

        .attention-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .attention-item {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 14px;
            background: rgba(248, 250, 252, 0.95);
            border: 1px solid rgba(226, 232, 240, 0.9);
        }

        .attention-item .name {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.84rem;
        }

        .attention-item .meta {
            color: #64748b;
            font-size: 0.74rem;
        }

        .cursor-pointer {
            cursor: pointer;
        }

        .cursor-pointer:hover {
            background: rgba(225, 6, 0, .04) !important;
        }

        /* Kredit sidebar pill labels */
        .kr-pill-label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            color: #475569;
            border: 1px solid rgba(229, 231, 235, .5);
            background: rgba(255, 255, 255, .5);
            transition: all 0.15s;
            user-select: none;
        }

        .kr-pill-label:hover {
            background: rgba(225, 6, 0, .04);
            border-color: rgba(225, 6, 0, .2);
            color: #E10600;
        }

        .kr-pill-label input {
            accent-color: #E10600;
            flex-shrink: 0;
            width: 14px;
            height: 14px;
        }

        /* Highlight checked product pills */
        .kr-pill-label:has(input.kr-prod:checked),
        .kr-pill-label:has(input.kr-mode:checked),
        .kr-pill-label:has(input.kr-view:checked) {
            background: rgba(225, 6, 0, .07);
            border-color: rgba(225, 6, 0, .3);
            color: #be123c;
            font-weight: 700;
        }

        /* Mobile responsive GMM */
        @media (max-width: 576px) {
            #appGmm h4.rajdhani {
                font-size: 1.1rem;
            }

            #appGmm h5.rajdhani {
                font-size: 1rem;
            }

            .group-pill {
                padding: 5px 12px !important;
                font-size: .78rem !important;
            }

            #gmmTabs .nav-link {
                padding: 6px 10px !important;
                font-size: .78rem !important;
            }
        }

        /* GMM Enhanced Styles */
        #gmmTabs .nav-link {
            color: #64748b;
            font-weight: 700;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 0.85rem;
            transition: all 0.2s;
            border: none;
            background: none;
        }

        #gmmTabs .nav-link.active {
            background: var(--f1-red);
            color: #fff;
            box-shadow: 0 4px 12px rgba(225, 6, 0, 0.25);
        }

        #gmmTabs .nav-link:hover:not(.active) {
            background: rgba(225, 6, 0, 0.06);
            color: var(--f1-red);
        }

        .gmm-rank-card {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(229, 231, 235, 0.6);
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(8px);
            transition: all 0.2s ease;
        }

        .gmm-rank-card:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        }
    </style>
</head>


<body class="<?= $isAuthenticated ? '' : 'login-body' ?>">
    <div class="loader-backdrop" id="loader">
        <div class="d-flex align-items-center gap-3 panel-tight px-4 py-3 shadow">
            <div class="spinner-border text-danger" role="status" aria-hidden="true"></div>
            <strong id="loaderText" class="text-dark">Memproses data...</strong>
        </div>
    </div>


    <?php if (!$isAuthenticated): ?>
        <div class="login-wrap d-flex align-items-center px-3 px-md-5 py-4">
            <div class="login-card w-100">
                <div class="row g-4 align-items-stretch">
                    <div class="col-lg-6">
                        <img src="https://regionsebelas.com/assets/GPT4.png" alt="Deskripsi Gambar" class="img-fluid">
                        <!-- <img src="GPT4.png" alt="Deskripsi Gambar" class="img-fluid"> -->
                    </div>
                    <div class="col-lg-6">
                        <div class="panel p-4 p-md-5 h-100 position-relative">
                            <?php if ($flash !== null): ?>
                                <div class="alert alert-<?= e($flash['tone'] === 'danger' ? 'danger' : ($flash['tone'] === 'success' ? 'success' : 'warning')) ?>"><?= e($flash['message']) ?></div>
                            <?php endif; ?>


                            <ul class="nav nav-pills mb-4" id="authTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="login-tab" data-bs-toggle="pill" data-bs-target="#login-panel" type="button" role="tab">Masuk</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="signup-tab" data-bs-toggle="pill" data-bs-target="#signup-panel" type="button" role="tab">Daftar Baru</button>
                                </li>
                            </ul>


                            <div class="tab-content">
                                <div class="tab-pane fade show active" id="login-panel" role="tabpanel">
                                    <div class="form-label mb-2">Halaman Login</div>
                                    <h3 class="rajdhani fw-bold mb-2">Masuk ke Dashboard</h3>
                                    <p class="text-secondary mb-4">Gunakan NIP yang telah terdaftar.</p>
                                    <form method="post" class="d-grid gap-3">
                                        <input type="hidden" name="form_action" value="login">
                                        <div>
                                            <label class="form-label" for="loginUserId">NIP</label>
                                            <input class="form-control form-control-lg" id="loginUserId" name="nip" placeholder="Masukkan NIP Anda" required>
                                        </div>
                                        <div>
                                            <label class="form-label" for="loginPassword">Password</label>
                                            <input class="form-control form-control-lg" id="loginPassword" name="password" type="password" placeholder="Masukkan password" required>
                                        </div>
                                        <button class="btn btn-f1 btn-lg mt-2" type="submit"><i class="bi bi-box-arrow-in-right me-2"></i>Login</button>
                                    </form>
                                </div>


                                <div class="tab-pane fade" id="signup-panel" role="tabpanel">
                                    <div class="form-label mb-2">Form Pendaftaran</div>
                                    <h3 class="rajdhani fw-bold mb-2">Buat Akun Baru</h3>
                                    <p class="text-secondary mb-4">Isi form di bawah ini untuk mendapatkan akses.</p>
                                    <form method="post" class="row g-3">
                                        <input type="hidden" name="form_action" value="signup">


                                        <div class="col-12">
                                            <label class="form-label">Nama Lengkap</label>
                                            <input class="form-control" name="name" placeholder="Nama Lengkap Sesuai KTP" required>
                                        </div>


                                        <div class="col-md-6">
                                            <label class="form-label">NIP</label>
                                            <input class="form-control" name="nip" type="text" pattern="\d{10}" minlength="10" maxlength="10" title="NIP wajib 10 digit angka" placeholder="Contoh: 0011231234" required>
                                        </div>


                                        <div class="col-md-6">
                                            <label class="form-label">Jabatan</label>
                                            <select class="form-select" name="jabatan" required>
                                                <option value="" disabled selected>-- Pilih Jabatan --</option>
                                                <option value="Area Head">Area Head</option>
                                                <option value="Branch Manager">Branch Manager</option>
                                                <option value="Segment Manager">Segment Manager</option>
                                                <option value="Segment Head">Segment Head</option>
                                                <option value="Officer">Officer</option>
                                            </select>
                                        </div>


                                        <div class="col-12">
                                            <label class="form-label">Kode & Nama Cabang</label>
                                            <select class="form-select" name="branch_combo" required>
                                                <option value="" disabled selected>-- Pilih Regional / Area / Cabang --</option>




                                                <option value="145 - Area Denpasar">145 - Area Denpasar</option>
                                                <option value="161 - Area Mataram">161 - Area Mataram</option>
                                                <option value="175 - Area Kuta">175 - Area Kuta</option>
                                                <option value="181 - Area Kupang">181 - Area Kupang</option>
                                                <option value="11 - Regional Office Bali Nusra">11 - Regional Office Bali Nusra</option>
                                                <option value="14500 - KC Denpasar Veteran">14500 - KC Denpasar Veteran</option>
                                                <option value="14501 - KCP Denpasar Gajah Mada">14501 - KCP Denpasar Gajah Mada</option>
                                                <option value="14502 - KCP Denpasar Udayana">14502 - KCP Denpasar Udayana</option>
                                                <option value="14503 - KCP Denpasar Teuku Umar">14503 - KCP Denpasar Teuku Umar</option>
                                                <option value="14510 - KCP Ubud">14510 - KCP Ubud</option>
                                                <option value="14511 - KCP Singaraja">14511 - KCP Singaraja</option>
                                                <option value="14516 - KCP Pelabuhan Benoa">14516 - KCP Pelabuhan Benoa</option>
                                                <option value="14517 - KCP Denpasar Renon">14517 - KCP Denpasar Renon</option>
                                                <option value="14518 - KCP Denpasar Pasar Kumbasari">14518 - KCP Denpasar Pasar Kumbasari</option>
                                                <option value="14519 - KCP Denpasar Gatot Subroto">14519 - KCP Denpasar Gatot Subroto</option>
                                                <option value="14520 - KCP Gianyar Celuk">14520 - KCP Gianyar Celuk</option>
                                                <option value="14521 - KCP Singaraja Seririt">14521 - KCP Singaraja Seririt</option>
                                                <option value="14523 - KCP Gianyar Ngurah Rai">14523 - KCP Gianyar Ngurah Rai</option>
                                                <option value="14525 - KCP Nusa Penida">14525 - KCP Nusa Penida</option>
                                                <option value="14528 - KCP Amlapura">14528 - KCP Amlapura</option>
                                                <option value="14531 - KCP Denpasar Sanur">14531 - KCP Denpasar Sanur</option>
                                                <option value="14532 - KCP Denpasar Klungkung">14532 - KCP Denpasar Klungkung</option>
                                                <option value="14534 - KCP Denpasar Imam Bonjol">14534 - KCP Denpasar Imam Bonjol</option>
                                                <option value="14536 - KCP Denpasar Sesetan Raya">14536 - KCP Denpasar Sesetan Raya</option>
                                                <option value="14537 - KCP Denpasar WR Supratman">14537 - KCP Denpasar WR Supratman</option>
                                                <option value="14539 - KCP Denpasar Mahendradata">14539 - KCP Denpasar Mahendradata</option>
                                                <option value="14540 - KCP Denpasar Gatot Subroto Barat">14540 - KCP Denpasar Gatot Subroto Barat</option>
                                                <option value="14580 - KCP Denpasar Pemogan">14580 - KCP Denpasar Pemogan</option>
                                                <option value="14581 - KCP Rendang">14581 - KCP Rendang</option>
                                                <option value="14588 - KCP Bangli">14588 - KCP Bangli</option>
                                                <option value="14589 - KCP Padang Bai">14589 - KCP Padang Bai</option>
                                                <option value="16100 - KC Mataram AA Gde Ngurah">16100 - KC Mataram AA Gde Ngurah</option>
                                                <option value="16101 - KCP Mataram Cakranegara">16101 - KCP Mataram Cakranegara</option>
                                                <option value="16102 - KCP Sumbawa Besar">16102 - KCP Sumbawa Besar</option>
                                                <option value="16109 - KCP Bertais">16109 - KCP Bertais</option>
                                                <option value="16110 - KCP Praya">16110 - KCP Praya</option>
                                                <option value="16111 - KCP Selong">16111 - KCP Selong</option>
                                                <option value="16113 - KCP Bima">16113 - KCP Bima</option>
                                                <option value="16114 - KCP Sumbawa Batu Hijau">16114 - KCP Sumbawa Batu Hijau</option>
                                                <option value="16115 - KCP Mataram Ampenan">16115 - KCP Mataram Ampenan</option>
                                                <option value="16117 - KCP Mataram Sriwijaya">16117 - KCP Mataram Sriwijaya</option>
                                                <option value="16120 - KCP Lombok Senggigi">16120 - KCP Lombok Senggigi</option>
                                                <option value="16121 - KCP Gili Trawangan">16121 - KCP Gili Trawangan</option>
                                                <option value="16122 - KCP Universitas Mataram">16122 - KCP Universitas Mataram</option>
                                                <option value="16123 - KCP Maluk">16123 - KCP Maluk</option>
                                                <option value="16158 - KCP Bima Raba">16158 - KCP Bima Raba</option>
                                                <option value="16159 - KCP Bima Sila">16159 - KCP Bima Sila</option>
                                                <option value="16161 - KCP Praya Penujak">16161 - KCP Praya Penujak</option>
                                                <option value="16162 - KCP Lombok Keruak">16162 - KCP Lombok Keruak</option>
                                                <option value="16163 - KCP Sumbawa Alas">16163 - KCP Sumbawa Alas</option>
                                                <option value="16164 - KCP Bima Sape">16164 - KCP Bima Sape</option>
                                                <option value="16171 - KCP Tente">16171 - KCP Tente</option>
                                                <option value="16172 - KCP Dompu">16172 - KCP Dompu</option>
                                                <option value="16173 - KCP Lombok Gunung Sari">16173 - KCP Lombok Gunung Sari</option>
                                                <option value="16175 - KCP Lombok Gerung">16175 - KCP Lombok Gerung</option>
                                                <option value="16176 - KCP Lombok Narmada">16176 - KCP Lombok Narmada</option>
                                                <option value="16177 - KCP Lombok Pemenang">16177 - KCP Lombok Pemenang</option>
                                                <option value="16178 - KCP Lombok Kopang">16178 - KCP Lombok Kopang</option>
                                                <option value="16179 - KCP Aikmel">16179 - KCP Aikmel</option>
                                                <option value="16180 - KCP Mataram Tanjung">16180 - KCP Mataram Tanjung</option>
                                                <option value="16181 - KCP Lombok Kediri">16181 - KCP Lombok Kediri</option>
                                                <option value="16182 - KCP Mataram Masbagik">16182 - KCP Mataram Masbagik</option>
                                                <option value="16183 - KCP Lombok Sakra">16183 - KCP Lombok Sakra</option>
                                                <option value="16184 - KCP Lombok Terara">16184 - KCP Lombok Terara</option>
                                                <option value="16185 - KCP Rembiga">16185 - KCP Rembiga</option>
                                                <option value="16186 - KCP Renteng Praya">16186 - KCP Renteng Praya</option>
                                                <option value="16191 - KCP Pringgabaya">16191 - KCP Pringgabaya</option>
                                                <option value="16192 - KCP Taliwang">16192 - KCP Taliwang</option>
                                                <option value="16194 - KCP Lombok Sikur">16194 - KCP Lombok Sikur</option>
                                                <option value="16196 - KCP Labuhan Lombok">16196 - KCP Labuhan Lombok</option>
                                                <option value="16198 - KCP Mataram Airlangga">16198 - KCP Mataram Airlangga</option>
                                                <option value="16199 - KCP Mataram Pagutan">16199 - KCP Mataram Pagutan</option>
                                                <option value="17500 - KC Kuta Raya">17500 - KC Kuta Raya</option>
                                                <option value="17501 - KCP Nusa Dua">17501 - KCP Nusa Dua</option>
                                                <option value="17502 - KCP Legian">17502 - KCP Legian</option>
                                                <option value="17503 - KCP Denpasar Dalung">17503 - KCP Denpasar Dalung</option>
                                                <option value="17504 - KCP Badung Ungasan">17504 - KCP Badung Ungasan</option>
                                                <option value="17505 - KCP Jimbaran">17505 - KCP Jimbaran</option>
                                                <option value="17506 - KCP Kerobokan">17506 - KCP Kerobokan</option>
                                                <option value="17507 - KCP Kuta Bypass Ngurah Rai">17507 - KCP Kuta Bypass Ngurah Rai</option>
                                                <option value="17508 - KCP Kuta Bintang">17508 - KCP Kuta Bintang</option>
                                                <option value="17509 - KCP Bandara Ngurah Rai">17509 - KCP Bandara Ngurah Rai</option>
                                                <option value="17510 - KCP Tabanan Kediri">17510 - KCP Tabanan Kediri</option>
                                                <option value="17511 - KCP Tabanan Kota">17511 - KCP Tabanan Kota</option>
                                                <option value="17512 - KCP Jembrana">17512 - KCP Jembrana</option>
                                                <option value="17513 - KCP Badung Sempidi">17513 - KCP Badung Sempidi</option>
                                                <option value="17514 - KCP Canggu Berawa">17514 - KCP Canggu Berawa</option>
                                                <option value="17515 - KCP Kuta Dewi Sri">17515 - KCP Kuta Dewi Sri</option>
                                                <option value="17550 - KCP Canggu">17550 - KCP Canggu</option>
                                                <option value="17551 - KCP Badung Kapal">17551 - KCP Badung Kapal</option>
                                                <option value="17553 - KCP Bajera">17553 - KCP Bajera</option>
                                                <option value="17555 - KCP Badung Mambal">17555 - KCP Badung Mambal</option>
                                                <option value="17556 - KCP Baturiti">17556 - KCP Baturiti</option>
                                                <option value="18100 - KC Kupang Urip Sumoharjo">18100 - KC Kupang Urip Sumoharjo</option>
                                                <option value="18101 - KCP Kupang M. Hatta">18101 - KCP Kupang M. Hatta</option>
                                                <option value="18102 - KC Atambua">18102 - KC Atambua</option>
                                                <option value="18103 - KCP Maumere">18103 - KCP Maumere</option>
                                                <option value="18104 - KCP Ruteng">18104 - KCP Ruteng</option>
                                                <option value="18105 - KCP Labuan Bajo">18105 - KCP Labuan Bajo</option>
                                                <option value="18106 - KCP Ende">18106 - KCP Ende</option>
                                                <option value="18107 - KCP Waingapu">18107 - KCP Waingapu</option>
                                                <option value="18108 - KCP Larantuka">18108 - KCP Larantuka</option>
                                                <option value="18109 - KCP Malaka">18109 - KCP Malaka</option>
                                                <option value="18110 - KCP Alor">18110 - KCP Alor</option>
                                                <option value="18150 - KCP Bajawa">18150 - KCP Bajawa</option>
                                                <option value="18153 - KCP Kupang Perintis Kemerdekaan">18153 - KCP Kupang Perintis Kemerdekaan</option>
                                                <option value="18154 - KCP Soe">18154 - KCP Soe</option>
                                                <option value="18155 - KCP Kefamenanu">18155 - KCP Kefamenanu</option>
                                                <option value="18157 - KCP Kupang Timor Raya">18157 - KCP Kupang Timor Raya</option>
                                                <option value="18158 - KCP Tambolaka Waitabula">18158 - KCP Tambolaka Waitabula</option>
                                                <option value="18159 - KCP Rote Ndao">18159 - KCP Rote Ndao</option>
                                            </select>
                                        </div>


                                        <div class="col-md-6">
                                            <label class="form-label">Password Baru</label>
                                            <input class="form-control" name="password" type="password" placeholder="Buat password" required>
                                        </div>


                                        <div class="col-md-6">
                                            <label class="form-label">Ulangi Password</label>
                                            <input class="form-control" name="password_confirm" type="password" placeholder="Ketik ulang password" required>
                                        </div>


                                        <div class="col-12 mt-3">
                                            <div class="form-check bg-light p-3 rounded border">
                                                <input class="form-check-input ms-1 me-2" type="checkbox" name="privacy_agreement" id="privacyAgreement" required>
                                                <label class="form-check-label small text-secondary" for="privacyAgreement" style="margin-top: 2px;">
                                                    Saya menyatakan setuju untuk menjaga kerahasiaan data performance dan mematuhi seluruh kebijakan privasi perusahaan.
                                                </label>
                                            </div>
                                        </div>


                                        <div class="col-12">
                                            <button class="btn btn-f1 w-100 mt-2" type="submit"><i class="bi bi-person-plus-fill me-2"></i>Daftar Sekarang</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($isHubPage): ?>
        <!-- ======= HUB PAGE (POST-LOGIN MENU) ======= -->
        <div class="container-fluid px-3 px-md-5 py-4" style="min-height:100vh; display:flex; align-items:center; justify-content:center;">
            <div style="max-width:900px; width:100%;">
                <div class="text-center mb-4">
                    <div class="redline mx-auto mb-3"></div>
                    <h2 class="rajdhani fw-bold mb-1">Selamat Datang, <?= e($currentUser['name'] ?? '') ?></h2>
                    <p class="text-secondary fw-semibold">Pilih menu yang ingin Anda akses</p>
                </div>
                <div class="row g-4 justify-content-center">
                    <div class="col-md-6 col-lg-5">
                        <a href="<?= e(currentPageUrl(['page' => 'dashboard'])) ?>" class="text-decoration-none">
                            <div class="panel p-0 overflow-hidden" style="cursor:pointer; transition:all 0.3s ease;" onmouseover="this.style.transform='translateY(-8px)';this.style.boxShadow='0 20px 40px rgba(225,6,0,0.12)';" onmouseout="this.style.transform='';this.style.boxShadow='';">
                                <img src="https://regionsebelas.com/assets/GPT5.png" alt="Dashboard Performance" class="w-100" style="height:240px; object-fit:cover;">
                                <!-- <img src="GPT5.png" alt="Dashboard Performance" class="w-100" style="height:240px; object-fit:cover;"> -->
                                <!-- <div class="p-4 text-center">
                                    <h4 class="rajdhani fw-bold text-dark mb-1">Dashboard Performance</h4>
                                    <p class="text-secondary small mb-0 fw-semibold">Neraca Produk Dana & Kredit</p>
                                </div> -->
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6 col-lg-5">
                        <a href="<?= e(currentPageUrl(['page' => 'gmm'])) ?>" class="text-decoration-none">
                            <div class="panel p-0 overflow-hidden" style="cursor:pointer; transition:all 0.3s ease;" onmouseover="this.style.transform='translateY(-8px)';this.style.boxShadow='0 20px 40px rgba(225,6,0,0.12)';" onmouseout="this.style.transform='';this.style.boxShadow='';">
                                <img src="https://regionsebelas.com/assets/GPT6.png" alt="GMM Raceboard" class="w-100" style="height:240px; object-fit:cover;">
                                <!-- <img src="GPT6.png" alt="GMM Raceboard" class="w-100" style="height:240px; object-fit:cover;"> -->
                                <!-- <div class="p-4 text-center">
                                    <h4 class="rajdhani fw-bold text-dark mb-1">Gerakan Mandirian Militian</h4>
                                    <p class="text-secondary small mb-0 fw-semibold">GMM Raceboard Fase 3</p>
                                </div> -->
                            </div>
                        </a>
                    </div>
                </div>
                <div class="text-center mt-4 d-flex flex-wrap justify-content-center gap-3">
                    <?php if (isAdmin($currentUser)): ?>
                        <a class="btn btn-ghost px-4" href="<?= e(currentPageUrl(['page' => 'admin'])) ?>"><i class="bi bi-sliders me-2"></i>Admin Panel</a>
                    <?php endif; ?>
                    <form method="post" class="m-0"><input type="hidden" name="form_action" value="logout"><button class="btn btn-ghost px-4" type="submit"><i class="bi bi-box-arrow-right me-1 text-danger"></i> Logout</button></form>
                </div>
                <!-- Update Dates Info -->
                <div class="text-center mt-4">
                    <div class="panel-tight p-3 d-inline-block" style="font-size:0.8rem;">
                        <span class="fw-bold text-dark">Terakhir Update:</span>
                        <span class="badge bg-light text-dark border ms-2">Dana: <?= e($updateDates['produk_dana'] ?: '-') ?></span>
                        <span class="badge bg-light text-dark border ms-1">Kredit: <?= e($updateDates['produk_kredit'] ?: '-') ?></span>
                        <span class="badge bg-light text-dark border ms-1">GMM: <?= e($updateDates['gmm'] ?: '-') ?></span>
                    </div>
                </div>
            </div>
        </div>


    <?php elseif ($isGmmPage): ?>
        <!-- ======= GMM PAGE (SPA) ======= -->
        <header class="top-shell">
            <div class="container-fluid px-3 px-md-5 py-3">
                <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="brand-mark" style="background:linear-gradient(135deg,#E10600,#b90500);"><i class="bi bi-trophy-fill fs-4"></i></div>
                        <div>
                            <div class="redline mb-2"></div>
                            <h3 class="rajdhani fw-bold mb-0">GMM Raceboard</h3>
                            <div class="text-secondary fw-semibold small">Gerakan Mandirian Militian — Fase 3</div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="user-pill text-dark"><i class="bi bi-person-circle text-danger"></i> <?= e($currentUser['name'] ?? '') ?></span>
                        <a class="btn btn-ghost" href="<?= e(currentPageUrl(['page' => 'hub'])) ?>"><i class="bi bi-house me-1"></i> Menu</a>
                        <form method="post" class="m-0"><input type="hidden" name="form_action" value="logout"><button class="btn btn-ghost" type="submit"><i class="bi bi-box-arrow-right me-1 text-danger"></i> Logout</button></form>
                    </div>
                </div>
            </div>
        </header>
        <main class="container-fluid px-2 px-md-4 px-xl-5 py-3" id="appGmm">
            <!-- GMM Nav -->
            <div class="panel-tight p-1 mb-3 d-flex gap-1 flex-wrap overflow-auto" id="gmmTabs" style="max-width:100%;">
                <button class="nav-link active fw-bold" data-gmm-view="dashboard" type="button">🏠 Dashboard</button>
                <button class="nav-link fw-bold" data-gmm-view="cabang" type="button">🏢 Leaderboard Cabang</button>
                <button class="nav-link fw-bold" data-gmm-view="pegawai" type="button">👨‍💼 Leaderboard Pegawai</button>
                <button class="nav-link fw-bold" data-gmm-view="search" type="button">🔍 Pencarian</button>
            </div>
            <!-- GMM Kategori Tabs -->
            <div class="d-flex gap-2 mb-4 flex-wrap" id="gmmKatTabs" style="display:none;">
                <button class="group-pill active" data-gmm-kat="LIVIN" style="display:flex;align-items:center;gap:5px;padding:6px 14px;font-size:.82rem;">
                    <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#2563eb;flex-shrink:0;"></span>📱 LIVIN
                </button>
                <button class="group-pill" data-gmm-kat="MERCHANT" style="display:flex;align-items:center;gap:5px;padding:6px 14px;font-size:.82rem;">
                    <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#7c3aed;flex-shrink:0;"></span>🏪 MERCHANT
                </button>
                <button class="group-pill" data-gmm-kat="TRANSAKSI" style="display:flex;align-items:center;gap:5px;padding:6px 14px;font-size:.82rem;">
                    <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#0891b2;flex-shrink:0;"></span>💳 TRANSAKSI
                </button>
            </div>
            <!-- GMM Content Area -->
            <div id="gmmContent">
                <div class="empty-state">
                    <div class="spinner-border text-danger"></div><strong class="mt-3">Memuat data GMM...</strong>
                </div>
            </div>
        </main>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const apiBase = window.location.pathname;
                const gmmState = {
                    view: 'dashboard',
                    kategori: 'LIVIN',
                    filter: 'ALL',
                    page: 1,
                    sortCol: ''
                };
                // Cache nama pegawai untuk autocomplete pencarian GMM
                const _gmmSearchCache = [];

                window.gmmSuggest = function(q) {
                    const box = document.getElementById('gmmSuggestBox');
                    if (!box) return;
                    q = (q || '').trim().toLowerCase();
                    if (q.length < 2) {
                        box.style.display = 'none';
                        return;
                    }

                    const matches = _gmmSearchCache.filter(c =>
                        (c.nama || '').toLowerCase().includes(q) ||
                        (c.nip || '').toLowerCase().includes(q) ||
                        (c.unit || '').toLowerCase().includes(q)
                    ).slice(0, 8);

                    if (!matches.length) {
                        box.style.display = 'none';
                        return;
                    }

                    box.style.display = 'block';
                    box.innerHTML = matches.map(m => `
        <div onclick="
                document.getElementById('gmmSearchInput').value='${escapeHtml(m.nama)}';
                document.getElementById('gmmSuggestBox').style.display='none';
                gmmSearchExec('${escapeHtml(m.nama)}')"
             style="padding:10px 14px;cursor:pointer;border-bottom:1px solid rgba(229,231,235,.5);
                    display:flex;align-items:center;gap:10px;transition:background .1s;"
             onmouseover="this.style.background='rgba(225,6,0,.04)'"
             onmouseout="this.style.background=''">
            <div style="width:32px;height:32px;border-radius:50%;
                background:linear-gradient(135deg,#fee2e2,#fecdd3);
                display:flex;align-items:center;justify-content:center;
                font-size:.85rem;flex-shrink:0;">👤</div>
            <div style="min-width:0;">
                <div style="font-weight:700;color:#1e293b;font-size:.85rem;
                    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(m.nama)}</div>
                <div style="font-size:.7rem;color:#64748b;">
                    NIP: ${escapeHtml(m.nip)} · ${escapeHtml(m.unit)}</div>
            </div>
        </div>`).join('');
                };

                // Sort column definitions per category
                const sortDefs = {
                    LIVIN: [{
                            col: 'end_balance',
                            label: 'End Balance',
                            fmt: 'rp'
                        },
                        {
                            col: 'cif_akuisisi',
                            label: 'CIF Akuisisi',
                            fmt: 'num'
                        },
                        {
                            col: 'cif_setor',
                            label: 'CIF Setor',
                            fmt: 'num'
                        },
                        {
                            col: 'rata_rata',
                            label: 'Rata-rata Bal.',
                            fmt: 'rp'
                        },
                        {
                            col: 'cif_sudah_transaksi',
                            label: 'CIF Transaksi',
                            fmt: 'num'
                        },
                        {
                            col: 'frek_dari_cif_akuisisi',
                            label: 'Frek CIF',
                            fmt: 'num'
                        },
                    ],
                    MERCHANT: [{
                            col: 'total_referral_edc',
                            label: 'Referral EDC',
                            fmt: 'num'
                        },
                        {
                            col: 'total_referral_livin',
                            label: 'Referral LVM',
                            fmt: 'num'
                        },
                    ],
                    TRANSAKSI: [{
                            col: 'pct_on_us',
                            label: '% On Us',
                            fmt: 'pct'
                        },
                        {
                            col: 'total_poin_transaksi',
                            label: 'Total Poin',
                            fmt: 'num'
                        },
                        {
                            col: 'frek_on_us',
                            label: 'Frek On Us',
                            fmt: 'num'
                        },
                        {
                            col: 'frek_off_us',
                            label: 'Frek Off Us',
                            fmt: 'num'
                        },
                        {
                            col: 'poin_on_us',
                            label: 'Poin On Us',
                            fmt: 'num'
                        },
                        {
                            col: 'poin_off_us',
                            label: 'Poin Off Us',
                            fmt: 'num'
                        },
                    ],
                };
                const fmtByKey = (val, fmtKey) => fmtKey === 'rp' ? fmtRp(val) : fmtKey === 'pct' ? fmtPct(val) : fmtNum(val);

                // Per-area colors
                const areaColors = {
                    '145': ['#dbeafe', '#1e40af'], // Denpasar - Blue
                    '161': ['#dcfce7', '#15803d'], // Mataram - Green
                    '175': ['#fef9c3', '#854d0e'], // Kuta - Yellow
                    '181': ['#fce7f3', '#9d174d'], // Kupang - Pink
                    'DENPASAR': ['#dbeafe', '#1e40af'],
                    'MATARAM': ['#dcfce7', '#15803d'],
                    'KUTA': ['#fef9c3', '#854d0e'],
                    'KUPANG': ['#fce7f3', '#9d174d'],
                    'R11': ['#f3e8ff', '#6b21a8'],
                };
                const areaNames = {
                    '145': 'DENPASAR',
                    '161': 'MATARAM',
                    '175': 'KUTA',
                    '181': 'KUPANG'
                };
                const fmtRp = v => {
                    try {
                        let n = Math.round(Number(v));
                        return 'Rp ' + n.toLocaleString('id-ID') + ' Jt';
                    } catch (e) {
                        return 'Rp 0 Jt';
                    }
                };
                const fmtNum = v => {
                    try {
                        return Math.round(Number(v)).toLocaleString('id-ID');
                    } catch (e) {
                        return '0';
                    }
                };
                const fmtPct = v => {
                    try {
                        let n = Number(v);
                        return (n <= 1 ? (n * 100).toFixed(1) : n.toFixed(1)) + '%';
                    } catch (e) {
                        return '0.0%';
                    }
                };
                // Always return neutral background/text colors (no per-area coloring)
                const getAreaStyle = (a) => {
                    const aStr = String(a || '').trim().toUpperCase();
                    // Try direct area code/name lookup
                    if (areaColors[aStr]) return areaColors[aStr];
                    // Try prefix match (e.g. '145' matches '1450x')
                    for (const [key, colors] of Object.entries(areaColors)) {
                        if (aStr.startsWith(key) || key.startsWith(aStr)) return colors;
                    }
                    return ['#F1F5F9', '#475569'];
                };


                // Tab clicks
                document.querySelectorAll('[data-gmm-view]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('[data-gmm-view]').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        gmmState.view = btn.dataset.gmmView;
                        gmmState.page = 1;
                        gmmState.filter = 'ALL';
                        loadGmm();
                    });
                });
                document.querySelectorAll('[data-gmm-kat]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        document.querySelectorAll('[data-gmm-kat]').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        gmmState.kategori = btn.dataset.gmmKat;
                        gmmState.page = 1;
                        gmmState.sortCol = '';
                        loadGmm();
                    });
                });


                async function loadGmm() {
                    const katTabs = document.getElementById('gmmKatTabs');
                    // dashboard & search → hidden, cabang & pegawai → show
                    katTabs.style.display = ['dashboard', 'cabang', 'pegawai'].includes(gmmState.view) ? 'flex' : 'none';
                    katTabs.style.flexWrap = 'wrap';
                    const content = document.getElementById('gmmContent');
                    content.innerHTML = `<h4 class="rajdhani fw-bold mb-3">🔍 Pencarian</h4>
                    <div style="max-width:500px;position:relative;">
                        <div class="d-flex gap-2 mb-1">
                            <div style="flex:1;position:relative;">
                                <input type="text" class="form-control" id="gmmSearchInput"
                                    placeholder="Ketik Nama, NIP, atau Unit..."
                                    autocomplete="off"
                                    oninput="gmmSuggest(this.value)"
                                    onkeypress="if(event.key==='Enter'){document.getElementById('gmmSuggestBox').style.display='none';gmmSearchExec(this.value);}">
                                <div id="gmmSuggestBox" style="display:none;position:absolute;top:100%;left:0;right:0;
                                    z-index:200;background:#fff;border:1px solid rgba(200,200,210,.6);
                                    border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);
                                    max-height:240px;overflow-y:auto;margin-top:4px;"></div>
                            </div>
                            <button class="btn btn-f1" onclick="document.getElementById('gmmSuggestBox').style.display='none';gmmSearchExec(document.getElementById('gmmSearchInput').value)">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div style="font-size:.72rem;color:#94a3b8;margin-top:4px;">Ketik minimal 2 huruf untuk saran pencarian</div>
                    </div>`;
                    try {
                        const params = new URLSearchParams({
                            action: 'gmm_data',
                            view: gmmState.view,
                            kategori: gmmState.kategori,
                            filter: gmmState.filter,
                            p: gmmState.page
                        });
                        if (gmmState.search) params.set('search', gmmState.search);
                        if (gmmState.sortCol) params.set('sort_col', gmmState.sortCol);
                        const res = await fetch(`${apiBase}?${params}`);
                        const data = await res.json();
                        if (!data.ok) throw new Error(data.message || 'Error');
                        if (!data.has_data) {
                            content.innerHTML = '<div class="empty-state"><i class="bi bi-database-x fs-1 text-secondary"></i><strong>Data GMM belum tersedia.</strong><p class="text-secondary">Admin perlu upload file Excel GMM terlebih dahulu.</p></div>';
                            return;
                        }
                        renderGmm(data);
                    } catch (e) {
                        content.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
                    }
                }


                function renderGmm(data) {
                    const content = document.getElementById('gmmContent');
                    const wrapper = document.createElement('div');
                    wrapper.className = 'gmm-fade-in';


                    if (data.view === 'dashboard') renderGmmDashboard(wrapper, data);
                    else if (data.view === 'cabang') renderGmmList(wrapper, data, 'cabang');
                    else if (data.view === 'pegawai') renderGmmList(wrapper, data, 'pegawai');
                    else if (data.view === 'search') renderGmmSearch(wrapper, data);
                    else if (data.view === 'detail_pegawai') renderGmmDetailPegawai(wrapper, data);
                    else if (data.view === 'detail_cabang') renderGmmDetailCabang(wrapper, data);


                    content.innerHTML = '';
                    content.appendChild(wrapper);
                }


                // Dashboard sort state per category
                const dashSortState = {
                    LIVIN: '',
                    MERCHANT: '',
                    TRANSAKSI: ''
                };
                window.gmmDashSort = (kat, col) => {
                    dashSortState[kat] = (dashSortState[kat] === col) ? '' : col;
                    // Re-render the specific section — just re-trigger full dashboard reload
                    loadGmm();
                };

                function renderGmmDashboard(el, data) {

                    // ── Helpers ────────────────────────────────────────────────────────────────

                    function sortPill(label, isActive, onclick, color = 'var(--f1-red)') {
                        const base = `
            display:inline-flex;align-items:center;gap:4px;
            padding:3px 11px;border-radius:999px;font-size:.71rem;font-weight:700;
            cursor:pointer;white-space:nowrap;transition:all .15s;
            border:1.5px solid ${isActive ? color : '#d1d5db'};
            background:${isActive ? color : '#fff'};
            color:${isActive ? '#fff' : '#64748b'};
        `;
                        return `<button onclick="${onclick}" style="${base.replace(/\s+/g, ' ').trim()}">
            ${isActive ? '<span style="font-size:.65rem;">▼</span>' : ''}${label}
        </button>`;
                    }

                    // ── Branch View ────────────────────────────────────────────────────────────

                    if (data.dashboard_type === 'branch') {
                        if (!window._branchSortCol) window._branchSortCol = 'end_balance';
                        const bsc = window._branchSortCol;

                        window.branchSort = col => {
                            window._branchSortCol = col;
                            loadGmm();
                        };

                        let list = [...(data.data || [])];
                        list.sort((a, b) => (b[bsc] || 0) - (a[bsc] || 0));

                        const pills = allMetricsDef
                            .map(m => sortPill(m.label, m.key === bsc, `branchSort('${m.key}')`))
                            .join('');

                        let html = `
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <div>
                    <div class="form-label mb-0">Dashboard Cabang</div>
                    <h4 class="rajdhani fw-bold mb-0">🏠 Capaian Pegawai Cabang</h4>
                </div>
                <span class="badge bg-light text-dark border fw-bold">${list.length} Pegawai</span>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-3">${pills}</div>
            <div class="d-flex flex-column gap-2">
        `;

                        const activeDef = allMetricsDef.find(m => m.key === bsc);

                        list.forEach((p, i) => {
                            const rank = i + 1;
                            const rs = getRankStyle(rank);
                            html += `
                <div style="background:${rs.bg};border:1px solid ${rs.border};border-radius:12px;
                            padding:10px 14px;display:grid;grid-template-columns:auto 1fr auto;
                            align-items:start;gap:10px;">
                    <div style="padding-top:2px;">${getRankBadge(rank)}</div>
                    <div style="min-width:0;">
                        <div style="font-weight:800;color:#1e293b;font-size:.88rem;">${p.nama}</div>
                        ${pegawaiBadges(p)}
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-weight:900;color:#16a34a;font-family:'Rajdhani',sans-serif;font-size:1.1rem;">
                            ${fmtByKey(p[bsc] || 0, activeDef?.fmt || 'num')}
                        </div>
                    </div>
                </div>
            `;
                        });

                        if (!list.length) html += '<div class="empty-state"><strong>Tidak ada data.</strong></div>';
                        html += '</div>';
                        el.innerHTML = html;
                        return;
                    }

                    // ── Main Dashboard ─────────────────────────────────────────────────────────

                    const katConfig = {
                        LIVIN: {
                            icon: '📱',
                            color: '#2563eb',
                            bg: '#dbeafe'
                        },
                        MERCHANT: {
                            icon: '🏪',
                            color: '#7c3aed',
                            bg: '#f3e8ff'
                        },
                        TRANSAKSI: {
                            icon: '💳',
                            color: '#0891b2',
                            bg: '#cffafe'
                        },
                    };

                    let html = `
        <div class="mb-3">
            <div class="form-label mb-0">Ringkasan</div>
            <h4 class="rajdhani fw-bold mb-0">🏠 Dashboard — Top 10 Per Kategori</h4>
        </div>
    `;

                    const activeDashboardKat = Object.prototype.hasOwnProperty.call(katConfig, gmmState.kategori) ? gmmState.kategori : 'LIVIN';
                    for (const [kat, cfg] of Object.entries(katConfig).filter(([name]) => name === activeDashboardKat)) {
                        const d = data.data[kat] || {
                            cabang: [],
                            pegawai: []
                        };
                        const defs = sortDefs[kat] || [];
                        const activeSort = dashSortState[kat] || (defs[0]?.col || '');
                        const activeDef = defs.find(x => x.col === activeSort) || defs[0] || {
                            col: 'score',
                            label: 'Score',
                            fmt: 'num'
                        };

                        const pills = defs
                            .map(dd => sortPill(dd.label, dd.col === activeSort, `gmmDashSort('${kat}','${dd.col}')`, cfg.color))
                            .join('');

                        html += `
            <div style="margin-bottom:2rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;
                            gap:10px;margin-bottom:10px;padding-bottom:8px;
                            border-bottom:2px solid ${cfg.bg};">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:1.2rem;">${cfg.icon}</span>
                        <h5 style="margin:0;font-family:'Rajdhani',sans-serif;font-weight:700;
                                   text-transform:uppercase;color:${cfg.color};">Kategori ${kat}</h5>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">${pills}</div>
                </div>
                <div class="row g-3">
                    <div class="col-lg-6"><div class="panel p-3">
                        <h6 class="rajdhani fw-bold mb-2" style="color:${cfg.color};">🏢 Top 10 Cabang</h6>
                        <div class="d-flex flex-column gap-1">
        `;

                        d.cabang.slice(0, 10).forEach((r, i) => {
                            const rank = i + 1;
                            const rs = getRankStyle(rank);
                            const em = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' :
                                `<span style="font-size:.8rem;color:#94a3b8;font-weight:700;">#${rank}</span>`;
                            html += `
                <div style="display:flex;align-items:center;gap:6px;padding:7px 10px;
                            border-radius:8px;background:${rs.bg};border:1px solid ${rs.border};">
                    <span style="min-width:22px;text-align:center;">${em}</span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:.82rem;overflow:hidden;
                                    text-overflow:ellipsis;white-space:nowrap;color:#1e293b;">
                            ${r.unit || r.kode_cabang}
                        </div>
                    </div>
                    <div style="font-weight:900;color:${cfg.color};font-family:'Rajdhani',sans-serif;font-size:.95rem;">
                        ${fmtByKey(r.score, activeDef.fmt)}
                    </div>
                </div>
            `;
                        });

                        html += `
                        </div></div></div>
                    <div class="col-lg-6"><div class="panel p-3">
                        <h6 class="rajdhani fw-bold mb-2" style="color:${cfg.color};">👤 Top 10 Pegawai</h6>
                        <div class="d-flex flex-column gap-1">
        `;

                        d.pegawai.slice(0, 10).forEach((r, i) => {
                            const rank = i + 1;
                            const rs = getRankStyle(rank);
                            const em = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' :
                                `<span style="font-size:.8rem;color:#94a3b8;font-weight:700;">#${rank}</span>`;
                            html += `
                <div style="display:flex;align-items:center;gap:6px;padding:7px 10px;
                            border-radius:8px;background:${rs.bg};border:1px solid ${rs.border};">
                    <span style="min-width:22px;text-align:center;">${em}</span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:.82rem;overflow:hidden;
                                    text-overflow:ellipsis;white-space:nowrap;color:#1e293b;">${r.nama || ''}</div>
                        <div style="font-size:.68rem;color:#64748b;">${r.unit || ''}</div>
                    </div>
                    <div style="font-weight:900;color:${cfg.color};font-family:'Rajdhani',sans-serif;font-size:.95rem;">
                        ${fmtByKey(r.score, activeDef.fmt)}
                    </div>
                </div>
            `;
                        });

                        html += `</div></div></div></div></div>`;
                    }

                    el.innerHTML = html;
                }

                function getRankBadge(rank) {
                    if (rank === 1) return '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#FFD700,#FFA500);color:#7c2d12;font-weight:900;font-size:0.9rem;box-shadow:0 2px 8px rgba(255,165,0,0.4);">1</span>';
                    if (rank === 2) return '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#C0C0C0,#A8A8A8);color:#1e293b;font-weight:900;font-size:0.9rem;box-shadow:0 2px 6px rgba(0,0,0,0.2);">2</span>';
                    if (rank === 3) return '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#CD7F32,#A0522D);color:#fff;font-weight:900;font-size:0.9rem;box-shadow:0 2px 6px rgba(0,0,0,0.2);">3</span>';
                    return `<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#f1f5f9;color:#64748b;font-weight:700;font-size:0.8rem;">${rank}</span>`;
                }

                function renderGmmList(el, data, type) {
                    const list = data.list || [];
                    const kat = data.kategori || gmmState.kategori;
                    const katIcon = kat === 'LIVIN' ? '📱' : (kat === 'MERCHANT' ? '🏪' : '💳');

                    // ── Column definitions per category ──────────────────────────
                    const cabangCols = {
                        LIVIN: [{
                                key: 'sum_ca',
                                label: 'CIF Akuisisi',
                                fmt: 'num',
                                sortCol: 'cif_akuisisi'
                            },
                            {
                                key: 'sum_eb',
                                label: 'End Balance',
                                fmt: 'rp',
                                sortCol: 'end_balance'
                            }
                        ],
                        MERCHANT: [{
                                key: 'sum_re',
                                label: 'Ref EDC',
                                fmt: 'num',
                                sortCol: 'total_referral_edc'
                            },
                            {
                                key: 'sum_rl',
                                label: 'Ref LVM',
                                fmt: 'num',
                                sortCol: 'total_referral_livin'
                            }
                        ],
                        TRANSAKSI: [{
                                key: 'pct_on_us_calc',
                                label: '% On Us',
                                fmt: 'pct',
                                sortCol: 'pct_on_us'
                            },
                            {
                                key: 'sum_tp',
                                label: 'Total Poin',
                                fmt: 'num',
                                sortCol: 'total_poin_transaksi'
                            }
                        ],
                    };
                    const pegawaiCols = {
                        LIVIN: [{
                                key: 'cif_akuisisi',
                                label: 'CIF Akuisisi',
                                fmt: 'num',
                                sortCol: 'cif_akuisisi'
                            },
                            {
                                key: 'end_balance',
                                label: 'End Balance',
                                fmt: 'rp',
                                sortCol: 'end_balance'
                            }
                        ],
                        MERCHANT: [{
                                key: 'total_referral_edc',
                                label: 'Ref EDC',
                                fmt: 'num',
                                sortCol: 'total_referral_edc'
                            },
                            {
                                key: 'total_referral_livin',
                                label: 'Ref LVM',
                                fmt: 'num',
                                sortCol: 'total_referral_livin'
                            }
                        ],
                        TRANSAKSI: [{
                                key: 'pct_on_us',
                                label: '% On Us',
                                fmt: 'pct',
                                sortCol: 'pct_on_us'
                            },
                            {
                                key: 'total_poin_transaksi',
                                label: 'Total Poin',
                                fmt: 'num',
                                sortCol: 'total_poin_transaksi'
                            }
                        ],
                    };
                    const cols = type === 'cabang' ? (cabangCols[kat] || []) : (pegawaiCols[kat] || []);

                    // Sort options
                    const sortOpts = cols.map(c => ({
                        col: c.sortCol,
                        label: c.label,
                        fmt: c.fmt
                    }));
                    const activeSort = gmmState.sortCol || (sortOpts[0] ? sortOpts[0].col : '');
                    const activeSortDef = sortOpts.find(s => s.col === activeSort) || sortOpts[0] || {
                        col: 'score',
                        label: 'Score',
                        fmt: 'num'
                    };

                    // Helper to get value from row
                    const getVal = (r, col) => {
                        if (col === 'pct_on_us_calc') {
                            const fo = (r.sum_fo || 0),
                                ff = (r.sum_ff || 0);
                            return (fo + ff) > 0 ? (fo / (fo + ff)) : 0;
                        }
                        return r[col] !== undefined ? r[col] : (r.score || 0);
                    };

                    // Area badge — always dark text on colored bg
                    const areaBadge = (r) => {
                        const [bg, tc] = getAreaStyle(r.area);
                        const an = areaNames[String(r.area || '').trim()] || r.area || '';
                        return `<span style="display:inline-block;padding:2px 8px;border-radius:5px;font-size:.65rem;font-weight:800;background:${bg};color:${tc};letter-spacing:.02em;">${an}</span>`;
                    };

                    // ── HEADER ────────────────────────────────────────────────────
                    let html = '';
                    if (type === 'pegawai' && gmmState.filter !== 'ALL') {
                        const branchName = list.length > 0 ? (list[0].unit || gmmState.filter) : gmmState.filter;
                        html += `<div class="d-flex align-items-center mb-3 flex-wrap gap-2">
                            <button class="btn btn-ghost btn-sm" onclick="document.querySelector('[data-gmm-view=cabang]').click()">← Kembali</button>
                            <div><div class="form-label mb-0">Leaderboard Pegawai</div>
                            <h5 class="rajdhani fw-bold mb-0">${branchName} ${katIcon} ${kat}</h5></div>
                        </div>`;
                    } else {
                        html += `<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div><div class="form-label mb-0">${type==='cabang'?'Leaderboard Cabang':'Leaderboard Pegawai'}</div>
                            <h5 class="rajdhani fw-bold mb-0">${katIcon} Kategori ${kat}</h5></div>`;
                        if (type === 'pegawai') {
                            html += `<div class="d-flex gap-2 align-items-center">
                                <span style="background:#f1f5f9;padding:3px 10px;border-radius:8px;font-size:.75rem;font-weight:700;">${fmtNum(data.total||0)} Pegawai · Hal. ${data.page||1}</span>
                            </div>`;
                        }
                        html += `</div>`;
                    }

                    // ── SORT PILLS ────────────────────────────────────────────────
                    html += `<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                        <span style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">Urutkan:</span>`;
                    sortOpts.forEach(s => {
                        const isA = s.col === activeSort;
                        html += `<button onclick="gmmSort('${s.col}')" style="padding:5px 14px;border-radius:999px;font-size:.76rem;font-weight:700;
                            border:2px solid ${isA?'#E10600':'rgba(200,200,210,.6)'};
                            background:${isA?'#E10600':'rgba(255,255,255,.8)'};
                            color:${isA?'#fff':'#334155'};cursor:pointer;transition:all .15s;white-space:nowrap;">
                            ${isA?'▼ ':''}${s.label}</button>`;
                    });
                    html += `</div>`;

                    // ────────────────────────────────────────────────────────────
                    // CABANG → responsive card-table (mobile cards, desktop table)
                    // ────────────────────────────────────────────────────────────
                    if (type === 'cabang') {
                        // Mobile-first CARD layout (always cards, not a scrolling table)
                        html += `<div class="d-flex flex-column gap-2">`;

                        list.forEach((r, i) => {
                            const rank = i + 1;
                            const [bg, tc] = getAreaStyle(r.area);
                            const em = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : null;
                            const pctOnUs = (r.sum_fo || r.sum_ff) ? ((r.sum_fo || 0) / ((r.sum_fo || 0) + (r.sum_ff || 0))) : 0;

                            // Card bg: top-3 use a very subtle tint, rest white
                            const cardBg = rank <= 3 ? bg : 'rgba(255,255,255,.85)';
                            // Text always dark regardless of area color
                            const textCol = '#1e293b';
                            const subCol = '#475569';
                            const rankColor = rank === 1 ? '#ca8a04' : rank === 2 ? '#6b7280' : rank === 3 ? '#92400e' : '#94a3b8';
                            const rankSize = rank <= 3 ? '1.1rem' : '.85rem';

                            // Active sort column value (large, red if rank 1)
                            const col1 = cols[0];
                            const col2 = cols[1];
                            const val1 = col1.key === 'pct_on_us_calc' ? pctOnUs : (r[col1.key] || 0);
                            const val2 = col2 ? (col2.key === 'pct_on_us_calc' ? pctOnUs : (r[col2.key] || 0)) : null;
                            const primaryCol = cols.find(c => c.sortCol === activeSort) || col1;
                            const primaryVal = primaryCol.key === 'pct_on_us_calc' ? pctOnUs : (r[primaryCol.key] || 0);

                            html += `<div style="background:${cardBg};border:1px solid ${rank<=3?tc+'22':'rgba(229,231,235,.7)'};border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:10px;box-shadow:${rank<=3?'0 2px 8px rgba(0,0,0,.06)':'none'};">
                                <!-- Rank -->
                                <div style="text-align:center;min-width:32px;">
                                    ${em
                                        ? `<span style="font-size:1.4rem;">${em}</span>`
                                        : `<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#f1f5f9;color:#64748b;font-weight:800;font-size:.8rem;">${rank}</span>`
                                    }
                                </div>
                                <!-- Info -->
                                <div style="min-width:0;">
                                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;flex-wrap:wrap;">
                                        <span style="font-weight:800;color:${textCol};font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px;">${r.unit||'-'}</span>
                                        ${areaBadge(r)}
                                    </div>
                                    <div style="font-size:.7rem;color:${subCol};font-weight:600;">${r.kode_cabang||'-'} · ${r.jml||0} pegawai</div>
                                    <!-- Both metric values shown on mobile -->
                                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:5px;">
                                        ${cols.map(c => {
                                            const v = c.key==='pct_on_us_calc' ? pctOnUs : (r[c.key]||0);
                                            const isActive = c.sortCol===activeSort;
                                            return `<span style="font-size:.78rem;font-weight:${isActive?'900':'700'};color:${isActive?'#E10600':'#334155'};
                                                background:${isActive?'rgba(225,6,0,.07)':'rgba(241,245,249,.9)'};
                                                padding:2px 8px;border-radius:5px;border:1px solid ${isActive?'rgba(225,6,0,.2)':'rgba(229,231,235,.6)'};">
                                                ${c.label}: <strong>${fmtByKey(v, c.fmt)}</strong></span>`;
                                        }).join('')}
                                    </div>
                                </div>
                                <!-- Action -->
                                <div style="flex-shrink:0;text-align:right;">
                                    <button style="background:#fff;border:1px solid rgba(200,200,210,.6);border-radius:8px;font-size:.72rem;font-weight:700;padding:5px 10px;cursor:pointer;color:#334155;white-space:nowrap;" onclick="gmmDetail('cabang','${r.kode_cabang}')">Detail ›</button>
                                </div>
                            </div>`;
                        });

                        if (!list.length) html += `<div class="empty-state"><strong>Tidak ada data.</strong></div>`;
                        html += `</div>`;

                    } else {
                        // ── PEGAWAI → card layout, mobile-first ──────────────────
                        html += '<div class="d-flex flex-column gap-2">';
                        list.forEach((r, i) => {
                            const [bg, tc] = getAreaStyle(r.area);
                            const rank = (data.page - 1) * data.page_size + i + 1;
                            const em = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : null;
                            const cardBg = rank <= 3 ? bg : 'rgba(255,255,255,.85)';
                            const scoreVal = getVal(r, activeSort);

                            html += `<div style="background:${cardBg};border:1px solid ${rank<=3?tc+'22':'rgba(229,231,235,.7)'};border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:auto 1fr auto;align-items:start;gap:10px;box-shadow:${rank<=3?'0 2px 8px rgba(0,0,0,.06)':'none'};">
                                <!-- Rank badge -->
                                <div style="text-align:center;min-width:32px;padding-top:2px;">
                                    ${em
                                        ? `<span style="font-size:1.3rem;">${em}</span>`
                                        : `<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#f1f5f9;color:#64748b;font-weight:800;font-size:.8rem;">${rank}</span>`
                                    }
                                </div>
                                <!-- Info -->
                                <div style="min-width:0;">
                                    <div style="font-weight:800;color:#1e293b;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r.nama||'-'}</div>
                                    <div style="font-size:.7rem;color:#475569;margin-top:2px;">${r.posisi||'-'} · ${r.unit||''}</div>
                                    <div style="display:flex;align-items:center;gap:6px;margin-top:4px;flex-wrap:wrap;">
                                        ${areaBadge(r)}
                                    </div>
                                    <!-- All cols shown below name -->
                                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
                                        ${cols.map(c => {
                                            const v = r[c.key] !== undefined ? r[c.key] : 0;
                                            const isActive = c.sortCol === activeSort;
                                            return `<span style="font-size:.78rem;font-weight:${isActive?'900':'700'};color:${isActive?'#E10600':'#334155'};
                                                background:${isActive?'rgba(225,6,0,.07)':'rgba(241,245,249,.9)'};
                                                padding:2px 8px;border-radius:5px;border:1px solid ${isActive?'rgba(225,6,0,.2)':'rgba(229,231,235,.6)'};">
                                                ${c.label}: <strong>${fmtByKey(v, c.fmt)}</strong></span>`;
                                        }).join('')}
                                    </div>
                                </div>
                                <!-- Score + action -->
                                <div style="text-align:right;flex-shrink:0;">
                                    <div style="font-weight:900;font-size:1.1rem;color:${rank===1?'#E10600':'#0f172a'};font-family:'Rajdhani',sans-serif;line-height:1.1;">${fmtByKey(scoreVal, activeSortDef.fmt)}</div>
                                    <div style="font-size:.62rem;color:#94a3b8;font-weight:600;margin-bottom:6px;">${activeSortDef.label}</div>
                                    <button style="background:#fff;border:1px solid rgba(200,200,210,.6);border-radius:8px;font-size:.7rem;font-weight:700;padding:4px 8px;cursor:pointer;color:#334155;" onclick="gmmDetail('pegawai','${r.nip}')">Detail ›</button>
                                </div>
                            </div>`;
                        });
                        html += '</div>';
                        if (!list.length) html += '<div class="empty-state"><strong>Tidak ada data.</strong></div>';
                    }

                    // Pagination
                    if (type === 'pegawai' && data.total > data.page_size) {
                        const tp = Math.ceil(data.total / data.page_size);
                        html += `<div style="display:flex;justify-content:center;align-items:center;gap:12px;margin-top:16px;padding-top:12px;border-top:1px solid rgba(229,231,235,.5);">
                            <button class="btn btn-ghost btn-sm" ${data.page<=1?'disabled':''} onclick="gmmPage(${Math.max(1,data.page-1)})">← Prev</button>
                            <span style="font-weight:700;color:#475569;font-size:.85rem;">${data.page} / ${tp}</span>
                            <button class="btn btn-ghost btn-sm" ${data.page>=tp?'disabled':''} onclick="gmmPage(${Math.min(tp,data.page+1)})">Next →</button>
                        </div>`;
                    }
                    el.innerHTML = html;
                }


                function renderGmmSearch(el, data) {
                    const results = data.results || [];
                    let html = '<h4 class="rajdhani fw-bold mb-3">🔍 Hasil Pencarian</h4>';
                    html += '<div class="table-responsive"><table class="table table-hover"><thead class="table-light"><tr><th>NIP</th><th>Nama</th><th>Unit</th><th>Posisi</th><th></th></tr></thead><tbody>';
                    results.forEach(r => {
                        html += `<tr><td class="fw-bold">${r.nip}</td><td>${r.nama}</td><td>${r.unit||'-'}</td><td>${r.posisi||'-'}</td>`;
                        html += `<td><button class="btn btn-sm btn-ghost" onclick="gmmDetail('pegawai','${r.nip}')">Detail</button></td></tr>`;
                    });
                    if (!results.length) html += '<tr><td colspan="5" class="text-center text-secondary py-4">Tidak ditemukan.</td></tr>';
                    html += '</tbody></table></div>';
                    el.innerHTML = html;
                }


                function renderGmmDetailPegawai(el, data) {
                    const r = data.pegawai;
                    if (!r) {
                        el.innerHTML = '<div class="alert alert-warning">Data pegawai tidak ditemukan.</div>';
                        return;
                    }
                    const [bg, tc] = getAreaStyle(r.area);
                    const an = areaNames[String(r.area || '').trim()] || r.area || '';
                    let html = `<button class="btn btn-ghost mb-3" onclick="document.querySelector('[data-gmm-view=pegawai]').click()">⬅️ Kembali</button>`;
                    html += `<div class="panel p-4 mb-4" style="border-left:6px solid ${tc};background:${bg}30;">
                        <h3 class="rajdhani fw-bold mb-1" style="color:#1e293b;">${r.nama || '-'}</h3>
                        <div style="font-size:.85rem;color:#475569;font-weight:600;margin-bottom:8px;">NIP: ${r.nip} · ${r.posisi || 'Pegawai'}</div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span style="background:rgba(0,0,0,0.07);padding:3px 12px;border-radius:8px;font-size:.78rem;font-weight:700;color:#334155;">${r.unit || '-'}</span>
                            <span style="background:${bg};color:${tc};padding:3px 12px;border-radius:8px;font-size:.78rem;font-weight:800;">${an}</span>
                        </div>
                    </div>`;
                    // LIVIN
                    html += '<div class="panel p-4 mb-3"><h5 class="rajdhani fw-bold mb-3" style="color:#2563eb;border-bottom:2px solid #dbeafe;padding-bottom:8px;">📱 LIVIN</h5><div class="row g-3">';
                    [{
                        l: 'End Balance',
                        v: r.end_balance,
                        f: fmtRp
                    }, {
                        l: 'CIF Akuisisi',
                        v: r.cif_akuisisi,
                        f: fmtNum
                    }, {
                        l: 'CIF Setor',
                        v: r.cif_setor,
                        f: fmtNum
                    }, {
                        l: 'Rata-rata',
                        v: r.rata_rata,
                        f: fmtRp
                    }, {
                        l: 'CIF Transaksi',
                        v: r.cif_sudah_transaksi,
                        f: fmtNum
                    }, {
                        l: 'Frek CIF',
                        v: r.frek_dari_cif_akuisisi,
                        f: fmtNum
                    }].forEach(c => {
                        html += `<div class="col-6 col-md-4 col-lg-2"><div class="mini-stat"><div class="label">${c.l}</div><div class="value" style="font-size:1.3rem;">${c.f(c.v||0)}</div></div></div>`;
                    });
                    html += '</div></div>';
                    // MERCHANT
                    html += '<div class="panel p-4 mb-3"><h5 class="rajdhani fw-bold mb-3" style="color:#7c3aed;border-bottom:2px solid #f3e8ff;padding-bottom:8px;">🏪 MERCHANT</h5><div class="row g-3">';
                    [{
                        l: 'Referral EDC',
                        v: r.total_referral_edc,
                        f: fmtNum
                    }, {
                        l: 'Referral LIVIN',
                        v: r.total_referral_livin,
                        f: fmtNum
                    }].forEach(c => {
                        html += `<div class="col-6 col-md-3"><div class="mini-stat"><div class="label">${c.l}</div><div class="value" style="font-size:1.3rem;">${c.f(c.v||0)}</div></div></div>`;
                    });
                    html += '</div></div>';
                    // TRANSAKSI
                    html += '<div class="panel p-4"><h5 class="rajdhani fw-bold mb-3" style="color:#0891b2;border-bottom:2px solid #cffafe;padding-bottom:8px;">💳 TRANSAKSI</h5><div class="row g-3">';
                    [{
                        l: '% On Us',
                        v: r.pct_on_us,
                        f: fmtPct
                    }, {
                        l: 'Total Poin',
                        v: r.total_poin_transaksi,
                        f: fmtNum
                    }, {
                        l: 'Poin On Us',
                        v: r.poin_on_us,
                        f: fmtNum
                    }, {
                        l: 'Poin Off Us',
                        v: r.poin_off_us,
                        f: fmtNum
                    }, {
                        l: 'Frek On Us',
                        v: r.frek_on_us,
                        f: fmtNum
                    }, {
                        l: 'Frek Off Us',
                        v: r.frek_off_us,
                        f: fmtNum
                    }].forEach(c => {
                        html += `<div class="col-6 col-md-4 col-lg-2"><div class="mini-stat"><div class="label">${c.l}</div><div class="value" style="font-size:1.3rem;">${c.f(c.v||0)}</div></div></div>`;
                    });
                    html += '</div></div>';
                    el.innerHTML = html;
                }


                function renderGmmDetailCabang(el, data) {
                    const r = data.cabang;
                    if (!r) {
                        el.innerHTML = '<div class="alert alert-warning">Data cabang tidak ditemukan.</div>';
                        return;
                    }
                    const [bg, tc] = getAreaStyle(r.area);
                    let html = `<button class="btn btn-ghost mb-3" onclick="document.querySelector('[data-gmm-view=cabang]').click()">⬅️ Kembali</button>`;
                    html += `<div class="panel p-4 mb-3" style="border-top:6px solid ${bg!=='#F1F5F9'?bg:'var(--f1-red)'};">
                    <h3 class="rajdhani fw-bold" style="color:${bg!=='#F1F5F9'?bg:'var(--f1-red)'};">${r.unit||r.kode_cabang}</h3>
                    <div class="text-secondary fw-semibold">Kode: ${r.kode_cabang} • Kelas: ${r.kelas_cabang||'-'} • Pegawai: ${r.jml||0}</div></div>`;
                    html += '<div class="row g-3">';
                    [{
                        l: 'End Balance',
                        v: r.sum_eb,
                        f: fmtRp
                    }, {
                        l: 'CIF Akuisisi',
                        v: r.sum_ca,
                        f: fmtNum
                    }, {
                        l: 'Referral EDC',
                        v: r.sum_re,
                        f: fmtNum
                    }, {
                        l: 'Referral LVM',
                        v: r.sum_rl,
                        f: fmtNum
                    }, {
                        l: 'Total Poin',
                        v: r.sum_tp,
                        f: fmtNum
                    }, {
                        l: 'Trx On Us',
                        v: r.sum_fo,
                        f: fmtNum
                    }, {
                        l: 'Trx Off Us',
                        v: r.sum_ff,
                        f: fmtNum
                    }].forEach(c => {
                        html += `<div class="col-md-3"><div class="mini-stat"><div class="label">${c.l}</div><div class="value">${c.f(c.v)}</div></div></div>`;
                    });
                    html += '</div>';
                    el.innerHTML = html;
                }


                // Search handler
                window.gmmSearch = (q) => {
                    gmmState.search = q;
                    gmmState.view = 'search';
                    loadGmm();
                };
                window.gmmDetail = (type, id) => {
                    if (type === 'cabang') {
                        document.querySelectorAll('[data-gmm-view]').forEach(b => b.classList.remove('active'));
                        const btnPegawai = document.querySelector('[data-gmm-view=pegawai]');
                        if (btnPegawai) btnPegawai.classList.add('active');
                        gmmState.view = 'pegawai';
                        gmmState.filter = id;
                        gmmState.page = 1;
                        loadGmm();
                        return;
                    }
                    gmmState.view = 'detail_pegawai';
                    gmmState.search = '';
                    const content = document.getElementById('gmmContent');
                    content.innerHTML = '<div class="empty-state"><div class="spinner-border text-danger"></div></div>';
                    const params = new URLSearchParams({
                        action: 'gmm_data',
                        view: gmmState.view,
                        nip: id
                    });
                    fetch(`${apiBase}?${params}`).then(r => r.json()).then(d => {
                        if (d.ok) renderGmm(d);
                        else content.innerHTML = '<div class="alert alert-danger gmm-fade-in">' + d.message + '</div>';
                    });
                };
                window.gmmPage = (p) => {
                    gmmState.page = p;
                    loadGmm();
                };
                window.gmmSort = (col) => {
                    gmmState.sortCol = (gmmState.sortCol === col) ? '' : col;
                    gmmState.page = 1;
                    loadGmm();
                };


                // Override search tab to show search box
                const origLoad = loadGmm;
                const realLoad = async function() {
                    if (gmmState.view === 'search' && !gmmState.search) {
                        document.getElementById('gmmKatTabs').style.display = 'none';
                        document.getElementById('gmmContent').innerHTML = `<h4 class="rajdhani fw-bold mb-3">🔍 Pencarian</h4>
                    <div class="d-flex gap-2 mb-3" style="max-width:500px;"><input type="text" class="form-control" id="gmmSearchInput" placeholder="Ketik Nama, NIP, atau Unit..." onkeypress="if(event.key==='Enter')gmmSearch(this.value)">
                    <button class="btn btn-f1" onclick="gmmSearch(document.getElementById('gmmSearchInput').value)"><i class="bi bi-search"></i></button></div>`;
                        return;
                    }
                    await origLoad.call(this);
                };
                loadGmm = realLoad;
                loadGmm();
            });
        </script>


    <?php elseif ($isAdminPage): ?>
        <header class="top-shell">
            <div class="container-fluid px-3 px-md-5 py-3">
                <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="brand-mark"><i class="bi bi-speedometer2 fs-4"></i></div>
                        <div>
                            <div class="redline mb-2"></div>
                            <h3 class="rajdhani fw-bold mb-0">Performance Cabang</h3>
                            <div class="text-secondary fw-semibold small">Daily Balance Dashboard</div>
                        </div>
                    </div>
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                        <div class="text-lg-end small text-secondary">
                            <div class="fw-bold text-dark"><?= e($cachedSourceLabel) ?></div>
                            <div><?= e($cachedGeneratedLabel) ?></div>
                        </div>
                        <div class="top-actions">
                            <span class="user-pill text-dark">
                                <i class="bi bi-person-circle text-danger"></i>
                                <?= e($currentUser['name'] ?? '') ?> | <?= e($currentUser['role'] ?? '') ?>
                            </span>
                            <a class="btn btn-ghost" href="<?= e(currentPageUrl(['page' => 'hub', 'edit_user' => null])) ?>"><i class="bi bi-house me-1"></i> Menu</a>
                            <a class="btn btn-ghost" href="<?= e(currentPageUrl(['page' => 'dashboard', 'edit_user' => null])) ?>"><i class="bi bi-graph-up-arrow me-1"></i> Dashboard</a>
                            <form method="post" class="m-0">
                                <input type="hidden" name="form_action" value="logout">
                                <button class="btn btn-ghost" type="submit"><i class="bi bi-box-arrow-right me-1 text-danger"></i> Logout</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>


        <main id="appAdmin" class="container-fluid px-3 px-md-5 py-4">
            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= e($flash['tone'] === 'danger' ? 'danger' : ($flash['tone'] === 'success' ? 'success' : 'warning')) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <div class="row g-3 mb-3">
                <div class="col-md-6 col-xl-3">
                    <div class="mini-stat">
                        <div class="label">Total User</div>
                        <div class="value"><?= e((string) count($adminUsers)) ?></div>
                        <div class="sub">Admin dan visitor di tabel user.</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="mini-stat">
                        <div class="label"><?= e($adminMonitoringInsights['cohort_label']) ?> Dipantau</div>
                        <div class="value"><?= e((string) ($adminMonitoringInsights['cohort_count'] ?? 0)) ?></div>
                        <div class="sub">Cohort utama untuk membaca intensitas monitoring.</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="mini-stat">
                        <div class="label">Monitoring Hari Ini</div>
                        <div class="value"><?= e((string) ($adminMonitoringInsights['active_today_count'] ?? 0)) ?></div>
                        <div class="sub">User cohort yang login atau melihat dashboard hari ini.</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="mini-stat">
                        <div class="label">View Hari Ini</div>
                        <div class="value"><?= e((string) ($adminMonitoringInsights['total_view_today'] ?? 0)) ?></div>
                        <div class="sub">Total pembukaan data dashboard oleh cohort hari ini.</div>
                    </div>
                </div>
            </div>

            <div class="panel insight-panel p-3 p-md-4 mb-3">
                <div class="d-flex flex-column flex-xl-row justify-content-between gap-3 mb-4">
                    <div>
                        <div class="form-label mb-1">Monitoring Insight</div>
                        <h4 class="rajdhani fw-bold mb-1">Pola Aktivitas <?= e($adminMonitoringInsights['cohort_label'] ?? 'User') ?></h4>
                        <div class="text-secondary small">Fokus panel ini adalah membaca kapan mereka login, seberapa sering membuka dashboard, dan siapa yang belum aktif.</div>
                    </div>
                    <div class="soft-pill align-self-start">
                        <i class="bi bi-database-check text-danger"></i>
                        Cache: <?= e($cachedDashboard['source_file'] ?? 'Belum ada cache') ?>
                    </div>
                </div>
                <div class="insight-grid mb-4">
                    <div class="insight-card">
                        <div class="kicker">Peak Login Hour</div>
                        <div class="headline"><?= e((string) ($adminMonitoringInsights['peak_hour_label'] ?? '-')) ?></div>
                        <div class="caption"><?= e((string) ($adminMonitoringInsights['peak_hour_count'] ?? 0)) ?> login tercatat pada jam tersibuk.</div>
                    </div>
                    <div class="insight-card">
                        <div class="kicker">Login Hari Ini</div>
                        <div class="headline"><?= e((string) ($adminMonitoringInsights['total_login_today'] ?? 0)) ?></div>
                        <div class="caption">Rata-rata view per user cohort: <?= e((string) ($adminMonitoringInsights['avg_view_per_user'] ?? 0)) ?>.</div>
                    </div>
                    <div class="insight-card">
                        <div class="kicker">Paling Aktif</div>
                        <div class="headline" style="font-size:1.25rem;line-height:1.1;"><?= e((string) (($adminMonitoringInsights['most_active_manager']['name'] ?? 'Belum ada data'))) ?></div>
                        <div class="caption"><?= e((string) (($adminMonitoringInsights['most_active_manager']['branch_name'] ?? '-'))) ?><?php if (!empty($adminMonitoringInsights['most_active_manager']['score'])): ?> · skor <?= e((string) $adminMonitoringInsights['most_active_manager']['score']) ?><?php endif; ?></div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-xl-4">
                        <div class="chart-panel">
                            <div class="chart-title">Perlu Di-follow Up</div>
                            <div class="chart-subtitle">Belum ada aktivitas 7 hari terakhir.</div>
                            <div class="attention-list">
                                <?php if (!empty($adminMonitoringInsights['inactive_over_7_days'])): ?>
                                    <?php foreach ($adminMonitoringInsights['inactive_over_7_days'] as $inactiveUser): ?>
                                        <div class="attention-item">
                                            <div>
                                                <div class="name"><?= e((string) ($inactiveUser['name'] ?? '-')) ?></div>
                                                <div class="meta"><?= e((string) ($inactiveUser['branch_name'] ?? '-')) ?></div>
                                            </div>
                                            <span class="soft-pill text-danger bg-danger bg-opacity-10">Perlu follow up</span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="attention-item">
                                        <div>
                                            <div class="name">Semua user cohort aktif</div>
                                            <div class="meta">Tidak ada user yang kosong aktivitas selama 7 hari.</div>
                                        </div>
                                        <span class="soft-pill text-success bg-success bg-opacity-10">Sehat</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="chart-title mt-4">Top Monitoring Cepat</div>
                            <div class="chart-subtitle">Skor gabungan dari login, view, konsistensi, dan aktivitas hari ini.</div>
                            <div class="attention-list">
                                <?php foreach (array_slice($adminMonitoringInsights['top_managers'] ?? [], 0, 3) as $topRow): ?>
                                    <div class="attention-item">
                                        <div>
                                            <div class="name"><?= e((string) ($topRow['name'] ?? '-')) ?></div>
                                            <div class="meta"><?= e((string) ($topRow['branch_name'] ?? '-')) ?></div>
                                        </div>
                                        <span class="metric-pill">Skor <?= e((string) ($topRow['monitoring_score'] ?? 0)) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-8">
                        <div class="row g-3 h-100">
                            <div class="col-lg-7">
                                <div class="chart-panel h-100">
                                    <div class="chart-title">Login per Jam</div>
                                    <div class="chart-subtitle">Menunjukkan kapan <?= e(strtolower((string) ($adminMonitoringInsights['cohort_label'] ?? 'user'))) ?> paling sering masuk.</div>
                                    <div id="adminHourlyLoginChart" class="chart-target"></div>
                                </div>
                            </div>
                            <div class="col-lg-5">
                                <div class="chart-panel h-100">
                                    <div class="chart-title">Komposisi Aktivitas</div>
                                    <div class="chart-subtitle">Perbandingan login, view, dan navigasi halaman.</div>
                                    <div id="adminEventMixChart" class="chart-target"></div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="chart-panel">
                                    <div class="chart-title">Tren 7 Hari</div>
                                    <div class="chart-subtitle">Membandingkan intensitas login dan pembukaan dashboard dari hari ke hari.</div>
                                    <div id="adminDailyActivityChart" class="chart-target"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="row g-3 mb-3">
                <div class="col-xl-5">
                    <div class="panel p-3 p-md-4 h-100">
                        <div class="form-label mb-2">Upload & Cache</div>
                        <h4 class="rajdhani fw-bold mb-3">Kelola Data Excel</h4>

                        <!-- Upload Tabs (FIX: hapus nav-fill, tambah scroll horizontal) -->
                        <ul class="nav nav-pills mb-3 flex-nowrap overflow-auto" id="uploadTypeTabs">
                            <li class="nav-item"><button class="nav-link active small" data-upload-tab="dana" type="button">📊 Dana</button></li>
                            <li class="nav-item"><button class="nav-link small" data-upload-tab="kredit" type="button">💳 Kredit</button></li>
                            <li class="nav-item"><button class="nav-link small" data-upload-tab="labarugi" type="button">📈 Laba Rugi</button></li>
                            <li class="nav-item"><button class="nav-link small" data-upload-tab="gmm" type="button">🏆 GMM</button></li>
                            <li class="nav-item"><button class="nav-link small" data-upload-tab="marketshare" type="button">🥧 Market Share</button></li>
                        </ul>

                        <!-- Dana Upload -->
                        <div class="upload-tab-content" data-upload-content="dana">
                            <form class="upload-zone p-4 mb-3" id="adminUploadForm">
                                <label class="form-label" for="adminExcelFile">Workbook Dana .xlsx (Max: 10 MB)</label>
                                <div class="d-flex flex-column flex-sm-row gap-2 mt-2">
                                    <input class="form-control" id="adminExcelFile" name="excel_file" type="file" accept=".xlsx" required>
                                    <button class="btn btn-f1 flex-shrink-0" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i> Upload</button>
                                </div>
                            </form>
                        </div>

                        <!-- Kredit Upload -->
                        <div class="upload-tab-content" data-upload-content="kredit" style="display:none;">
                            <form class="upload-zone p-4 mb-3" id="adminKreditUploadForm">
                                <label class="form-label" for="adminKreditFile">Workbook Kredit .xlsx (Max: 10 MB)</label>
                                <div class="d-flex flex-column flex-sm-row gap-2 mt-2">
                                    <input class="form-control" id="adminKreditFile" name="excel_file" type="file" accept=".xlsx" required>
                                    <button class="btn btn-f1 flex-shrink-0" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i> Upload</button>
                                </div>
                            </form>
                        </div>

                        <!-- GMM Upload -->
                        <div class="upload-tab-content" data-upload-content="gmm" style="display:none;">
                            <form class="upload-zone p-4 mb-3" id="adminGmmUploadForm">
                                <label class="form-label" for="adminGmmFile">File GMM .xlsx</label>
                                <div class="d-flex flex-column gap-2 mt-2">
                                    <div class="d-flex gap-3 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="gmm_upload_type" id="gmmCurrent" value="current" checked>
                                            <label class="form-check-label fw-semibold" for="gmmCurrent">Current (Hari ini)</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="gmm_upload_type" id="gmmBaseline" value="baseline">
                                            <label class="form-check-label fw-semibold" for="gmmBaseline">Baseline (Awal)</label>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column flex-sm-row gap-2">
                                        <input class="form-control" id="adminGmmFile" name="gmm_file" type="file" accept=".xlsx" required>
                                        <button class="btn btn-f1 flex-shrink-0" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i> Upload GMM</button>
                                    </div>
                                </div>
                            </form>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                                <div>
                                    <div class="fw-bold text-danger small">Reset Database GMM</div>
                                    <div class="small text-secondary">Hapus seluruh data GMM (User aman).</div>
                                </div>
                                <button class="btn btn-outline-danger btn-sm" id="btnResetGmm"><i class="bi bi-trash3-fill"></i> Reset GMM</button>
                            </div>
                        </div>

                        <!-- Laba Rugi Upload -->
                        <div class="upload-tab-content" data-upload-content="labarugi" style="display:none;">
                            <form class="upload-zone p-4 mb-3" id="adminLabaRugiUploadForm">
                                <label class="form-label" for="adminLabaRugiFile">Workbook Laba Rugi .xlsx (Max: 60 MB)</label>
                                <div class="small text-secondary mb-2">
                                    File harus mengandung sheet: Revenue, NII, FBI, Cost, OHC, CM, Net Income, AvgBal Kredit, AvgBal DPK, NPL, dst.
                                </div>
                                <div class="d-flex flex-column flex-sm-row gap-2 mt-2">
                                    <input class="form-control" id="adminLabaRugiFile" name="excel_file" type="file" accept=".xlsx" required>
                                    <button class="btn btn-f1 flex-shrink-0" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i> Upload</button>
                                </div>
                            </form>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                                <div>
                                    <div class="fw-bold text-danger small">Reset Cache Laba Rugi</div>
                                    <div class="small text-secondary">Hapus seluruh data Laba Rugi.</div>
                                </div>
                                <button class="btn btn-outline-danger btn-sm" id="btnDeleteLabaRugiCache"><i class="bi bi-trash3-fill"></i> Hapus Laba Rugi</button>
                            </div>
                        </div>

                        <!-- Market Share Upload (FIX: dipindah keluar, sekarang sibling labarugi) -->
                        <div class="upload-tab-content" data-upload-content="marketshare" style="display:none;">
                            <form class="upload-zone p-4 mb-3" id="adminMarketShareUploadForm">
                                <label class="form-label" for="adminMarketShareFile">Workbook Market Share .xlsx (Max: 60 MB)</label>
                                <div class="small text-secondary mb-2">
                                    Sheet UPPERCASE: BMRI_TABUNGAN, MARKET_TABUNGAN, BMRI_GIRO, dst.<br>
                                    Kolom wajib: Kode, Nama, Nama Provinsi, Nama Pulau, Nama Area, Nama Kabupaten, LatLong + kolom tanggal.
                                </div>
                                <div class="d-flex flex-column flex-sm-row gap-2 mt-2">
                                    <input class="form-control" id="adminMarketShareFile" name="excel_file" type="file" accept=".xlsx" required>
                                    <button class="btn btn-f1 flex-shrink-0" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i> Upload</button>
                                </div>
                            </form>
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                                <div>
                                    <div class="fw-bold text-danger small">Reset Cache Market Share</div>
                                    <div class="small text-secondary">Hapus seluruh data Market Share.</div>
                                </div>
                                <button class="btn btn-outline-danger btn-sm" id="btnDeleteMarketShareCache"><i class="bi bi-trash3-fill"></i> Hapus Market Share</button>
                            </div>
                        </div>

                        <div class="status-strip p-3 mb-3" id="adminUploadStatus">
                            <div class="d-flex align-items-start gap-3">
                                <i class="bi bi-info-circle text-danger fs-4"></i>
                                <div>
                                    <div class="fw-bold" id="adminStatusTitle">Panel Upload</div>
                                    <div class="text-secondary small" id="adminStatusText">Upload workbook dari halaman admin untuk memperbarui data performance.</div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                            <div>
                                <div class="fw-bold text-danger">Reset Financial Data</div>
                                <div class="small text-secondary">Hapus file Excel cache (Data User aman).</div>
                            </div>
                            <button class="btn btn-outline-danger btn-sm" id="btnDeleteCache">
                                <i class="bi bi-trash3-fill"></i> Hapus Excel
                            </button>
                        </div>


                        <!-- Update Dates Section -->
                        <div class="mt-4 pt-3 border-top">
                            <div class="form-label mb-2">Tanggal Update Data</div>
                            <form id="updateDatesForm" class="row g-2 align-items-end">
                                <div class="col-4"><label class="form-label small">Dana</label><input type="date" class="form-control form-control-sm" id="dateDana" value="<?= e($updateDates['produk_dana'] ?? '') ?>"></div>
                                <div class="col-4"><label class="form-label small">Kredit</label><input type="date" class="form-control form-control-sm" id="dateKredit" value="<?= e($updateDates['produk_kredit'] ?? '') ?>"></div>
                                <div class="col-4"><label class="form-label small">GMM</label><input type="date" class="form-control form-control-sm" id="dateGmm" value="<?= e($updateDates['gmm'] ?? '') ?>"></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-ghost w-100 mt-1"><i class="bi bi-floppy me-1"></i>Simpan Tanggal</button></div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-xl-7">
                    <div class="panel p-3 p-md-4 h-100">
                        <div class="form-label mb-2">Form User</div>
                        <form method="post" class="row g-3">
                            <?php
                            $jabatanList = ['Area Head', 'Branch Manager', 'Segment Manager', 'Segment Head', 'Officer'];
                            $cabangList = [
                                '11 - Regional Office Bali Nusra',
                                '145 - Area Denpasar',
                                '161 - Area Mataram',
                                '175 - Area Kuta',
                                '181 - Area Kupang',
                                '14500 - KC Denpasar Veteran',
                                '14501 - KCP Denpasar Gajah Mada',
                                '14502 - KCP Denpasar Udayana',
                                '14503 - KCP Denpasar Teuku Umar',
                                '14510 - KCP Ubud',
                                '14511 - KCP Singaraja',
                                '14516 - KCP Pelabuhan Benoa',
                                '14517 - KCP Denpasar Renon',
                                '14518 - KCP Denpasar Pasar Kumbasari',
                                '14519 - KCP Denpasar Gatot Subroto',
                                '14520 - KCP Gianyar Celuk',
                                '14521 - KCP Singaraja Seririt',
                                '14523 - KCP Gianyar Ngurah Rai',
                                '14525 - KCP Nusa Penida',
                                '14528 - KCP Amlapura',
                                '14531 - KCP Denpasar Sanur',
                                '14532 - KCP Denpasar Klungkung',
                                '14534 - KCP Denpasar Imam Bonjol',
                                '14536 - KCP Denpasar Sesetan Raya',
                                '14537 - KCP Denpasar WR Supratman',
                                '14539 - KCP Denpasar Mahendradata',
                                '14540 - KCP Denpasar Gatot Subroto Barat',
                                '14580 - KCP Denpasar Pemogan',
                                '14581 - KCP Rendang',
                                '14588 - KCP Bangli',
                                '14589 - KCP Padang Bai',
                                '16100 - KC Mataram AA Gde Ngurah',
                                '16101 - KCP Mataram Cakranegara',
                                '16102 - KCP Sumbawa Besar',
                                '16109 - KCP Bertais',
                                '16110 - KCP Praya',
                                '16111 - KCP Selong',
                                '16113 - KCP Bima',
                                '16114 - KCP Sumbawa Batu Hijau',
                                '16115 - KCP Mataram Ampenan',
                                '16117 - KCP Mataram Sriwijaya',
                                '16120 - KCP Lombok Senggigi',
                                '16121 - KCP Gili Trawangan',
                                '16122 - KCP Universitas Mataram',
                                '16123 - KCP Maluk',
                                '16158 - KCP Bima Raba',
                                '16159 - KCP Bima Sila',
                                '16161 - KCP Praya Penujak',
                                '16162 - KCP Lombok Keruak',
                                '16163 - KCP Sumbawa Alas',
                                '16164 - KCP Bima Sape',
                                '16171 - KCP Tente',
                                '16172 - KCP Dompu',
                                '16173 - KCP Lombok Gunung Sari',
                                '16175 - KCP Lombok Gerung',
                                '16176 - KCP Lombok Narmada',
                                '16177 - KCP Lombok Pemenang',
                                '16178 - KCP Lombok Kopang',
                                '16179 - KCP Aikmel',
                                '16180 - KCP Mataram Tanjung',
                                '16181 - KCP Lombok Kediri',
                                '16182 - KCP Mataram Masbagik',
                                '16183 - KCP Lombok Sakra',
                                '16184 - KCP Lombok Terara',
                                '16185 - KCP Rembiga',
                                '16186 - KCP Renteng Praya',
                                '16191 - KCP Pringgabaya',
                                '16192 - KCP Taliwang',
                                '16194 - KCP Lombok Sikur',
                                '16196 - KCP Labuhan Lombok',
                                '16198 - KCP Mataram Airlangga',
                                '16199 - KCP Mataram Pagutan',
                                '17500 - KC Kuta Raya',
                                '17501 - KCP Nusa Dua',
                                '17502 - KCP Legian',
                                '17503 - KCP Denpasar Dalung',
                                '17504 - KCP Badung Ungasan',
                                '17505 - KCP Jimbaran',
                                '17506 - KCP Kerobokan',
                                '17507 - KCP Kuta Bypass Ngurah Rai',
                                '17508 - KCP Kuta Bintang',
                                '17509 - KCP Bandara Ngurah Rai',
                                '17510 - KCP Tabanan Kediri',
                                '17511 - KCP Tabanan Kota',
                                '17512 - KCP Jembrana',
                                '17513 - KCP Badung Sempidi',
                                '17514 - KCP Canggu Berawa',
                                '17515 - KCP Kuta Dewi Sri',
                                '17550 - KCP Canggu',
                                '17551 - KCP Badung Kapal',
                                '17553 - KCP Bajera',
                                '17555 - KCP Badung Mambal',
                                '17556 - KCP Baturiti',
                                '18100 - KC Kupang Urip Sumoharjo',
                                '18101 - KCP Kupang M. Hatta',
                                '18102 - KC Atambua',
                                '18103 - KCP Maumere',
                                '18104 - KCP Ruteng',
                                '18105 - KCP Labuan Bajo',
                                '18106 - KCP Ende',
                                '18107 - KCP Waingapu',
                                '18108 - KCP Larantuka',
                                '18109 - KCP Malaka',
                                '18110 - KCP Alor',
                                '18150 - KCP Bajawa',
                                '18153 - KCP Kupang Perintis Kemerdekaan',
                                '18154 - KCP Soe',
                                '18155 - KCP Kefamenanu',
                                '18157 - KCP Kupang Timor Raya',
                                '18158 - KCP Tambolaka Waitabula',
                                '18159 - KCP Rote Ndao'
                            ];


                            $currJabatan = trim((string)($editingUser['jabatan'] ?? ''));
                            $currBranchId = trim((string)($editingUser['branch_id'] ?? ''));
                            ?>
                            <input type="hidden" name="form_action" value="save_user">
                            <input type="hidden" name="editing_id" value="<?= e($editingUser['id'] ?? '') ?>">


                            <div class="col-md-4">
                                <label class="form-label" for="userIdInput">System ID</label>
                                <input class="form-control" id="userIdInput" name="user_id" value="<?= e($editingUser['id'] ?? '') ?>" placeholder="Unik (misal 14500)" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="nipInput">NIP (Utk Login)</label>
                                <input class="form-control" id="nipInput" name="nip" type="text" pattern="\d{10}" minlength="10" maxlength="10" title="NIP wajib 10 digit angka" value="<?= e($editingUser['nip'] ?? $editingUser['id'] ?? '') ?>" placeholder="NIP 10 Angka" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="userRoleInput">Role</label>
                                <select class="form-select" id="userRoleInput" name="role">
                                    <option value="Admin" <?= (($editingUser['role'] ?? 'Visitor') === 'Admin') ? 'selected' : '' ?>>Admin</option>
                                    <option value="Visitor" <?= (($editingUser['role'] ?? 'Visitor') === 'Visitor') ? 'selected' : '' ?>>Visitor</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="userNameInput">Nama Lengkap</label>
                                <input class="form-control" id="userNameInput" name="name" value="<?= e($editingUser['name'] ?? '') ?>" placeholder="Nama lengkap user" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="jabatanInput">Jabatan</label>
                                <select class="form-select" id="jabatanInput" name="jabatan" required>
                                    <option value="" <?= empty($currJabatan) ? 'selected' : '' ?> disabled>-- Pilih Jabatan --</option>
                                    <?php foreach ($jabatanList as $j): ?>
                                        <option value="<?= e($j) ?>" <?= $currJabatan === $j ? 'selected' : '' ?>><?= e($j) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label" for="branchComboInput">Kode & Nama Cabang</label>
                                <select class="form-select" id="branchComboInput" name="branch_combo" required>
                                    <option value="" <?= empty($currBranchId) ? 'selected' : '' ?> disabled>-- Pilih Regional / Area / Cabang --</option>
                                    <?php foreach ($cabangList as $b): ?>
                                        <?php $bId = explode(' - ', $b)[0]; ?>
                                        <option value="<?= e($b) ?>" <?= $currBranchId === $bId ? 'selected' : '' ?>><?= e($b) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="statusInput">Status</label>
                                <select class="form-select" id="statusInput" name="status">
                                    <option value="Active" <?= (($editingUser['status'] ?? 'Active') === 'Active') ? 'selected' : '' ?>>Active</option>
                                    <option value="Inactive" <?= (($editingUser['status'] ?? '') === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="passwordInput">Password</label>
                                <input class="form-control" id="passwordInput" name="password" type="password" placeholder="<?= $editingUser !== null ? 'Kosongkan jika tak ingin ganti' : 'Default = NIP' ?>">
                            </div>
                            <div class="col-12 d-flex flex-wrap gap-2 mt-4 pt-2">
                                <button class="btn btn-f1" type="submit"><i class="bi bi-floppy me-1"></i> Simpan User</button>
                                <a class="btn btn-ghost" href="<?= e(currentPageUrl(['page' => 'admin', 'edit_user' => null])) ?>">Reset Form</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>


            <div class="panel p-3 p-md-4 mb-3">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                    <div>
                        <div class="form-label mb-1">Tabel User</div>
                        <h4 class="rajdhani fw-bold mb-0">Admin, Visitor, dan Status Monitoring</h4>
                    </div>
                    <div class="soft-pill">Last update: <?= e($cachedGeneratedLabel) ?></div>
                </div>
                <div class="table-responsive admin-table-wrap">
                    <table class="table table-hover admin-table align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>NIP / ID</th>
                                <th>Nama & Jabatan</th>
                                <th>Role</th>
                                <th>Unit Cabang</th>
                                <th>Status</th>
                                <th>Aktivitas Terakhir</th>
                                <th>Ringkasan Monitoring</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adminUsers as $userRow): ?>
                                <?php
                                $summaryRow = $adminSummaryIndex[(string) ($userRow['id'] ?? '')] ?? [];
                                $lastActivityRaw = (string) ($summaryRow['last_view'] ?? $summaryRow['last_login'] ?? '');
                                $lastActivityLabel = formatAdminTimestamp($lastActivityRaw);
                                $viewCount = (int) ($summaryRow['view_count'] ?? 0);
                                $loginCount = (int) ($summaryRow['login_count'] ?? 0);
                                $isManager = stripos((string) ($userRow['jabatan'] ?? ''), 'branch manager') !== false;
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= e($userRow['nip'] ?? $userRow['id'] ?? '') ?></div>
                                        <div class="small text-secondary"><?= e((string) ($userRow['id'] ?? '')) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($userRow['name'] ?? '') ?></div>
                                        <div class="small text-secondary"><?= e($userRow['jabatan'] ?? '-') ?></div>
                                        <?php if ($isManager): ?>
                                            <div class="metric-pill mt-2">Fokus Monitoring</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill <?= strtolower((string) ($userRow['role'] ?? '')) === 'admin' ? 'bg-danger text-white' : 'bg-primary bg-opacity-10 text-primary' ?>">
                                            <?= e($userRow['role'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?= e($userRow['branch_name'] ?? '-') ?></div>
                                        <div class="small text-secondary"><?= e($userRow['branch_id'] ?? '-') ?></div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill <?= strtolower((string) ($userRow['status'] ?? '')) === 'active' ? 'bg-success bg-opacity-10 text-success' : 'bg-secondary bg-opacity-10 text-secondary' ?>">
                                            <?= e($userRow['status'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($lastActivityLabel) ?></div>
                                        <div class="small text-secondary"><?= e(formatAdminEventLabel((string) ($summaryRow['last_event'] ?? ''))) ?></div>
                                    </td>
                                    <td style="min-width:210px;">
                                        <div class="d-flex gap-2 flex-wrap mb-2">
                                            <span class="soft-pill"><i class="bi bi-eye"></i><?= e((string) $viewCount) ?> view</span>
                                            <span class="soft-pill"><i class="bi bi-box-arrow-in-right"></i><?= e((string) $loginCount) ?> login</span>
                                        </div>
                                        <div class="small text-secondary mb-1">Histori monitoring yang terekam dari dashboard.</div>
                                        <div class="score-bar"><span style="width: <?= e((string) min(100, (($viewCount * 8) + ($loginCount * 12)))) ?>%;"></span></div>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2 flex-wrap">
                                            <a class="btn btn-sm btn-ghost" href="<?= e(currentPageUrl(['page' => 'admin', 'edit_user' => (string) ($userRow['id'] ?? '')])) ?>"><i class="bi bi-pencil-square"></i></a>
                                            <?php if (($userRow['id'] ?? '') !== ($currentUser['id'] ?? '')): ?>
                                                <form method="post" class="m-0" onsubmit="return confirm('Hapus user ini?');">
                                                    <input type="hidden" name="form_action" value="delete_user">
                                                    <input type="hidden" name="delete_user_id" value="<?= e($userRow['id'] ?? '') ?>">
                                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>


            <div class="row g-3">
                <div class="col-xl-6">
                    <div class="panel p-3 p-md-4 h-100">
                        <div class="form-label mb-2">Tracking Ringkas</div>
                        <h4 class="rajdhani fw-bold mb-3">Top Monitoring <?= e($adminMonitoringInsights['cohort_label'] ?? 'User') ?></h4>
                        <div class="table-responsive admin-table-wrap">
                            <table class="table table-hover" id="viewerSummaryTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Rank</th>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Unit</th>
                                        <th>Jabatan</th>
                                        <th>Hari Aktif</th>
                                        <th>View / Login</th>
                                        <th>Skor</th>
                                        <th>Last Activity</th>
                                        <th>Detail</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($adminMonitoringInsights['top_managers'] ?? []) as $rankIndex => $row): ?>
                                        <?php
                                        // Lookup user data for unit & jabatan
                                        $rowUserId = (string)($row['user_id'] ?? '');
                                        $rowUserData = null;
                                        foreach ($adminUsers as $u) {
                                            if ((string)($u['id'] ?? '') === $rowUserId) {
                                                $rowUserData = $u;
                                                break;
                                            }
                                        }
                                        $rowUnit = $rowUserData['branch_name'] ?? ($row['branch_name'] ?? '-');
                                        $rowJabatan = $rowUserData['jabatan'] ?? '-';
                                        // Format last_view: "2026-05-11T09:59:17+07:00" → "09:59 · 11 Mei 2026"
                                        $lastViewRaw = $row['last_activity'] ?? ($row['last_view'] ?? '');
                                        $lastViewFormatted = formatAdminTimestamp(is_string($lastViewRaw) ? $lastViewRaw : '');
                                        if ($lastViewRaw && $lastViewRaw !== '-') {
                                            $lvDate = new DateTime($lastViewRaw);
                                            $bulanId = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                                            $lastViewFormatted = $lvDate->format('H:i') . ' · ' . $lvDate->format('j') . ' ' . $bulanId[(int)$lvDate->format('n') - 1] . ' ' . $lvDate->format('Y');
                                        }
                                        ?>
                                        <tr>
                                            <td><span class="metric-pill">#<?= e((string) ($rankIndex + 1)) ?></span></td>
                                            <td>
                                                <div class="fw-semibold"><?= e($row['name'] ?? '') ?></div>
                                                <div class="small text-secondary"><?= e($rowUserId) ?></div>
                                            </td>
                                            <td><?= e($row['role'] ?? '') ?></td>
                                            <td class="small text-truncate" style="max-width:120px;" title="<?= e($rowUnit) ?>"><?= e($rowUnit) ?></td>
                                            <td class="small"><?= e($rowJabatan) ?></td>
                                            <td><span class="soft-pill"><?= e((string) ($row['active_days'] ?? 0)) ?> / 7 hari</span></td>
                                            <td>
                                                <div class="fw-bold text-danger"><?= e((string) ($row['view_count'] ?? 0)) ?> view</div>
                                                <div class="small text-secondary"><?= e((string) ($row['login_count'] ?? 0)) ?> login</div>
                                            </td>
                                            <td style="min-width:140px;">
                                                <div class="fw-bold text-dark"><?= e((string) ($row['monitoring_score'] ?? 0)) ?></div>
                                                <div class="score-bar mt-1"><span style="width: <?= e((string) min(100, (int) ($row['monitoring_score'] ?? 0))) ?>%;"></span></div>
                                            </td>
                                            <td class="small" style="white-space:nowrap;"><?= e($lastViewFormatted) ?></td>
                                            <td>
                                                <button class="btn btn-ghost btn-sm py-0 px-2"
                                                    style="font-size:.75rem;"
                                                    onclick="toggleUserDetail('<?= e($rowUserId) ?>')"
                                                    title="Lihat aktivitas">
                                                    <i class="bi bi-activity"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr id="detail-<?= e($rowUserId) ?>" data-viewer-detail-row="true" style="display:none;">
                                            <td colspan="10" style="padding:0;">
                                                <div style="background:rgba(241,245,249,0.8);border-top:1px solid rgba(229,231,235,.5);padding:12px 16px;">
                                                    <div class="fw-bold text-dark mb-2" style="font-size:.8rem;">
                                                        📋 Aktivitas <?= e($row['name'] ?? $rowUserId) ?>
                                                    </div>
                                                    <?php
                                                    // Filter events for this user
                                                    $userEvents = array_filter($adminEvents, fn($ev) => ($ev['user_id'] ?? '') === $rowUserId);
                                                    $userEvents = array_slice(array_values($userEvents), 0, 20);
                                                    if ($userEvents):
                                                    ?>
                                                        <div style="display:flex;flex-direction:column;gap:4px;max-height:200px;overflow-y:auto;">
                                                            <?php foreach ($userEvents as $ev):
                                                                $evTime = $ev['time'] ?? '';
                                                                $evFormatted = '-';
                                                                if ($evTime) {
                                                                    $evDate = new DateTime($evTime);
                                                                    $bulanId = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                                                                    $evFormatted = $evDate->format('H:i') . ' · ' . $evDate->format('j') . ' ' . $bulanId[(int)$evDate->format('n') - 1] . ' ' . $evDate->format('Y');
                                                                }
                                                                $evMeta = $ev['meta'] ?? [];
                                                                $evEvent = $ev['event'] ?? '';
                                                                // Build human-readable event description
                                                                $evDesc = $evEvent;
                                                                if ($evEvent === 'view_data') {
                                                                    $parts = [];
                                                                    if (!empty($evMeta['product'])) $parts[] = '📊 ' . $evMeta['product'];
                                                                    if (!empty($evMeta['period'])) $parts[] = $evMeta['period'];
                                                                    if (!empty($evMeta['id'])) $parts[] = '🏢 ' . $evMeta['id'];
                                                                    $evDesc = implode(' · ', $parts) ?: 'View Data';
                                                                } elseif ($evEvent === 'view_kredit') {
                                                                    $parts = [];
                                                                    if (!empty($evMeta['products'])) $parts[] = '💳 ' . implode(', ', array_slice(explode(',', $evMeta['products']), 0, 2));
                                                                    if (!empty($evMeta['period'])) $parts[] = $evMeta['period'];
                                                                    if (!empty($evMeta['id'])) $parts[] = '🏢 ' . $evMeta['id'];
                                                                    $evDesc = implode(' · ', $parts) ?: 'View Kredit';
                                                                } elseif ($evEvent === 'login') {
                                                                    $evDesc = '✅ Login';
                                                                    if (!empty($evMeta['source'])) $evDesc .= ' via ' . $evMeta['source'];
                                                                } elseif (str_starts_with($evEvent, 'page_')) {
                                                                    $pageName = strtoupper(str_replace('page_', '', $evEvent));
                                                                    $evDesc = "📄 Halaman $pageName";
                                                                }
                                                                $evColor = $evEvent === 'login' ? '#15803d' : ($evEvent === 'view_data' || $evEvent === 'view_kredit' ? '#2563eb' : '#64748b');
                                                            ?>
                                                                <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:6px;background:rgba(255,255,255,0.8);">
                                                                    <span style="font-size:.68rem;color:#94a3b8;white-space:nowrap;min-width:110px;"><?= e($evFormatted) ?></span>
                                                                    <span style="font-size:.75rem;font-weight:600;color:<?= $evColor ?>;flex:1;"><?= e($evDesc) ?></span>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div style="font-size:.8rem;color:#94a3b8;">Belum ada aktivitas tercatat.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="panel p-3 p-md-4 h-100">
                        <div class="form-label mb-2">Recent Activity</div>
                        <h4 class="rajdhani fw-bold mb-3">Jejak Aktivitas Terbaru</h4>
                        <!-- Filter bar -->
                        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                            <input type="date" class="form-control form-control-sm" id="actFilterFrom"
                                style="max-width:140px;" placeholder="Dari">
                            <input type="date" class="form-control form-control-sm" id="actFilterTo"
                                style="max-width:140px;" placeholder="Sampai">
                            <input type="text" class="form-control form-control-sm" id="actFilterUser"
                                style="max-width:160px;" placeholder="Cari nama/unit...">
                            <select class="form-select form-select-sm" id="actFilterEvent" style="max-width:160px;">
                                <option value="">Semua Event</option>
                                <option value="login">Login</option>
                                <option value="view_data">View Dana</option>
                                <option value="view_kredit">View Kredit</option>
                                <option value="page_">Halaman</option>
                            </select>
                            <button class="btn btn-ghost btn-sm" onclick="filterActivity()">
                                <i class="bi bi-funnel me-1"></i>Filter
                            </button>
                            <button class="btn btn-ghost btn-sm" onclick="resetActivity()">
                                <i class="bi bi-x me-1"></i>Reset
                            </button>
                        </div>
                        <div class="table-responsive admin-table-wrap" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-hover admin-table" id="activityLogTable">
                                <thead class="table-light" style="position: sticky; top: 0;">
                                    <tr>
                                        <th>Waktu</th>
                                        <th>User</th>
                                        <th>Event</th>
                                        <th>Info</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($adminEvents as $eventRow):
                                        $evTime = (string) ($eventRow['time'] ?? '');
                                        $evFormatted = formatAdminTimestamp($evTime);
                                        if ($evTime) {
                                            $evDate = new DateTime($evTime);
                                            $bulanId = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                                            $evFormatted = $evDate->format('H:i') . ' · ' . $evDate->format('j') . ' ' . $bulanId[(int)$evDate->format('n') - 1] . ' ' . $evDate->format('Y');
                                        }
                                        $evEvent = (string) ($eventRow['event'] ?? '');
                                        $evMeta = is_array($eventRow['meta'] ?? null) ? $eventRow['meta'] : [];
                                        $evBadgeColor = 'bg-light text-dark border';
                                        $evDesc = formatAdminEventDescription($evEvent, $evMeta);
                                        if ($evEvent === 'view_data') {
                                            $evBadgeColor = 'bg-primary bg-opacity-10 text-primary border-0';
                                            $parts = [];
                                            if (!empty($evMeta['product'])) $parts[] = '📊 ' . $evMeta['product'];
                                            if (!empty($evMeta['period'])) $parts[] = $evMeta['period'];
                                            if (!empty($evMeta['id'])) $parts[] = '🏢 ' . $evMeta['id'];
                                            $evDesc = implode(' · ', $parts);
                                        } elseif ($evEvent === 'view_kredit') {
                                            $evBadgeColor = 'bg-info bg-opacity-10 text-info border-0';
                                            $parts = [];
                                            if (!empty($evMeta['products'])) {
                                                $prods = array_slice(explode(',', $evMeta['products']), 0, 2);
                                                $parts[] = '💳 ' . implode(', ', $prods);
                                            }
                                            if (!empty($evMeta['period'])) $parts[] = $evMeta['period'];
                                            if (!empty($evMeta['id'])) $parts[] = '🏢 ' . $evMeta['id'];
                                            $evDesc = implode(' · ', $parts);
                                        } elseif ($evEvent === 'login') {
                                            $evBadgeColor = 'bg-success bg-opacity-10 text-success border-0';
                                            $evDesc = '✅ Login berhasil';
                                            if (!empty($evMeta['source'])) $evDesc .= ' (via ' . $evMeta['source'] . ')';
                                        } elseif (str_starts_with($evEvent, 'page_')) {
                                            $pageName = strtoupper(str_replace('page_', '', $evEvent));
                                            $evDesc = "📄 Buka halaman $pageName";
                                        }
                                    ?>
                                        <tr>
                                            <td class="small" style="white-space:nowrap;"><?= e($evFormatted) ?></td>
                                            <td>
                                                <div class="fw-semibold text-dark" style="font-size:.82rem;"><?= e($eventRow['name'] ?? '') ?></div>
                                                <div class="small text-secondary" style="font-size:.7rem;"><?= e($eventRow['user_id'] ?? '') ?></div>
                                            </td>
                                            <td><span class="badge <?= $evBadgeColor ?>" style="font-size:.72rem;"><?= e($evEvent) ?></span></td>
                                            <td class="small">
                                                <?php if ($evDesc): ?>
                                                    <span style="color:#334155;font-size:.78rem;"><?= e($evDesc) ?></span>
                                                <?php else: ?>
                                                    <span class="text-secondary" style="font-size:.72rem;"><?= e(json_encode($evMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    <?php elseif ($isDashboardPage): ?>
        <header class="top-shell">
            <div class="container-fluid px-3 px-md-5 py-3">
                <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="brand-mark"><i class="bi bi-speedometer2 fs-4"></i></div>
                        <div>
                            <div class="redline mb-2"></div>
                            <h3 class="rajdhani fw-bold mb-0">Performance Cabang</h3>
                            <div class="text-secondary fw-semibold small">Daily Balance Dashboard</div>
                        </div>
                    </div>
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3">
                        <div class="text-lg-end small text-secondary">
                            <div class="fw-bold text-dark" id="cacheSource"><?= e($cachedSourceLabel) ?></div>
                            <div id="cacheGenerated"><?= e($cachedGeneratedLabel) ?></div>
                        </div>
                        <div class="top-actions">
                            <span class="user-pill text-dark">
                                <i class="bi bi-person-circle text-danger"></i>
                                <?= e($currentUser['name'] ?? '') ?> | <?= e($currentUser['role'] ?? '') ?>
                            </span>
                            <a class="btn btn-ghost" href="<?= e(currentPageUrl(['page' => 'hub'])) ?>"><i class="bi bi-house me-1"></i> Menu</a>
                            <?php if (isAdmin($currentUser)): ?>
                                <a class="btn btn-ghost" href="<?= e(currentPageUrl(['page' => 'admin', 'edit_user' => null])) ?>"><i class="bi bi-sliders me-1"></i> Admin</a>
                            <?php endif; ?>
                            <form method="post" class="m-0">
                                <input type="hidden" name="form_action" value="logout">
                                <button class="btn btn-ghost" type="submit"><i class="bi bi-box-arrow-right me-1 text-danger"></i> Logout</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>


        <main id="appDashboard" class="container-fluid px-3 px-md-5 py-4">
            <?php if ($flash !== null): ?>
                <div class="alert alert-<?= e($flash['tone'] === 'danger' ? 'danger' : ($flash['tone'] === 'success' ? 'success' : 'warning')) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>


            <ul class="nav nav-pills mb-4" id="dashboardTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="neraca-tab-button" data-bs-toggle="pill" data-bs-target="#neraca-tab" type="button" role="tab">Neraca</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="pl-tab-button" data-bs-toggle="pill" data-bs-target="#pl-tab" type="button" role="tab">Laba Rugi</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="ms-tab-button" data-bs-toggle="pill" data-bs-target="#ms-tab" type="button" role="tab">Market Share</button>
                </li>
            </ul>


            <div class="tab-content">
                <section class="tab-pane fade show active" id="neraca-tab" role="tabpanel" aria-labelledby="neraca-tab-button">
                    <!-- DANA WORKSPACE -->
                    <div id="neracaWorkspace">
                        <!-- Status strip -->
                        <!-- <div class="status-strip p-3 mb-3" id="statusBox">
                            <div class="d-flex align-items-start gap-3">
                                <i class="bi bi-info-circle text-danger fs-5"></i>
                                <div>
                                    <div class="fw-bold" id="statusTitle">Memuat dashboard</div>
                                    <div class="text-secondary small" id="statusText">Dashboard sedang menyiapkan metadata dan filter produk.</div>
                                </div>
                            </div>
                        </div> -->

                        <!-- 8 Stat Cards Row -->
                        <div class="row g-2 mb-3" id="statCardsRow">
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon blue"><i class="bi bi-bar-chart-fill"></i></div>
                                    <div class="label">Ending Balance</div>
                                    <div class="value" id="latestBalance">-</div>
                                    <div class="sub" id="latestBalanceSub">Saldo akhir bulan aktif.</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon slate"><i class="bi bi-arrow-down-circle-fill"></i></div>
                                    <div class="label">Bottom Balance</div>
                                    <div class="value" id="latestBottomBalance">-</div>
                                    <div class="sub" id="latestBottomSub">Saldo terendah bulan aktif.</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon green"><i class="bi bi-graph-up-arrow"></i></div>
                                    <div class="label">Maximum Balance</div>
                                    <div class="value" id="maxBalance">-</div>
                                    <div class="sub" id="maxBalanceSub">Tertinggi di bulan aktif.</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon green"><i class="bi bi-calendar2-range-fill"></i></div>
                                    <div class="label">Growth YOY End Balance</div>
                                    <div class="value fw-bold" id="growthYoyCombined">-</div>
                                    <div class="sub" id="growthYoyCombinedSub">Tahun Lalu vs Bulan Aktif</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon amber"><i class="bi bi-calendar-check"></i></div>
                                    <div class="label">Growth YTD Balance</div>
                                    <div class="value fw-bold" id="growthYtdCombined">-</div>
                                    <div class="sub" id="growthYtdCombinedSub">Des Tahun Lalu vs Bulan Aktif</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon red"><i class="bi bi-arrow-left-right"></i></div>
                                    <div class="label">Growth End Balance</div>
                                    <div class="value" id="growthEndCombined">-</div>
                                    <div class="sub" id="growthEndCombinedSub">Bulan sebelumnya vs Bulan aktif.</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon purple"><i class="bi bi-arrow-left-right"></i></div>
                                    <div class="label">Growth Bottom Balance</div>
                                    <div class="value" id="growthBottomCombined">-</div>
                                    <div class="sub" id="growthBottomCombinedSub">Bulan sebelumnya vs Bulan aktif.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Main Content: Left Sidebar + Right Charts -->
                        <div class="row g-3">
                            <!-- LEFT SIDEBAR: Filters + Scope Card -->
                            <div class="col-xl-3 col-lg-4">
                                <div class="panel p-3 p-md-4 mb-3">
                                    <div class="mb-3">
                                        <div class="form-label mb-2">Kategori Neraca</div>
                                        <div class="d-flex gap-2 flex-wrap" id="groupPillsSidebar">
                                            <button class="btn btn-f1 btn-sm px-3" type="button" data-group="dana">Produk Dana</button>
                                            <button class="btn btn-ghost btn-sm px-3" type="button" data-group="kredit">Produk Kredit</button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="form-label mb-2">Tampilan Waktu</div>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-f1 btn-sm px-3" data-dana-view="timeline" type="button">📈 Timeline</button>
                                            <button class="btn btn-ghost btn-sm px-3" data-dana-view="calendar" type="button">📅 Kalender</button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="productSelect">Produk</label>
                                        <select class="form-select form-select-sm" id="productSelect" disabled></select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="entityInput">Pencarian Unit (Region/Area/Cabang)</label>
                                        <div class="d-flex gap-2">
                                            <input type="text" class="form-control form-control-sm" id="entityInput" list="entityList" placeholder="Ketik Kode atau Nama..." disabled>
                                            <datalist id="entityList"></datalist>
                                            <button class="btn btn-ghost btn-sm" id="searchEntityBtn" type="button" disabled>
                                                <i class="bi bi-search text-danger"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <label class="form-label" for="monthSelect">Bulan</label>
                                            <select class="form-select form-select-sm" id="monthSelect" disabled>
                                                <option value="">Terbaru</option>
                                            </select>
                                        </div>
                                        <div class="col-5">
                                            <label class="form-label" for="periodSelect">Periode</label>
                                            <select class="form-select form-select-sm" id="periodSelect" disabled>
                                                <option value="MtD" selected>MtD</option>
                                                <option value="3M">3 Bulan</option>
                                                <option value="YTD">YTD</option>
                                                <option value="YoY">YoY</option>
                                            </select>
                                        </div>
                                        <div class="col-1 d-flex align-items-end">
                                            <button class="btn btn-ghost btn-sm p-1" id="refreshButton" type="button" disabled>
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="small text-secondary bg-light rounded px-2 py-2 border" id="dashboardAccessNote">
                                        <i class="bi bi-info-circle me-1"></i> Anda tetap dapat mencari unit lain.
                                    </div>
                                </div>

                                <!-- Scope Card -->
                                <div class="scope-card mb-3" id="scopeCardWrapper">
                                    <div class="scope-icon">🏢</div>
                                    <div style="min-width:0;">
                                        <div class="form-label mb-0">Scope</div>
                                        <div class="rajdhani fw-bold" style="font-size:1.2rem;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="scopeLabel">-</div>
                                        <div class="small text-secondary fw-semibold mt-1" id="scopeSub">-</div>
                                    </div>
                                </div>

                                <div class="small text-secondary text-center mt-2" style="opacity:.6;">* Semua nilai dalam juta (M) kecuali dinyatakan lain</div>
                            </div>

                            <!-- RIGHT: Charts stacked -->
                            <div class="col-xl-9 col-lg-8">
                                <!-- Trend Chart -->
                                <div class="panel p-3 p-md-4 mb-3">
                                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-3">
                                        <div>
                                            <h5 class="rajdhani fw-bold mb-0">Tren Ending Balance Harian <span class="text-danger" id="activeProductName"></span></h5>
                                            <div class="small text-secondary" id="chartSubtitle">Hari 1 sampai 31, multi-series per bulan.</div>
                                        </div>
                                    </div>
                                    <div class="chart-shell">
                                        <div id="chartNeraca"></div>
                                        <div class="empty-state" id="chartEmpty">
                                            <i class="bi bi-graph-up-arrow fs-1 text-danger"></i>
                                            <strong>Chart akan tampil setelah data tersedia.</strong>
                                        </div>
                                    </div>
                                </div>
                                <!-- Comparison / New CIF Chart -->
                                <div class="panel p-3 p-md-4 mb-3" id="panelPerbandingan" style="display:none;">
                                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-3">
                                        <div>
                                            <h5 class="rajdhani fw-bold mb-0">Produktivitas Akuisisi New CIF <span class="text-danger" id="activeProductNameComp"></span></h5>
                                        </div>
                                        <select id="comboChartType" class="form-select form-select-sm w-auto">
                                            <option value="mixed">Kombinasi (Line & Bar)</option>
                                            <option value="line">Semua Line</option>
                                            <option value="bar">Semua Bar</option>
                                        </select>
                                    </div>
                                    <div class="chart-shell" style="min-height:350px;">
                                        <div id="chartPerbandinganTarget"></div>
                                    </div>
                                </div>
                                <!-- Daily Table -->
                                <div class="panel p-3 p-md-4">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                        <h5 class="rajdhani fw-bold mb-0">Tabel Harian <span class="text-danger" id="activeProductNameComp"></span></h5>
                                        </h5>
                                        <span class="small text-secondary"><span class="badge bg-danger bg-opacity-10 text-danger border border-danger">Merah</span> = Nilai terendah per bulan.</span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-daily table-hover" id="dataTableNeraca">
                                            <thead id="theadNeraca"></thead>
                                            <tbody id="tbodyNeraca">
                                                <tr>
                                                    <td class="text-center text-secondary py-4" colspan="32">Belum ada data.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div><!-- /col right -->
                        </div><!-- /row main -->
                    </div>


                    <!-- KREDIT WORKSPACE -->
                    <div id="kreditWorkspace" style="display: none;">

                        <!-- 4 Stat Cards Row — same pattern as Dana -->
                        <div class="row g-2 mb-3">
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon blue"><i class="bi bi-bank2"></i></div>
                                    <div class="label">Ending Balance</div>
                                    <div class="value" id="kreditLatestBalance">-</div>
                                    <div class="sub" id="kreditLatestBalanceSub">Saldo akhir bulan aktif.</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon amber"><i class="bi bi-arrow-left-right"></i></div>
                                    <div class="label">Growth MTD Balance</div>
                                    <div class="value fw-bold" id="kreditGrowthMtd">-</div>
                                    <div class="sub" id="kreditGrowthMtdSub">Bulan sebelumnya vs Bulan aktif.</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon green"><i class="bi bi-calendar-check"></i></div>
                                    <div class="label">Growth YTD Balance</div>
                                    <div class="value fw-bold" id="kreditGrowthYtd">-</div>
                                    <div class="sub" id="kreditGrowthYtdSub">Desember Tahun Lalu vs Bulan Aktif</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="mini-stat h-100">
                                    <div class="stat-icon purple"><i class="bi bi-calendar2-range-fill"></i></div>
                                    <div class="label">Growth YOY Balance</div>
                                    <div class="value fw-bold" id="kreditGrowthYoy">-</div>
                                    <div class="sub" id="kreditGrowthYoySub">Tahun Lalu vs Bulan Aktif</div>
                                </div>
                            </div>
                        </div>

                        <!-- Main Content: Left Sidebar + Right Chart -->
                        <div class="row g-3">
                            <div class="mb-3">
                                <div class="form-label mb-2">Kategori Neraca</div>
                                <div class="d-flex gap-2 flex-wrap" id="groupPillsSidebar">
                                    <button class="btn btn-f1 btn-sm px-3" type="button" data-group="dana">Produk Dana</button>
                                    <button class="btn btn-ghost btn-sm px-3" type="button" data-group="kredit">Produk Kredit</button>
                                </div>
                            </div>
                            <!-- LEFT SIDEBAR: Filters -->
                            <div class="col-xl-3 col-lg-4">
                                <div class="panel p-3 p-md-4 mb-3">
                                    <div class="form-label mb-3">Filter Produk Kredit</div>

                                    <!-- Product checkboxes — pill style -->
                                    <div class="mb-3">
                                        <label class="form-label">Pilih Produk</label>
                                        <div class="d-flex flex-column gap-1" id="krProdList">
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="KreditRetail" id="kp1" checked> Kredit Retail</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="SME" id="kp2"> SME</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="ConsumerBanking" id="kp3"> Consumer Banking</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="ConsumerLoan" id="kp4"> Consumer Loan</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="KKB" id="kp11"> Auto Loan</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="CreditCard" id="kp5"> Credit Card</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="Micro" id="kp6"> Micro</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="KSM" id="kp7"> KSM</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="KUMBlend" id="kp8"> KUM Blended</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="KUM" id="kp9"> KUM</label>
                                            <label class="kr-pill-label"><input class="kr-prod" type="checkbox" value="KUR" id="kp10"> KUR</label>

                                        </div>
                                    </div>

                                    <!-- Indikator -->
                                    <div class="mb-3">
                                        <label class="form-label">Indikator</label>
                                        <div class="d-flex flex-column gap-1">
                                            <label class="kr-pill-label kr-radio"><input class="kr-mode" type="radio" name="kr_mode_sel" value="endbal" id="km1" checked> EndBal</label>
                                            <label class="kr-pill-label kr-radio"><input class="kr-mode" type="radio" name="kr_mode_sel" value="kol" id="km2"> KOL 2</label>
                                            <label class="kr-pill-label kr-radio"><input class="kr-mode" type="radio" name="kr_mode_sel" value="npl" id="km3"> NPL</label>
                                        </div>
                                    </div>

                                    <!-- Tampilan Waktu -->
                                    <div class="mb-3">
                                        <label class="form-label">Tampilan Waktu</label>
                                        <div class="d-flex flex-column gap-1">
                                            <label class="kr-pill-label kr-radio"><input class="kr-view" type="radio" name="kr_view" value="continuous" id="kv1"> Timeline Lanjut</label>
                                            <label class="kr-pill-label kr-radio"><input class="kr-view" type="radio" name="kr_view" value="annual" id="kv2" checked> Komparasi Tahunan</label>
                                        </div>
                                    </div>

                                    <!-- Search Unit -->
                                    <div>
                                        <label class="form-label">Pencarian Unit</label>
                                        <div class="d-flex gap-2">
                                            <input type="text" class="form-control form-control-sm" id="kreditEntityInput" list="entityList" placeholder="Kode atau Nama...">
                                            <button class="btn btn-ghost btn-sm" id="refreshKreditBtn" type="button">
                                                <i class="bi bi-search text-danger"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Scope Card (kredit) -->
                                <div class="scope-card mb-3" id="kreditScopeCardWrapper">
                                    <div class="scope-icon">💳</div>
                                    <div style="min-width:0;">
                                        <div class="form-label mb-0">Scope</div>
                                        <div class="rajdhani fw-bold" style="font-size:1.1rem;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" id="kreditScopeLabel">-</div>
                                        <div class="small text-secondary fw-semibold mt-1" id="kreditScopeSub">Produk Kredit Terpilih</div>
                                    </div>
                                </div>

                                <div class="small text-secondary text-center mt-2" style="opacity:.6;">* Semua nilai dalam juta (M)</div>
                            </div>

                            <!-- RIGHT: Chart -->
                            <div class="col-xl-9 col-lg-8">
                                <div class="panel p-3 p-md-4">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
                                        <div>
                                            <h5 class="rajdhani fw-bold mb-0">Grafik Pertumbuhan &amp; Kualitas Kredit Bulanan</h5>
                                            <div class="small text-secondary" id="kreditChartSubtitle">Pilih produk dan indikator untuk mulai.</div>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap align-items-center">
                                            <span class="small text-secondary bg-light px-2 py-1 rounded border" id="kreditScopeChip">-</span>
                                        </div>
                                    </div>
                                    <div class="chart-shell" style="min-height:450px;">
                                        <div id="chartKreditTarget"></div>
                                    </div>
                                </div>
                            </div>

                        </div><!-- /row kredit main -->
                    </div>
                </section>


                <section class="tab-pane fade" id="pl-tab" role="tabpanel" aria-labelledby="pl-tab-button">
                    <?= labarugiHtmlSection() ?>
                </section>
                <section class="tab-pane fade" id="ms-tab" role="tabpanel" aria-labelledby="ms-tab-button">
                    <?= marketshareHtmlSection() ?>
                </section>
            </div>
        </main>
    <?php else: ?>
        <script>
            window.location.href = '<?= e(currentPageUrl(['page' => 'hub'])) ?>';
        </script>
    <?php endif; ?>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <?php if ($isDashboardPage): ?>
        <?= labarugiScript() ?>
        <?= marketshareScript() ?>
    <?php endif; ?>


    <script>
        // ============================================================
        // GLOBALS & FORMATTER
        // ============================================================
        const apiBase = window.location.pathname;
        const formatter = new Intl.NumberFormat('id-ID', {
            maximumFractionDigits: 1
        });
        const percentFormatter = new Intl.NumberFormat('id-ID', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1
        });
        const appState = {
            meta: null,
            chart: null,
            kreditChart: null,
            comparisonChart: null,
            lastData: null,
            activeGroup: 'dana',
            danaViewMode: 'timeline', // 'timeline' | 'calendar'
            holidays: {}, // { 'YYYY-MM-DD': 'Nama Libur' } — from APIHariLibur_V2
            holidaysLoaded: false
        };
        const adminMonitoringData = <?= json_encode($adminMonitoringInsights, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        // Cache untuk autocomplete — diisi dari response dashboard
        let _gmmSearchCache = [];

        window.gmmSuggest = function(q) {
            const box = document.getElementById('gmmSuggestBox');
            if (!box) return;
            q = q.trim().toLowerCase();
            if (q.length < 2) {
                box.style.display = 'none';
                return;
            }
            const matches = _gmmSearchCache.filter(c =>
                (c.nama || '').toLowerCase().includes(q) ||
                (c.nip || '').toLowerCase().includes(q) ||
                (c.unit || '').toLowerCase().includes(q)
            ).slice(0, 8);
            if (!matches.length) {
                box.style.display = 'none';
                return;
            }
            box.style.display = 'block';
            box.innerHTML = matches.map(m => `
                <div onclick="document.getElementById('gmmSearchInput').value='${escapeHtml(m.nama)}';
                            document.getElementById('gmmSuggestBox').style.display='none';
                            gmmSearchExec('${escapeHtml(m.nama)}')"
                    style="padding:10px 14px;cursor:pointer;border-bottom:1px solid rgba(229,231,235,.5);
                            display:flex;align-items:center;gap:10px;"
                    onmouseover="this.style.background='rgba(225,6,0,.04)'"
                    onmouseout="this.style.background=''">
                    <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#fee2e2,#fecdd3);
                        display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;">👤</div>
                    <div>
                        <div style="font-weight:700;color:#1e293b;font-size:.85rem;">${escapeHtml(m.nama)}</div>
                        <div style="font-size:.7rem;color:#64748b;">NIP: ${escapeHtml(m.nip)} · ${escapeHtml(m.unit)}</div>
                    </div>
                </div>`).join('');
        };

        // ============================================================
        // PUBLIC HOLIDAY FETCHER — guangrei/APIHariLibur_V2 (GitHub)
        // Format: { "YYYY-MM-DD": { "summary": "Nama Hari Libur" }, ... }
        // Includes: hari libur nasional + cuti bersama + belum pasti
        // URL: https://raw.githubusercontent.com/guangrei/APIHariLibur_V2/main/holidays.json
        // ============================================================
        const HOLIDAY_URL = 'https://raw.githubusercontent.com/guangrei/APIHariLibur_V2/main/holidays.json';

        async function loadHolidays() {
            if (appState.holidaysLoaded) return;
            try {
                const res = await fetch(HOLIDAY_URL);
                if (!res.ok) {
                    console.warn('[Holiday] Gagal fetch:', res.status);
                    return;
                }
                const raw = await res.json();
                appState.holidays = {};
                for (const [dateStr, val] of Object.entries(raw)) {
                    // Skip the "info" metadata key
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) continue;
                    const summary = Array.isArray(val.summary) ?
                        val.summary.join(', ') :
                        (val.summary || '');
                    if (summary) appState.holidays[dateStr] = summary;
                }
                appState.holidaysLoaded = true;
            } catch (e) {
                console.warn('[Holiday] Error:', e);
            }
        }

        // ============================================================
        // RANK BADGE — Gold / Silver / Bronze only
        // ============================================================
        function getRankBadge(rank) {
            if (rank === 1) return '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#FFD700,#FFA500);color:#7c2d12;font-weight:900;font-size:0.9rem;box-shadow:0 2px 8px rgba(255,165,0,0.4);">1</span>';
            if (rank === 2) return '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#C0C0C0,#A8A8A8);color:#1e293b;font-weight:900;font-size:0.9rem;box-shadow:0 2px 6px rgba(0,0,0,0.2);">2</span>';
            if (rank === 3) return '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#CD7F32,#A0522D);color:#fff;font-weight:900;font-size:0.9rem;box-shadow:0 2px 6px rgba(0,0,0,0.2);">3</span>';
            return `<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#f1f5f9;color:#64748b;font-weight:700;font-size:0.8rem;">${rank}</span>`;
        }

        // getRankStyle: returns {bg, border, text} purely on rank (no area color)
        function getRankStyle(rank) {
            if (rank === 1) return {
                bg: 'linear-gradient(135deg,rgba(255,215,0,0.15),rgba(255,165,0,0.08))',
                border: 'rgba(255,165,0,0.4)',
                text: '#92400e'
            };
            if (rank === 2) return {
                bg: 'linear-gradient(135deg,rgba(192,192,192,0.18),rgba(168,168,168,0.08))',
                border: 'rgba(150,150,150,0.35)',
                text: '#374151'
            };
            if (rank === 3) return {
                bg: 'linear-gradient(135deg,rgba(205,127,50,0.15),rgba(160,82,45,0.08))',
                border: 'rgba(160,82,45,0.35)',
                text: '#78350f'
            };
            return {
                bg: 'rgba(255,255,255,0.85)',
                border: 'rgba(229,231,235,0.7)',
                text: '#1e293b'
            };
        }

        // ============================================================
        // FORMAT HELPERS
        // ============================================================
        const fmtRp = v => {
            try {
                return 'Rp ' + Math.round(Number(v)).toLocaleString('id-ID') + ' Jt';
            } catch (e) {
                return 'Rp 0 Jt';
            }
        };
        const fmtNum = v => {
            try {
                return Math.round(Number(v)).toLocaleString('id-ID');
            } catch (e) {
                return '0';
            }
        };
        const fmtPct = v => {
            try {
                let n = Number(v);
                return (n <= 1 ? (n * 100).toFixed(1) : n.toFixed(1)) + '%';
            } catch (e) {
                return '0.0%';
            }
        };
        const fmtByKey = (val, fmtKey) => fmtKey === 'rp' ? fmtRp(val) : fmtKey === 'pct' ? fmtPct(val) : fmtNum(val);

        function formatNumber(value) {
            if (value === null || value === undefined || Number.isNaN(Number(value))) return '-';
            return formatter.format(value);
        }

        function formatNumberWithUnit(value) {
            const numValue = Number(value);
            if (value === null || value === undefined || Number.isNaN(numValue)) return '-';
            const absValue = Math.abs(numValue);
            if (absValue > 0 && absValue < 1) {
                return (numValue * 1000).toLocaleString('id-ID', {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 2
                }) + ' Jt';
            }
            return numValue.toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 1
            }) + ' M';
        }

        function formatDateTime(value) {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return value;
            return date.toLocaleString('id-ID', {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function escapeHtml(value) {
            return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function renderAdminMonitoringCharts() {
            if (!document.getElementById('appAdmin') || typeof ApexCharts === 'undefined') return;
            const charts = adminMonitoringData?.charts || {};

            const renderers = [{
                id: 'adminHourlyLoginChart',
                options: {
                    chart: {
                        type: 'bar',
                        height: 260,
                        toolbar: {
                            show: false
                        }
                    },
                    series: [{
                        name: 'Login',
                        data: charts.hourly_logins || []
                    }],
                    xaxis: {
                        categories: charts.hourly_categories || [],
                        labels: {
                            rotate: -45
                        }
                    },
                    colors: ['#E10600'],
                    plotOptions: {
                        bar: {
                            borderRadius: 6,
                            columnWidth: '58%'
                        }
                    },
                    dataLabels: {
                        enabled: false
                    },
                    grid: {
                        borderColor: '#e2e8f0'
                    },
                    yaxis: {
                        min: 0
                    }
                }
            }, {
                id: 'adminEventMixChart',
                options: {
                    chart: {
                        type: 'donut',
                        height: 260
                    },
                    series: charts.event_mix_series || [],
                    labels: charts.event_mix_labels || [],
                    colors: ['#E10600', '#2563eb', '#0ea5e9', '#64748b', '#94a3b8', '#cbd5e1'],
                    legend: {
                        position: 'bottom'
                    },
                    dataLabels: {
                        enabled: false
                    },
                    stroke: {
                        width: 0
                    }
                }
            }, {
                id: 'adminDailyActivityChart',
                options: {
                    chart: {
                        type: 'line',
                        height: 260,
                        toolbar: {
                            show: false
                        }
                    },
                    series: [{
                        name: 'Login',
                        data: charts.daily_logins || []
                    }, {
                        name: 'View Dashboard',
                        data: charts.daily_views || []
                    }],
                    xaxis: {
                        categories: charts.daily_categories || []
                    },
                    stroke: {
                        curve: 'smooth',
                        width: [3, 3]
                    },
                    markers: {
                        size: 4
                    },
                    colors: ['#E10600', '#2563eb'],
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shadeIntensity: 1,
                            opacityFrom: 0.18,
                            opacityTo: 0.02
                        }
                    },
                    grid: {
                        borderColor: '#e2e8f0'
                    },
                    yaxis: {
                        min: 0
                    }
                }
            }];

            renderers.forEach(item => {
                const target = document.getElementById(item.id);
                if (!target) return;
                target.innerHTML = '';
                new ApexCharts(target, item.options).render();
            });
        }

        // ============================================================
        // MAIN INIT
        // ============================================================
        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('appDashboard')) initDashboard();
            if (document.getElementById('appAdmin')) initAdminUpload();
            if (document.getElementById('appAdmin')) renderAdminMonitoringCharts();
        });

        function findMaxDateLabel(series) {
            const MONTHS_ID = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            let maxVal = null,
                maxDay = null,
                maxMonthKey = null;
            (series || []).forEach(s => {
                (s.data || []).forEach((v, idx) => {
                    if (v !== null && (maxVal === null || v > maxVal)) {
                        maxVal = v;
                        maxDay = idx + 1;
                        maxMonthKey = s.month_key;
                    }
                });
            });
            if (!maxMonthKey) return null;
            const [yr, mo] = maxMonthKey.split('-').map(Number);
            return `${maxDay} ${MONTHS_ID[mo - 1]} ${yr}`;
        }

        function initDashboard() {
            const els = {
                loader: document.getElementById('loader'),
                loaderText: document.getElementById('loaderText'),
                productSelect: document.getElementById('productSelect'),
                entityInput: document.getElementById('entityInput'),
                entityList: document.getElementById('entityList'),
                searchEntityBtn: document.getElementById('searchEntityBtn'),
                monthSelect: document.getElementById('monthSelect'),
                periodSelect: document.getElementById('periodSelect'),
                refreshButton: document.getElementById('refreshButton'),
                cacheSource: document.getElementById('cacheSource'),
                cacheGenerated: document.getElementById('cacheGenerated'),
                statusTitle: document.getElementById('statusTitle'),
                statusText: document.getElementById('statusText'),
                scopeLabel: document.getElementById('scopeLabel'),
                scopeSub: document.getElementById('scopeSub'),
                maxBalance: document.getElementById('maxBalance'),
                maxBalanceSub: document.getElementById('maxBalanceSub'),
                latestBalance: document.getElementById('latestBalance'),
                latestBalanceSub: document.getElementById('latestBalanceSub'),
                latestBottomBalance: document.getElementById('latestBottomBalance'),
                latestBottomSub: document.getElementById('latestBottomSub'),
                growthEndCombined: document.getElementById('growthEndCombined'),
                growthEndCombinedSub: document.getElementById('growthEndCombinedSub'),
                growthBottomCombined: document.getElementById('growthBottomCombined'),
                growthBottomCombinedSub: document.getElementById('growthBottomCombinedSub'),
                growthYtdCombined: document.getElementById('growthYtdCombined'),
                growthYtdCombinedSub: document.getElementById('growthYtdCombinedSub'),
                growthYoyCombined: document.getElementById('growthYoyCombined'),
                growthYoyCombinedSub: document.getElementById('growthYoyCombinedSub'),
                activeProductName: document.getElementById('activeProductName'),
                activeProductNameComp: document.getElementById('activeProductNameComp'),
                chartSubtitle: document.getElementById('chartSubtitle'),
                chartEmpty: document.getElementById('chartEmpty'),
                chartTarget: document.getElementById('chartNeraca'),
                thead: document.getElementById('theadNeraca'),
                tbody: document.getElementById('tbodyNeraca'),
                groupButtons: Array.from(document.querySelectorAll('[data-group]')),
                neracaWorkspace: document.getElementById('neracaWorkspace'),
                kreditWorkspace: document.getElementById('kreditWorkspace'),
                dashboardAccessNote: document.getElementById('dashboardAccessNote'),
                kreditEntityInput: document.getElementById('kreditEntityInput'),
                refreshKreditBtn: document.getElementById('refreshKreditBtn'),
                kreditScopeLabel: document.getElementById('kreditScopeLabel'),
                kreditScopeSub: document.getElementById('kreditScopeSub'),
                kreditLatestBalance: document.getElementById('kreditLatestBalance'),
                kreditLatestBalanceSub: document.getElementById('kreditLatestBalanceSub'),
                kreditGrowthMtd: document.getElementById('kreditGrowthMtd'),
                kreditGrowthMtdSub: document.getElementById('kreditGrowthMtdSub'),
                kreditGrowthYtd: document.getElementById('kreditGrowthYtd'),
                kreditGrowthYtdSub: document.getElementById('kreditGrowthYtdSub'),
                kreditGrowthYoy: document.getElementById('kreditGrowthYoy'),
                kreditGrowthYoySub: document.getElementById('kreditGrowthYoySub'),
            };

            els.productSelect.addEventListener('change', () => updateDashboard(els));
            els.periodSelect.addEventListener('change', () => updateDashboard(els));
            els.monthSelect.addEventListener('change', () => updateDashboard(els));
            els.refreshButton.addEventListener('click', () => updateDashboard(els));
            els.searchEntityBtn.addEventListener('click', () => updateDashboard(els));
            els.entityInput.addEventListener('keypress', e => {
                if (e.key === 'Enter') updateDashboard(els);
            });
            els.groupButtons.forEach(button => {
                button.addEventListener('click', () => {
                    appState.activeGroup = button.dataset.group || 'dana';
                    applyGroupState(els);
                });
            });

            // Dana view mode toggle (Timeline / Calendar) — buttons injected in HTML
            document.addEventListener('click', e => {
                const btn = e.target.closest('[data-dana-view]');
                if (btn) {
                    appState.danaViewMode = btn.dataset.danaView;
                    document.querySelectorAll('[data-dana-view]').forEach(b => {
                        b.className = b.dataset.danaView === appState.danaViewMode ? 'btn btn-f1 btn-sm px-3' : 'btn btn-ghost btn-sm px-3';
                    });
                    updateDashboard(els);
                }
            });

            document.querySelectorAll('input.kr-prod, input.kr-mode, input.kr-view').forEach(el => {
                el.addEventListener('change', () => updateKreditDashboard(els));
            });
            els.refreshKreditBtn.addEventListener('click', () => updateKreditDashboard(els));
            els.kreditEntityInput.addEventListener('keypress', e => {
                if (e.key === 'Enter') updateKreditDashboard(els);
            });

            loadMeta(els);
        }

        async function loadMeta(els) {
            showLoader(true, 'Memuat metadata dashboard...');
            try {
                const payload = await apiGet('meta');
                appState.meta = payload;
                els.cacheSource.textContent = payload.source_file || 'Belum ada cache';
                els.cacheGenerated.textContent = payload.generated_at ?
                    `Cache dibuat ${formatDateTime(payload.generated_at)}` :
                    (payload.setup_message || 'Upload workbook .xlsx untuk mulai.');
                if (!payload.cached) {
                    setControlsEnabled(els, false);
                    setStatus(els, 'Menunggu data Excel', payload.setup_message || 'Admin perlu upload workbook terlebih dahulu.', 'warning');
                    renderEmptySummary(els);
                    renderKreditSummary(els, null);
                    renderChart(els, []);
                    renderComparisonChart(els, payload);
                    renderEmptyTable(els);
                    return;
                }
                const groups = payload.product_groups || {};
                appState.activeGroup = initialGroup(groups);
                els.periodSelect.value = 'MTD';
                populateEntityOptions(els);
                if (payload.user?.role === 'Visitor') {
                    els.dashboardAccessNote.innerHTML = `<i class="bi bi-info-circle me-2"></i> Login sebagai visitor <strong>${payload.user.name || payload.user.id}</strong>`;
                }
                setStatus(els, 'Cache aktif', `${payload.products.length} produk dan ${payload.branches.length} unit entitas. Periode data ${payload.min_date || '-'} s.d. ${payload.max_date || '-'}.`, 'success');
                applyGroupState(els);
            } catch (error) {
                setControlsEnabled(els, false);
                setStatus(els, 'Gagal memuat metadata', error.message, 'danger');
                renderEmptySummary(els);
                renderKreditSummary(els, null);
                renderChart(els, []);
                renderComparisonChart(els, {
                    comparison_series: []
                });
                renderEmptyTable(els);
            } finally {
                showLoader(false);
            }
        }

        function initialGroup(groups) {
            if ((groups.dana?.products || []).length) return 'dana';
            if ((groups.kredit?.products || []).length) return 'kredit';
            return 'dana';
        }

        function applyGroupState(els) {
            const groups = appState.meta?.product_groups || {};
            els.groupButtons.forEach(button => {
                const isActive = button.dataset.group === appState.activeGroup;
                if (button.classList.contains('btn')) {
                    button.className = isActive ? 'btn btn-f1 btn-sm px-3' : 'btn btn-ghost btn-sm px-3';
                } else {
                    button.classList.toggle('active', isActive);
                }
            });
            if (appState.activeGroup === 'kredit') {
                els.neracaWorkspace.style.display = 'none';
                els.kreditWorkspace.style.display = 'block';
                updateKreditDashboard(els);
                return;
            }
            els.neracaWorkspace.style.display = 'block';
            els.kreditWorkspace.style.display = 'none';
            const products = groups[appState.activeGroup]?.products || [];
            fillSelect(els.productSelect, products.map(p => ({
                value: p,
                label: p
            })));
            const months = appState.meta?.months || [];
            if (els.monthSelect.options.length <= 1) {
                let monthOptions = [{
                    value: '',
                    label: 'Terbaru'
                }];
                [...months].reverse().forEach(m => monthOptions.push({
                    value: m,
                    label: m
                }));
                fillSelect(els.monthSelect, monthOptions);
            }
            if (!products.includes(els.productSelect.value)) els.productSelect.value = products[0] || '';
            setControlsEnabled(els, products.length > 0);
            updateDashboard(els);
        }

        function populateEntityOptions(els) {
            const meta = appState.meta || {};
            const user = meta.user || {};
            els.entityList.innerHTML = '';
            (meta.branches || []).forEach(branch => {
                const opt = document.createElement('option');
                opt.value = branch.id;
                opt.textContent = branch.name;
                els.entityList.appendChild(opt);
            });
            if (!els.entityInput.value) {
                if (user.role === 'Visitor' && user.branch_id) {
                    els.entityInput.value = user.branch_id;
                    els.kreditEntityInput.value = user.branch_id;
                } else if ((meta.branches || []).length > 0) {
                    els.entityInput.value = meta.branches[0].id;
                    els.kreditEntityInput.value = meta.branches[0].id;
                }
            } else {
                if (!els.kreditEntityInput.value) els.kreditEntityInput.value = els.entityInput.value;
            }
        }


        async function updateDashboard(els) {
            if (!appState.meta?.cached || !els.productSelect.value || appState.activeGroup !== 'dana') return;

            const params = {
                product: els.productSelect.value,
                month: els.monthSelect.value,
                period: els.periodSelect.value,
                id: els.entityInput.value,
                group: 'dana'
            };

            showLoader(true, 'Mengambil data dashboard...');
            try {
                await loadHolidays();
                const payload = await apiGet('data', params);
                appState.lastData = payload;

                renderSummary(els, payload);
                renderMonthlySummaryTables(payload.all_series_summary || []);

                if (appState.danaViewMode === 'calendar') {
                    renderCalendarView(els, payload);
                } else {
                    renderChart(els, payload.series || []);
                    renderTable(els, payload.series || []);
                    renderComparisonChart(els, payload);

                }
            } catch (error) {
                setStatus(els, 'Data tidak ditemukan', error.message, 'warning');
                renderEmptySummary(els);
                renderMonthlySummaryTables([]);
                renderChart(els, []);
                renderComparisonChart(els, {
                    comparison_series: []
                });
                renderEmptyTable(els);
            } finally {
                showLoader(false);
            }
        }
        // ============================================================
        // CALENDAR VIEW — Dana (daily data)
        // Uses appState.holidays from guangrei/APIHariLibur_V2
        // Holiday notes shown as a legend BELOW each monthly calendar
        // ============================================================
        function renderCalendarView(els, payload) {
            // Destroy & hide timeline chart/comparison
            if (appState.chart) {
                appState.chart.destroy();
                appState.chart = null;
            }
            if (appState.comparisonChart) {
                appState.comparisonChart.destroy();
                appState.comparisonChart = null;
            }
            els.chartEmpty.style.display = 'none';
            const compPanel = document.getElementById('panelPerbandingan');
            if (compPanel) compPanel.style.display = 'none';

            const series = payload.series || [];
            if (!series.length) {
                els.chartTarget.innerHTML = '<div class="empty-state"><i class="bi bi-calendar3 fs-1 text-secondary"></i><strong>Tidak ada data kalender.</strong></div>';
                renderEmptyTable(els);
                return;
            }

            const H = appState.holidays; // { 'YYYY-MM-DD': 'Nama Libur' }

            // Minggu dimulai dari Senin
            const DOW_ID = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
            const MONTH_LONG = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

            // Build per-month data map
            const dataByMonth = {};
            series.forEach(s => {
                const mk = s.month_key;
                if (!mk) return;
                const [y, m] = mk.split('-').map(Number);
                dataByMonth[mk] = {
                    year: y,
                    month: m,
                    data: s.data,
                    bottom_index: s.bottom_index,
                    end_index: s.end_index,
                    name: s.name
                };
            });

            let calHtml = '<div style="display:flex;flex-direction:column;gap:24px;">';

            Object.keys(dataByMonth).sort().forEach(mk => {
                const {
                    year,
                    month,
                    data,
                    bottom_index,
                    end_index
                } = dataByMonth[mk];

                // Mon-start: Mon=0, Tue=1, Wed=2, Thu=3, Fri=4, Sat=5, Sun=6
                const firstDow = (new Date(year, month - 1, 1).getDay() + 6) % 7;
                const daysInMonth = new Date(year, month, 0).getDate();

                // Collect all holidays in this month for the legend below calendar
                const monthHolidays = [];
                for (let d = 1; d <= daysInMonth; d++) {
                    const ds = `${year}-${String(month).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
                    if (H[ds]) monthHolidays.push({
                        day: d,
                        name: H[ds]
                    });
                }

                // End / Bottom values for the header chips
                const endVal = end_index >= 0 && data[end_index] !== null ? data[end_index] : null;
                const bottomVal = bottom_index >= 0 && data[bottom_index] !== null ? data[bottom_index] : null;

                /* ── CARD START ── */
                calHtml += `<div style="background:rgba(255,255,255,0.85);border:1px solid rgba(229,231,235,0.6);border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.05);">`;

                /* ── Header bar ── */
                calHtml += `<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:rgba(21,21,30,0.95);flex-wrap:wrap;gap:8px;">`;
                calHtml += `<span style="font-family:'Rajdhani',sans-serif;font-weight:700;font-size:1.05rem;text-transform:uppercase;color:#fff;letter-spacing:.04em;">${MONTH_LONG[month-1]} ${year}</span>`;
                calHtml += `<div style="display:flex;gap:8px;flex-wrap:wrap;">`;
                if (endVal !== null) calHtml += `<span style="font-size:.75rem;font-weight:700;padding:3px 10px;background:rgba(22,163,74,0.2);color:#86efac;border-radius:6px;">Ending: ${formatNumberWithUnit(endVal)}</span>`;
                if (bottomVal !== null) calHtml += `<span style="font-size:.75rem;font-weight:700;padding:3px 10px;background:rgba(225,6,0,0.2);color:#fca5a5;border-radius:6px;">Bottom: ${formatNumberWithUnit(bottomVal)}</span>`;
                calHtml += `</div></div>`;

                /* ── Calendar grid ── */
                calHtml += `<div style="overflow-x:auto;padding:12px 10px 6px;">`;
                calHtml += `<table style="width:100%;border-collapse:separate;border-spacing:3px;min-width:520px;">`;

                // Day-of-week header
                calHtml += `<thead><tr>`;
                DOW_ID.forEach((d, i) => {
                    // Sat=5, Sun=6 di layout Mon-start
                    const isWknd = i === 5 || i === 6;
                    calHtml += `<th style="text-align:center;font-size:.68rem;font-weight:800;text-transform:uppercase;color:${isWknd ? '#ef4444' : '#64748b'};padding:4px 2px;letter-spacing:.04em;min-width:58px;">${d}</th>`;
                });
                calHtml += `<th style="text-align:center;font-size:.65rem;font-weight:700;color:#94a3b8;padding:4px 2px;white-space:nowrap;">WoW&nbsp;%</th>`;
                calHtml += `</tr></thead><tbody>`;

                let dayNum = 1;
                let weekRow = 0;
                let prevWeekLastVal = null;
                let prevWeekFridayVal = null; // WoW berbasis Jumat

                while (dayNum <= daysInMonth) {
                    calHtml += '<tr>';
                    let weekLastVal = null;
                    let fridayVal = null; // nilai Jumat minggu ini (dow=4)

                    for (let dow = 0; dow < 7; dow++) {
                        // Empty leading/trailing cells
                        const isEmpty = (weekRow === 0 && dow < firstDow) || dayNum > daysInMonth;
                        if (isEmpty) {
                            calHtml += `<td style="height:56px;"></td>`;
                            continue;
                        }

                        const dateStr = `${year}-${String(month).padStart(2,'0')}-${String(dayNum).padStart(2,'0')}`;
                        const val = dayNum <= data.length ? data[dayNum - 1] : null;
                        const isHoliday = !!H[dateStr];
                        // Sat=5, Sun=6 di layout Mon-start
                        const isWknd = dow === 5 || dow === 6;
                        const isBottom = (dayNum - 1) === bottom_index;
                        const isEnd = (dayNum - 1) === end_index;

                        if (val !== null) weekLastVal = val;

                        // Tangkap nilai Jumat (dow=4 = Jumat di layout Mon-start)
                        if (dow === 4 && val !== null) fridayVal = val;

                        // Cell styling
                        let cellBg = 'rgba(255,255,255,0.75)';
                        let dayColor = '#64748b';
                        let numColor = '#1e293b';
                        let borderLeft = 'none';
                        let cellTitle = '';
                        let dotHtml = '';

                        if (isWknd) {
                            cellBg = 'rgba(254,242,242,0.7)';
                            dayColor = '#dc2626';
                        }
                        if (isHoliday) {
                            cellBg = 'rgba(254,226,226,0.85)';
                            dayColor = '#dc2626';
                            numColor = '#991b1b';
                            dotHtml = `<span style="display:inline-block;width:5px;height:5px;border-radius:50%;background:#dc2626;margin-left:2px;vertical-align:middle;flex-shrink:0;"></span>`;
                            cellTitle = H[dateStr];
                        }
                        if (isBottom && !isHoliday) {
                            cellBg = 'rgba(225,6,0,0.09)';
                            numColor = '#b90500';
                            borderLeft = '3px solid rgba(225,6,0,0.5)';
                        } else if (isBottom && isHoliday) {
                            borderLeft = '3px solid rgba(225,6,0,0.5)';
                        }
                        if (isEnd) {
                            cellBg = 'rgba(22,163,74,0.09)';
                            numColor = '#15803d';
                            borderLeft = '3px solid rgba(22,163,74,0.45)';
                        }

                        // DoD % vs previous data day
                        let dodHtml = '';
                        let prevVal = null;
                        for (let pi = dayNum - 2; pi >= 0; pi--) {
                            if (data[pi] !== null) {
                                prevVal = data[pi];
                                break;
                            }
                        }
                        if (prevVal !== null && val !== null) {
                            const pct = ((val - prevVal) / Math.abs(prevVal)) * 100;
                            const pctStr = (pct >= 0 ? '+' : '') + pct.toFixed(1) + '%';
                            const pctColor = pct >= 0 ? '#15803d' : '#dc2626';
                            dodHtml = `<div style="font-size:.58rem;font-weight:700;color:${pctColor};line-height:1;margin-top:1px;">${pctStr}</div>`;
                        }

                        calHtml += `<td title="${escapeHtml(cellTitle)}" style="background:${cellBg};border-radius:8px;border-left:${borderLeft};padding:4px 5px;vertical-align:top;height:56px;min-width:58px;">`;
                        calHtml += `<div style="display:flex;align-items:center;gap:2px;"><span style="font-size:.68rem;font-weight:800;color:${dayColor};line-height:1;">${dayNum}</span>${dotHtml}</div>`;
                        calHtml += dodHtml;
                        if (val !== null) {
                            calHtml += `<div style="font-size:.74rem;font-weight:900;color:${numColor};margin-top:3px;line-height:1.1;">${formatNumberWithUnit(val)}</div>`;
                        } else {
                            calHtml += `<div style="font-size:.65rem;color:#cbd5e1;margin-top:3px;">-</div>`;
                        }
                        calHtml += `</td>`;
                        dayNum++;
                    }

                    // WoW % cell — berbasis nilai Jumat, fallback ke last value jika Jumat null
                    const wowCurrent = fridayVal ?? weekLastVal;
                    const wowPrev = prevWeekFridayVal ?? prevWeekLastVal;

                    let wowHtml = '<td style="text-align:center;padding:4px;vertical-align:middle;">';
                    if (weekRow > 0 && wowPrev !== null && wowCurrent !== null) {
                        const wow = ((wowCurrent - wowPrev) / Math.abs(wowPrev)) * 100;
                        const wowStr = (wow >= 0 ? '+' : '') + wow.toFixed(1) + '%';
                        const wowColor = wow >= 0 ? '#15803d' : '#dc2626';
                        wowHtml += `<span style="font-size:.75rem;font-weight:800;color:${wowColor};white-space:nowrap;">${wowStr}</span>`;
                    } else {
                        wowHtml += `<span style="font-size:.7rem;color:#cbd5e1;">-</span>`;
                    }
                    wowHtml += '</td>';
                    calHtml += wowHtml + '</tr>';

                    // Simpan nilai minggu ini sebagai "prev" untuk minggu berikutnya
                    prevWeekFridayVal = fridayVal ?? prevWeekFridayVal;
                    prevWeekLastVal = weekLastVal ?? prevWeekLastVal;
                    weekRow++;
                }

                calHtml += `</tbody></table></div>`;

                /* ── Holiday legend BELOW calendar ── */
                if (monthHolidays.length > 0) {
                    calHtml += `<div style="border-top:1px dashed rgba(229,231,235,0.7);padding:10px 16px 12px;">`;
                    calHtml += `<div style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:6px;">📅 Hari Libur &amp; Cuti Bersama</div>`;
                    calHtml += `<div style="display:flex;flex-wrap:wrap;gap:5px;">`;
                    monthHolidays.forEach(({
                        day,
                        name
                    }) => {
                        const ds = `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
                        const isCuti = /cuti bersama/i.test(name);
                        const tagBg = isCuti ? 'rgba(254,243,199,0.9)' : 'rgba(254,226,226,0.9)';
                        const tagTc = isCuti ? '#92400e' : '#991b1b';
                        const tagBd = isCuti ? 'rgba(251,191,36,0.4)' : 'rgba(220,38,38,0.3)';
                        const icon = isCuti ? '🏖️' : '🔴';
                        calHtml += `<span style="display:inline-flex;align-items:center;gap:4px;font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:6px;background:${tagBg};color:${tagTc};border:1px solid ${tagBd};">${icon} <strong>${day}</strong>&nbsp;–&nbsp;${escapeHtml(name)}</span>`;
                    });
                    calHtml += `</div>`;

                    // Color legend key
                    calHtml += `<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;">`;
                    calHtml += `<span style="font-size:.62rem;color:#64748b;display:flex;align-items:center;gap:4px;"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(254,226,226,0.85);border:1px solid rgba(220,38,38,0.3);"></span>Hari Libur Nasional</span>`;
                    calHtml += `<span style="font-size:.62rem;color:#64748b;display:flex;align-items:center;gap:4px;"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(254,243,199,0.9);border:1px solid rgba(251,191,36,0.4);"></span>Cuti Bersama</span>`;
                    calHtml += `<span style="font-size:.62rem;color:#64748b;display:flex;align-items:center;gap:4px;"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(22,163,74,0.09);border-left:3px solid rgba(22,163,74,0.45);"></span>Ending Balance</span>`;
                    calHtml += `<span style="font-size:.62rem;color:#64748b;display:flex;align-items:center;gap:4px;"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(225,6,0,0.09);border-left:3px solid rgba(225,6,0,0.5);"></span>Bottom Balance</span>`;
                    calHtml += `</div>`;
                    calHtml += `</div>`;
                }

                calHtml += `</div>`; // card end
            });

            calHtml += '</div>';
            els.chartTarget.innerHTML = calHtml;

            // Keep daily table updated below
            renderTable(els, series);
        }
        // ============================================================
        // KREDIT DASHBOARD — now with Dana/Kredit category toggle
        // ============================================================
        async function updateKreditDashboard(els) {
            const products = Array.from(document.querySelectorAll('input.kr-prod:checked'))
                .map(cb => cb.value).join(',');
            const modeEl = document.querySelector('input.kr-mode:checked');
            const modes = modeEl?.value || 'endbal';
            const viewMode = document.querySelector('input.kr-view:checked')?.value || 'continuous';
            const entityId = document.getElementById('kreditEntityInput')?.value || '';
            const target = document.getElementById('chartKreditTarget');

            if (!products) {
                if (target) target.innerHTML = '<div class="empty-state"><i class="bi bi-graph-down text-secondary fs-1"></i><strong>Pilih setidaknya satu produk.</strong></div>';
                if (appState.kreditChart) {
                    appState.kreditChart.destroy();
                    appState.kreditChart = null;
                }
                renderKreditSummary(els, null);
                return;
            }

            showLoader(true, 'Mengambil data bulanan...');
            try {
                const payload = await apiGet('data', {
                    group: 'kredit',
                    products,
                    modes,
                    view_mode: viewMode,
                    id: entityId
                });

                if (modes === 'kol' || modes === 'npl') {
                    // Ambil endbal dulu untuk realisasiEndBalance (pembagi %)
                    let realisasiEndBalance = null;
                    try {
                        const endbalPayload = await apiGet('data', {
                            group: 'kredit',
                            products,
                            modes: 'endbal',
                            view_mode: viewMode,
                            id: entityId
                        });
                        realisasiEndBalance = endbalPayload?.summary?.end_balance ?? null;
                    } catch (_) {
                        // Gagal fetch endbal — ratio tidak ditampilkan, tapi tidak crash
                    }
                    renderKolNplSummary(els, payload, realisasiEndBalance);
                } else {
                    renderKreditSummary(els, payload);
                }

                renderKreditChart(payload);

                // ── TAMBAHAN: Tabel bulanan (di bawah chart, hanya untuk mode endbal) ──
                if (modes === 'endbal') {
                    renderKreditMonthlyTable(payload);
                } else {
                    const mc = document.getElementById('kreditMonthlyContainer');
                    if (mc) mc.innerHTML = '';
                }

                // ── TAMBAHAN: Tabel summary semua produk (fire & forget) ──
                renderKreditSummaryTable(entityId);
            } catch (err) {
                if (target) target.innerHTML = `<div class="empty-state"><i class="bi bi-exclamation-triangle text-warning fs-1"></i><strong>${err.message}</strong></div>`;
                if (appState.kreditChart) {
                    appState.kreditChart.destroy();
                    appState.kreditChart = null;
                }
                renderKreditSummary(els, null);
            } finally {
                showLoader(false);
            }
        }

        function renderMonthlySummaryTables(allSeries) {
            let container = document.getElementById('monthlySummaryTables');
            if (!container) {
                const dailyPanel = document.querySelector('#tbodyNeraca')?.closest('.panel');
                if (!dailyPanel) return;
                container = document.createElement('div');
                container.id = 'monthlySummaryTables';
                container.className = 'mt-3';
                dailyPanel.insertAdjacentElement('afterend', container);
            }
            if (!allSeries.length) {
                container.innerHTML = '';
                return;
            }

            // Build map: { year: { "01": { end, bottom }, ... } }
            const dataMap = {};
            allSeries.forEach(s => {
                if (!s.month_key) return;
                const [yr, mo] = s.month_key.split('-');
                if (!dataMap[yr]) dataMap[yr] = {};
                let endVal = null,
                    bottomVal = null;
                s.data.forEach(v => {
                    if (v !== null && (bottomVal === null || v < bottomVal)) bottomVal = v;
                });
                for (let i = s.data.length - 1; i >= 0; i--) {
                    if (s.data[i] !== null) {
                        endVal = s.data[i];
                        break;
                    }
                }
                dataMap[yr][mo] = {
                    end: endVal,
                    bottom: bottomVal
                };
            });

            const years = Object.keys(dataMap).sort();
            const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            const MONTH_NUMS = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];

            // % change helper
            function pctCell(curr, prev, bold = true) {
                if (curr === null || prev === null || prev === 0)
                    return '<span style="color:#cbd5e1;">-</span>';
                const pct = ((curr - prev) / Math.abs(prev)) * 100;
                const color = pct >= 0 ? '#15803d' : '#dc2626';
                const fw = bold ? 'font-weight:800;' : 'font-weight:700;';
                return `<span style="color:${color};${fw}">${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%</span>`;
            }

            function buildTable(title, color, valKey) {
                let html = `
        <div class="panel p-3 p-md-4 mb-3">
            <div class="d-flex align-items-center gap-2 mb-3">
                <span style="display:inline-block;width:4px;height:22px;border-radius:2px;background:${color};"></span>
                <h5 class="rajdhani fw-bold mb-0">${title} <span style="color:${color};">(Jan – Des)</span></h5>
            </div>
            <div class="table-responsive">
            <table class="table table-daily table-hover" style="font-size:.72rem;">
            <thead><tr>
                <th style="min-width:120px;">${valKey === 'end' ? 'Endbal' : 'Bottom'}</th>
                ${MONTHS_SHORT.map(m => `<th>${m}</th>`).join('')}
            </tr></thead><tbody>`;

                let prevYearKey = null;

                years.forEach(yr => {
                    // ── Row 1: Data tahun ──
                    html += `<tr>
                <td style="font-weight:800;color:#1e293b;">Realisasi ${yr}</td>`;
                    MONTH_NUMS.forEach(mo => {
                        const v = dataMap[yr]?.[mo]?.[valKey] ?? null;
                        html += `<td>${v !== null ? formatNumberWithUnit(v) : '<span style="color:#cbd5e1;">-</span>'}</td>`;
                    });
                    html += `</tr>`;

                    // ── Row 2: % YoY vs tahun sebelumnya ──
                    if (prevYearKey) {
                        html += `<tr style="background:rgba(241,245,249,0.7);">
                    <td style="font-size:.65rem;color:#64748b;font-weight:700;">% YoY vs ${prevYearKey}</td>`;
                        MONTH_NUMS.forEach(mo => {
                            const curr = dataMap[yr]?.[mo]?.[valKey] ?? null;
                            const prev = dataMap[prevYearKey]?.[mo]?.[valKey] ?? null;
                            html += `<td>${pctCell(curr, prev)}</td>`;
                        });
                        html += `</tr>`;
                    }

                    // ── Row 3: % MtD (MoM) tahun ini ──
                    html += `<tr style="background:rgba(234,244,255,0.5);">
                <td style="font-size:.65rem;color:#64748b;font-weight:700;">% MtD ${yr}</td>`;
                    MONTH_NUMS.forEach((mo, idx) => {
                        if (idx === 0) {
                            // Januari tidak ada bulan sebelumnya
                            html += `<td><span style="color:#cbd5e1;">-</span></td>`;
                        } else {
                            const prevMo = MONTH_NUMS[idx - 1];
                            const curr = dataMap[yr]?.[mo]?.[valKey] ?? null;
                            const prev = dataMap[yr]?.[prevMo]?.[valKey] ?? null;
                            html += `<td>${pctCell(curr, prev, true)}</td>`;
                        }
                    });
                    html += `</tr>`;

                    // Divider antar tahun (kecuali tahun terakhir)
                    if (yr !== years[years.length - 1]) {
                        html += `<tr><td colspan="${MONTH_NUMS.length + 1}" style="padding:2px 0;background:rgba(226,232,240,0.5);"></td></tr>`;
                    }

                    prevYearKey = yr;
                });

                html += `</tbody></table></div></div>`;
                return html;
            }

            container.innerHTML =
                buildTable('Ending Balance Bulanan', '#16a34a', 'end') +
                buildTable('Bottom Balance Bulanan', '#dc2626', 'bottom');
        }

        function renderKreditSummary(els, payload) {
            if (!payload || !payload.summary) {
                if (els.kreditScopeLabel) els.kreditScopeLabel.textContent = '-';
                if (els.kreditScopeSub) els.kreditScopeSub.textContent = '-';
                if (els.kreditLatestBalance) els.kreditLatestBalance.textContent = '-';
                if (els.kreditLatestBalanceSub) els.kreditLatestBalanceSub.textContent = 'Saldo akhir bulan aktif.';
                renderCombinedDelta(els.kreditGrowthMtd, els.kreditGrowthMtdSub, null, null, null, null);
                renderCombinedDelta(els.kreditGrowthYtd, els.kreditGrowthYtdSub, null, null, null, null);
                renderCombinedDelta(els.kreditGrowthYoy, els.kreditGrowthYoySub, null, null, null, null);
                return;
            }

            const summary = payload.summary;

            if (els.kreditScopeLabel) els.kreditScopeLabel.textContent = payload.label || '-';
            if (els.kreditScopeSub) els.kreditScopeSub.textContent = 'Produk Terpilih';

            const scopeChip = document.getElementById('kreditScopeChip');
            if (scopeChip) scopeChip.textContent = payload.label || '-';

            const chartSub = document.getElementById('kreditChartSubtitle');
            if (chartSub && summary.current_month)
                chartSub.textContent = `${summary.current_month} | ${payload.label || '-'}`;

            if (els.kreditLatestBalance)
                els.kreditLatestBalance.textContent = formatNumberWithUnit(summary.end_balance);
            if (els.kreditLatestBalanceSub)
                els.kreditLatestBalanceSub.textContent = summary.current_month ?
                `Ending balance ${summary.current_month}` :
                'Saldo akhir bulan aktif.';

            // Growth MTD
            renderCombinedDelta(
                els.kreditGrowthMtd, els.kreditGrowthMtdSub,
                summary.growth_mtd_nominal, summary.growth_mtd_percent,
                summary.current_month, summary.previous_month
            );

            // Growth YTD
            renderCombinedDelta(
                els.kreditGrowthYtd, els.kreditGrowthYtdSub,
                summary.growth_ytd_nominal, summary.growth_ytd_percent,
                summary.current_month, 'Des Tahun Lalu'
            );

            // Growth YoY — hitung tahun sebelumnya dari current_month
            let yoyPrevLabel = 'Tahun Lalu';
            if (summary.current_month) {
                const parts = summary.current_month.split(' ');
                if (parts.length === 2) {
                    yoyPrevLabel = `${parts[0]} ${parseInt(parts[1], 10) - 1}`;
                }
            }
            renderCombinedDelta(
                els.kreditGrowthYoy, els.kreditGrowthYoySub,
                summary.growth_yoy_nominal, summary.growth_yoy_percent,
                summary.current_month, yoyPrevLabel
            );
        }

        function renderKreditChart(payload) {
            const target = document.getElementById('chartKreditTarget');
            if (appState.kreditChart) {
                appState.kreditChart.destroy();
                appState.kreditChart = null;
            }
            if (!payload.series || payload.series.length === 0) {
                target.innerHTML = '<div class="empty-state"><i class="bi bi-graph-down text-secondary fs-1"></i><strong>Tidak ada data.</strong></div>';
                return;
            }
            const pointAnnotations = [];
            payload.series.forEach((s, si) => {
                const data = s.data || [];
                let lastIdx = -1,
                    lastVal = null;
                data.forEach((v, i) => {
                    if (v !== null && v !== undefined) {
                        lastVal = v;
                        lastIdx = i;
                    }
                });
                if (lastIdx >= 0 && lastVal !== null) {
                    pointAnnotations.push({
                        x: payload.categories[lastIdx],
                        y: lastVal,
                        seriesIndex: si,
                        marker: {
                            size: 5,
                            fillColor: '#fff',
                            strokeColor: '#E10600',
                            radius: 2
                        },
                        label: {
                            text: [s.name.length > 18 ? s.name.slice(0, 16) + '…' : s.name, formatNumberWithUnit(lastVal)],
                            borderColor: '#fecdd3',
                            style: {
                                background: '#fff1f2',
                                color: '#be123c',
                                fontSize: '9px',
                                fontWeight: 700,
                                padding: {
                                    left: 4,
                                    right: 4,
                                    top: 2,
                                    bottom: 2
                                }
                            }
                        }
                    });
                }
            });
            const options = {
                series: payload.series,
                chart: {
                    height: 460,
                    type: 'line',
                    toolbar: {
                        show: true,
                        tools: {
                            download: true,
                            pan: false,
                            reset: true,
                            zoom: true,
                            zoomin: true,
                            zoomout: true
                        }
                    },
                    fontFamily: 'Inter, sans-serif',
                    animations: {
                        enabled: true,
                        easing: 'easeinout',
                        speed: 600
                    },
                    background: 'transparent'
                },
                stroke: {
                    width: payload.series.map(s => s.type === 'line' ? 3 : 0),
                    curve: 'smooth'
                },
                colors: ['#E10600', '#0f172a', '#0891b2', '#f97316', '#16a34a', '#7c3aed', '#db2777', '#ca8a04', '#475569', '#be123c'],
                fill: {
                    opacity: payload.series.map(s => s.type === 'line' ? 1 : 0.8)
                },
                markers: {
                    size: payload.series.map(s => s.type === 'line' ? 3 : 0)
                },
                plotOptions: {
                    bar: {
                        columnWidth: '60%',
                        borderRadius: 3
                    }
                },
                xaxis: {
                    categories: payload.categories,
                    labels: {
                        rotate: -35,
                        style: {
                            colors: '#64748b',
                            fontWeight: 600,
                            fontSize: '11px'
                        }
                    },
                    axisBorder: {
                        show: false
                    },
                    axisTicks: {
                        show: false
                    }
                },
                yaxis: {
                    labels: {
                        formatter: v => formatNumberWithUnit(v),
                        style: {
                            colors: '#64748b'
                        }
                    }
                },
                grid: {
                    borderColor: 'rgba(229,231,235,.4)',
                    strokeDashArray: 4
                },
                tooltip: {
                    shared: true,
                    intersect: false,
                    y: {
                        formatter: v => formatNumberWithUnit(v)
                    }
                },
                annotations: {
                    points: pointAnnotations
                },
                legend: {
                    position: 'bottom',
                    horizontalAlign: 'center',
                    fontWeight: 700,
                    fontSize: '12px',
                    itemMargin: {
                        horizontal: 8
                    }
                },
                dataLabels: {
                    enabled: false
                }
            };
            appState.kreditChart = new ApexCharts(target, options);
            appState.kreditChart.render();
        }

        // ============================================================
        // DANA CHART (timeline)
        // ============================================================
        function renderChart(els, series) {
            if (appState.chart) {
                appState.chart.destroy();
                appState.chart = null;
            }
            // Hide calendar, show chart
            document.getElementById('panelPerbandingan') && (document.getElementById('panelPerbandingan').style.display = series.length ? 'block' : 'none');
            if (!series.length) {
                els.chartEmpty.style.display = 'flex';
                els.chartTarget.innerHTML = '';
                return;
            }
            els.chartEmpty.style.display = 'none';
            const bottomMarkers = [],
                pointAnnotations = [];
            series.forEach((item, seriesIndex) => {
                if (item.bottom_index >= 0) {
                    bottomMarkers.push({
                        seriesIndex,
                        dataPointIndex: item.bottom_index,
                        fillColor: '#fff',
                        strokeColor: '#E10600',
                        size: 7,
                        shape: 'circle'
                    });
                    pointAnnotations.push({
                        x: item.bottom_index + 1,
                        y: item.bottom_value,
                        seriesIndex,
                        marker: {
                            size: 6,
                            fillColor: '#fff',
                            strokeColor: '#E10600',
                            radius: 2
                        },
                        label: {
                            text: ['Bottom', Math.round(item.bottom_value).toLocaleString('id-ID') + ' M'],
                            borderColor: '#fecdd3',
                            style: {
                                background: '#fff',
                                color: '#be123c',
                                fontSize: '10px',
                                fontWeight: 700
                            }
                        }
                    });
                }
                if (item.end_index >= 0) {
                    pointAnnotations.push({
                        x: item.end_index + 1,
                        y: item.end_value,
                        seriesIndex,
                        marker: {
                            size: 5,
                            fillColor: '#fff',
                            strokeColor: '#0f172a',
                            radius: 2
                        },
                        label: {
                            text: ['Ending', Math.round(item.end_value).toLocaleString('id-ID') + ' M'],
                            borderColor: '#d8dee8',
                            style: {
                                background: '#fff',
                                color: '#0f172a',
                                fontSize: '10px',
                                fontWeight: 700
                            }
                        }
                    });
                }
            });
            const options = {
                series: series.map(item => ({
                    name: item.name,
                    data: item.data
                })),
                chart: {
                    type: 'line',
                    height: 400,
                    toolbar: {
                        show: false
                    },
                    animations: {
                        enabled: true,
                        easing: 'easeinout',
                        speed: 650
                    },
                    fontFamily: 'Inter, sans-serif',
                    background: 'transparent'
                },
                annotations: {
                    points: pointAnnotations
                },
                colors: ['#94a3b8', '#0f172a', '#E10600', '#f97316', '#16a34a', '#7c3aed', '#0891b2', '#db2777'],
                stroke: {
                    curve: 'smooth',
                    width: 3
                },
                markers: {
                    size: 0,
                    discrete: bottomMarkers
                },
                grid: {
                    borderColor: 'rgba(229, 231, 235, 0.4)',
                    strokeDashArray: 4
                },
                xaxis: {
                    categories: Array.from({
                        length: 31
                    }, (_, i) => i + 1),
                    labels: {
                        style: {
                            colors: '#64748b',
                            fontWeight: 700
                        }
                    },
                    axisBorder: {
                        show: false
                    },
                    axisTicks: {
                        show: false
                    }
                },
                yaxis: {
                    labels: {
                        formatter: value => formatNumber(value),
                        style: {
                            colors: '#64748b'
                        }
                    }
                },
                legend: {
                    position: 'bottom',
                    horizontalAlign: 'left',
                    fontWeight: 700
                },
                tooltip: {
                    theme: 'light',
                    y: {
                        formatter: value => formatNumberWithUnit(value)
                    }
                },
                noData: {
                    text: 'Tidak ada data'
                }
            };
            appState.chart = new ApexCharts(els.chartTarget, options);
            appState.chart.render();
        }

        // ============================================================
        // COMPARISON CHART
        // ============================================================
        function renderComparisonChart(els, payload) {
            const compPanel = document.getElementById('panelPerbandingan');
            const compSelect = document.getElementById('comboChartType');
            if (appState.comparisonChart) {
                appState.comparisonChart.destroy();
                appState.comparisonChart = null;
            }
            const compSeries = payload.comparison_series || [];
            if (compSeries.length === 0) {
                compPanel.style.display = 'none';
                return;
            }
            compPanel.style.display = 'block';
            let finalSeries = [],
                yAxesConfig = [],
                tooltipFormatters = [],
                pointAnnotations = [];
            const barDataLabelFormatters = [];
            compSeries.forEach((s, index) => {
                let displayName = s.name,
                    isSatuan = false;
                if (s.name.includes('_1')) {
                    displayName = 'Endbal New CIF';
                } else if (s.name.includes('_2')) {
                    displayName = 'New CIF';
                    isSatuan = true;
                }
                let seriesType = 'line';
                if (compSelect.value === 'bar') seriesType = 'column';
                else if (compSelect.value === 'mixed') seriesType = isSatuan ? 'column' : 'line';
                finalSeries.push({
                    name: displayName,
                    type: seriesType,
                    data: s.data
                });
                const customFormatter = v => (v === null || v === undefined) ? '-' : (isSatuan ? formatNumber(v) : formatNumberWithUnit(v));
                tooltipFormatters.push({
                    formatter: customFormatter
                });
                barDataLabelFormatters.push({
                    isColumn: seriesType === 'column',
                    isSatuan,
                    formatter: customFormatter
                });
                yAxesConfig.push({
                    opposite: isSatuan && compSelect.value === 'mixed',
                    title: {
                        text: displayName + (isSatuan ? ' (Rek)' : ' (M)'),
                        style: {
                            fontWeight: 600,
                            color: '#64748b',
                            fontSize: '11px'
                        }
                    },
                    labels: {
                        formatter: customFormatter,
                        style: {
                            colors: '#64748b'
                        }
                    }
                });
                const dataArray = s.data || [];
                let endIdx = -1,
                    endVal = null;
                for (let i = dataArray.length - 1; i >= 0; i--) {
                    if (dataArray[i] !== null && dataArray[i] !== undefined && dataArray[i] !== 0) {
                        endIdx = i;
                        endVal = dataArray[i];
                        break;
                    }
                }
                if (endIdx >= 0 && endVal !== null) {
                    const ev = isSatuan ? Math.round(endVal).toLocaleString('id-ID') : Math.round(endVal).toLocaleString('id-ID') + ' M';
                    const mc = isSatuan ? '#0891b2' : '#E10600';
                    const bg = isSatuan ? '#f0f9ff' : '#fff1f2';
                    const tc = isSatuan ? '#0369a1' : '#be123c';
                    const bc = isSatuan ? '#bae6fd' : '#fecdd3';
                    pointAnnotations.push({
                        x: endIdx + 1,
                        y: endVal,
                        seriesIndex: index,
                        marker: {
                            size: seriesType === 'line' ? 7 : 0,
                            fillColor: '#fff',
                            strokeColor: mc,
                            radius: 2,
                            strokeWidth: 2
                        },
                        label: {
                            text: [isSatuan ? 'Akhir CIF' : 'Endbal New CIF', ev],
                            borderColor: bc,
                            offsetY: -12,
                            style: {
                                background: bg,
                                color: tc,
                                fontSize: '10px',
                                fontWeight: 700,
                                padding: {
                                    left: 5,
                                    right: 5,
                                    top: 3,
                                    bottom: 3
                                }
                            }
                        }
                    });
                }
            });
            const options = {
                series: finalSeries,
                chart: {
                    height: 380,
                    type: 'line',
                    toolbar: {
                        show: true,
                        tools: {
                            download: true,
                            pan: false,
                            reset: true,
                            zoom: true,
                            zoomin: true,
                            zoomout: true
                        }
                    },
                    fontFamily: 'Inter, sans-serif',
                    animations: {
                        enabled: true,
                        easing: 'easeinout',
                        speed: 500
                    }
                },
                stroke: {
                    width: finalSeries.map(s => s.type === 'line' ? 3 : 0),
                    curve: 'smooth'
                },
                colors: ['#0f172a', '#E10600', '#0891b2', '#f97316'],
                fill: {
                    opacity: finalSeries.map(s => s.type === 'line' ? 1 : 0.8)
                },
                markers: {
                    size: finalSeries.map(s => s.type === 'line' ? 4 : 0)
                },
                dataLabels: {
                    enabled: true,
                    enabledOnSeries: finalSeries.map((s, i) => s.type === 'column' ? i : null).filter(i => i !== null),
                    formatter: function(val, opts) {
                        if (!val || val === 0) return '';
                        const def = barDataLabelFormatters[opts.seriesIndex];
                        if (!def || !def.isColumn) return '';
                        return def.isSatuan ? Math.round(val).toLocaleString('id-ID') : Math.round(val).toLocaleString('id-ID') + ' M';
                    },
                    style: {
                        fontSize: '9px',
                        fontWeight: 700,
                        colors: ['#004cff']
                    },
                    offsetY: -6,
                    background: {
                        enabled: true,
                        foreColor: '#0f172a',
                        borderRadius: 3,
                        padding: 2,
                        opacity: 0.8,
                        borderWidth: 0
                    }
                },
                plotOptions: {
                    bar: {
                        columnWidth: '55%',
                        borderRadius: 3,
                        dataLabels: {
                            position: 'top'
                        }
                    }
                },
                xaxis: {
                    categories: Array.from({
                        length: 31
                    }, (_, i) => i + 1),
                    labels: {
                        style: {
                            colors: '#64748b',
                            fontWeight: 700
                        }
                    }
                },
                yaxis: yAxesConfig,
                tooltip: {
                    shared: true,
                    intersect: false,
                    y: tooltipFormatters
                },
                legend: {
                    position: 'top',
                    horizontalAlign: 'right',
                    fontWeight: 700
                },
                annotations: {
                    points: pointAnnotations
                },
                grid: {
                    borderColor: 'rgba(229,231,235,0.4)',
                    strokeDashArray: 4
                }
            };
            appState.comparisonChart = new ApexCharts(document.getElementById('chartPerbandinganTarget'), options);
            appState.comparisonChart.render();
            compSelect.onchange = null;
            compSelect.onchange = () => renderComparisonChart(els, payload);
        }

        function findMaxDateLabel(series) {
            const MONTHS_ID = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            let maxVal = null,
                maxDay = null,
                maxMonthKey = null;
            (series || []).forEach(s => {
                (s.data || []).forEach((v, idx) => {
                    if (v !== null && (maxVal === null || v > maxVal)) {
                        maxVal = v;
                        maxDay = idx + 1;
                        maxMonthKey = s.month_key;
                    }
                });
            });
            if (!maxMonthKey) return null;
            const [yr, mo] = maxMonthKey.split('-').map(Number);
            return `${maxDay} ${MONTHS_ID[mo-1]} ${yr}`;
        }
        // ============================================================
        // SUMMARY RENDERERS
        // ============================================================
        function renderSummary(els, payload) {
            if (!payload) {
                renderEmptySummary(els);
                return;
            }

            const summary = payload.summary || {};

            els.scopeLabel.textContent = payload.label || '-';
            els.scopeSub.textContent = `${payload.period} | ${payload.group === 'kredit' ? 'Produk Kredit' : 'Produk Dana'}`;

            // Ending Balance
            els.latestBalance.textContent = formatNumberWithUnit(summary.end_balance);
            els.latestBalanceSub.textContent = summary.current_month ?
                `Ending balance ${summary.current_month}` :
                'Saldo akhir bulan aktif.';

            // Bottom Balance
            els.latestBottomBalance.textContent = formatNumberWithUnit(summary.bottom_balance);
            els.latestBottomSub.textContent = summary.current_month ?
                `Bottom balance ${summary.current_month}` :
                'Saldo terendah bulan aktif.';

            // Maximum Balance — dari periode yang dipilih (dihitung PHP dari $series filtered)
            els.maxBalance.textContent = formatNumberWithUnit(summary.max_balance);
            const maxDateLabel = findMaxDateLabel(payload.series || []);
            els.maxBalanceSub.textContent = maxDateLabel ?
                `Titik Tertinggi pada ${maxDateLabel}` :
                'Titik tertinggi di periode aktif.';

            // Growth YTD
            renderCombinedDelta(
                els.growthYtdCombined, els.growthYtdCombinedSub,
                summary.growth_ytd_nominal, summary.growth_ytd_percent,
                summary.current_month, 'Des Tahun Lalu'
            );

            // Growth YoY — hitung tahun sebelumnya dari current_month
            let yoyPrevLabel = 'Tahun Lalu';
            if (summary.current_month) {
                const parts = summary.current_month.split(' ');
                if (parts.length === 2) {
                    yoyPrevLabel = `${parts[0]} ${parseInt(parts[1], 10) - 1}`;
                }
            }
            renderCombinedDelta(
                els.growthYoyCombined, els.growthYoyCombinedSub,
                summary.growth_yoy_nominal, summary.growth_yoy_percent,
                summary.current_month, yoyPrevLabel
            );

            // Growth End Balance (MtD)
            renderCombinedDelta(
                els.growthEndCombined, els.growthEndCombinedSub,
                summary.growth_end_nominal, summary.growth_end_percent,
                summary.current_month, summary.previous_month
            );

            // Growth Bottom Balance (MtD)
            renderCombinedDelta(
                els.growthBottomCombined, els.growthBottomCombinedSub,
                summary.growth_bottom_nominal, summary.growth_bottom_percent,
                summary.current_month, summary.previous_month
            );

            if (els.activeProductName) els.activeProductName.textContent = payload.product ? `(${payload.product})` : '';
            if (els.activeProductNameComp) els.activeProductNameComp.textContent = payload.product ? `(${payload.product})` : '';

            els.chartSubtitle.textContent = `${payload.period} | ${payload.label} | ${payload.months.join(', ')}`;
            setStatus(els, 'Data Siap', `Menampilkan grafik performa untuk ${payload.label}.`, 'success');
        }

        function renderEmptySummary(els) {
            [els.scopeLabel, els.maxBalance, els.latestBalance, els.latestBottomBalance, els.growthEndCombined, els.growthBottomCombined, els.growthYtdCombined, els.growthYoyCombined].forEach(el => {
                if (el) {
                    el.textContent = '-';
                    el.classList.remove('delta-up', 'delta-down', 'delta-flat');
                }
            });
            els.scopeSub.textContent = '-';
            els.maxBalanceSub.textContent = 'Titik tertinggi bulan aktif.';
            els.latestBalanceSub.textContent = 'Saldo akhir bulan aktif.';
            els.latestBottomSub.textContent = 'Saldo terendah bulan aktif.';
            if (els.growthEndCombinedSub) els.growthEndCombinedSub.textContent = 'Bulan sebelumnya vs Bulan aktif.';
            if (els.growthBottomCombinedSub) els.growthBottomCombinedSub.textContent = 'Bulan sebelumnya vs Bulan aktif.';
            if (els.growthYtdCombinedSub) els.growthYtdCombinedSub.textContent = 'YTD vs Bulan aktif.';
            if (els.growthYoyCombinedSub) els.growthYoyCombinedSub.textContent = 'Tahun lalu vs Bulan aktif.';
            if (els.activeProductName) els.activeProductName.textContent = '';
            if (els.activeProductNameComp) els.activeProductNameComp.textContent = '';
            els.chartSubtitle.textContent = 'Hari 1 sampai 31, multi-series per bulan.';
        }

        // Tambah parameter `inverse` di akhir (default false, backward compatible)
        function renderCombinedDelta(valueEl, subEl, nominal, percent, currentMonth, previousMonth, inverse = false) {
            if (!valueEl || !subEl) return;
            valueEl.classList.remove('delta-up', 'delta-down', 'delta-flat', 'text-success');

            if (nominal === null || nominal === undefined || percent === null || percent === undefined) {
                valueEl.textContent = '-';
                subEl.textContent = 'Data komparasi tidak lengkap.';
                valueEl.classList.add('delta-flat');
                return;
            }

            const num = Number(nominal);
            const prefix = num > 0 ? '+' : '';
            valueEl.textContent = `${prefix}${percentFormatter.format(percent)}% (${prefix}${formatNumberWithUnit(nominal)})`;

            // Jika inverse: naik = merah (buruk), turun = hijau (baik)
            const isPositive = num > 0;
            const isNegative = num < 0;
            const upClass = inverse ? 'delta-down' : 'delta-up';
            const downClass = inverse ? 'delta-up' : 'delta-down';

            valueEl.classList.add(isPositive ? upClass : isNegative ? downClass : 'delta-flat');

            const iconEl = valueEl.closest('.mini-stat')?.querySelector('.stat-icon');
            if (iconEl) {
                const greenOrRed = inverse ? 'red' : 'green';
                const redOrGreen = inverse ? 'green' : 'red';
                iconEl.className = 'stat-icon ' + (isPositive ? greenOrRed : isNegative ? redOrGreen : 'slate');
            }

            subEl.textContent = currentMonth && previousMonth ?
                `${previousMonth} vs ${currentMonth}` :
                'Data komparasi tidak lengkap.';
        }

        // Function khusus KOL / NPL
        // realisasiEndBalance = Endbal Realisasi (misal 100M) — dipass dari luar
        function renderKolNplSummary(els, payload, realisasiEndBalance) {
            if (!payload || !payload.summary) {
                if (els.kreditScopeLabel) els.kreditScopeLabel.textContent = '-';
                if (els.kreditScopeSub) els.kreditScopeSub.textContent = '-';
                if (els.kreditLatestBalance) els.kreditLatestBalance.textContent = '-';
                if (els.kreditLatestBalanceSub) els.kreditLatestBalanceSub.textContent = 'Saldo akhir bulan aktif.';
                renderCombinedDelta(els.kreditGrowthMtd, els.kreditGrowthMtdSub, null, null, null, null, true);
                renderCombinedDelta(els.kreditGrowthYtd, els.kreditGrowthYtdSub, null, null, null, null, true);
                renderCombinedDelta(els.kreditGrowthYoy, els.kreditGrowthYoySub, null, null, null, null, true);
                return;
            }

            const summary = payload.summary;

            if (els.kreditScopeLabel) els.kreditScopeLabel.textContent = payload.label || '-';
            if (els.kreditScopeSub) els.kreditScopeSub.textContent = 'Produk Terpilih';

            const scopeChip = document.getElementById('kreditScopeChip');
            if (scopeChip) scopeChip.textContent = payload.label || '-';

            const chartSub = document.getElementById('kreditChartSubtitle');
            if (chartSub && summary.current_month)
                chartSub.textContent = `${summary.current_month} | ${payload.label || '-'}`;

            // ── Ending Balance: nominal + % terhadap Realisasi ──
            if (els.kreditLatestBalance) {
                const endBal = summary.end_balance;
                const nominal = formatNumberWithUnit(endBal);
                const hasRatio = realisasiEndBalance !== null &&
                    realisasiEndBalance !== undefined &&
                    realisasiEndBalance !== 0;
                const ratioStr = hasRatio ?
                    ` (${((endBal / realisasiEndBalance) * 100).toFixed(2)}%)` :
                    '';
                els.kreditLatestBalance.textContent = `${nominal}${ratioStr}`;
            }

            if (els.kreditLatestBalanceSub)
                els.kreditLatestBalanceSub.textContent = summary.current_month ?
                `Ending balance ${summary.current_month}` :
                'Saldo akhir bulan aktif.';

            // ── Growth: inverse = true (naik = merah) ──
            renderCombinedDelta(
                els.kreditGrowthMtd, els.kreditGrowthMtdSub,
                summary.growth_mtd_nominal, summary.growth_mtd_percent,
                summary.current_month, summary.previous_month,
                true // inverse
            );

            renderCombinedDelta(
                els.kreditGrowthYtd, els.kreditGrowthYtdSub,
                summary.growth_ytd_nominal, summary.growth_ytd_percent,
                summary.current_month, 'Des Tahun Lalu',
                true // inverse
            );

            let yoyPrevLabel = 'Tahun Lalu';
            if (summary.current_month) {
                const parts = summary.current_month.split(' ');
                if (parts.length === 2)
                    yoyPrevLabel = `${parts[0]} ${parseInt(parts[1], 10) - 1}`;
            }
            renderCombinedDelta(
                els.kreditGrowthYoy, els.kreditGrowthYoySub,
                summary.growth_yoy_nominal, summary.growth_yoy_percent,
                summary.current_month, yoyPrevLabel,
                true // inverse
            );
        }
        // ============================================================
        // TABLE
        // ============================================================

        /**
         * Render tabel Ending Balance & Bottom Balance bulanan (Jan–Des) multi-tahun
         * dengan baris % YoY, disisipkan setelah panel tabel harian.
         * @param {Array} allSeriesSummary - payload.all_series_summary dari PHP
         */
        function renderMonthlySummaryTables(allSeriesSummary) {
            let container = document.getElementById('monthlySummaryTables');
            if (!container) {
                const dailyPanel = document.querySelector('#tbodyNeraca')?.closest('.panel');
                if (!dailyPanel) return;
                container = document.createElement('div');
                container.id = 'monthlySummaryTables';
                container.className = 'mt-3';
                dailyPanel.insertAdjacentElement('afterend', container);
            }

            if (!allSeriesSummary || !allSeriesSummary.length) {
                container.innerHTML = '';
                return;
            }

            // Build map: { "2024": { "01": { end, bottom }, ... }, ... }
            const dataMap = {};
            allSeriesSummary.forEach(s => {
                if (!s.month_key) return;
                const [yr, mo] = s.month_key.split('-');
                if (!dataMap[yr]) dataMap[yr] = {};
                dataMap[yr][mo] = {
                    end: s.end_value ?? null,
                    bottom: s.bottom_value ?? null,
                };
            });

            const years = Object.keys(dataMap).sort();
            const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            const MONTH_NUMS = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];

            function pctCell(curr, prev) {
                if (curr === null || prev === null || prev === 0)
                    return '<span style="color:#cbd5e1;font-size:.68rem;">-</span>';
                const pct = ((curr - prev) / Math.abs(prev)) * 100;
                const color = pct >= 0 ? '#15803d' : '#dc2626';
                const sign = pct >= 0 ? '+' : '';
                return `<span style="color:${color};font-weight:800;font-size:.7rem;">${sign}${pct.toFixed(1)}%</span>`;
            }

            function buildTable(title, accentColor, valKey) {
                let html = `
        <div class="panel p-3 p-md-4 mb-3">
            <div class="d-flex align-items-center gap-2 mb-3">
                <span style="display:inline-block;width:4px;height:22px;border-radius:2px;background:${accentColor};flex-shrink:0;"></span>
                <h5 class="rajdhani fw-bold mb-0">${title} <span style="color:${accentColor};">(Jan – Des)</span></h5>
            </div>
            <div class="table-responsive">
            <table class="table table-daily table-hover">
                <thead>
                    <tr>
                        <th style="text-align:left;">${valKey === 'end' ? 'Endbal' : 'Bottom'}</th>
                        ${MONTHS_SHORT.map(m => `<th>${m}</th>`).join('')}
                    </tr>
                </thead>
                <tbody>`;

                let prevYr = null;
                years.forEach(yr => {
                    // ── Baris nilai tahun ──
                    html += `<tr>
                <td style="font-weight:800;color:#1e293b;white-space:nowrap;">${yr}</td>`;
                    MONTH_NUMS.forEach(mo => {
                        const v = dataMap[yr]?.[mo]?.[valKey] ?? null;
                        html += `<td>${v !== null
                    ? `<span style="font-weight:600;">${formatNumberWithUnit(v)}</span>`
                    : '<span style="color:#cbd5e1;">-</span>'
                }</td>`;
                    });
                    // ── Baris % MtD (MoM vs bulan sebelumnya) ──
                    html += `<tr style="background:rgba(234,244,255,0.45);">
                <td style="font-size:.65rem;color:#64748b;font-weight:700;white-space:nowrap;">
                    % MtD ${yr}
                </td>`;
                    MONTH_NUMS.forEach((mo, idx) => {
                        if (idx === 0) {
                            // Januari — tidak ada bulan sebelumnya
                            html += `<td><span style="color:#cbd5e1;font-size:.68rem;">-</span></td>`;
                        } else {
                            const prevMo = MONTH_NUMS[idx - 1];
                            const curr = dataMap[yr]?.[mo]?.[valKey] ?? null;
                            const prev = dataMap[yr]?.[prevMo]?.[valKey] ?? null;
                            html += `<td>${pctCell(curr, prev)}</td>`;
                        }
                    });
                    html += `</tr>`;
                    html += `</tr>`;

                    // ── Baris % YoY ──
                    if (prevYr) {
                        html += `<tr style="background:rgba(241,245,249,0.7);">
                    <td style="font-size:.65rem;color:#64748b;font-weight:700;white-space:nowrap;">
                        % YoY vs ${prevYr}
                    </td>`;
                        MONTH_NUMS.forEach(mo => {
                            const curr = dataMap[yr]?.[mo]?.[valKey] ?? null;
                            const prev = dataMap[prevYr]?.[mo]?.[valKey] ?? null;
                            html += `<td>${pctCell(curr, prev)}</td>`;
                        });
                        html += `</tr>`;
                    }



                    prevYr = yr;
                });

                html += `</tbody></table></div></div>`;
                return html;
            }

            container.innerHTML =
                buildTable('Ending Balance Bulanan', '#16a34a', 'end') +
                buildTable('Bottom Balance Bulanan', '#dc2626', 'bottom');
        }

        function renderTable(els, series) {
            let headHtml = '<tr><th>Bulan</th>';
            for (let day = 1; day <= 31; day++) headHtml += `<th>${day}</th>`;
            headHtml += '</tr>';
            els.thead.innerHTML = headHtml;
            if (!series.length) {
                renderEmptyTable(els);
                return;
            }
            let bodyHtml = '';
            series.forEach(item => {
                bodyHtml += `<tr><td>${escapeHtml(item.name)}</td>`;
                item.data.forEach((value, index) => {
                    if (value === null) bodyHtml += '<td class="cell-empty">-</td>';
                    else bodyHtml += `<td class="${index === item.bottom_index ? 'cell-bottom' : ''}">${formatNumberWithUnit(value)}</td>`;
                });
                bodyHtml += '</tr>';
            });
            els.tbody.innerHTML = bodyHtml;
        }

        function renderEmptyTable(els) {
            let headHtml = '<tr><th>Bulan</th>';
            for (let day = 1; day <= 31; day++) headHtml += `<th>${day}</th>`;
            headHtml += '</tr>';
            els.thead.innerHTML = headHtml;
            els.tbody.innerHTML = '<tr><td class="text-center text-secondary py-4" colspan="32">Belum ada data.</td></tr>';
        }

        // ============================================================
        // KREDIT SUMMARY TABLE — semua produk, di atas layout filter+chart
        // ============================================================
        async function renderKreditSummaryTable(id) {
            const kreditWs = document.getElementById('kreditWorkspace');
            if (!kreditWs) return;

            let container = document.getElementById('kreditSummaryContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'kreditSummaryContainer';
                container.className = 'panel p-3 p-md-4 mb-3';
                // Sisip sebelum baris utama (row g-3) yang berisi sidebar + chart
                const chartEl = document.getElementById('chartKreditTarget');
                const chartCard = chartEl?.closest('.panel') || chartEl?.parentElement;

                if (chartCard) {
                    chartCard.insertAdjacentElement('afterend', container);
                } else {
                    kreditWs.appendChild(container);
                }
            }

            container.innerHTML = `<div class="d-flex align-items-center gap-2 py-1">
        <div class="spinner-border spinner-border-sm text-danger"></div>
        <span class="small text-secondary ms-2">Memuat tabel summary kredit...</span></div>`;

            try {
                const res = await fetch(`${apiBase}?action=kredit_summary&id=${encodeURIComponent(id || '')}`);
                const data = await res.json();

                if (!data.ok || !data.rows?.length) {
                    container.innerHTML = '';
                    return;
                }

                const rows = data.rows;
                const r0 = rows[0];

                // Helper: tampilkan pertumbuhan nominal + persen dengan warna
                const growthCell = (nominal, percent) => {
                    if (nominal === null || nominal === undefined) return `<span style="color:#94a3b8;">-</span>`;
                    const n = Number(nominal);
                    const isPos = n >= 0;
                    const col = isPos ? '#15803d' : '#dc2626';
                    const sign = isPos ? '+' : '';
                    const pStr = (percent !== null && percent !== undefined) ?
                        `<span style="font-size:.68rem;color:${col};opacity:.75;"> (${sign}${Number(percent).toFixed(1)}%)</span>` :
                        '';
                    return `<span style="color:${col};font-weight:800;">${sign}${formatNumberWithUnitv2(n)}</span>${pStr}`;
                };

                const valCell = (v, bold) => {
                    if (v === null || v === undefined) return `<span style="color:#94a3b8;">-</span>`;
                    return `<span style="font-weight:${bold ? '800' : '600'};font-size:.82rem;">${formatNumberWithUnitv2(v)}</span>`;
                };

                let html = `
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span style="display:inline-block;width:4px;height:22px;border-radius:2px;background:#E10600;flex-shrink:0;"></span>
                <div>
                    <div class="form-label mb-0">Summary Kredit</div>
                    <h5 class="rajdhani fw-bold mb-0">${escapeHtml(data.label || '-')}</h5>
                </div>
            </div>
            <span class="small text-secondary">dalam Miliar (M)</span>
        </div>
        <div class="table-responsive">
        <table class="table table-hover" style="font-size:.82rem;margin-bottom:0;">
        <thead>
            <tr style="background:rgba(21,21,30,.95);">
                <th style="color:#fff;font-weight:700;padding:10px 12px;min-width:160px;position:sticky;left:0;z-index:3;">Produk</th>
                <th style="color:#94a3b8;font-weight:700;text-align:right;padding:10px 8px;white-space:nowrap;">${escapeHtml(r0.yoy_ym)}</th>
                <th style="color:#94a3b8;font-weight:700;text-align:right;padding:10px 8px;white-space:nowrap;">${escapeHtml(r0.ytd_ym)}</th>
                <th style="color:#94a3b8;font-weight:700;text-align:right;padding:10px 8px;white-space:nowrap;">${escapeHtml(r0.prev_ym)}</th>
                <th style="color:#60a5fa;font-weight:800;text-align:right;padding:10px 8px;white-space:nowrap;background:rgba(37,99,235,.2);">${escapeHtml(r0.current_ym)} ✦</th>
                <th style="color:#fca5a5;font-weight:700;text-align:right;padding:10px 8px;">MtD</th>
                <th style="color:#fca5a5;font-weight:700;text-align:right;padding:10px 8px;">YtD</th>
                <th style="color:#fca5a5;font-weight:700;text-align:right;padding:10px 8px;">YoY</th>
            </tr>
        </thead><tbody>`;

                rows.forEach(r => {
                    const bg = r.bold ? 'rgba(241,245,249,.9)' : 'rgba(255,255,255,.9)';
                    const fw = r.bold ? '800' : '600';
                    const col = r.bold ? '#0f172a' : '#334155';
                    const topBorder = r.bold ? 'border-top:2px solid rgba(203,213,225,.7);' : '';

                    html += `<tr style="background:${bg};${topBorder}">
                <td style="font-weight:${fw};color:${col};padding:8px 12px;position:sticky;left:0;background:${bg};z-index:1;">
                    ${escapeHtml(r.label)}
                </td>
                <td style="text-align:right;padding:7px 8px;">${valCell(r.yoy_val,  r.bold)}</td>
                <td style="text-align:right;padding:7px 8px;">${valCell(r.ytd_val,  r.bold)}</td>
                <td style="text-align:right;padding:7px 8px;">${valCell(r.prev_val, r.bold)}</td>
                <td style="text-align:right;padding:7px 8px;background:rgba(37,99,235,.06);">${valCell(r.current_val, true)}</td>
                <td style="text-align:right;padding:7px 8px;">${growthCell(r.mtd_nominal, r.mtd_percent)}</td>
                <td style="text-align:right;padding:7px 8px;">${growthCell(r.ytd_nominal, r.ytd_percent)}</td>
                <td style="text-align:right;padding:7px 8px;">${growthCell(r.yoy_nominal, r.yoy_percent)}</td>
            </tr>`;
                });

                html += `</tbody></table></div>
        <div class="d-flex flex-wrap gap-3 mt-2" style="font-size:.67rem;color:#94a3b8;">
            <span>MtD: vs ${escapeHtml(r0.prev_ym)}</span>
            <span>YtD: vs ${escapeHtml(r0.ytd_ym)}</span>
            <span>YoY: vs ${escapeHtml(r0.yoy_ym)}</span>
        </div>`;

                container.innerHTML = html;

            } catch (e) {
                container.innerHTML = `<div class="small text-secondary p-2">Tabel summary tidak tersedia: ${escapeHtml(e.message)}</div>`;
            }
        }



        // ============================================================
        // KREDIT MONTHLY TABLE — di bawah chart
        // ============================================================
        function renderKreditMonthlyTable(payload) {

            const chartEl = document.getElementById('chartKreditTarget');
            if (!chartEl) return;

            const rightCol = chartEl.closest('.col-xl-9') || chartEl.closest('.panel')?.parentElement;
            if (!rightCol) return;

            // =========================
            // 🔹 CREATE / GET CONTAINER
            // =========================
            let container = document.getElementById('kreditMonthlyContainer');

            if (!container) {
                container = document.createElement('div');
                container.id = 'kreditMonthlyContainer';
                container.className = 'panel p-3 p-md-4 mt-3';
            }

            // =========================
            // 🔥 ALWAYS FIX POSITION (ANTI ASYNC BUG)
            // =========================
            const summary = document.getElementById('kreditSummaryContainer');

            if (summary) {
                summary.insertAdjacentElement('afterend', container);
            } else {
                const chartCard = chartEl.closest('.panel') || chartEl.parentElement;
                chartCard.insertAdjacentElement('afterend', container);
            }

            // =========================
            // 🔹 DATA VALIDATION
            // =========================
            const series = payload.series || [];
            const cats = payload.categories || [];

            if (!series.length || !cats.length) {
                container.innerHTML = '';
                return;
            }

            // =========================
            // 🔹 HEADER
            // =========================
            let html = `
    <div class="d-flex align-items-center gap-2 mb-3">
        <span style="display:inline-block;width:4px;height:22px;border-radius:2px;background:#2563eb;"></span>
        <h5 class="rajdhani fw-bold mb-0">Tabel Bulanan Ending Balance Kredit</h5>
        <span class="small text-secondary ms-2">dalam Miliar (M)</span>
    </div>

    <div class="table-responsive">
    <table class="table table-daily table-hover" style="font-size:.72rem;">
    <thead>
        <tr>
            <th style="text-align:left;min-width:160px;position:sticky;left:0;z-index:3;background:rgba(21,21,30,.95);">Produk</th>
            ${cats.map(c => `<th style="white-space:nowrap;">${escapeHtml(c)}</th>`).join('')}
        </tr>
    </thead>
    <tbody>
    `;

            // =========================
            // 🔹 LOOP SERIES
            // =========================
            series.forEach((s, si) => {

                const data = s.data || [];
                const prevSeries = series[si - 1]?.data || null;

                const prodName = (s.name || '')
                    .replace(/\s*-\s*Endbal/i, '')
                    .replace(/\s*-\s*KOL/i, '')
                    .replace(/\s*-\s*NPL/i, '');

                // =========================
                // 🔹 BARIS NILAI
                // =========================
                html += `<tr>
            <td style="font-weight:700;color:#1e293b;position:sticky;left:0;background:rgba(255,255,255,.95);z-index:2;">
                ${escapeHtml(prodName)}
            </td>`;

                data.forEach(v => {
                    html += v !== null ?
                        `<td>${formatNumberWithUnit(v)}</td>` :
                        `<td><span style="color:#cbd5e1;">-</span></td>`;
                });

                html += `</tr>`;

                // =========================
                // 🔹 BARIS MtD
                // =========================
                html += `<tr style="background:rgba(234,244,255,.4);">
            <td style="font-size:.62rem;color:#64748b;font-weight:700;position:sticky;left:0;background:rgba(234,244,255,.8);z-index:2;">% MtD</td>`;

                data.forEach((v, i) => {
                    if (i === 0 || data[i - 1] === null || v === null) {
                        html += `<td><span style="color:#cbd5e1;font-size:.65rem;">-</span></td>`;
                    } else {
                        const pct = ((v - data[i - 1]) / Math.abs(data[i - 1])) * 100;
                        const col = pct >= 0 ? '#15803d' : '#dc2626';
                        html += `<td style="font-size:.68rem;font-weight:800;color:${col};">
                    ${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%
                </td>`;
                    }
                });

                html += `</tr>`;

                // =========================
                // 🔹 BARIS YoY (ANTAR SERIES)
                // =========================
                html += `<tr style="background:rgba(255,243,234,.4);">
            <td style="font-size:.62rem;color:#92400e;font-weight:700;position:sticky;left:0;background:rgba(255,243,234,.8);z-index:2;">% YoY</td>`;

                data.forEach((v, i) => {
                    if (!prevSeries || prevSeries[i] === null || v === null) {
                        html += `<td><span style="color:#cbd5e1;font-size:.65rem;">-</span></td>`;
                    } else {
                        const pct = ((v - prevSeries[i]) / Math.abs(prevSeries[i])) * 100;
                        const col = pct >= 0 ? '#15803d' : '#dc2626';
                        html += `<td style="font-size:.68rem;font-weight:800;color:${col};">
                    ${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%
                </td>`;
                    }
                });

                html += `</tr>`;

                // =========================
                // 🔹 SEPARATOR
                // =========================
                if (si < series.length - 1) {
                    html += `<tr>
                <td colspan="${cats.length + 1}" style="padding:1px 0;background:rgba(226,232,240,.5);"></td>
            </tr>`;
                }
            });

            html += `</tbody></table></div>`;
            container.innerHTML = html;
        }


        // ============================================================
        // GMM SECTION
        // ============================================================
        document.addEventListener('DOMContentLoaded', () => {
            if (!document.getElementById('appGmm')) return;

            const gmmState = {
                view: 'dashboard',
                kategori: 'LIVIN',
                filter: 'ALL',
                page: 1,
                sortCol: '',
                sortDir: 'desc',
                search: '',
                searchMode: 'pegawai',
                searchMetricMode: 'LIVIN',
                searchSortCol: 'end_balance',
                searchFilterArea: 'ALL',
                searchFilterPosisi: 'ALL',
                searchFilterKelas: 'ALL',
                _searchPayload: null
            };

            const sortDefs = {
                LIVIN: [{
                    col: 'end_balance',
                    label: 'End Balance',
                    fmt: 'rp'
                }, {
                    col: 'cif_akuisisi',
                    label: 'CIF Akuisisi',
                    fmt: 'num'
                }, {
                    col: 'cif_setor',
                    label: 'CIF Setor',
                    fmt: 'num'
                }, {
                    col: 'rata_rata',
                    label: 'Rata-rata Bal.',
                    fmt: 'rp'
                }, {
                    col: 'cif_sudah_transaksi',
                    label: 'CIF Transaksi',
                    fmt: 'num'
                }, {
                    col: 'frek_dari_cif_akuisisi',
                    label: 'Frek CIF',
                    fmt: 'num'
                }],
                MERCHANT: [{
                    col: 'total_referral_edc',
                    label: 'Referral EDC',
                    fmt: 'num'
                }, {
                    col: 'total_referral_livin',
                    label: 'Referral LVM',
                    fmt: 'num'
                }],
                TRANSAKSI: [{
                    col: 'pct_on_us',
                    label: '% On Us',
                    fmt: 'pct'
                }, {
                    col: 'total_poin_transaksi',
                    label: 'Total Poin',
                    fmt: 'num'
                }, {
                    col: 'frek_on_us',
                    label: 'Frek On Us',
                    fmt: 'num'
                }, {
                    col: 'frek_off_us',
                    label: 'Frek Off Us',
                    fmt: 'num'
                }, {
                    col: 'poin_on_us',
                    label: 'Poin On Us',
                    fmt: 'num'
                }, {
                    col: 'poin_off_us',
                    label: 'Poin Off Us',
                    fmt: 'num'
                }],
            };
            // Letakkan ini SETELAH definisi allMetricsDef, sebelum function renderGmmDashboard

            const pegawaiMetrics = {
                LIVIN: [{
                        key: 'end_balance',
                        label: 'End Bal',
                        fmt: 'rp',
                        bg: 'rgba(37,99,235,.07)',
                        tc: '#1d4ed8'
                    },
                    {
                        key: 'cif_akuisisi',
                        label: 'CIF Akuisisi',
                        fmt: 'num',
                        bg: 'rgba(124,58,237,.07)',
                        tc: '#6d28d9'
                    },
                    {
                        key: 'cif_setor',
                        label: 'CIF Setor',
                        fmt: 'num',
                        bg: 'rgba(8,145,178,.07)',
                        tc: '#0e7490'
                    },
                    {
                        key: 'rata_rata',
                        label: 'Rata-rata',
                        fmt: 'rp',
                        bg: 'rgba(22,163,74,.07)',
                        tc: '#15803d'
                    },
                    {
                        key: 'cif_sudah_transaksi',
                        label: 'CIF Trx',
                        fmt: 'num',
                        bg: 'rgba(249,115,22,.07)',
                        tc: '#c2410c'
                    },
                    {
                        key: 'frek_dari_cif_akuisisi',
                        label: 'Frek CIF',
                        fmt: 'num',
                        bg: 'rgba(202,138,4,.07)',
                        tc: '#a16207'
                    },
                ],
                MERCHANT: [{
                        key: 'total_referral_edc',
                        label: 'Ref EDC',
                        fmt: 'num',
                        bg: 'rgba(8,145,178,.07)',
                        tc: '#0e7490'
                    },
                    {
                        key: 'total_referral_livin',
                        label: 'Ref LVM',
                        fmt: 'num',
                        bg: 'rgba(249,115,22,.07)',
                        tc: '#c2410c'
                    },
                ],
                TRANSAKSI: [{
                        key: 'pct_on_us',
                        label: '% On Us',
                        fmt: 'pct',
                        bg: 'rgba(22,163,74,.07)',
                        tc: '#15803d'
                    },
                    {
                        key: 'total_poin_transaksi',
                        label: 'Total Poin',
                        fmt: 'num',
                        bg: 'rgba(202,138,4,.07)',
                        tc: '#a16207'
                    },
                    {
                        key: 'poin_on_us',
                        label: 'Poin On Us',
                        fmt: 'num',
                        bg: 'rgba(37,99,235,.07)',
                        tc: '#1d4ed8'
                    },
                    {
                        key: 'poin_off_us',
                        label: 'Poin Off Us',
                        fmt: 'num',
                        bg: 'rgba(124,58,237,.07)',
                        tc: '#6d28d9'
                    },
                    {
                        key: 'frek_on_us',
                        label: 'Frek On Us',
                        fmt: 'num',
                        bg: 'rgba(8,145,178,.07)',
                        tc: '#0e7490'
                    },
                    {
                        key: 'frek_off_us',
                        label: 'Frek Off Us',
                        fmt: 'num',
                        bg: 'rgba(249,115,22,.07)',
                        tc: '#c2410c'
                    },
                ],
            };

            // pegawaiBadges menggunakan gmmState.kategori yang juga scope DOMContentLoaded
            const pegawaiBadges = (r, katOverride) => {
                const kat = katOverride || gmmState.kategori;
                const metrics = pegawaiMetrics[kat] || pegawaiMetrics.LIVIN;
                return '<div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:5px;">' +
                    metrics.map(m =>
                        `<span style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:5px;background:${m.bg};color:${m.tc};">${m.label}: <strong>${fmtByKey(r[m.key] || 0, m.fmt)}</strong></span>`
                    ).join('') +
                    '</div>';
            };

            // All 6 metrics shown in cards/lists
            const allMetricsDef = [{
                    key: 'end_balance',
                    label: 'End Balance',
                    fmt: 'rp',
                    icon: '💰'
                },
                {
                    key: 'cif_akuisisi',
                    label: 'CIF',
                    fmt: 'num',
                    icon: '👤'
                },
                {
                    key: 'total_referral_edc',
                    label: 'Ref EDC',
                    fmt: 'num',
                    icon: '💳'
                },
                {
                    key: 'total_referral_livin',
                    label: 'Ref LVM',
                    fmt: 'num',
                    icon: '📱'
                },
                {
                    key: 'pct_on_us',
                    label: '%On Us',
                    fmt: 'pct',
                    icon: '📊'
                },
                {
                    key: 'total_poin_transaksi',
                    label: 'Poin',
                    fmt: 'num',
                    icon: '⭐'
                },
            ];

            // Render 6-metric horizontal strip
            function metricsStrip(r) {
                return '<div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;">' +
                    allMetricsDef.map(m => {
                        const v = r[m.key] ?? 0;
                        return `<span style="display:inline-flex;align-items:center;gap:3px;font-size:.72rem;font-weight:700;padding:2px 7px;border-radius:6px;background:rgba(241,245,249,.9);color:#334155;border:1px solid rgba(229,231,235,.6);">${m.icon}<span style="color:#64748b;font-weight:600;">${m.label}:</span><strong>${fmtByKey(v,m.fmt)}</strong></span>`;
                    }).join('') + '</div>';
            }

            document.querySelectorAll('[data-gmm-view]').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('[data-gmm-view]').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    gmmState.view = btn.dataset.gmmView;
                    gmmState.page = 1;
                    gmmState.filter = 'ALL';
                    gmmState.search = '';
                    gmmState.sortCol = ''; // ← TAMBAH
                    gmmState.sortDir = 'desc'; // ← TAMBAH
                    loadGmm();
                });
            });
            document.querySelectorAll('[data-gmm-kat]').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('[data-gmm-kat]').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    gmmState.kategori = btn.dataset.gmmKat;
                    gmmState.page = 1;
                    gmmState.sortCol = '';
                    gmmState.sortDir = 'desc'; // ← TAMBAH
                    loadGmm();
                });
            });

            async function loadGmm() {
                const katTabs = document.getElementById('gmmKatTabs');
                katTabs.style.display = ['dashboard', 'cabang', 'pegawai'].includes(gmmState.view) ? 'flex' : 'none';
                katTabs.style.flexWrap = 'wrap';
                const content = document.getElementById('gmmContent');
                content.innerHTML = '<div class="empty-state"><div class="spinner-border text-danger"></div><strong class="mt-3">Memuat...</strong></div>';

                if (false && gmmState.view === 'search' && !gmmState.search) {
                    katTabs.style.display = 'none';
                    content.innerHTML = `
        <h4 class="rajdhani fw-bold mb-3">🔍 Pencarian</h4>
        <div style="max-width:500px;position:relative;">
            <div class="d-flex gap-2 mb-1">
                <div style="flex:1;position:relative;">
                    <input type="text" class="form-control" id="gmmSearchInput"
                        placeholder="Ketik Nama, NIP, atau Unit..."
                        autocomplete="off"
                        oninput="gmmSuggest(this.value)"
                        onkeypress="if(event.key==='Enter'){document.getElementById('gmmSuggestBox').style.display='none';gmmSearchExec(this.value);}">
                    <div id="gmmSuggestBox" style="display:none;position:absolute;top:100%;left:0;right:0;
                        z-index:200;background:#fff;border:1px solid rgba(200,200,210,.6);
                        border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);
                        max-height:240px;overflow-y:auto;margin-top:4px;"></div>
                </div>
                <button class="btn btn-f1"
                    onclick="document.getElementById('gmmSuggestBox').style.display='none';
                             gmmSearchExec(document.getElementById('gmmSearchInput').value)">
                    <i class="bi bi-search"></i>
                </button>
            </div>
            <div style="font-size:.72rem;color:#94a3b8;margin-top:4px;">
                Ketik minimal 2 huruf untuk saran pencarian
            </div>
        </div>`;
                    return;
                }

                try {
                    const params = new URLSearchParams({
                        action: 'gmm_data',
                        view: gmmState.view,
                        kategori: gmmState.kategori,
                        filter: gmmState.filter,
                        p: gmmState.page
                    });
                    if (gmmState.search) params.set('search', gmmState.search);
                    if (gmmState.sortCol) {
                        params.set('sort_col', gmmState.sortCol);
                        params.set('sort_dir', gmmState.sortDir); // ← TAMBAH INI
                    }
                    if (gmmState.view === 'dashboard') {
                        Object.entries(dashSortState).forEach(([kat, col]) => {
                            if (col) params.set(`dash_sort_${kat.toLowerCase()}`, col);
                        });
                    }
                    const res = await fetch(`${apiBase}?${params}`);
                    const data = await res.json();
                    if (!data.ok) throw new Error(data.message || 'Error');
                    if (!data.has_data) {
                        content.innerHTML = '<div class="empty-state"><i class="bi bi-database-x fs-1 text-secondary"></i><strong>Data GMM belum tersedia.</strong><p class="text-secondary">Admin perlu upload file Excel GMM terlebih dahulu.</p></div>';
                        return;
                    }
                    renderGmm(data);
                } catch (e) {
                    content.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
                }
            }

            function renderGmm(data) {
                const content = document.getElementById('gmmContent');
                const wrapper = document.createElement('div');
                wrapper.className = 'gmm-fade-in';
                if (data.view === 'dashboard') renderGmmDashboard(wrapper, data);
                else if (data.view === 'cabang') renderGmmLeaderboard(wrapper, data, 'cabang');
                else if (data.view === 'pegawai') renderGmmLeaderboard(wrapper, data, 'pegawai');
                else if (data.view === 'search') renderGmmSearchResults(wrapper, data);
                else if (data.view === 'detail_pegawai') renderGmmDetailPegawai(wrapper, data);
                else if (data.view === 'detail_cabang') renderGmmDetailCabang(wrapper, data);
                content.innerHTML = '';
                content.appendChild(wrapper);
            }

            const dashSortState = {
                LIVIN: '',
                MERCHANT: '',
                TRANSAKSI: ''
            };
            window.gmmDashSort = (kat, col) => {
                dashSortState[kat] = dashSortState[kat] === col ? '' : col;
                rerenderSearchView();
            };

            function renderGmmDashboard(el, data) {

                // ── Helpers ────────────────────────────────────────────────────────────────

                function sortPill(label, isActive, onclick, color = 'var(--f1-red)') {
                    const base = `
            display:inline-flex;align-items:center;gap:4px;
            padding:3px 11px;border-radius:999px;font-size:.71rem;font-weight:700;
            cursor:pointer;white-space:nowrap;transition:all .15s;
            border:1.5px solid ${isActive ? color : '#d1d5db'};
            background:${isActive ? color : '#fff'};
            color:${isActive ? '#fff' : '#64748b'};
        `;
                    return `<button onclick="${onclick}" style="${base.replace(/\s+/g, ' ').trim()}">
            ${isActive ? '<span style="font-size:.65rem;">▼</span>' : ''}${label}
        </button>`;
                }

                // ── Branch View ────────────────────────────────────────────────────────────

                if (data.dashboard_type === 'branch') {
                    if (!window._branchSortCol) window._branchSortCol = 'end_balance';
                    const bsc = window._branchSortCol;

                    window.branchSort = col => {
                        window._branchSortCol = col;
                        loadGmm();
                    };

                    let list = [...(data.data || [])];
                    list.sort((a, b) => (b[bsc] || 0) - (a[bsc] || 0));

                    const pills = allMetricsDef
                        .map(m => sortPill(m.label, m.key === bsc, `branchSort('${m.key}')`))
                        .join('');

                    let html = `
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <div>
                    <div class="form-label mb-0">Dashboard Cabang</div>
                    <h4 class="rajdhani fw-bold mb-0">🏠 Capaian Pegawai Cabang</h4>
                </div>
                <span class="badge bg-light text-dark border fw-bold">${list.length} Pegawai</span>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-3">${pills}</div>
            <div class="d-flex flex-column gap-2">
        `;

                    const activeDef = allMetricsDef.find(m => m.key === bsc);

                    list.forEach((p, i) => {
                        const rank = i + 1;
                        const rs = getRankStyle(rank);
                        html += `
                <div style="background:${rs.bg};border:1px solid ${rs.border};border-radius:12px;
                            padding:10px 14px;display:grid;grid-template-columns:auto 1fr auto;
                            align-items:start;gap:10px;">
                    <div style="padding-top:2px;">${getRankBadge(rank)}</div>
                    <div style="min-width:0;">
                        <div style="font-weight:800;color:#1e293b;font-size:.88rem;">${p.nama}</div>
                        ${pegawaiBadges(p)}
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-weight:900;color:#16a34a;font-family:'Rajdhani',sans-serif;font-size:1.1rem;">
                            ${fmtByKey(p[bsc] || 0, activeDef?.fmt || 'num')}
                        </div>
                    </div>
                </div>
            `;
                    });

                    if (!list.length) html += '<div class="empty-state"><strong>Tidak ada data.</strong></div>';
                    html += '</div>';
                    el.innerHTML = html;
                    return;
                }

                // ── Main Dashboard ─────────────────────────────────────────────────────────

                const katConfig = {
                    LIVIN: {
                        icon: '📱',
                        color: '#2563eb',
                        bg: '#dbeafe'
                    },
                    MERCHANT: {
                        icon: '🏪',
                        color: '#7c3aed',
                        bg: '#f3e8ff'
                    },
                    TRANSAKSI: {
                        icon: '💳',
                        color: '#0891b2',
                        bg: '#cffafe'
                    },
                };

                let html = `
        <div class="mb-3">
            <div class="form-label mb-0">Ringkasan</div>
            <h4 class="rajdhani fw-bold mb-0">🏠 Dashboard — Top 10 Per Kategori</h4>
        </div>
    `;

                const activeDashboardKat = Object.prototype.hasOwnProperty.call(katConfig, gmmState.kategori) ? gmmState.kategori : 'LIVIN';
                for (const [kat, cfg] of Object.entries(katConfig).filter(([name]) => name === activeDashboardKat)) {
                    const d = data.data[kat] || {
                        cabang: [],
                        pegawai: []
                    };
                    const defs = sortDefs[kat] || [];
                    const activeSort = dashSortState[kat] || (defs[0]?.col || '');
                    const activeDef = defs.find(x => x.col === activeSort) || defs[0] || {
                        col: 'score',
                        label: 'Score',
                        fmt: 'num'
                    };

                    const pills = defs
                        .map(dd => sortPill(dd.label, dd.col === activeSort, `gmmDashSort('${kat}','${dd.col}')`, cfg.color))
                        .join('');

                    html += `
            <div style="margin-bottom:2rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;
                            gap:10px;margin-bottom:10px;padding-bottom:8px;
                            border-bottom:2px solid ${cfg.bg};">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:1.2rem;">${cfg.icon}</span>
                        <h5 style="margin:0;font-family:'Rajdhani',sans-serif;font-weight:700;
                                   text-transform:uppercase;color:${cfg.color};">Kategori ${kat}</h5>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;">${pills}</div>
                </div>
                <div class="row g-3">
                    <div class="col-lg-6"><div class="panel p-3">
                        <h6 class="rajdhani fw-bold mb-2" style="color:${cfg.color};">🏢 Top 10 Cabang</h6>
                        <div class="d-flex flex-column gap-1">
        `;

                    d.cabang.slice(0, 10).forEach((r, i) => {
                        const rank = i + 1;
                        const rs = getRankStyle(rank);
                        const em = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' :
                            `<span style="font-size:.8rem;color:#94a3b8;font-weight:700;">#${rank}</span>`;
                        html += `
                <div style="display:flex;align-items:center;gap:6px;padding:7px 10px;
                            border-radius:8px;background:${rs.bg};border:1px solid ${rs.border};">
                    <span style="min-width:22px;text-align:center;">${em}</span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:.82rem;overflow:hidden;
                                    text-overflow:ellipsis;white-space:nowrap;color:#1e293b;">
                            ${r.unit || r.kode_cabang}
                        </div>
                    </div>
                    <div style="font-weight:900;color:${cfg.color};font-family:'Rajdhani',sans-serif;font-size:.95rem;">
                        ${fmtByKey(r.score, activeDef.fmt)}
                    </div>
                </div>
            `;
                    });

                    html += `
                        </div></div></div>
                    <div class="col-lg-6"><div class="panel p-3">
                        <h6 class="rajdhani fw-bold mb-2" style="color:${cfg.color};">👤 Top 10 Pegawai</h6>
                        <div class="d-flex flex-column gap-1">
        `;

                    d.pegawai.slice(0, 10).forEach((r, i) => {
                        const rank = i + 1;
                        const rs = getRankStyle(rank);
                        const em = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' :
                            `<span style="font-size:.8rem;color:#94a3b8;font-weight:700;">#${rank}</span>`;
                        html += `
                <div style="display:flex;align-items:center;gap:6px;padding:7px 10px;
                            border-radius:8px;background:${rs.bg};border:1px solid ${rs.border};">
                    <span style="min-width:22px;text-align:center;">${em}</span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:700;font-size:.82rem;overflow:hidden;
                                    text-overflow:ellipsis;white-space:nowrap;color:#1e293b;">${r.nama || ''}</div>
                        <div style="font-size:.68rem;color:#64748b;">${r.unit || ''}</div>
                    </div>
                    <div style="font-weight:900;color:${cfg.color};font-family:'Rajdhani',sans-serif;font-size:.95rem;">
                        ${fmtByKey(r.score, activeDef.fmt)}
                    </div>
                </div>
            `;
                    });

                    html += `</div></div></div></div></div>`;
                }

                el.innerHTML = html;
            }

            function renderGmmLeaderboard(el, data, type) {
                const list = data.list || [];
                const kat = data.kategori || gmmState.kategori;
                const katIcon = kat === 'LIVIN' ? '📱' : (kat === 'MERCHANT' ? '🏪' : '💳');
                const defs = sortDefs[kat] || [];
                const activeSort = gmmState.sortCol || (defs[0]?.col || '');
                const activeDef = defs.find(s => s.col === activeSort) || defs[0] || {
                    col: 'score',
                    label: 'Score',
                    fmt: 'num'
                };


                // ── Metric badges per kategori (cabang) ──────────────────────────
                const cabangMetrics = {
                    LIVIN: [{
                            key: 'sum_eb',
                            label: 'End Bal',
                            fmt: 'rp',
                            bg: 'rgba(37,99,235,.07)',
                            tc: '#1d4ed8'
                        },
                        {
                            key: 'sum_ca',
                            label: 'CIF Akuisisi',
                            fmt: 'num',
                            bg: 'rgba(124,58,237,.07)',
                            tc: '#6d28d9'
                        },
                        {
                            key: 'sum_cs',
                            label: 'CIF Setor',
                            fmt: 'num',
                            bg: 'rgba(8,145,178,.07)',
                            tc: '#0e7490'
                        },
                    ],
                    MERCHANT: [{
                            key: 'sum_re',
                            label: 'Ref EDC',
                            fmt: 'num',
                            bg: 'rgba(8,145,178,.07)',
                            tc: '#0e7490'
                        },
                        {
                            key: 'sum_rl',
                            label: 'Ref LVM',
                            fmt: 'num',
                            bg: 'rgba(249,115,22,.07)',
                            tc: '#c2410c'
                        },
                    ],
                    TRANSAKSI: [{
                            key: '__pct_on_us',
                            label: '% On Us',
                            fmt: 'pct',
                            bg: 'rgba(22,163,74,.07)',
                            tc: '#15803d'
                        },
                        {
                            key: 'sum_tp',
                            label: 'Total Poin',
                            fmt: 'num',
                            bg: 'rgba(202,138,4,.07)',
                            tc: '#a16207'
                        },
                        {
                            key: 'sum_po',
                            label: 'Poin On Us',
                            fmt: 'num',
                            bg: 'rgba(37,99,235,.07)',
                            tc: '#1d4ed8'
                        },
                        {
                            key: 'sum_pf',
                            label: 'Poin Off Us',
                            fmt: 'num',
                            bg: 'rgba(124,58,237,.07)',
                            tc: '#6d28d9'
                        },
                        {
                            key: 'sum_fo',
                            label: 'Frek On Us',
                            fmt: 'num',
                            bg: 'rgba(8,145,178,.07)',
                            tc: '#0e7490'
                        },
                        {
                            key: 'sum_ff',
                            label: 'Frek Off Us',
                            fmt: 'num',
                            bg: 'rgba(249,115,22,.07)',
                            tc: '#c2410c'
                        },
                    ],
                };

                // Helper: ambil nilai dari row cabang, termasuk computed field
                const getCabangVal = (r, key) => {
                    if (key === '__pct_on_us') {
                        const fo = r.sum_fo || 0,
                            ff = r.sum_ff || 0;
                        return (fo + ff) > 0 ? fo / (fo + ff) : 0;
                    }
                    return r[key] || 0;
                };

                // Render badge strip untuk cabang sesuai kategori aktif
                const cabangBadges = (r) => {
                    const metrics = cabangMetrics[kat] || [];
                    return metrics.map(m => {
                        const val = getCabangVal(r, m.key);
                        return `<span style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:5px;background:${m.bg};color:${m.tc};">${m.label}: <strong>${fmtByKey(val, m.fmt)}</strong></span>`;
                    }).join('');
                };

                // ── Header ───────────────────────────────────────────────────────
                let html = `<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div><div class="form-label mb-0">${type === 'cabang' ? 'Leaderboard Cabang' : 'Leaderboard Pegawai'}</div>
        <h5 class="rajdhani fw-bold mb-0">${katIcon} Kategori ${kat}</h5></div>`;
                if (type === 'pegawai') html += `<span style="background:#f1f5f9;padding:3px 10px;border-radius:8px;font-size:.75rem;font-weight:700;">${fmtNum(data.total || 0)} Pegawai · Hal. ${data.page || 1}</span>`;
                html += `</div>`;

                // Sort pills with asc/desc indicator
                html += `<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <span style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">Urutkan:</span>`;
                defs.forEach(s => {
                    const isA = s.col === activeSort;
                    const dir = gmmState.sortDir || 'desc';
                    const arrow = isA ? (dir === 'desc' ? '▼' : '▲') : '';
                    const tooltip = isA ?
                        (dir === 'desc' ? 'Klik untuk Ascending' : 'Klik untuk hapus sort') :
                        'Klik untuk Descending';
                    html += `<button onclick="gmmSort('${s.col}')"
        title="${tooltip}"
        style="padding:5px 14px;border-radius:999px;font-size:.76rem;font-weight:700;
        border:2px solid ${isA ? '#E10600' : 'rgba(200,200,210,.6)'};
        background:${isA ? '#E10600' : 'rgba(255,255,255,.8)'};
        color:${isA ? '#fff' : '#334155'};
        cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
        ${arrow ? `<span style="font-size:.7rem;">${arrow}</span>` : ''}${s.label}
    </button>`;
                });
                html += `</div>`;

                list.forEach((r, i) => {
                    const rank = (type === 'pegawai' ? (data.page - 1) * data.page_size : 0) + i + 1;
                    const rs = getRankStyle(rank);
                    const scoreVal = r[activeSort] !== undefined ? r[activeSort] : (r.score || 0);

                    if (type === 'cabang') {
                        html += `<div style="background:${rs.bg};border:1px solid ${rs.border};border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:auto 1fr auto;align-items:start;gap:10px;">
                <div style="padding-top:2px;">${getRankBadge(rank)}</div>
                <div style="min-width:0;">
                    <div style="font-weight:800;color:#1e293b;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r.unit || '-'}</div>
                    <div style="font-size:.7rem;color:#475569;margin-top:2px;">${r.kode_cabang || '-'} · ${r.jml || 0} pegawai</div>
                    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:5px;">${cabangBadges(r)}</div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-weight:900;font-size:1.05rem;color:${rank === 1 ? '#ca8a04' : '#0f172a'};font-family:'Rajdhani',sans-serif;line-height:1.1;">${fmtByKey(scoreVal, activeDef.fmt)}</div>
                    <div style="font-size:.62rem;color:#94a3b8;font-weight:600;margin-bottom:6px;">${activeDef.label}</div>
                    <button style="background:#fff;border:1px solid rgba(200,200,210,.6);border-radius:8px;font-size:.72rem;font-weight:700;padding:5px 10px;cursor:pointer;color:#334155;" onclick="gmmDetailCabang('${r.kode_cabang}')">Detail ›</button>
                </div>
            </div>`;
                    } else {
                        html += `<div style="background:${rs.bg};border:1px solid ${rs.border};border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:auto 1fr auto;align-items:start;gap:10px;">
                <div style="padding-top:2px;">${getRankBadge(rank)}</div>
                <div style="min-width:0;">
                    <div style="font-weight:800;color:#1e293b;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r.nama || '-'}</div>
                    <div style="font-size:.7rem;color:#475569;margin-top:2px;">${r.posisi || '-'} · ${r.unit || ''}</div>
                    ${pegawaiBadges(r)}
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-weight:900;font-size:1.1rem;color:${rank === 1 ? '#ca8a04' : '#0f172a'};font-family:'Rajdhani',sans-serif;line-height:1.1;">${fmtByKey(scoreVal, activeDef.fmt)}</div>
                    <div style="font-size:.62rem;color:#94a3b8;font-weight:600;margin-bottom:6px;">${activeDef.label}</div>
                    <button style="background:#fff;border:1px solid rgba(200,200,210,.6);border-radius:8px;font-size:.7rem;font-weight:700;padding:4px 8px;cursor:pointer;color:#334155;" onclick="gmmDetailPegawai('${r.nip}')">Detail ›</button>
                </div>
            </div>`;
                    }
                });

                html += '</div>';
                if (!list.length) html += '<div class="empty-state"><strong>Tidak ada data.</strong></div>';

                // Pagination
                if (type === 'pegawai' && data.total > data.page_size) {
                    const tp = Math.ceil(data.total / data.page_size);
                    html += `<div style="display:flex;justify-content:center;align-items:center;gap:12px;margin-top:16px;padding-top:12px;border-top:1px solid rgba(229,231,235,.5);">
            <button class="btn btn-ghost btn-sm" ${data.page <= 1 ? 'disabled' : ''} onclick="gmmPage(${Math.max(1, data.page - 1)})">← Prev</button>
            <span style="font-weight:700;color:#475569;font-size:.85rem;">${data.page} / ${tp}</span>
            <button class="btn btn-ghost btn-sm" ${data.page >= tp ? 'disabled' : ''} onclick="gmmPage(${Math.min(tp, data.page + 1)})">Next →</button>
        </div>`;
                }
                el.innerHTML = html;
            }

            function renderGmmSearchResults(el, data) {
                gmmState._searchPayload = data;
                const pegawaiResults = data.pegawai_results || [];
                const cabangResults = data.cabang_results || [];
                const filterMeta = data.filters || {};
                const mode = gmmState.searchMode === 'cabang' ? 'cabang' : 'pegawai';
                const metricMode = sortDefs[gmmState.searchMetricMode] ? gmmState.searchMetricMode : 'LIVIN';
                const metricDefs = sortDefs[metricMode] || sortDefs.LIVIN || [];
                const defaultSort = metricDefs[0]?.col || 'end_balance';
                const activeSort = metricDefs.some(def => def.col === gmmState.searchSortCol) ? gmmState.searchSortCol : defaultSort;
                const query = (gmmState.search || '').trim().toLowerCase();

                gmmState.searchMetricMode = metricMode;
                gmmState.searchSortCol = activeSort;

                _gmmSearchCache.length = 0;
                pegawaiResults.forEach(r => {
                    if (r.nip) {
                        _gmmSearchCache.push({
                            nip: String(r.nip || ''),
                            nama: String(r.nama || ''),
                            unit: String(r.unit || '')
                        });
                    }
                });

                const cabangMetricMap = {
                    end_balance: 'sum_eb',
                    cif_akuisisi: 'sum_ca',
                    cif_setor: 'sum_cs',
                    rata_rata: 'avg_rr',
                    cif_sudah_transaksi: 'sum_cst',
                    frek_dari_cif_akuisisi: 'sum_fca',
                    total_referral_edc: 'sum_re',
                    total_referral_livin: 'sum_rl',
                    pct_on_us: 'pct_on_us',
                    total_poin_transaksi: 'sum_tp',
                    frek_on_us: 'sum_fo',
                    frek_off_us: 'sum_ff',
                    poin_on_us: 'sum_po',
                    poin_off_us: 'sum_pf'
                };
                const getMetricValue = (row, col) => {
                    if (mode === 'pegawai') return Number(row?.[col] || 0);
                    return Number(row?.[cabangMetricMap[col] || col] || 0);
                };

                const rows = (mode === 'pegawai' ? pegawaiResults : cabangResults).filter(row => {
                    const haystack = mode === 'pegawai' ? [row.nama, row.nip, row.unit, row.kode_cabang, row.posisi, row.area] : [row.unit, row.kode_cabang, row.area, row.kelas_cabang];
                    if (query && !haystack.some(v => String(v || '').toLowerCase().includes(query))) return false;
                    if (gmmState.searchFilterArea !== 'ALL' && String(row.area || '') !== gmmState.searchFilterArea) return false;
                    if (mode === 'pegawai' && gmmState.searchFilterPosisi !== 'ALL' && String(row.posisi || '') !== gmmState.searchFilterPosisi) return false;
                    if (mode === 'cabang' && gmmState.searchFilterKelas !== 'ALL' && String(row.kelas_cabang || '') !== gmmState.searchFilterKelas) return false;
                    return true;
                }).sort((a, b) => {
                    const diff = getMetricValue(b, activeSort) - getMetricValue(a, activeSort);
                    if (diff !== 0) return diff;
                    const aLabel = mode === 'pegawai' ? String(a.nama || '') : String(a.unit || '');
                    const bLabel = mode === 'pegawai' ? String(b.nama || '') : String(b.unit || '');
                    return aLabel.localeCompare(bLabel);
                });

                const activeDef = metricDefs.find(def => def.col === activeSort) || metricDefs[0] || {
                    col: activeSort,
                    label: 'Nilai',
                    fmt: 'num'
                };
                const renderOptions = (items, selected) => ['<option value="ALL">Semua</option>']
                    .concat((items || []).map(item => `<option value="${escapeHtml(String(item))}" ${String(item) === selected ? 'selected' : ''}>${escapeHtml(String(item))}</option>`))
                    .join('');

                let html = `
        <div class="panel p-3 p-md-4 mb-3" style="border:1px solid rgba(225,6,0,.12);background:linear-gradient(180deg,rgba(255,255,255,.96),rgba(255,250,250,.96));">
            <div class="d-flex align-items-start justify-content-between gap-3 mb-3 flex-wrap">
                <div>
                    <div class="form-label mb-1">Pencarian Dinamis</div>
                    <h4 class="rajdhani fw-bold mb-1">Explorer GMM</h4>
                    <div style="font-size:.78rem;color:#64748b;">Semua data tampil sejak awal. Ketik untuk mempersempit, lalu atur mode, filter, dan sorting.</div>
                </div>
                <button class="btn btn-ghost btn-sm" onclick="gmmResetSearch()">Reset</button>
            </div>

            <div class="mb-3">
                <input id="gmmSearchInput" type="text" class="form-control" value="${escapeHtml(gmmState.search || '')}"
                    placeholder="Cari nama, NIP, unit, area, atau kode cabang..."
                    oninput="gmmSearchInput(this.value)"
                    style="border:1.5px solid rgba(225,6,0,.25);padding:.8rem 1rem;font-size:1rem;">
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                <span style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">Mode:</span>
                <button onclick="gmmSearchSetMode('pegawai')" style="padding:6px 14px;border-radius:999px;border:2px solid ${mode === 'pegawai' ? '#E10600' : 'rgba(200,200,210,.6)'};background:${mode === 'pegawai' ? '#E10600' : '#fff'};color:${mode === 'pegawai' ? '#fff' : '#334155'};font-size:.78rem;font-weight:800;">Pegawai</button>
                <button onclick="gmmSearchSetMode('cabang')" style="padding:6px 14px;border-radius:999px;border:2px solid ${mode === 'cabang' ? '#E10600' : 'rgba(200,200,210,.6)'};background:${mode === 'cabang' ? '#E10600' : '#fff'};color:${mode === 'cabang' ? '#fff' : '#334155'};font-size:.78rem;font-weight:800;">Cabang</button>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
                <span style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">Mode Metric:</span>
                ${['LIVIN', 'MERCHANT', 'TRANSAKSI'].map(name => `<button onclick="gmmSearchSetMetric('${name}')" style="padding:6px 14px;border-radius:999px;border:2px solid ${metricMode === name ? '#111827' : 'rgba(200,200,210,.6)'};background:${metricMode === name ? '#111827' : '#fff'};color:${metricMode === name ? '#fff' : '#334155'};font-size:.78rem;font-weight:800;">${name}</button>`).join('')}
            </div>

            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4 col-xl-3">
                    <label class="form-label" style="font-size:.72rem;font-weight:800;color:#64748b;">Area</label>
                    <select class="form-select" onchange="gmmSearchSetFilter('area', this.value)">${renderOptions(filterMeta.areas || [], gmmState.searchFilterArea)}</select>
                </div>
                <div class="col-12 col-md-4 col-xl-3">
                    <label class="form-label" style="font-size:.72rem;font-weight:800;color:#64748b;">${mode === 'pegawai' ? 'Jabatan' : 'Kelas Cabang'}</label>
                    <select class="form-select" onchange="gmmSearchSetFilter('${mode === 'pegawai' ? 'posisi' : 'kelas'}', this.value)">${renderOptions(mode === 'pegawai' ? (filterMeta.positions || []) : (filterMeta.kelas_cabang || []), mode === 'pegawai' ? gmmState.searchFilterPosisi : gmmState.searchFilterKelas)}</select>
                </div>
                <div class="col-12 col-md-4 col-xl-3">
                    <label class="form-label" style="font-size:.72rem;font-weight:800;color:#64748b;">Urutkan</label>
                    <select class="form-select" onchange="gmmSearchSetSort(this.value)">${metricDefs.map(def => `<option value="${def.col}" ${def.col === activeSort ? 'selected' : ''}>${escapeHtml(def.label)}</option>`).join('')}</select>
                </div>
                <div class="col-12 col-xl-3">
                    <div style="padding:.75rem 1rem;border-radius:12px;background:rgba(241,245,249,.9);border:1px solid rgba(226,232,240,.9);font-weight:800;color:#1e293b;">
                        ${rows.length.toLocaleString('id-ID')} ${mode === 'pegawai' ? 'pegawai' : 'cabang'} cocok
                        <div style="font-size:.72rem;color:#64748b;font-weight:700;">Sort: ${escapeHtml(activeDef.label)}</div>
                    </div>
                </div>
            </div>
        </div>`;

                if (!rows.length) {
                    html += `<div class="empty-state"><i class="bi bi-search fs-1 text-secondary"></i><strong>Tidak ada hasil yang cocok.</strong><p class="text-secondary mb-0">Coba longgarkan kata kunci atau ubah filter.</p></div>`;
                    el.innerHTML = html;
                    return;
                }

                html += '<div class="d-flex flex-column gap-2">';
                rows.forEach((row, i) => {
                    const rank = i + 1;
                    const rs = getRankStyle(rank);
                    const scoreVal = getMetricValue(row, activeSort);
                    const badges = metricDefs.map(def => {
                        const isActive = def.col === activeSort;
                        return `<span style="font-size:.72rem;font-weight:${isActive ? '900' : '700'};padding:2px 8px;border-radius:5px;background:${isActive ? 'rgba(225,6,0,.07)' : 'rgba(241,245,249,.9)'};color:${isActive ? '#E10600' : '#334155'};border:1px solid ${isActive ? 'rgba(225,6,0,.2)' : 'rgba(229,231,235,.6)'};">${escapeHtml(def.label)}: <strong>${fmtByKey(getMetricValue(row, def.col), def.fmt)}</strong></span>`;
                    }).join('');

                    if (mode === 'pegawai') {
                        html += `<div style="background:${rank <= 3 ? rs.bg : 'rgba(255,255,255,0.88)'};border:1px solid ${rank <= 3 ? rs.border : 'rgba(229,231,235,.6)'};border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:auto 1fr auto;align-items:start;gap:10px;">
            <div style="padding-top:2px;">${getRankBadge(rank)}</div>
            <div style="min-width:0;">
                <div style="font-weight:800;color:#1e293b;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(row.nama || '-')}</div>
                <div style="font-size:.72rem;color:#475569;margin-top:2px;">NIP: ${escapeHtml(row.nip || '-')} Â· ${escapeHtml(row.posisi || '-')} Â· ${escapeHtml(row.unit || '-')}</div>
                <div style="font-size:.68rem;color:#94a3b8;margin-top:3px;">Area ${escapeHtml(row.area || '-')} Â· Cabang ${escapeHtml(row.kode_cabang || '-')}</div>
                <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;">${badges}</div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <div style="font-weight:900;font-size:1.08rem;color:${rank === 1 ? '#E10600' : '#0f172a'};font-family:'Rajdhani',sans-serif;line-height:1.1;">${fmtByKey(scoreVal, activeDef.fmt)}</div>
                <div style="font-size:.62rem;color:#94a3b8;font-weight:700;margin-bottom:6px;">${escapeHtml(activeDef.label)}</div>
                <button style="background:#fff;border:1px solid rgba(200,200,210,.6);border-radius:8px;font-size:.72rem;font-weight:700;padding:5px 10px;cursor:pointer;color:#334155;" onclick="gmmDetailPegawai('${escapeHtml(row.nip || '')}')">Detail â€º</button>
            </div>
        </div>`;
                    } else {
                        html += `<div style="background:${rank <= 3 ? rs.bg : 'rgba(255,255,255,0.88)'};border:1px solid ${rank <= 3 ? rs.border : 'rgba(229,231,235,.6)'};border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:auto 1fr auto;align-items:start;gap:10px;">
            <div style="padding-top:2px;">${getRankBadge(rank)}</div>
            <div style="min-width:0;">
                <div style="font-weight:800;color:#1e293b;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(row.unit || row.kode_cabang || '-')}</div>
                <div style="font-size:.72rem;color:#475569;margin-top:2px;">${escapeHtml(row.kode_cabang || '-')} Â· ${escapeHtml(row.kelas_cabang || '-')} Â· ${fmtNum(row.jml || 0)} pegawai</div>
                <div style="font-size:.68rem;color:#94a3b8;margin-top:3px;">Area ${escapeHtml(row.area || '-')}</div>
                <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;">${badges}</div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <div style="font-weight:900;font-size:1.08rem;color:${rank === 1 ? '#E10600' : '#0f172a'};font-family:'Rajdhani',sans-serif;line-height:1.1;">${fmtByKey(scoreVal, activeDef.fmt)}</div>
                <div style="font-size:.62rem;color:#94a3b8;font-weight:700;margin-bottom:6px;">${escapeHtml(activeDef.label)}</div>
                <button style="background:#fff;border:1px solid rgba(200,200,210,.6);border-radius:8px;font-size:.72rem;font-weight:700;padding:5px 10px;cursor:pointer;color:#334155;" onclick="gmmDetailCabang('${escapeHtml(row.kode_cabang || '')}')">Buka â€º</button>
            </div>
        </div>`;
                    }
                });
                html += '</div>';
                el.innerHTML = html;
                return;
                {

                    const results = data.results || [];

                    // Populate autocomplete cache
                    results.forEach(r => {
                        if (r.nip && !_gmmSearchCache.find(x => x.nip === r.nip)) {
                            _gmmSearchCache.push({
                                nip: String(r.nip),
                                nama: String(r.nama || ''),
                                unit: String(r.unit || '')
                            });
                        }
                    });

                    // Sort definitions — sesuai metric yang tampil di card
                    const searchSortDefs = [{
                            col: 'end_balance',
                            label: 'End Bal',
                            fmt: 'rp'
                        },
                        {
                            col: 'cif_akuisisi',
                            label: 'CIF',
                            fmt: 'num'
                        },
                        {
                            col: 'total_referral_edc',
                            label: 'Ref EDC',
                            fmt: 'num'
                        },
                        {
                            col: 'total_referral_livin',
                            label: 'Ref LVM',
                            fmt: 'num'
                        },
                        {
                            col: 'pct_on_us',
                            label: '% On Us',
                            fmt: 'pct'
                        },
                        {
                            col: 'total_poin_transaksi',
                            label: 'Poin',
                            fmt: 'num'
                        },
                    ];

                    // Ambil / set sort state dari gmmState
                    if (!gmmState._searchSortCol) gmmState._searchSortCol = 'end_balance';
                    const activeSort = gmmState._searchSortCol;

                    // Sort results client-side
                    const sorted = [...results].sort((a, b) => (b[activeSort] || 0) - (a[activeSort] || 0));

                    // ── Header + tombol cari lagi ──
                    let html = `
        <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <button class="btn btn-ghost btn-sm" onclick="gmmResetSearch()">← Cari Lagi</button>
            <h5 class="rajdhani fw-bold mb-0">🔍 Hasil Pencarian
                <span style="font-size:.75rem;font-weight:600;color:#64748b;margin-left:6px;">${results.length} ditemukan</span>
            </h5>
        </div>`;

                    // ── Sort pills ──
                    html += `<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        <span style="font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">Urutkan:</span>`;
                    searchSortDefs.forEach(s => {
                        const isA = s.col === activeSort;
                        html += `<button onclick="gmmSearchSort('${s.col}')"
            style="padding:5px 14px;border-radius:999px;font-size:.76rem;font-weight:700;
            border:2px solid ${isA ? '#E10600' : 'rgba(200,200,210,.6)'};
            background:${isA ? '#E10600' : 'rgba(255,255,255,.8)'};
            color:${isA ? '#fff' : '#334155'};cursor:pointer;white-space:nowrap;">
            ${isA ? '▼ ' : ''}${s.label}</button>`;
                    });
                    html += `</div>`;

                    if (!sorted.length) {
                        html += `<div class="empty-state">
            <i class="bi bi-search fs-1 text-secondary"></i>
            <strong>Tidak ada hasil ditemukan.</strong>
        </div>`;
                        el.innerHTML = html;
                        return;
                    }

                    const activeDef = searchSortDefs.find(s => s.col === activeSort) || searchSortDefs[0];

                    html += '<div class="d-flex flex-column gap-2">';
                    sorted.forEach((r, i) => {
                        const rs = getRankStyle(i + 1);
                        const scoreVal = r[activeSort] || 0;
                        html += `<div style="background:${i < 3 ? rs.bg : 'rgba(255,255,255,0.85)'};border:1px solid ${i < 3 ? rs.border : 'rgba(229,231,235,.6)'};border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:auto 1fr auto;align-items:start;gap:10px;">
            <div style="padding-top:2px;">${getRankBadge(i + 1)}</div>
            <div style="min-width:0;">
                <div style="font-weight:800;color:#1e293b;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(r.nama || '-')}</div>
                <div style="font-size:.7rem;color:#475569;margin-top:2px;">NIP: ${escapeHtml(r.nip)} · ${escapeHtml(r.posisi || '-')} · ${escapeHtml(r.unit || '')}</div>
                <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;">
                    ${searchSortDefs.map(s => {
                        const v = r[s.col] || 0;
                        const isActive = s.col === activeSort;
                        return `<span style="font-size:.72rem;font-weight:${isActive ? '900' : '700'};padding:2px 8px;border-radius:5px;
                            background:${isActive ? 'rgba(225,6,0,.07)' : 'rgba(241,245,249,.9)'};
                            color:${isActive ? '#E10600' : '#334155'};
                            border:1px solid ${isActive ? 'rgba(225,6,0,.2)' : 'rgba(229,231,235,.6)'};">
                            ${s.label}: <strong>${fmtByKey(v, s.fmt)}</strong></span>`;
                    }).join('')}
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <div style="font-weight:900;font-size:1.1rem;color:${i === 0 ? '#E10600' : '#0f172a'};font-family:'Rajdhani',sans-serif;line-height:1.1;">${fmtByKey(scoreVal, activeDef.fmt)}</div>
                <div style="font-size:.62rem;color:#94a3b8;font-weight:600;margin-bottom:6px;">${activeDef.label}</div>
                <button style="background:#fff;border:1px solid rgba(200,200,210,.6);border-radius:8px;font-size:.72rem;font-weight:700;padding:5px 10px;cursor:pointer;color:#334155;" onclick="gmmDetailPegawai('${escapeHtml(r.nip)}')">Detail ›</button>
            </div>
        </div>`;
                    });
                    html += '</div>';
                    el.innerHTML = html;
                }
            }

            function renderGmmDetailPegawai(el, data) {
                const r = data.pegawai;
                if (!r) {
                    el.innerHTML = '<div class="alert alert-warning">Data tidak ditemukan.</div>';
                    return;
                }
                let html = `<button class="btn btn-ghost mb-3" onclick="document.querySelector('[data-gmm-view=pegawai]').click()">⬅️ Kembali</button>`;
                html += `<div class="panel p-4 mb-4" style="border-left:6px solid #E10600;">
            <h3 class="rajdhani fw-bold mb-1">${r.nama||'-'}</h3>
            <div style="font-size:.85rem;color:#475569;font-weight:600;margin-bottom:8px;">NIP: ${r.nip} · ${r.posisi||'Pegawai'}</div>
            <div style="font-size:.8rem;color:#334155;">${r.unit||'-'}</div>
        </div>`;
                html += '<div class="panel p-4 mb-3"><h5 class="rajdhani fw-bold mb-3" style="color:#2563eb;border-bottom:2px solid #dbeafe;padding-bottom:8px;">📱 LIVIN</h5><div class="row g-3">';
                [{
                    l: 'End Balance',
                    v: r.end_balance,
                    f: fmtRp
                }, {
                    l: 'CIF Akuisisi',
                    v: r.cif_akuisisi,
                    f: fmtNum
                }, {
                    l: 'CIF Setor',
                    v: r.cif_setor,
                    f: fmtNum
                }, {
                    l: 'Rata-rata',
                    v: r.rata_rata,
                    f: fmtRp
                }, {
                    l: 'CIF Transaksi',
                    v: r.cif_sudah_transaksi,
                    f: fmtNum
                }, {
                    l: 'Frek CIF',
                    v: r.frek_dari_cif_akuisisi,
                    f: fmtNum
                }].forEach(c => {
                    html += `<div class="col-6 col-md-4 col-lg-2"><div class="mini-stat"><div class="label">${c.l}</div><div class="value" style="font-size:1.3rem;">${c.f(c.v||0)}</div></div></div>`;
                });
                html += '</div></div>';
                html += '<div class="panel p-4 mb-3"><h5 class="rajdhani fw-bold mb-3" style="color:#7c3aed;border-bottom:2px solid #f3e8ff;padding-bottom:8px;">🏪 MERCHANT</h5><div class="row g-3">';
                [{
                    l: 'Referral EDC',
                    v: r.total_referral_edc,
                    f: fmtNum
                }, {
                    l: 'Referral LIVIN',
                    v: r.total_referral_livin,
                    f: fmtNum
                }].forEach(c => {
                    html += `<div class="col-6 col-md-3"><div class="mini-stat"><div class="label">${c.l}</div><div class="value" style="font-size:1.3rem;">${c.f(c.v||0)}</div></div></div>`;
                });
                html += '</div></div>';
                html += '<div class="panel p-4"><h5 class="rajdhani fw-bold mb-3" style="color:#0891b2;border-bottom:2px solid #cffafe;padding-bottom:8px;">💳 TRANSAKSI</h5><div class="row g-3">';
                [{
                    l: '% On Us',
                    v: r.pct_on_us,
                    f: fmtPct
                }, {
                    l: 'Total Poin',
                    v: r.total_poin_transaksi,
                    f: fmtNum
                }, {
                    l: 'Poin On Us',
                    v: r.poin_on_us,
                    f: fmtNum
                }, {
                    l: 'Poin Off Us',
                    v: r.poin_off_us,
                    f: fmtNum
                }, {
                    l: 'Frek On Us',
                    v: r.frek_on_us,
                    f: fmtNum
                }, {
                    l: 'Frek Off Us',
                    v: r.frek_off_us,
                    f: fmtNum
                }].forEach(c => {
                    html += `<div class="col-6 col-md-4 col-lg-2"><div class="mini-stat"><div class="label">${c.l}</div><div class="value" style="font-size:1.3rem;">${c.f(c.v||0)}</div></div></div>`;
                });
                html += '</div></div>';
                el.innerHTML = html;
            }

            function renderGmmDetailCabang(el, data) {
                const r = data.cabang;
                if (!r) {
                    el.innerHTML = '<div class="alert alert-warning">Data cabang tidak ditemukan.</div>';
                    return;
                }
                let html = `<button class="btn btn-ghost mb-3" onclick="document.querySelector('[data-gmm-view=cabang]').click()">⬅️ Kembali</button>`;
                html += `<div class="panel p-4 mb-3" style="border-top:6px solid var(--f1-red);">
            <h3 class="rajdhani fw-bold" style="color:var(--f1-red);">${r.unit||r.kode_cabang}</h3>
            <div class="text-secondary fw-semibold">Kode: ${r.kode_cabang} • Kelas: ${r.kelas_cabang||'-'} • Pegawai: ${r.jml||0}</div></div>`;
                html += '<div class="row g-3">';
                [{
                    l: 'End Balance',
                    v: r.sum_eb,
                    f: fmtRp
                }, {
                    l: 'CIF Akuisisi',
                    v: r.sum_ca,
                    f: fmtNum
                }, {
                    l: 'Referral EDC',
                    v: r.sum_re,
                    f: fmtNum
                }, {
                    l: 'Referral LVM',
                    v: r.sum_rl,
                    f: fmtNum
                }, {
                    l: 'Total Poin',
                    v: r.sum_tp,
                    f: fmtNum
                }, {
                    l: 'Trx On Us',
                    v: r.sum_fo,
                    f: fmtNum
                }, {
                    l: 'Trx Off Us',
                    v: r.sum_ff,
                    f: fmtNum
                }].forEach(c => {
                    html += `<div class="col-md-3"><div class="mini-stat"><div class="label">${c.l}</div><div class="value">${c.f(c.v||0)}</div></div></div>`;
                });
                html += '</div>';
                el.innerHTML = html;
            }
            const rerenderSearchView = () => {
                if (!gmmState._searchPayload) {
                    loadGmm();
                    return;
                }
                // Simpan state input sebelum re-render
                const oldInput = document.getElementById('gmmSearchInput');
                const cursorPos = oldInput ? oldInput.selectionStart : null;
                const hadFocus = oldInput && document.activeElement === oldInput;

                renderGmm(gmmState._searchPayload);

                // Restore focus & cursor setelah DOM di-recreate
                if (hadFocus) {
                    setTimeout(() => {
                        const newInput = document.getElementById('gmmSearchInput');
                        if (newInput) {
                            newInput.focus();
                            if (cursorPos !== null) {
                                try {
                                    newInput.setSelectionRange(cursorPos, cursorPos);
                                } catch (e) {
                                    // beberapa input type tidak support setSelectionRange
                                }
                            }
                        }
                    }, 0);
                }
            };

            window.gmmSearchSort = (col) => {
                gmmState.searchSortCol = col;
                gmmState.view = 'search';
                // Re-render dengan data yang sama — panggil ulang loadGmm
                rerenderSearchView();
            };

            window.gmmResetSearch = () => {
                gmmState.search = '';
                gmmState.view = 'search';
                gmmState.searchMode = 'pegawai';
                gmmState.searchMetricMode = 'LIVIN';
                gmmState.searchSortCol = 'end_balance';
                gmmState.searchFilterArea = 'ALL';
                gmmState.searchFilterPosisi = 'ALL';
                gmmState.searchFilterKelas = 'ALL';
                rerenderSearchView();
                return;

                gmmState.search = '';
                gmmState.view = 'search';
                // Render ulang form pencarian langsung tanpa API call
                document.getElementById('gmmKatTabs').style.display = 'none';
                document.getElementById('gmmContent').innerHTML = `
        <h4 class="rajdhani fw-bold mb-3">🔍 Pencarian</h4>
        <div style="max-width:500px;position:relative;">
            <div class="d-flex gap-2 mb-1">
                <div style="flex:1;position:relative;">
                    <input type="text" class="form-control" id="gmmSearchInput"
                        placeholder="Ketik Nama, NIP, atau Unit..."
                        autocomplete="off"
                        oninput="gmmSuggest(this.value)"
                        onkeypress="if(event.key==='Enter'){document.getElementById('gmmSuggestBox').style.display='none';gmmSearchExec(this.value);}">
                    <div id="gmmSuggestBox" style="display:none;position:absolute;top:100%;left:0;right:0;
                        z-index:200;background:#fff;border:1px solid rgba(200,200,210,.6);
                        border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);
                        max-height:240px;overflow-y:auto;margin-top:4px;"></div>
                </div>
                <button class="btn btn-f1"
                    onclick="document.getElementById('gmmSuggestBox').style.display='none';
                             gmmSearchExec(document.getElementById('gmmSearchInput').value)">
                    <i class="bi bi-search"></i>
                </button>
            </div>
            <div style="font-size:.72rem;color:#94a3b8;margin-top:4px;">
                Ketik minimal 2 huruf untuk saran pencarian
            </div>
        </div>`;
                // Focus ke input
                setTimeout(() => document.getElementById('gmmSearchInput')?.focus(), 100);
            };

            // Global GMM functions
            window.gmmSearchInput = (q) => {
                gmmState.search = String(q || '');
                gmmState.view = 'search';
                rerenderSearchView();
            };
            window.gmmSearchSetMode = (mode) => {
                gmmState.searchMode = mode === 'cabang' ? 'cabang' : 'pegawai';
                gmmState.searchFilterPosisi = 'ALL';
                gmmState.searchFilterKelas = 'ALL';
                rerenderSearchView();
            };
            window.gmmSearchSetMetric = (mode) => {
                gmmState.searchMetricMode = ['LIVIN', 'MERCHANT', 'TRANSAKSI'].includes(mode) ? mode : 'LIVIN';
                gmmState.searchSortCol = (sortDefs[gmmState.searchMetricMode] || [])[0]?.col || 'end_balance';
                rerenderSearchView();
            };
            window.gmmSearchSetFilter = (key, value) => {
                if (key === 'area') gmmState.searchFilterArea = value || 'ALL';
                if (key === 'posisi') gmmState.searchFilterPosisi = value || 'ALL';
                if (key === 'kelas') gmmState.searchFilterKelas = value || 'ALL';
                rerenderSearchView();
            };
            window.gmmSearchSetSort = window.gmmSearchSort;
            window.gmmSearchExec = (q) => {
                gmmState.search = String(q || '');
                gmmState.view = 'search';
                rerenderSearchView();
                return;

                if (!q) {
                    gmmState.search = '';
                    gmmState.view = 'search';
                    loadGmm();
                    return;
                }
                gmmState.search = q;
                gmmState.view = 'search';
                loadGmm();
            };
            window.gmmDetailPegawai = (nip) => {
                const content = document.getElementById('gmmContent');
                content.innerHTML = '<div class="empty-state"><div class="spinner-border text-danger"></div></div>';
                fetch(`${apiBase}?action=gmm_data&view=detail_pegawai&nip=${encodeURIComponent(nip)}`).then(r => r.json()).then(d => {
                    if (d.ok) renderGmm(d);
                    else content.innerHTML = `<div class="alert alert-danger">${d.message}</div>`;
                });
            };
            window.gmmDetailCabang = (kode) => {
                // Show pegawai list filtered to branch
                document.querySelectorAll('[data-gmm-view]').forEach(b => b.classList.remove('active'));
                const btnPegawai = document.querySelector('[data-gmm-view=pegawai]');
                if (btnPegawai) btnPegawai.classList.add('active');
                gmmState.view = 'pegawai';
                gmmState.filter = kode;
                gmmState.page = 1;
                loadGmm();
            };
            window.gmmPage = p => {
                gmmState.page = p;
                loadGmm();
            };
            window.gmmSort = col => {
                // 3-state toggle: desc → asc → off
                if (gmmState.sortCol !== col) {
                    gmmState.sortCol = col;
                    gmmState.sortDir = 'desc';
                } else if (gmmState.sortDir === 'desc') {
                    gmmState.sortDir = 'asc';
                } else {
                    gmmState.sortCol = '';
                    gmmState.sortDir = 'desc';
                }
                gmmState.page = 1;
                loadGmm();
            };

            loadGmm();
        });

        // ============================================================
        // ADMIN UPLOAD
        // ============================================================
        function initAdminUpload() {
            const title = document.getElementById('adminStatusTitle');
            const text = document.getElementById('adminStatusText');
            const form = document.getElementById('adminUploadForm');
            const fileInput = document.getElementById('adminExcelFile');
            const btnDeleteCache = document.getElementById('btnDeleteCache');

            document.querySelectorAll('[data-upload-tab]').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('[data-upload-tab]').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    const tab = btn.dataset.uploadTab;
                    document.querySelectorAll('[data-upload-content]').forEach(c => {
                        c.style.display = c.dataset.uploadContent === tab ? 'block' : 'none';
                    });
                });
            });

            if (form && fileInput) {
                form.addEventListener('submit', async e => {
                    e.preventDefault();
                    if (!fileInput.files.length) {
                        title.textContent = 'File belum dipilih';
                        text.textContent = 'Pilih file .xlsx.';
                        return;
                    }
                    if (fileInput.files[0].size > 10 * 1024 * 1024) {
                        title.textContent = 'Terlalu besar';
                        text.textContent = 'Max 10 MB.';
                        return;
                    }
                    const fd = new FormData();
                    fd.append('excel_file', fileInput.files[0]);
                    fd.append('type', 'dana'); // 🔥 TAMBAHKAN INI
                    showLoader(true, 'Upload dan parsing Excel...');
                    try {
                        const res = await fetch(`${apiBase}?action=upload`, {
                            method: 'POST',
                            body: fd
                        });
                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Upload gagal.');
                        title.textContent = 'Upload berhasil';
                        text.textContent = `${payload.summary?.products?.length||0} produk. Reload...`;
                        setTimeout(() => {
                            window.location.href = `${window.location.pathname}?page=admin`;
                        }, 1200);
                    } catch (err) {
                        title.textContent = 'Upload gagal';
                        text.textContent = err.message;
                    } finally {
                        showLoader(false);
                    }
                });
            }

            const kreditForm = document.getElementById('adminKreditUploadForm');
            if (kreditForm) {
                kreditForm.addEventListener('submit', async e => {
                    e.preventDefault();
                    const fi = document.getElementById('adminKreditFile');
                    if (!fi.files.length) {
                        title.textContent = 'File belum dipilih';
                        return;
                    }
                    const fd = new FormData();
                    fd.append('excel_file', fi.files[0]);
                    fd.append('type', 'kredit'); // 🔥 TAMBAHKAN INI
                    showLoader(true, 'Upload Kredit...');
                    try {
                        const res = await fetch(`${apiBase}?action=upload`, {
                            method: 'POST',
                            body: fd
                        });
                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Gagal.');
                        title.textContent = 'Upload Kredit berhasil';
                        text.textContent = `${payload.summary?.products?.length||0} produk. Reload...`;
                        setTimeout(() => {
                            window.location.href = `${window.location.pathname}?page=admin`;
                        }, 1200);
                    } catch (err) {
                        title.textContent = 'Upload gagal';
                        text.textContent = err.message;
                    } finally {
                        showLoader(false);
                    }
                });
            }

            const gmmForm = document.getElementById('adminGmmUploadForm');
            if (gmmForm) {
                gmmForm.addEventListener('submit', async e => {
                    e.preventDefault();
                    const fi = document.getElementById('adminGmmFile');
                    if (!fi.files.length) {
                        title.textContent = 'File belum dipilih';
                        return;
                    }
                    const uploadType = document.querySelector('input[name="gmm_upload_type"]:checked')?.value || 'current';
                    const fd = new FormData();
                    fd.append('gmm_file', fi.files[0]);
                    fd.append('upload_type', uploadType);
                    showLoader(true, 'Upload GMM...');
                    try {
                        const res = await fetch(`${apiBase}?action=gmm_upload`, {
                            method: 'POST',
                            body: fd
                        });
                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Gagal.');
                        title.textContent = 'Upload GMM berhasil';
                        text.textContent = payload.message;
                    } catch (err) {
                        title.textContent = 'Upload GMM gagal';
                        text.textContent = err.message;
                    } finally {
                        showLoader(false);
                    }
                });
            }

            // Laba Rugi Upload
            const labaRugiForm = document.getElementById('adminLabaRugiUploadForm');
            if (labaRugiForm) {
                labaRugiForm.addEventListener('submit', async e => {
                    e.preventDefault();
                    const fi = document.getElementById('adminLabaRugiFile');
                    if (!fi.files.length) {
                        title.textContent = 'File belum dipilih';
                        text.textContent = 'Pilih file .xlsx Laba Rugi.';
                        return;
                    }
                    if (fi.files[0].size > 60 * 1024 * 1024) {
                        title.textContent = 'Terlalu besar';
                        text.textContent = 'Max 60 MB untuk file Laba Rugi.';
                        return;
                    }
                    const fd = new FormData();
                    fd.append('excel_file', fi.files[0]);
                    showLoader(true, 'Upload dan parsing Laba Rugi... (bisa 1-2 menit)');
                    try {
                        const res = await fetch(`${apiBase}?action=labarugi_upload`, {
                            method: 'POST',
                            body: fd
                        });
                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Upload gagal.');
                        title.textContent = 'Upload Laba Rugi berhasil';
                        text.textContent = `${payload.summary?.sheets?.length||0} sheet, ${payload.summary?.branches||0} cabang, ${payload.summary?.months||0} bulan diproses.`;
                    } catch (err) {
                        title.textContent = 'Upload Laba Rugi gagal';
                        text.textContent = err.message;
                    } finally {
                        showLoader(false);
                    }
                });
            }

            // Reset Laba Rugi cache
            const btnDeleteLabaRugi = document.getElementById('btnDeleteLabaRugiCache');
            if (btnDeleteLabaRugi) {
                btnDeleteLabaRugi.addEventListener('click', async () => {
                    if (!confirm('Hapus seluruh cache Laba Rugi?')) return;
                    showLoader(true, 'Menghapus cache Laba Rugi...');
                    try {
                        const res = await fetch(`${apiBase}?action=labarugi_delete_cache`, {
                            method: 'POST'
                        });
                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Gagal.');
                        alert(payload.message);
                    } catch (err) {
                        alert(err.message);
                    } finally {
                        showLoader(false);
                    }
                });
            }
            // Market Share Upload
            const msForm = document.getElementById('adminMarketShareUploadForm');
            if (msForm) {
                msForm.addEventListener('submit', async e => {
                    e.preventDefault();
                    const fi = document.getElementById('adminMarketShareFile');
                    if (!fi.files.length) {
                        title.textContent = 'File belum dipilih';
                        text.textContent = 'Pilih file .xlsx Market Share.';
                        return;
                    }
                    if (fi.files[0].size > 60 * 1024 * 1024) {
                        title.textContent = 'Terlalu besar';
                        text.textContent = 'Max 60 MB.';
                        return;
                    }
                    const fd = new FormData();
                    fd.append('excel_file', fi.files[0]);
                    showLoader(true, 'Upload dan parsing Market Share...');
                    try {
                        const res = await fetch(`${apiBase}?action=marketshare_upload`, {
                            method: 'POST',
                            body: fd
                        });
                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Upload gagal.');
                        title.textContent = 'Upload Market Share berhasil';
                        text.textContent = `${payload.summary?.products?.length || 0} produk, ${payload.summary?.branches || 0} cabang, ${payload.summary?.months || 0} bulan diproses.`;
                    } catch (err) {
                        title.textContent = 'Upload Market Share gagal';
                        text.textContent = err.message;
                    } finally {
                        showLoader(false);
                    }
                });
            }

            // Reset Market Share cache
            const btnDeleteMs = document.getElementById('btnDeleteMarketShareCache');
            if (btnDeleteMs) {
                btnDeleteMs.addEventListener('click', async () => {
                    if (!confirm('Hapus seluruh cache Market Share?')) return;
                    showLoader(true, 'Menghapus cache Market Share...');
                    try {
                        const res = await fetch(`${apiBase}?action=marketshare_delete_cache`, {
                            method: 'POST'
                        });
                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Gagal.');
                        alert(payload.message);
                    } catch (err) {
                        alert(err.message);
                    } finally {
                        showLoader(false);
                    }
                });
            }

            const btnResetGmm = document.getElementById('btnResetGmm');
            if (btnResetGmm) {
                btnResetGmm.addEventListener('click', async () => {
                    if (!confirm('Reset semua data GMM?')) return;
                    showLoader(true, 'Reset GMM...');
                    try {
                        const res = await fetch(`${apiBase}?action=gmm_reset`);
                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Gagal.');
                        title.textContent = 'Reset GMM berhasil';
                        text.textContent = payload.message;
                    } catch (err) {
                        alert(err.message);
                    } finally {
                        showLoader(false);
                    }
                });
            }

            if (btnDeleteCache) {
                btnDeleteCache.addEventListener('click', async () => {
                    if (!confirm('Hapus data financial? Data user tetap aman.')) return;

                    // 🔥 ambil tab aktif
                    const activeTab = document.querySelector('[data-upload-tab].active')?.dataset.uploadTab || 'dana';

                    const fd = new FormData();
                    fd.append('type', activeTab); // 'dana' atau 'kredit'

                    showLoader(true, 'Menghapus cache...');

                    try {
                        const res = await fetch(`${apiBase}?action=delete_cache`, {
                            method: 'POST',
                            body: fd
                        });

                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Gagal.');

                        alert(payload.message);
                        window.location.reload();
                    } catch (err) {
                        alert(err.message);
                    } finally {
                        showLoader(false);
                    }
                });
            }

            const datesForm = document.getElementById('updateDatesForm');
            if (datesForm) {
                datesForm.addEventListener('submit', async e => {
                    e.preventDefault();
                    const fd = new FormData();
                    fd.append('produk_dana', document.getElementById('dateDana').value);
                    fd.append('produk_kredit', document.getElementById('dateKredit').value);
                    fd.append('gmm', document.getElementById('dateGmm').value);
                    try {
                        const res = await fetch(`${apiBase}?action=save_update_dates`, {
                            method: 'POST',
                            body: fd
                        });
                        const payload = await res.json();
                        if (!res.ok || !payload.ok) throw new Error(payload.message || 'Gagal.');
                        if (title) title.textContent = 'Tanggal tersimpan';
                        if (text) text.textContent = payload.message;
                    } catch (err) {
                        alert(err.message);
                    }
                });
            }
        }

        // Toggle detail row di Admin Top Viewer
        function toggleUserDetail(userId) {
            const row = document.getElementById('detail-' + userId);
            if (!row) return;
            const isVisible = row.style.display !== 'none';
            // Close all other detail rows first
            document.querySelectorAll('[data-viewer-detail-row="true"]').forEach(r => {
                r.style.display = 'none';
            });
            if (!isVisible) {
                row.style.display = '';
                row.querySelector('div')?.classList.add('detail-row-content');
            }
        }

        // Filter Recent Activity table
        function filterActivity() {
            const from = document.getElementById('actFilterFrom')?.value || '';
            const to = document.getElementById('actFilterTo')?.value || '';
            const userQ = (document.getElementById('actFilterUser')?.value || '').toLowerCase();
            const eventQ = (document.getElementById('actFilterEvent')?.value || '').toLowerCase();

            document.querySelectorAll('.admin-table').forEach((tbl, idx) => {
                if (idx !== 1) return; // Only 2nd table = Recent Activity
                tbl.querySelectorAll('tbody tr').forEach(row => {
                    // Skip detail rows
                    if (row.id && row.id.startsWith('detail-')) return;

                    const cells = row.querySelectorAll('td');
                    if (cells.length < 4) return;

                    const timeText = cells[0]?.textContent?.trim() || '';
                    const userText = cells[1]?.textContent?.trim().toLowerCase() || '';
                    const eventText = cells[2]?.textContent?.trim().toLowerCase() || '';

                    let show = true;
                    if (userQ && !userText.includes(userQ)) show = false;
                    if (eventQ && !eventText.includes(eventQ)) show = false;

                    // Date filter — timeText format: "H:i · d Mon YYYY"
                    if (from || to) {
                        try {
                            const parts = timeText.split('·');
                            if (parts.length >= 2) {
                                const datePart = parts[1].trim(); // e.g. "11 Mei 2026"
                                const months = {
                                    Jan: 1,
                                    Feb: 2,
                                    Mar: 3,
                                    Apr: 4,
                                    Mei: 5,
                                    Jun: 6,
                                    Jul: 7,
                                    Agu: 8,
                                    Sep: 9,
                                    Okt: 10,
                                    Nov: 11,
                                    Des: 12
                                };
                                const dp = datePart.split(' ');
                                if (dp.length === 3) {
                                    const d = parseInt(dp[0]),
                                        m = months[dp[1]] || 0,
                                        y = parseInt(dp[2]);
                                    const rowDate = new Date(y, m - 1, d);
                                    if (from) {
                                        const fd = new Date(from);
                                        if (rowDate < fd) show = false;
                                    }
                                    if (to) {
                                        const td = new Date(to);
                                        if (rowDate > td) show = false;
                                    }
                                }
                            }
                        } catch (e) {}
                    }

                    row.style.display = show ? '' : 'none';
                });
            });
        }

        function resetActivity() {
            ['actFilterFrom', 'actFilterTo', 'actFilterUser', 'actFilterEvent'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            document.querySelectorAll('.admin-table').forEach((tbl, idx) => {
                if (idx !== 1) return;
                tbl.querySelectorAll('tbody tr').forEach(row => {
                    row.style.display = '';
                });
            });
        }

        // ============================================================
        // SHARED UTILITIES
        // ============================================================
        async function apiGet(action, params = {}) {
            const query = new URLSearchParams({
                action,
                ...params
            });
            const res = await fetch(`${apiBase}?${query}`);
            const payload = await res.json();
            if (!res.ok || !payload.ok) throw new Error(payload.message || 'Request gagal.');
            return payload;
        }

        function fillSelect(select, options) {
            select.innerHTML = '';
            options.forEach(o => {
                const el = document.createElement('option');
                el.value = o.value;
                el.textContent = o.label;
                select.appendChild(el);
            });
        }

        function setControlsEnabled(els, enabled) {
            ['productSelect', 'entityInput', 'searchEntityBtn', 'monthSelect', 'periodSelect', 'refreshButton'].forEach(k => {
                if (els[k]) els[k].disabled = !enabled;
            });
        }

        function setStatus(els, title, text, tone = 'info') {
            const iconClass = tone === 'success' ? 'bi-check-circle-fill text-success' : tone === 'danger' ? 'bi-x-circle-fill text-danger' : tone === 'warning' ? 'bi-exclamation-triangle-fill text-warning' : 'bi-info-circle-fill text-danger';
            const icon = document.querySelector('#statusBox i');
            if (icon) icon.className = `bi ${iconClass} fs-4`;
            if (els.statusTitle) els.statusTitle.textContent = title;
            if (els.statusText) els.statusText.textContent = text;
        }

        function showLoader(show, text = 'Memproses data...') {
            const loader = document.getElementById('loader'),
                loaderText = document.getElementById('loaderText');
            if (!loader || !loaderText) return;
            loaderText.textContent = text;
            loader.classList.toggle('show', show);
        }


        function formatNumber(value) {
            if (value === null || value === undefined || Number.isNaN(Number(value))) return '-';
            return formatter.format(value);
        }

        function formatNumberWithUnitv2(value) {
            const numValue = Number(value);


            if (value === null || value === undefined || Number.isNaN(numValue)) return '-';


            const absValue = Math.abs(numValue);

            return numValue.toLocaleString('id-ID', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + ' M';
        }


        function formatNumberWithUnit(value) {
            const numValue = Number(value);


            if (value === null || value === undefined || Number.isNaN(numValue)) return '-';


            const absValue = Math.abs(numValue);


            // Jika sesuai preferensi: Jt jika di bawah miliar
            if (absValue > 0 && absValue < 1) {
                const inJuta = numValue * 1000;
                return inJuta.toLocaleString('id-ID', {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 2
                }) + ' Jt';
            }


            return numValue.toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 1
            }) + ' M';
        }


        function formatCompactWithUnit(value) {
            return formatNumberWithUnit(value);
        }


        function formatDateTime(value) {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return value;
            return date.toLocaleString('id-ID', {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }


        function escapeHtml(value) {
            return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
    </script>
</body>


</html>