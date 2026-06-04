<?php
/**
 * --
 *  OJS Core Updater / Reinstaller
 *  Versi: 1.0.0
 *  Versi: 1.0.0
 *  Deskripsi: Script untuk update atau reinstall OJS core
 *             tanpa menghilangkan data yang sudah ada.
 *
 *  PERINGATAN KEAMANAN:
 *  - Hapus script ini setelah selesai digunakan!
 *  - Pastikan hanya diakses oleh administrator!
 * ---
 */

// KONFIGURASI - Sesuaikan sebelum dijalankan

define('SCRIPT_PASSWORD',   'ganti_password_anda');                       // Password untuk mengakses script ini
define('OJS_ROOT',          dirname(__FILE__));                           // Root instalasi OJS (ubah jika perlu)
define('BACKUP_BASE_DIR',   '/tmp');                                      // Direktori untuk menyimpan backup sementara
define('MAX_EXEC_SECONDS',  600);                                         // Batas waktu eksekusi (detik)
define('OJS_DOWNLOAD_BASE', 'https://pkp.sfu.ca/software/ojs/download/'); // URL dasar download OJS

// INISIALISASI

@set_time_limit(MAX_EXEC_SECONDS);
@ini_set('memory_limit', '512M');
session_start();

$step    = isset($_GET['step'])   ? (int)$_GET['step']   : 0;
$action  = isset($_POST['action'])? trim($_POST['action']): '';
$errors  = [];
$success = [];
$info    = [];

// FUNGSI-FUNGSI UTAMA

/**
 * Verifikasi password
 */
function checkAuth(): bool {
    if (isset($_SESSION['ojs_updater_auth']) && $_SESSION['ojs_updater_auth'] === true) {
        return true;
    }
    return false;
}

/**
 * Baca konfigurasi OJS dari config.inc.php
 */
function readOjsConfig(string $ojsRoot): array {
    $configFile = $ojsRoot . '/config.inc.php';
    if (!file_exists($configFile)) {
        return ['error' => 'File config.inc.php tidak ditemukan di: ' . $configFile];
    }

    $config = [];
    $content = file_get_contents($configFile);

    // Parse beberapa nilai penting
    $patterns = [
        'db_driver'   => '/^\s*driver\s*=\s*(.+)$/m',
        'db_host'     => '/^\s*host\s*=\s*(.+)$/m',
        'db_username' => '/^\s*username\s*=\s*(.+)$/m',
        'db_password' => '/^\s*password\s*=\s*(.+)$/m',
        'db_name'     => '/^\s*name\s*=\s*(.+)$/m',
        'files_dir'   => '/^\s*files_dir\s*=\s*(.+)$/m',
        'base_url'    => '/^\s*base_url\s*=\s*(.+)$/m',
    ];

    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $content, $m)) {
            $config[$key] = trim($m[1]);
        }
    }

    $config['config_path'] = $configFile;
    return $config;
}

/**
 * Deteksi versi OJS yang terinstall
 */
function detectOjsVersion(string $ojsRoot): string {
    // Coba dari dbscripts/xml/version.xml
    $versionFile = $ojsRoot . '/dbscripts/xml/version.xml';
    if (file_exists($versionFile)) {
        $xml = @simplexml_load_file($versionFile);
        if ($xml) {
            $release = (string)($xml->release ?? '');
            if ($release) return $release;
        }
    }
    // Coba dari lib/pkp/lib/vendor/autoload.php (heuristic)
    return 'Tidak diketahui';
}

/**
 * Hitung ukuran direktori (rekursif)
 */
function dirSize(string $dir): int {
    $size = 0;
    if (!is_dir($dir)) return 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        $size += $file->getSize();
    }
    return $size;
}

/**
 * Format bytes ke string yang mudah dibaca
 */
function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

/**
 * Salin direktori secara rekursif
 */
function copyDir(string $src, string $dst): bool {
    if (!is_dir($dst)) {
        if (!@mkdir($dst, 0755, true)) return false;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $item) {
        $target = $dst . DIRECTORY_SEPARATOR . $iter->getSubPathname();
        if ($item->isDir()) {
            @mkdir($target, 0755, true);
        } else {
            if (!@copy($item->getPathname(), $target)) return false;
        }
    }
    return true;
}

/**
 * Hapus direktori secara rekursif
 */
function removeDir(string $dir): bool {
    if (!is_dir($dir)) return true;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    return @rmdir($dir);
}

/**
 * Deteksi nama folder files_dir dari config.inc.php.
 * Kembalikan nama folder (basename) jika files_dir berada DALAM ojsRoot,
 * atau null jika di luar root (sudah aman secara otomatis).
 */
function detectFilesDirInsideRoot(string $ojsRoot): ?string {
    $config = readOjsConfig($ojsRoot);
    if (empty($config['files_dir'])) return null;

    $filesDir = rtrim($config['files_dir'], '/\\');

    // Normalisasi path (resolve relative, symlink, dll)
    $realRoot     = realpath($ojsRoot);
    $realFilesDir = realpath($filesDir);

    // Jika realpath gagal (folder belum ada?), coba resolve manual
    if (!$realFilesDir) {
        // Coba interpretasi sebagai relative path dari ojsRoot
        $candidate = realpath($ojsRoot . DIRECTORY_SEPARATOR . $filesDir);
        if ($candidate) $realFilesDir = $candidate;
    }

    if (!$realRoot || !$realFilesDir) return null;

    // Cek apakah files_dir adalah subdirektori dari ojsRoot
    if (strpos($realFilesDir, $realRoot . DIRECTORY_SEPARATOR) === 0 ||
        $realFilesDir === $realRoot) {
        // Ambil nama folder pertama relatif dari root
        $relative = ltrim(substr($realFilesDir, strlen($realRoot)), DIRECTORY_SEPARATOR);
        $parts    = explode(DIRECTORY_SEPARATOR, $relative);
        return $parts[0]; // basename level pertama
    }

    return null; // files_dir di luar root → aman
}

/**
 * Daftar file/folder OJS yang harus DIPERTAHANKAN (tidak ditimpa).
 * Otomatis menambahkan files_dir jika berada di dalam OJS root.
 */
function getPreservedItems(string $ojsRoot = ''): array {
    $base = [
        'config.inc.php',   // Konfigurasi utama
        'public',           // File publik yang diupload
        '.htaccess',        // Konfigurasi Apache kustom
        'cache',            // Akan dibersihkan, bukan diganti
    ];

    if ($ojsRoot) {
        $filesDirName = detectFilesDirInsideRoot($ojsRoot);
        if ($filesDirName && !in_array($filesDirName, $base)) {
            $base[] = $filesDirName; // Proteksi otomatis!
        }
    }

    return $base;
}

/**
 * Scan dan bandingkan isi root OJS existing vs direktori OJS baru.
 * Hasilkan laporan: akan diganti, dipertahankan, item custom (hanya di existing).
 *
 * @return array {
 *   'will_replace'  => [...],  // Ada di ZIP baru DAN existing, bukan preserved
 *   'preserved'     => [...],  // Ada di ZIP baru TAPI masuk daftar preserved
 *   'custom_only'   => [...],  // Hanya ada di existing (TIDAK AKAN DISENTUH)
 *   'new_only'      => [...],  // Hanya ada di ZIP baru (akan ditambahkan)
 *   'files_dir_name'=> string|null,
 *   'files_dir_loc' => 'inside'|'outside'|'unknown',
 * }
 */
function scanRootDiff(string $ojsRoot, string $newOjsDir): array {
    $preserved     = getPreservedItems($ojsRoot);
    $filesDirName  = detectFilesDirInsideRoot($ojsRoot);
    $config        = readOjsConfig($ojsRoot);

    // Baca isi direktori existing
    $existingItems = [];
    if (is_dir($ojsRoot)) {
        foreach (new DirectoryIterator($ojsRoot) as $item) {
            if ($item->isDot()) continue;
            $existingItems[$item->getFilename()] = $item->isDir() ? 'dir' : 'file';
        }
    }

    // Baca isi direktori OJS baru
    $newItems = [];
    if ($newOjsDir && is_dir($newOjsDir)) {
        foreach (new DirectoryIterator($newOjsDir) as $item) {
            if ($item->isDot()) continue;
            $newItems[$item->getFilename()] = $item->isDir() ? 'dir' : 'file';
        }
    }

    $willReplace  = [];
    $preservedHit = [];
    $newOnly      = [];
    $customOnly   = [];

    // Dari ZIP baru → apa yang akan terjadi
    foreach ($newItems as $name => $type) {
        if (in_array($name, $preserved)) {
            $preservedHit[] = ['name' => $name, 'type' => $type,
                               'exists_in_root' => isset($existingItems[$name])];
        } elseif ($name === 'plugins') {
            $willReplace[] = ['name' => $name, 'type' => $type, 'action' => 'merge'];
        } else {
            if (isset($existingItems[$name])) {
                $willReplace[] = ['name' => $name, 'type' => $type, 'action' => 'replace'];
            } else {
                $newOnly[] = ['name' => $name, 'type' => $type, 'action' => 'add'];
            }
        }
    }

    // Item yang HANYA ada di existing (custom / bukan bagian OJS baru)
    foreach ($existingItems as $name => $type) {
        if (!isset($newItems[$name])) {
            $reason = 'custom';
            if (in_array($name, $preserved)) $reason = 'preserved';
            if ($name === $filesDirName)      $reason = 'files_dir';
            if ($name === basename(__FILE__)) $reason = 'script_ini';
            $customOnly[] = ['name' => $name, 'type' => $type, 'reason' => $reason];
        }
    }

    // Tentukan status files_dir
    $filesDirRaw = $config['files_dir'] ?? '';
    if (!$filesDirName) {
        $filesDirLoc = $filesDirRaw ? 'outside' : 'unknown';
    } else {
        $filesDirLoc = 'inside';
    }

    return [
        'will_replace'   => $willReplace,
        'preserved'      => $preservedHit,
        'custom_only'    => $customOnly,
        'new_only'       => $newOnly,
        'files_dir_name' => $filesDirName,
        'files_dir_raw'  => $filesDirRaw,
        'files_dir_loc'  => $filesDirLoc,
        'preserved_list' => $preserved,
    ];
}

/**
 * Daftar folder plugin yang mungkin berisi plugin kustom
 * Akan di-merge, bukan ditimpa penuh
 */
function getPluginDirs(): array {
    return [
        'plugins/generic',
        'plugins/themes',
        'plugins/importexport',
        'plugins/metadata',
        'plugins/paymethod',
        'plugins/blocks',
        'plugins/reports',
        'plugins/gateways',
        'plugins/oaiMetadataFormats',
        'plugins/pubIds',
    ];
}

/**
 * Backup file-file penting sebelum update
 */
function backupImportantFiles(string $ojsRoot, string $backupDir): array {
    $result = ['success' => false, 'backup_dir' => $backupDir, 'backed_up' => []];

    if (!@mkdir($backupDir, 0755, true)) {
        $result['error'] = 'Tidak dapat membuat direktori backup: ' . $backupDir;
        return $result;
    }

    // Backup config.inc.php
    $configSrc = $ojsRoot . '/config.inc.php';
    $configDst = $backupDir . '/config.inc.php';
    if (file_exists($configSrc)) {
        if (@copy($configSrc, $configDst)) {
            $result['backed_up'][] = 'config.inc.php';
        }
    }

    // Backup folder public/
    $publicSrc = $ojsRoot . '/public';
    $publicDst = $backupDir . '/public';
    if (is_dir($publicSrc)) {
        if (copyDir($publicSrc, $publicDst)) {
            $result['backed_up'][] = 'public/';
        }
    }

    // Backup .htaccess jika ada
    $htSrc = $ojsRoot . '/.htaccess';
    $htDst = $backupDir . '/.htaccess';
    if (file_exists($htSrc)) {
        if (@copy($htSrc, $htDst)) {
            $result['backed_up'][] = '.htaccess';
        }
    }

    // Backup files_dir JIKA berada di dalam OJS root
    $filesDirName = detectFilesDirInsideRoot($ojsRoot);
    if ($filesDirName) {
        $filesSrc = $ojsRoot . '/' . $filesDirName;
        $filesDst = $backupDir . '/' . $filesDirName;
        if (is_dir($filesSrc)) {
            // Hitung ukuran dulu — jika sangat besar, skip backup tapi catat warning
            $filesSize = dirSize($filesSrc);
            if ($filesSize < 500 * 1024 * 1024) { // < 500MB: backup
                if (copyDir($filesSrc, $filesDst)) {
                    $result['backed_up'][] = $filesDirName . '/ (files_dir)';
                } else {
                    $result['warnings'][] = 'Gagal backup ' . $filesDirName . '/ — lanjutkan dengan hati-hati.';
                }
            } else {
                $result['warnings'][] = $filesDirName . '/ terlalu besar (' . formatBytes($filesSize) .
                    ') untuk dibackup otomatis. Backup manual sebelum melanjutkan!';
            }
        }
    }

    // Catat versi yang di-backup
    $versionInfo = detectOjsVersion($ojsRoot);
    $filesDirInfo = $filesDirName
        ? "files_dir (inside root): " . $ojsRoot . '/' . $filesDirName
        : "files_dir: di luar root OJS";
    file_put_contents($backupDir . '/backup_info.txt',
        "Backup dibuat: " . date('Y-m-d H:i:s') . "\n" .
        "Versi OJS: " . $versionInfo . "\n" .
        "OJS Root: " . $ojsRoot . "\n" .
        $filesDirInfo . "\n"
    );

    $result['success'] = true;
    return $result;
}

/**
 * Ekstrak file ZIP OJS ke direktori sementara
 */
function extractOjsZip(string $zipPath, string $extractTo): array {
    $result = ['success' => false];

    if (!extension_loaded('zip')) {
        $result['error'] = 'Ekstensi PHP zip tidak tersedia.';
        return $result;
    }

    if (!file_exists($zipPath)) {
        $result['error'] = 'File ZIP tidak ditemukan: ' . $zipPath;
        return $result;
    }

    if (!@mkdir($extractTo, 0755, true)) {
        $result['error'] = 'Tidak dapat membuat direktori ekstraksi: ' . $extractTo;
        return $result;
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($zipPath);
    if ($openResult !== true) {
        $result['error'] = 'Tidak dapat membuka ZIP. Kode error: ' . $openResult;
        return $result;
    }

    if (!$zip->extractTo($extractTo)) {
        $zip->close();
        $result['error'] = 'Gagal mengekstrak ZIP ke: ' . $extractTo;
        return $result;
    }

    $zip->close();

    // Cari subfolder OJS di dalam ZIP (biasanya ojs-x.x.x/)
    $items = glob($extractTo . '/*', GLOB_ONLYDIR);
    $ojsSubDir = null;
    foreach ($items as $item) {
        if (strpos(basename($item), 'ojs') !== false) {
            $ojsSubDir = $item;
            break;
        }
    }

    // Jika hanya satu subfolder, gunakan itu
    if (!$ojsSubDir && count($items) === 1) {
        $ojsSubDir = $items[0];
    }

    $result['success']    = true;
    $result['extract_to'] = $extractTo;
    $result['ojs_subdir'] = $ojsSubDir ?? $extractTo;
    return $result;
}

/**
 * Terapkan (apply) file-file OJS baru ke instalasi yang ada
 * sambil mempertahankan data penting
 */
function applyOjsUpdate(string $newOjsDir, string $ojsRoot): array {
    $result   = ['success' => false, 'replaced' => [], 'skipped' => [], 'errors' => []];
    // Gunakan preserved list DINAMIS — termasuk files_dir jika di dalam root
    $preserved = getPreservedItems($ojsRoot);

    if (!is_dir($newOjsDir)) {
        $result['error'] = 'Direktori OJS baru tidak ditemukan: ' . $newOjsDir;
        return $result;
    }

    // Iterasi semua item di root OJS baru
    $items = new DirectoryIterator($newOjsDir);
    foreach ($items as $item) {
        if ($item->isDot()) continue;

        $name   = $item->getFilename();
        $srcPath = $item->getPathname();
        $dstPath = $ojsRoot . DIRECTORY_SEPARATOR . $name;

        // Lewati item yang harus dipertahankan
        if (in_array($name, $preserved)) {
            $result['skipped'][] = $name;
            continue;
        }

        // Untuk folder plugins: lakukan merge, bukan ganti penuh
        if ($name === 'plugins' && is_dir($srcPath) && is_dir($dstPath)) {
            $pluginResult = mergePluginsDir($srcPath, $dstPath);
            if ($pluginResult) {
                $result['replaced'][] = 'plugins/ (merged)';
            } else {
                $result['errors'][] = 'Gagal merge direktori plugins/';
            }
            continue;
        }

        // Hapus yang lama, salin yang baru
        if ($item->isDir()) {
            removeDir($dstPath);
            if (copyDir($srcPath, $dstPath)) {
                $result['replaced'][] = $name . '/';
            } else {
                $result['errors'][] = 'Gagal menyalin direktori: ' . $name;
            }
        } else {
            if (@copy($srcPath, $dstPath)) {
                $result['replaced'][] = $name;
            } else {
                $result['errors'][] = 'Gagal menyalin file: ' . $name;
            }
        }
    }

    $result['success'] = empty($result['errors']);
    return $result;
}

/**
 * Merge direktori plugins: pertahankan plugin kustom, update plugin bawaan
 */
function mergePluginsDir(string $newPluginsDir, string $existingPluginsDir): bool {
    // Iterasi kategori plugin (generic, themes, dll.)
    $categories = new DirectoryIterator($newPluginsDir);
    foreach ($categories as $cat) {
        if ($cat->isDot() || !$cat->isDir()) continue;

        $catName    = $cat->getFilename();
        $newCatDir  = $cat->getPathname();
        $existCatDir = $existingPluginsDir . '/' . $catName;

        if (!is_dir($existCatDir)) {
            // Kategori baru, salin seluruhnya
            copyDir($newCatDir, $existCatDir);
            continue;
        }

        // Untuk setiap plugin dalam kategori baru
        $plugins = new DirectoryIterator($newCatDir);
        foreach ($plugins as $plugin) {
            if ($plugin->isDot() || !$plugin->isDir()) continue;

            $pluginName     = $plugin->getFilename();
            $newPluginPath  = $plugin->getPathname();
            $existPluginPath = $existCatDir . '/' . $pluginName;

            // Ganti dengan versi baru (plugin bawaan OJS)
            removeDir($existPluginPath);
            copyDir($newPluginPath, $existPluginPath);
        }
    }
    return true;
}

/**
 * Bersihkan cache OJS
 */
function clearOjsCache(string $ojsRoot): array {
    $result    = ['cleared' => []];
    $cacheDirs = [
        $ojsRoot . '/cache',
        $ojsRoot . '/cache/t_compile',
        $ojsRoot . '/cache/t_config',
        $ojsRoot . '/cache/t_qc',
        $ojsRoot . '/cache/_db',
    ];

    foreach ($cacheDirs as $cacheDir) {
        if (!is_dir($cacheDir)) continue;
        $files = new FilesystemIterator($cacheDir, FilesystemIterator::SKIP_DOTS);
        $count = 0;
        foreach ($files as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitignore') {
                @unlink($file->getPathname());
                $count++;
            }
        }
        if ($count > 0) $result['cleared'][] = basename($cacheDir) . "/ ($count file)";
    }
    return $result;
}

/**
 * Jalankan upgrade database OJS menggunakan CLI tools OJS
 * (php tools/upgrade.php upgrade)
 */
function runOjsDbUpgrade(string $ojsRoot): array {
    $result    = ['success' => false, 'output' => ''];
    $upgradeTool = $ojsRoot . '/tools/upgrade.php';

    if (!file_exists($upgradeTool)) {
        $result['error'] = 'tools/upgrade.php tidak ditemukan.';
        return $result;
    }

    $phpBin  = PHP_BINARY ?: 'php';
    $command = escapeshellarg($phpBin) . ' ' .
               escapeshellarg($upgradeTool) . ' upgrade 2>&1';

    $oldCwd = getcwd();
    chdir($ojsRoot);
    exec($command, $outputLines, $returnCode);
    chdir($oldCwd);

    $result['output']  = implode("\n", $outputLines);
    $result['return_code'] = $returnCode;
    $result['success'] = ($returnCode === 0);

    if (!$result['success']) {
        $result['error'] = 'Upgrade DB selesai dengan kode: ' . $returnCode;
    }

    return $result;
}

// ============================================================
// PROSES FORM ACTIONS
// ============================================================

if ($action === 'login') {
    $password = trim($_POST['password'] ?? '');
    if ($password === SCRIPT_PASSWORD) {
        $_SESSION['ojs_updater_auth'] = true;
        header('Location: ?step=1');
        exit;
    } else {
        $errors[] = 'Password salah!';
    }
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ?step=0');
    exit;
}

if ($action === 'backup' && checkAuth()) {
    $backupDir = BACKUP_BASE_DIR . '/ojs_backup_' . date('YmdHis');
    $result = backupImportantFiles(OJS_ROOT, $backupDir);
    if ($result['success']) {
        $_SESSION['ojs_backup_dir'] = $backupDir;
        $success[] = 'Backup berhasil ke: ' . $backupDir;
        $success[] = 'File yang dibackup: ' . implode(', ', $result['backed_up']);
        // Tampilkan warnings (misal files_dir terlalu besar)
        foreach ($result['warnings'] ?? [] as $w) {
            $errors[] = '⚠ Peringatan: ' . $w;
        }
    } else {
        $errors[] = $result['error'] ?? 'Backup gagal.';
    }
}

if ($action === 'upload_zip' && checkAuth()) {
    if (!isset($_FILES['ojs_zip']) || $_FILES['ojs_zip']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Gagal mengupload file ZIP. Error: ' . ($_FILES['ojs_zip']['error'] ?? 'tidak ada file');
    } else {
        $uploadDir  = sys_get_temp_dir() . '/ojs_upload_' . time();
        @mkdir($uploadDir, 0755, true);
        $zipPath    = $uploadDir . '/ojs_new.zip';

        if (@move_uploaded_file($_FILES['ojs_zip']['tmp_name'], $zipPath)) {
            $extractTo   = sys_get_temp_dir() . '/ojs_extract_' . time();
            $extractResult = extractOjsZip($zipPath, $extractTo);

            if ($extractResult['success']) {
                $_SESSION['ojs_new_dir'] = $extractResult['ojs_subdir'];
                $success[] = 'ZIP berhasil diekstrak ke: ' . $extractResult['ojs_subdir'];
            } else {
                $errors[] = $extractResult['error'] ?? 'Gagal mengekstrak ZIP.';
            }
            @unlink($zipPath);
        } else {
            $errors[] = 'Gagal memindahkan file upload.';
        }
    }
}

if ($action === 'apply_update' && checkAuth()) {
    if (empty($_SESSION['ojs_new_dir']) || !is_dir($_SESSION['ojs_new_dir'])) {
        $errors[] = 'Direktori OJS baru tidak ditemukan. Silakan upload ZIP terlebih dahulu.';
    } else {
        $applyResult = applyOjsUpdate($_SESSION['ojs_new_dir'], OJS_ROOT);
        if ($applyResult['success']) {
            $success[] = 'Update core berhasil! ' . count($applyResult['replaced']) . ' item diperbarui.';
            $success[] = 'Dilewati (dipertahankan): ' . implode(', ', $applyResult['skipped']);
        } else {
            $errors[] = 'Ada error saat apply: ' . implode('; ', $applyResult['errors']);
        }
        if (!empty($applyResult['errors'])) {
            foreach ($applyResult['errors'] as $e) $errors[] = $e;
        }
    }
}

if ($action === 'clear_cache' && checkAuth()) {
    $cacheResult = clearOjsCache(OJS_ROOT);
    $success[] = 'Cache dibersihkan. ' .
        (!empty($cacheResult['cleared']) ? implode(', ', $cacheResult['cleared']) : 'Tidak ada cache yang perlu dibersihkan.');
}

if ($action === 'run_upgrade' && checkAuth()) {
    $upgradeResult = runOjsDbUpgrade(OJS_ROOT);
    if ($upgradeResult['success']) {
        $success[] = 'Upgrade database berhasil!';
    } else {
        $errors[] = 'Upgrade database: ' . ($upgradeResult['error'] ?? 'gagal');
    }
    if (!empty($upgradeResult['output'])) {
        $info[] = 'Output: ' . nl2br(htmlspecialchars($upgradeResult['output']));
    }
}

// Handle set manual dir path
if ($action === 'set_manual_dir' && checkAuth()) {
    $manualDir = trim($_POST['manual_dir'] ?? '');
    if ($manualDir && is_dir($manualDir)) {
        $_SESSION['ojs_new_dir'] = $manualDir;
        $success[] = 'Direktori manual ditetapkan: ' . $manualDir;
    } else {
        $errors[] = 'Path direktori tidak valid atau tidak ditemukan: ' . htmlspecialchars($manualDir);
    }
}

// KUMPULKAN INFO SISTEM

$ojsConfig    = checkAuth() ? readOjsConfig(OJS_ROOT) : [];
$ojsVersion   = checkAuth() ? detectOjsVersion(OJS_ROOT) : '-';
$phpVersion   = PHP_VERSION;
$zipAvail     = extension_loaded('zip') ? '✓ Tersedia' : '✗ Tidak tersedia';
$maxUpload    = ini_get('upload_max_filesize');
$postMax      = ini_get('post_max_size');
$backupDir    = $_SESSION['ojs_backup_dir'] ?? null;
$newOjsDir    = $_SESSION['ojs_new_dir'] ?? null;
$filesDirName = checkAuth() ? detectFilesDirInsideRoot(OJS_ROOT) : null;
$preservedNow = checkAuth() ? getPreservedItems(OJS_ROOT) : [];
// Scan diff hanya jika ZIP sudah diupload
$scanDiff     = ($newOjsDir && is_dir($newOjsDir) && checkAuth())
                ? scanRootDiff(OJS_ROOT, $newOjsDir) : null;

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OJS Core Updater</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Outfit:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #0b0a08;
  --surface:   #141210;
  --surface2:  #1b1815;
  --surface3:  #221f1b;
  --border:    rgba(255,235,180,.07);
  --border-md: rgba(255,235,180,.13);
  --border-hi: rgba(212,148,58,.35);

  --gold:      #d4943a;
  --gold-hi:   #e8a84a;
  --gold-dim:  rgba(212,148,58,.12);
  --gold-glow: 0 0 0 3px rgba(212,148,58,.15);

  --mint:      #4ade80;
  --mint-dim:  rgba(74,222,128,.1);
  --amber:     #fbbf24;
  --amber-dim: rgba(251,191,36,.1);
  --coral:     #f87171;
  --coral-dim: rgba(248,113,113,.1);
  --sky:       #7dd3fc;
  --sky-dim:   rgba(125,211,252,.1);

  --text:      #f0ead8;
  --text-dim:  #9e9a8e;
  --text-faint:#5a5650;
  --code-bg:   #0f0d0b;

  --r:    6px;
  --r-lg: 10px;
}

body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Outfit', sans-serif;
  font-size: 14px;
  font-weight: 400;
  line-height: 1.65;
  min-height: 100vh;
  /* warm grain overlay */
  background-image:
    url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");
}

/* ─── SCROLLBAR ─── */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--surface3); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: var(--text-faint); }

/* ─── HEADER ─── */
.header {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 0 28px;
  height: 58px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
  backdrop-filter: blur(12px);
}
.header::after {
  content: '';
  position: absolute;
  bottom: 0; left: 0; right: 0;
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
  opacity: .35;
}
.header-brand { display: flex; align-items: center; gap: 12px; }
.header-icon {
  width: 32px; height: 32px;
  background: var(--gold-dim);
  border: 1px solid var(--border-hi);
  border-radius: var(--r);
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
  flex-shrink: 0;
}
.header-title {
  font-family: 'Cormorant Garamond', serif;
  font-size: 20px;
  font-weight: 600;
  color: var(--text);
  letter-spacing: .01em;
  line-height: 1;
}
.header-title span { color: var(--gold); }
.header-sub {
  font-size: 11px;
  color: var(--text-faint);
  font-family: 'JetBrains Mono', monospace;
  letter-spacing: .04em;
  margin-top: 2px;
}
.logout-btn {
  display: flex; align-items: center; gap: 6px;
  background: none; border: 1px solid var(--border-md);
  color: var(--text-dim); font-size: 12px; font-family: 'Outfit', sans-serif;
  padding: 6px 12px; border-radius: var(--r); cursor: pointer;
  transition: all .18s;
  text-decoration: none;
}
.logout-btn:hover { border-color: var(--coral); color: var(--coral); background: var(--coral-dim); }

/* ─── LAYOUT ─── */
.container { max-width: 860px; margin: 0 auto; padding: 32px 20px 60px; }

/* ─── FLASH MESSAGES ─── */
.flash { display: flex; align-items: flex-start; gap: 10px; padding: 12px 16px; border-radius: var(--r);
         margin-bottom: 10px; font-size: 13px; border: 1px solid; animation: fadeIn .25s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
.flash-icon { flex-shrink: 0; font-size: 14px; margin-top: 1px; }
.flash-err  { background: var(--coral-dim); border-color: rgba(248,113,113,.25); color: #fca5a5; }
.flash-ok   { background: var(--mint-dim);  border-color: rgba(74,222,128,.2);   color: #86efac; }
.flash-info { background: var(--sky-dim);   border-color: rgba(125,211,252,.2);  color: #bae6fd; }

/* ─── STEP TIMELINE ─── */
.steps-nav {
  display: flex;
  align-items: center;
  margin-bottom: 32px;
  padding: 20px 24px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  position: relative;
  overflow: hidden;
}
.steps-nav::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse at 50% 100%, rgba(212,148,58,.04) 0%, transparent 70%);
  pointer-events: none;
}
.step-node {
  display: flex; flex-direction: column; align-items: center; gap: 6px;
  flex: 1; position: relative; cursor: default;
  text-decoration: none;
}
.step-node:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 16px;
  left: calc(50% + 18px);
  right: calc(-50% + 18px);
  height: 1px;
  background: var(--border-md);
}
.step-node.done:not(:last-child)::after { background: var(--gold); opacity: .45; }
.step-circle {
  width: 34px; height: 34px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 600;
  border: 1.5px solid var(--border-md);
  background: var(--surface2);
  color: var(--text-faint);
  transition: all .2s;
  position: relative; z-index: 1;
  font-family: 'Outfit', sans-serif;
}
.step-label {
  font-size: 11px; color: var(--text-faint);
  text-align: center; line-height: 1.2;
  white-space: nowrap;
}
.step-node.active .step-circle {
  background: var(--gold);
  border-color: var(--gold);
  color: #1a0e00;
  box-shadow: 0 0 0 4px rgba(212,148,58,.18), 0 2px 10px rgba(212,148,58,.3);
}
.step-node.active .step-label { color: var(--gold); font-weight: 500; }
.step-node.done .step-circle {
  background: rgba(74,222,128,.12);
  border-color: rgba(74,222,128,.4);
  color: var(--mint);
}
.step-node.done .step-label { color: var(--mint); }

/* ─── CARD ─── */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  margin-bottom: 16px;
  overflow: hidden;
  transition: border-color .2s;
}
.card:hover { border-color: var(--border-md); }
.card-head {
  padding: 18px 24px 16px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 12px;
  background: linear-gradient(to bottom, var(--surface2), var(--surface));
}
.card-head-icon {
  width: 30px; height: 30px;
  background: var(--gold-dim);
  border: 1px solid rgba(212,148,58,.2);
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; flex-shrink: 0;
}
.card-head h2 {
  font-family: 'Cormorant Garamond', serif;
  font-size: 18px; font-weight: 600; color: var(--text);
  letter-spacing: .01em; line-height: 1;
}
.card-head small {
  font-size: 11px; color: var(--text-faint);
  font-family: 'JetBrains Mono', monospace;
  letter-spacing: .03em; margin-top: 2px; display: block;
}
.card-body { padding: 22px 24px; }
.card-footer {
  padding: 14px 24px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px;
  background: linear-gradient(to top, var(--surface2), var(--surface));
}

/* ─── INFO GRID ─── */
.info-table { width: 100%; border-collapse: collapse; }
.info-table tr { border-bottom: 1px solid var(--border); }
.info-table tr:last-child { border-bottom: none; }
.info-table td { padding: 10px 0; vertical-align: top; }
.info-table td.lbl {
  color: var(--text-faint); font-size: 11px;
  text-transform: uppercase; letter-spacing: .06em;
  font-family: 'JetBrains Mono', monospace;
  width: 38%; padding-right: 16px; padding-top: 12px;
}
.info-table td.val {
  font-weight: 500; color: var(--text);
  font-size: 13.5px; word-break: break-all;
}
.info-table td.val code {
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px; color: var(--gold);
  background: var(--gold-dim);
  padding: 1px 6px; border-radius: 4px;
}

/* ─── CHIP / TAG ─── */
.chip-group { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.chip {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 10px 3px 8px;
  border-radius: 100px;
  font-size: 11px; font-weight: 500;
  border: 1px solid;
  letter-spacing: .02em;
  font-family: 'JetBrains Mono', monospace;
  transition: all .15s;
  white-space: nowrap;
}
.chip-dot { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
.chip-green  { border-color: rgba(74,222,128,.3);  color: #86efac; background: rgba(74,222,128,.06); }
.chip-green .chip-dot  { background: var(--mint); }
.chip-blue   { border-color: rgba(125,211,252,.3); color: #bae6fd; background: rgba(125,211,252,.06); }
.chip-blue .chip-dot   { background: var(--sky); }
.chip-amber  { border-color: rgba(251,191,36,.3);  color: #fde68a; background: rgba(251,191,36,.06); }
.chip-amber .chip-dot  { background: var(--amber); }
.chip-muted  { border-color: var(--border-md);     color: var(--text-dim); background: var(--surface2); }
.chip-muted .chip-dot  { background: var(--text-faint); }
.chip-coral  { border-color: rgba(248,113,113,.3); color: #fca5a5; background: rgba(248,113,113,.06); }

/* ─── CALLOUT / ALERT ─── */
.callout {
  display: flex; gap: 12px; align-items: flex-start;
  padding: 14px 16px;
  border-radius: var(--r);
  border: 1px solid;
  font-size: 13px; line-height: 1.55;
  margin-bottom: 14px;
}
.callout-ico { flex-shrink: 0; font-size: 15px; }
.callout-warn  { background: var(--amber-dim); border-color: rgba(251,191,36,.2);  color: #fde68a; }
.callout-ok    { background: var(--mint-dim);  border-color: rgba(74,222,128,.2);  color: #a7f3d0; }
.callout-err   { background: var(--coral-dim); border-color: rgba(248,113,113,.2); color: #fca5a5; }
.callout-info  { background: var(--sky-dim);   border-color: rgba(125,211,252,.2); color: #bae6fd; }
.callout-gold  { background: var(--gold-dim);  border-color: rgba(212,148,58,.25); color: #fde68a; }

/* ─── NOTICE BANNER ─── */
.notice {
  border-left: 3px solid;
  padding: 12px 16px;
  border-radius: 0 var(--r) var(--r) 0;
  font-size: 13px; line-height: 1.6;
  margin-bottom: 14px;
}
.notice strong { display: block; margin-bottom: 4px; font-size: 13.5px; }
.notice-warn  { background: rgba(251,191,36,.07); border-color: var(--amber); color: var(--text-dim); }
.notice-warn strong { color: var(--amber); }
.notice-err   { background: rgba(248,113,113,.07); border-color: var(--coral); color: var(--text-dim); }
.notice-err strong { color: var(--coral); }
.notice-ok    { background: rgba(74,222,128,.07); border-color: var(--mint); color: var(--text-dim); }
.notice-ok strong { color: var(--mint); }

/* ─── DIVIDER ─── */
.divider {
  height: 1px;
  background: var(--border);
  margin: 20px 0;
  position: relative;
}
.divider-label {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  background: var(--surface);
  padding: 0 10px;
  font-size: 10px; color: var(--text-faint);
  letter-spacing: .08em; text-transform: uppercase;
  font-family: 'JetBrains Mono', monospace;
}

/* ─── SUB-STEP BLOCK ─── */
.sub-step {
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 16px 18px;
  margin-bottom: 12px;
  background: var(--surface2);
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 12px;
  align-items: center;
}
.sub-step-info {}
.sub-step-label {
  font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
  font-family: 'JetBrains Mono', monospace; color: var(--text-faint);
  margin-bottom: 4px;
}
.sub-step-desc { font-size: 13px; color: var(--text-dim); line-height: 1.5; }
.sub-step-action { flex-shrink: 0; }

/* ─── FORM ─── */
form { display: contents; }
.form-group { margin-bottom: 16px; }
.form-group label {
  display: block;
  font-size: 11px; color: var(--text-faint);
  text-transform: uppercase; letter-spacing: .06em;
  font-family: 'JetBrains Mono', monospace;
  margin-bottom: 7px;
}
input[type="password"],
input[type="text"],
input[type="file"] {
  width: 100%;
  background: var(--surface2);
  border: 1px solid var(--border-md);
  border-radius: var(--r);
  color: var(--text);
  padding: 10px 14px;
  font-size: 13px;
  font-family: 'Outfit', sans-serif;
  outline: none;
  transition: border-color .2s, box-shadow .2s;
}
input:focus { border-color: var(--gold); box-shadow: var(--gold-glow); }
.form-hint { font-size: 11.5px; color: var(--text-faint); margin-top: 6px; line-height: 1.5; }
.form-hint code { font-family: 'JetBrains Mono', monospace; color: var(--gold); font-size: 11px; }

/* ─── BUTTONS ─── */
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 18px;
  border-radius: var(--r);
  border: 1px solid transparent;
  cursor: pointer;
  font-size: 13px; font-weight: 500;
  font-family: 'Outfit', sans-serif;
  text-decoration: none;
  white-space: nowrap;
  transition: all .16s;
  line-height: 1;
}
.btn:active { transform: scale(.97); }
.btn-primary {
  background: var(--gold); color: #1a0e00;
  border-color: var(--gold);
}
.btn-primary:hover { background: var(--gold-hi); border-color: var(--gold-hi); box-shadow: 0 2px 12px rgba(212,148,58,.3); }
.btn-success {
  background: rgba(74,222,128,.15); color: var(--mint);
  border-color: rgba(74,222,128,.3);
}
.btn-success:hover { background: rgba(74,222,128,.22); box-shadow: 0 2px 10px rgba(74,222,128,.15); }
.btn-danger {
  background: var(--coral-dim); color: var(--coral);
  border-color: rgba(248,113,113,.3);
}
.btn-danger:hover { background: rgba(248,113,113,.18); }
.btn-amber {
  background: var(--amber-dim); color: var(--amber);
  border-color: rgba(251,191,36,.3);
}
.btn-amber:hover { background: rgba(251,191,36,.18); }
.btn-ghost {
  background: transparent; color: var(--text-dim);
  border-color: var(--border-md);
}
.btn-ghost:hover { background: var(--surface2); color: var(--text); border-color: var(--border-hi); }
.btn-sm { padding: 6px 14px; font-size: 12px; }
.btn-lg { padding: 12px 24px; font-size: 14px; font-weight: 600; }

/* ─── CODE / PRE ─── */
code {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11.5px;
  background: var(--gold-dim);
  color: var(--gold);
  padding: 1px 6px;
  border-radius: 4px;
}
pre {
  background: var(--code-bg);
  border: 1px solid var(--border);
  border-radius: var(--r);
  padding: 14px 16px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 12px; color: var(--mint);
  overflow: auto;
  max-height: 220px;
  line-height: 1.65;
}

/* ─── LOGIN PAGE ─── */
.login-wrap {
  min-height: calc(100vh - 58px);
  display: flex; align-items: center; justify-content: center;
}
.login-card {
  width: 100%; max-width: 400px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  overflow: hidden;
}
.login-card-head {
  padding: 24px;
  text-align: center;
  background: linear-gradient(to bottom, var(--surface2), var(--surface));
  border-bottom: 1px solid var(--border);
}
.login-card-head .lock-icon {
  font-size: 28px; display: block; margin-bottom: 10px;
}
.login-card-head h2 {
  font-family: 'Cormorant Garamond', serif;
  font-size: 22px; font-weight: 600; color: var(--text);
}
.login-card-head p { font-size: 12px; color: var(--text-faint); margin-top: 4px; }
.login-card-body { padding: 24px; }

/* ─── SCAN DIFF TABLE ─── */
.diff-section { margin-bottom: 20px; }
.diff-section-title {
  font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
  font-family: 'JetBrains Mono', monospace;
  color: var(--text-faint);
  margin-bottom: 8px;
  display: flex; align-items: center; gap: 8px;
}
.diff-section-title::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}

/* ─── GUIDE CARD ─── */
.guide-steps { display: flex; flex-direction: column; gap: 0; }
.guide-step {
  display: flex; gap: 16px; padding: 12px 0;
  border-bottom: 1px solid var(--border);
}
.guide-step:last-child { border-bottom: none; }
.guide-step-num {
  width: 24px; height: 24px; border-radius: 50%;
  background: var(--gold-dim); border: 1px solid rgba(212,148,58,.25);
  color: var(--gold); font-size: 11px; font-weight: 600;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; margin-top: 1px;
  font-family: 'JetBrains Mono', monospace;
}
.guide-step-text { font-size: 13px; color: var(--text-dim); line-height: 1.5; }
.guide-step-text strong { color: var(--text); }

/* ─── RESPONSIVE ─── */
@media (max-width: 600px) {
  .container { padding: 20px 14px 50px; }
  .steps-nav { padding: 16px 10px; gap: 0; }
  .step-label { display: none; }
  .step-circle { width: 28px; height: 28px; font-size: 11px; }
  .sub-step { grid-template-columns: 1fr; }
  .card-body { padding: 16px; }
  .card-head { padding: 14px 16px; }
  .card-footer { padding: 12px 16px; flex-wrap: wrap; }
  .header { padding: 0 16px; }
  .header-sub { display: none; }
}
@media (max-width: 480px) {
  .step-node:not(:last-child)::after { display: none; }
}
</style>
</head>
<body>

<header class="header">
  <div class="header-brand">
    <div class="header-icon">⚙</div>
    <div>
      <div class="header-title">OJS <span>Core</span> Updater</div>
      <div class="header-sub">Easy update · reinstall · your OJS system </div>
    </div>
  </div>
  <?php if (checkAuth()): ?>
  <form method="post" style="display:contents">
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="logout-btn">↩ Keluar</button>
  </form>
  <?php endif; ?>
</header>

<div class="container">

  <?php foreach ($errors  as $e): ?>
    <div class="flash flash-err"><span class="flash-icon">✕</span><span><?= htmlspecialchars($e) ?></span></div>
  <?php endforeach; ?>
  <?php foreach ($success as $s): ?>
    <div class="flash flash-ok"><span class="flash-icon">✓</span><span><?= $s ?></span></div>
  <?php endforeach; ?>
  <?php foreach ($info    as $i): ?>
    <div class="flash flash-info"><span class="flash-icon">ℹ</span><span><?= $i ?></span></div>
  <?php endforeach; ?>

  <?php if (!checkAuth()): ?>
  <!-- ═══════════ LOGIN ═══════════ -->
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-card-head">
        <span class="lock-icon">🔐</span>
        <h2>Autentikasi Admin</h2>
        <p>Script ini hanya boleh diakses oleh administrator sistem</p>
      </div>
      <div class="login-card-body">
        <div class="notice notice-warn" style="margin-bottom:18px;">
          <strong>Peringatan Keamanan</strong>
          Ubah nilai <code>SCRIPT_PASSWORD</code> di baris konfigurasi atas sebelum digunakan.
        </div>
        <form method="post" style="display:block">
          <input type="hidden" name="action" value="login">
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" autofocus placeholder="Masukkan password...">
          </div>
          <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;margin-top:4px;">
            Masuk →
          </button>
        </form>
      </div>
    </div>
  </div>

  <?php else: ?>

  <!-- ═══════════ STEP TIMELINE ═══════════ -->
  <nav class="steps-nav">
    <?php
      $stepDefs = [1=>'Info & Cek', 2=>'Backup', 3=>'Upload ZIP', 4=>'Apply', 5=>'Selesai'];
      foreach ($stepDefs as $n => $label):
        $cls = ($step == $n) ? 'active' : ($step > $n ? 'done' : '');
    ?>
    <a href="?step=<?= $n ?>" class="step-node <?= $cls ?>">
      <div class="step-circle"><?= $cls==='done' ? '✓' : $n ?></div>
      <div class="step-label"><?= $label ?></div>
    </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($step <= 1): ?>
  <!-- ═══════════ STEP 1: INFO ═══════════ -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-icon">🖥</div>
      <div>
        <h2>Informasi Sistem & Instalasi OJS</h2>
        <small>sistem · versi · konfigurasi</small>
      </div>
    </div>
    <div class="card-body">

      <table class="info-table">
        <tr><td class="lbl">PHP Version</td><td class="val"><?= $phpVersion ?></td></tr>
        <tr><td class="lbl">OJS Root</td><td class="val"><code><?= htmlspecialchars(OJS_ROOT) ?></code></td></tr>
        <tr><td class="lbl">Versi Terinstall</td><td class="val"><?= htmlspecialchars($ojsVersion) ?></td></tr>
        <tr><td class="lbl">Ekstensi ZIP</td><td class="val"><?= $zipAvail ?></td></tr>
        <tr><td class="lbl">Max Upload</td><td class="val"><?= $maxUpload ?></td></tr>
        <tr><td class="lbl">Post Max</td><td class="val"><?= $postMax ?></td></tr>
        <?php if (!empty($ojsConfig['db_name'])): ?>
        <tr><td class="lbl">Database</td><td class="val"><code><?= htmlspecialchars($ojsConfig['db_name']) ?></code></td></tr>
        <tr><td class="lbl">Files Dir</td><td class="val"><code><?= htmlspecialchars($ojsConfig['files_dir'] ?? '-') ?></code></td></tr>
        <?php endif; ?>
      </table>

      <div class="divider" style="margin:20px 0 16px">
        <span class="divider-label">status perlindungan file</span>
      </div>

      <div class="diff-section">
        <div class="diff-section-title"><span style="color:var(--mint)">▸</span> DIPERTAHANKAN (tidak akan disentuh)</div>
        <div class="chip-group">
          <?php foreach ($preservedNow as $item): ?>
            <span class="chip chip-green"><span class="chip-dot"></span><?= htmlspecialchars($item) ?></span>
          <?php endforeach; ?>
          <?php if (!$filesDirName): ?>
            <span class="chip chip-green"><span class="chip-dot"></span>files_dir (luar root)</span>
          <?php else: ?>
            <span class="chip chip-green"><span class="chip-dot"></span><?= htmlspecialchars($filesDirName) ?>/ ✓ auto-protected</span>
          <?php endif; ?>
          <span class="chip chip-green"><span class="chip-dot"></span>Database</span>
        </div>

        <?php if ($filesDirName): ?>
        <div class="callout callout-gold" style="margin-top:12px;">
          <span class="callout-ico">📁</span>
          <div><strong>files_dir di dalam root OJS —</strong>
          folder <code><?= htmlspecialchars($filesDirName) ?>/</code>
          (config: <code><?= htmlspecialchars($ojsConfig['files_dir'] ?? '-') ?></code>)
          otomatis dilindungi dan <strong>tidak akan disentuh</strong>.</div>
        </div>
        <?php else: ?>
        <div class="callout callout-ok" style="margin-top:12px;">
          <span class="callout-ico">✓</span>
          <div><strong>files_dir di luar root OJS</strong>
          <?= !empty($ojsConfig['files_dir']) ? '— <code>' . htmlspecialchars($ojsConfig['files_dir']) . '</code>' : '' ?>
          sudah aman secara otomatis.</div>
        </div>
        <?php endif; ?>
      </div>

      <div class="diff-section">
        <div class="diff-section-title"><span style="color:var(--sky)">▸</span> AKAN DIGANTI (core OJS)</div>
        <div class="chip-group">
          <?php foreach (['lib/','classes/','pages/','templates/','controllers/','plugins/ (merge)','index.php','dll.'] as $c): ?>
            <span class="chip chip-blue"><span class="chip-dot"></span><?= $c ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="callout callout-info">
        <span class="callout-ico">ℹ</span>
        <div>Folder/file custom di root yang <strong>tidak ada</strong> di ZIP OJS baru tidak akan tersentuh.
        Upload ZIP di Step 3 untuk melihat analisis lengkap per-item.</div>
      </div>

      <?php if (!extension_loaded('zip')): ?>
      <div class="callout callout-err">
        <span class="callout-ico">✕</span>
        <div>Ekstensi PHP <code>zip</code> tidak tersedia.
        Install dengan: <code>sudo apt install php-zip &amp;&amp; service apache2 restart</code></div>
      </div>
      <?php endif; ?>

    </div>
    <div class="card-footer">
      <a href="?step=2" class="btn btn-primary">Lanjut ke Backup →</a>
    </div>
  </div>

  <?php elseif ($step == 2): ?>
  <!-- ═══════════ STEP 2: BACKUP ═══════════ -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-icon">📦</div>
      <div>
        <h2>Backup File Penting</h2>
        <small>config · public/ · .htaccess · files_dir</small>
      </div>
    </div>
    <div class="card-body">

      <div class="notice notice-warn">
        <strong>Sangat Direkomendasikan</strong>
        Backup otomatis akan menyimpan <code>config.inc.php</code>, folder <code>public/</code>,
        <code>.htaccess</code>, dan <code>files_dir</code> (jika berada di dalam root OJS).
        Backup database secara <strong>terpisah</strong> sebelum melanjutkan.
      </div>

      <?php if ($backupDir): ?>
      <div class="callout callout-ok" style="margin-bottom:14px;">
        <span class="callout-ico">✓</span>
        <div>Backup tersimpan di: <code><?= htmlspecialchars($backupDir) ?></code></div>
      </div>
      <?php endif; ?>

      <form method="post" style="display:block">
        <input type="hidden" name="action" value="backup">
        <button type="submit" class="btn btn-amber">📦 Buat Backup Sekarang</button>
      </form>

    </div>
    <div class="card-footer">
      <a href="?step=1" class="btn btn-ghost btn-sm">← Kembali</a>
      <a href="?step=3" class="btn btn-primary" style="margin-left:auto;">
        <?= $backupDir ? 'Lanjut →' : 'Lewati (tidak direkomendasikan) →' ?>
      </a>
    </div>
  </div>

  <?php elseif ($step == 3): ?>
  <!-- ═══════════ STEP 3: UPLOAD ZIP ═══════════ -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-icon">⬆</div>
      <div>
        <h2>Upload File OJS Baru</h2>
        <small>zip package · pkp.sfu.ca</small>
      </div>
    </div>
    <div class="card-body">

      <div class="callout callout-info" style="margin-bottom:18px;">
        <span class="callout-ico">ℹ</span>
        <div>Download ZIP dari
          <a href="https://pkp.sfu.ca/software/ojs/download/" target="_blank"
             style="color:var(--sky);">pkp.sfu.ca/software/ojs/download/</a> —
          pastikan versi sesuai dengan yang ingin diinstall/update.</div>
      </div>

      <?php if ($newOjsDir): ?>
      <div class="callout callout-ok" style="margin-bottom:16px;">
        <span class="callout-ico">✓</span>
        <div>ZIP diekstrak ke: <code><?= htmlspecialchars($newOjsDir) ?></code></div>
      </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" style="display:block">
        <input type="hidden" name="action" value="upload_zip">
        <div class="form-group">
          <label>Upload File ZIP OJS</label>
          <input type="file" name="ojs_zip" accept=".zip">
          <p class="form-hint">Upload limit: <code><?= $maxUpload ?></code>.
            Jika terlalu besar, naikkan <code>upload_max_filesize</code> di php.ini.</p>
        </div>
        <button type="submit" class="btn btn-primary">⬆ Upload & Ekstrak</button>
      </form>

      <div class="divider" style="margin:22px 0 18px">
        <span class="divider-label">atau ekstrak manual</span>
      </div>

      <div class="callout callout-info" style="margin-bottom:14px;">
        <span class="callout-ico">💡</span>
        <div>Untuk file besar, ekstrak ZIP via SSH ke <code>/tmp/ojs_new/</code>
          lalu masukkan path direktori di bawah.</div>
      </div>

      <form method="post" style="display:block">
        <input type="hidden" name="action" value="set_manual_dir">
        <div class="form-group">
          <label>Path Direktori OJS (hasil ekstrak)</label>
          <input type="text" name="manual_dir"
                 placeholder="/tmp/ojs_new/ojs-3.4.0-7"
                 value="<?= htmlspecialchars($_SESSION['ojs_new_dir'] ?? '') ?>">
          <p class="form-hint">Folder ini harus berisi <code>index.php</code> OJS langsung di dalamnya.</p>
        </div>
        <button type="submit" class="btn btn-ghost btn-sm">📂 Gunakan Path Ini</button>
      </form>

      <?php if ($scanDiff): ?>
      <!-- ─── SCAN DIFF ─── -->
      <div class="divider" style="margin:24px 0 20px">
        <span class="divider-label">analisis dampak update</span>
      </div>

      <?php if ($scanDiff['files_dir_loc'] === 'inside'): ?>
      <div class="callout callout-gold" style="margin-bottom:16px;">
        <span class="callout-ico">📁</span>
        <div><strong>files_dir di dalam root OJS!</strong>
          Folder <code><?= htmlspecialchars($scanDiff['files_dir_name']) ?>/</code>
          (config: <code><?= htmlspecialchars($scanDiff['files_dir_raw']) ?></code>)
          — sudah otomatis dilindungi, <strong>tidak akan dihapus</strong>.</div>
      </div>
      <?php elseif ($scanDiff['files_dir_loc'] === 'outside'): ?>
      <div class="callout callout-ok" style="margin-bottom:16px;">
        <span class="callout-ico">✓</span>
        <div><strong>files_dir di luar root</strong>
          (<code><?= htmlspecialchars($scanDiff['files_dir_raw']) ?></code>) — aman otomatis.</div>
      </div>
      <?php endif; ?>

      <?php if (!empty($scanDiff['custom_only'])): ?>
      <div class="diff-section">
        <div class="diff-section-title"><span style="color:var(--mint)">●</span> TIDAK TERSENTUH — hanya ada di instalasi Anda</div>
        <div class="chip-group">
          <?php foreach ($scanDiff['custom_only'] as $item): ?>
            <?php $icon = $item['type']==='dir' ? '📁' : '📄'; $cls = 'chip-green';
              if ($item['reason']==='script_ini') $cls='chip-coral';
              if ($item['reason']==='files_dir')  $cls='chip-amber'; ?>
            <span class="chip <?= $cls ?>">
              <span class="chip-dot"></span><?= $icon ?> <?= htmlspecialchars($item['name']) ?>
              <?php if ($item['reason']==='files_dir'): ?><em>(files_dir)</em><?php endif; ?>
              <?php if ($item['reason']==='script_ini'): ?><em>(script ini)</em><?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
        <p style="font-size:11.5px;color:var(--text-faint);margin-top:8px;">
          Item-item ini tidak ada di ZIP baru → tidak diproses sama sekali.
        </p>
      </div>
      <?php endif; ?>

      <?php if (!empty($scanDiff['preserved'])): ?>
      <div class="diff-section">
        <div class="diff-section-title"><span style="color:var(--sky)">●</span> DILINDUNGI — ada di ZIP tapi dilewati</div>
        <div class="chip-group">
          <?php foreach ($scanDiff['preserved'] as $item): ?>
            <span class="chip chip-blue">
              <span class="chip-dot"></span>
              <?= $item['type']==='dir'?'📁':'📄' ?> <?= htmlspecialchars($item['name']) ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="diff-section">
        <div class="diff-section-title">
          <span style="color:var(--amber)">●</span>
          AKAN DIGANTI/DITAMBAH — <?= count($scanDiff['will_replace']) + count($scanDiff['new_only']) ?> item
        </div>
        <div class="chip-group">
          <?php foreach ($scanDiff['will_replace'] as $item): ?>
            <span class="chip chip-amber"
                  title="<?= $item['action']==='merge'?'Di-merge':'Diganti' ?>">
              <span class="chip-dot"></span>
              <?= $item['type']==='dir'?'📁':'📄' ?>
              <?= htmlspecialchars($item['name']) ?>
              <?= $item['action']==='merge' ? ' ⚡' : '' ?>
            </span>
          <?php endforeach; ?>
          <?php foreach ($scanDiff['new_only'] as $item): ?>
            <span class="chip chip-muted" title="Item baru">
              <span class="chip-dot"></span>
              <?= $item['type']==='dir'?'📁':'📄' ?>
              <?= htmlspecialchars($item['name']) ?> <em style="font-size:10px">+baru</em>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <div class="card-footer">
      <a href="?step=2" class="btn btn-ghost btn-sm">← Kembali</a>
      <?php if ($newOjsDir): ?>
      <a href="?step=4" class="btn btn-primary" style="margin-left:auto;">Lanjut ke Apply Update →</a>
      <?php endif; ?>
    </div>
  </div>

  <?php elseif ($step == 4): ?>
  <!-- ═══════════ STEP 4: APPLY ═══════════ -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-icon">🔄</div>
      <div>
        <h2>Terapkan Update Core OJS</h2>
        <small>replace · merge · upgrade db · clear cache</small>
      </div>
    </div>
    <div class="card-body">

      <?php if (empty($newOjsDir) || !is_dir($newOjsDir)): ?>
      <div class="callout callout-err">
        <span class="callout-ico">✕</span>
        <div>Direktori OJS baru tidak ditemukan. Kembali ke Step 3 dan upload ZIP terlebih dahulu.</div>
      </div>
      <?php else: ?>

      <div class="notice notice-warn" style="margin-bottom:20px;">
        <strong>Proses tidak dapat dibatalkan</strong>
        File core OJS di <code><?= htmlspecialchars(OJS_ROOT) ?></code> akan diganti.
        Pastikan backup sudah dibuat.<br>
        <span style="font-size:11.5px;margin-top:6px;display:block;">
          Sumber: <code><?= htmlspecialchars($newOjsDir) ?></code>
        </span>
      </div>

      <!-- 4a -->
      <div class="sub-step">
        <div class="sub-step-info">
          <div class="sub-step-label">Langkah 4a</div>
          <div style="font-size:14px;font-weight:500;color:var(--text);margin-bottom:4px;">Ganti Core Files</div>
          <div class="sub-step-desc">
            Mengganti <code>lib/</code>, <code>classes/</code>, <code>templates/</code>, dll.
            Config, public/, dan files_dir tetap aman.
          </div>
        </div>
        <div class="sub-step-action">
          <form method="post" style="display:block">
            <input type="hidden" name="action" value="apply_update">
            <button type="submit" class="btn btn-amber"
                    onclick="return confirm('Yakin mengganti core OJS?\nPastikan backup sudah ada!')">
              🔄 Apply Update
            </button>
          </form>
        </div>
      </div>

      <!-- 4b -->
      <div class="sub-step">
        <div class="sub-step-info">
          <div class="sub-step-label">Langkah 4b</div>
          <div style="font-size:14px;font-weight:500;color:var(--text);margin-bottom:4px;">Bersihkan Cache</div>
          <div class="sub-step-desc">
            Hapus cache Smarty dan cache OJS agar file baru aktif.
            Aman dijalankan kapan saja.
          </div>
        </div>
        <div class="sub-step-action">
          <form method="post" style="display:block">
            <input type="hidden" name="action" value="clear_cache">
            <button type="submit" class="btn btn-ghost btn-sm">🗑 Clear Cache</button>
          </form>
        </div>
      </div>

      <!-- 4c -->
      <div class="sub-step">
        <div class="sub-step-info">
          <div class="sub-step-label">Langkah 4c — hanya untuk upgrade versi baru</div>
          <div style="font-size:14px;font-weight:500;color:var(--text);margin-bottom:4px;">Upgrade Database</div>
          <div class="sub-step-desc">
            Jalankan hanya jika naik ke versi baru (bukan reinstall).
            Menjalankan <code>php tools/upgrade.php upgrade</code>.
          </div>
        </div>
        <div class="sub-step-action">
          <form method="post" style="display:block">
            <input type="hidden" name="action" value="run_upgrade">
            <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Jalankan DB upgrade?\nPastikan backup database sudah ada!')">
              ⚡ DB Upgrade
            </button>
          </form>
        </div>
      </div>

      <?php endif; ?>
    </div>
    <div class="card-footer">
      <a href="?step=3" class="btn btn-ghost btn-sm">← Kembali</a>
      <a href="?step=5" class="btn btn-success" style="margin-left:auto;">Tandai Selesai →</a>
    </div>
  </div>

  <?php elseif ($step == 5): ?>
  <!-- ═══════════ STEP 5: SELESAI ═══════════ -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-icon" style="background:var(--mint-dim);border-color:rgba(74,222,128,.25);">✓</div>
      <div>
        <h2>Proses Selesai</h2>
        <small>cleanup · verifikasi · keamanan</small>
      </div>
    </div>
    <div class="card-body">

      <div class="callout callout-ok" style="margin-bottom:18px;">
        <span class="callout-ico">✓</span>
        <div>Update/Reinstall OJS core selesai. Silakan verifikasi instalasi OJS Anda.</div>
      </div>

      <div class="notice notice-err">
        <strong>⚠ Langkah Keamanan Wajib</strong>
        <ol style="margin:8px 0 0 18px;line-height:2;font-size:13px;">
          <li>Hapus file <code><?= htmlspecialchars(basename(__FILE__)) ?></code> dari server <strong>segera</strong></li>
          <li>Bersihkan direktori backup sementara yang tidak dibutuhkan</li>
          <li>Hapus file ekstraksi ZIP di <code>/tmp/</code></li>
          <li>Login ke OJS admin dashboard dan cek fungsionalitas</li>
          <li>Periksa jurnal dan submission untuk memastikan data intact</li>
        </ol>
      </div>

      <div style="margin-top:16px;">
        <table class="info-table" style="max-width:500px;">
          <tr>
            <td class="lbl">Script ini</td>
            <td class="val" style="color:var(--coral);"><code><?= htmlspecialchars(basename(__FILE__)) ?></code> — hapus segera</td>
          </tr>
          <?php if ($backupDir): ?>
          <tr>
            <td class="lbl">Lokasi backup</td>
            <td class="val"><code><?= htmlspecialchars($backupDir) ?></code></td>
          </tr>
          <?php endif; ?>
        </table>
      </div>

    </div>
    <div class="card-footer">
      <a href="?step=1" class="btn btn-ghost btn-sm">← Mulai Ulang</a>
      <a href="<?= htmlspecialchars($ojsConfig['base_url'] ?? '/') ?>"
         class="btn btn-success" target="_blank" style="margin-left:auto;">
        🌐 Buka OJS
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══════════ PANDUAN ═══════════ -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-icon" style="background:var(--sky-dim);border-color:rgba(125,211,252,.2);">📖</div>
      <div>
        <h2>Panduan Penggunaan</h2>
        <small>alur · tips · catatan penting</small>
      </div>
    </div>
    <div class="card-body">
      <div class="guide-steps">
        <div class="guide-step">
          <div class="guide-step-num">1</div>
          <div class="guide-step-text">
            <strong>Info & Cek</strong> — Verifikasi versi PHP, ekstensi ZIP, dan deteksi otomatis
            lokasi <code>files_dir</code> dari <code>config.inc.php</code>.
          </div>
        </div>
        <div class="guide-step">
          <div class="guide-step-num">2</div>
          <div class="guide-step-text">
            <strong>Backup</strong> — Simpan <code>config.inc.php</code>, <code>public/</code>,
            <code>.htaccess</code>, dan <code>files_dir</code> (jika dalam root).
            Backup database secara terpisah via <code>mysqldump</code>.
          </div>
        </div>
        <div class="guide-step">
          <div class="guide-step-num">3</div>
          <div class="guide-step-text">
            <strong>Upload ZIP</strong> — Upload ZIP OJS dari pkp.sfu.ca atau ekstrak manual via SSH.
            Panel analisis otomatis menampilkan apa yang aman dan apa yang akan diganti.
          </div>
        </div>
        <div class="guide-step">
          <div class="guide-step-num">4</div>
          <div class="guide-step-text">
            <strong>Apply Update</strong> — Jalankan 4a (core), 4b (cache), dan 4c hanya jika
            naik ke versi baru. Untuk <em>reinstall</em> versi sama, lewati 4c.
          </div>
        </div>
        <div class="guide-step">
          <div class="guide-step-num">5</div>
          <div class="guide-step-text">
            <strong>Selesai</strong> — Hapus script ini dari server.
            Data yang tidak tersentuh: <strong>Database</strong>, <code>files_dir</code>,
            <code>config.inc.php</code>, <code>public/</code>, <code>.htaccess</code>,
            dan semua folder/file custom yang tidak ada di ZIP baru.
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php endif; // checkAuth ?>
</div>
</body>
</html>
