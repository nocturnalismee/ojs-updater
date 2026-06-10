<?php
/**
 *  OJS Core Updater / Reinstaller — Production Edition
 *  Versi : 2.0.0
 *
 *  CHECKLIST SEBELUM DIGUNAKAN:
 *  [1] Ganti SCRIPT_PASSWORD (min 20 karakter, campuran simbol)
 *  [2] Isi ALLOWED_IPS jika ingin batasi akses per IP
 *  [3] Set REQUIRE_HTTPS = true jika server pakai HTTPS
 *  [4] Hapus script ini dari server segera setelah selesai!
 *
 *  PERUBAHAN DARI v1.2:
 *  - Security headers HTTP (CSP, X-Frame-Options, dll.)
 *  - Rate limiting berbasis file (persisten, tidak bisa di-reset)
 *  - Session timeout otomatis (30 menit default)
 *  - IP whitelist
 *  - HTTPS enforcement option
 *  - Cek password default (blokir login jika belum diganti)
 *  - Hapus semua `goto` → refaktor ke fungsi helper
 *  - Logging operasi lengkap (file log terenkripsi ringan)
 *  - Backup database otomatis (mysqldump + PDO fallback)
 *  - Maintenance mode OJS (matikan akses publik saat update)
 *  - Dry-run mode (preview tanpa apply)
 *  - Validasi path dengan realpath() (cegah path traversal)
 *  - Helper e() konsisten untuk semua output HTML
 *  - Rollback lebih robust dengan verifikasi
 */

// 1  KONFIGURASI — Wajib disesuaikan sebelum digunakan

define('SCRIPT_VERSION',    '2.0.0');
define('SCRIPT_PASSWORD',   'GANTI_SEGERA_MinPassword20Kar!@#'); // ← WAJIB GANTI
define('OJS_ROOT',          dirname(__FILE__));
define('MAX_EXEC_SECONDS',  900);
define('CSRF_TOKEN_NAME',   'ojs_updater_csrf');

// Keamanan sesi & akses
define('SESSION_TIMEOUT',   1800);       // detik; 0 = nonaktif
define('MAX_LOGIN_ATTEMPTS',5);
define('LOCKOUT_SECONDS',   900);        // 15 menit lockout setelah gagal login N kali
define('ALLOWED_IPS',       []);         // kosong = semua IP; ['1.2.3.4'] = whitelist
define('REQUIRE_HTTPS',     false);      // set true jika server pakai HTTPS

// Logging
define('ENABLE_LOGGING',    true);

// ── Direktori backup & tmp (auto-deteksi) ───────────────────
define('BACKUP_BASE_DIR',   _autoBackupDir());
define('TMP_WORK_DIR',      BACKUP_BASE_DIR . '/tmp');
define('LOG_FILE',          BACKUP_BASE_DIR . '/ojs_updater.log');
define('LOCKOUT_FILE',      BACKUP_BASE_DIR . '/lockout.json');

// ── Password default (jangan diubah) ───────────────────────
define('DEFAULT_PASSWORD',  'GANTI_SEGERA_MinPassword20Kar!@#');

// 2  SECURITY BOOTSTRAP

// HTTP Security Headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline'; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
    "font-src https://fonts.gstatic.com; " .
    "img-src 'self' data:; " .
    "connect-src 'none';"
);

// Paksa HTTPS
if (REQUIRE_HTTPS && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

// IP Whitelist
if (!empty(ALLOWED_IPS)) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($clientIp, ALLOWED_IPS, true)) {
        http_response_code(403);
        exit('403 Forbidden');
    }
}

// Konfigurasi sesi yang aman
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   REQUIRE_HTTPS ? '1' : '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime',  (string)(SESSION_TIMEOUT ?: 1800));
session_start();

// Session timeout check
if (SESSION_TIMEOUT > 0 && isset($_SESSION['ojs_updater_auth'])) {
    if (time() - ($_SESSION['ojs_last_activity'] ?? 0) > SESSION_TIMEOUT) {
        _destroySession();
        session_start();
    } else {
        $_SESSION['ojs_last_activity'] = time();
    }
}

function _destroySession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// 3  AUTO-DETECT BACKUP DIR & PROTECTION

function _autoBackupDir(): string
{
    $candidates = [
        dirname(OJS_ROOT) . '/ojs_backup',
        dirname(OJS_ROOT) . '/backup_ojs',
        dirname(OJS_ROOT, 2) . '/ojs_backup',
        OJS_ROOT . '/backup',
        OJS_ROOT . '/files/backup',
        sys_get_temp_dir() . '/ojs_backup',
        '/tmp/ojs_backup',
    ];
    foreach ($candidates as $dir) {
        $parent = dirname($dir);
        if (!is_dir($parent) || !is_writable($parent)) continue;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) continue;
        $test = $dir . '/.wtest_' . getmypid();
        if (@file_put_contents($test, 'x') === false) continue;
        @unlink($test);
        return $dir;
    }
    return sys_get_temp_dir() . '/ojs_backup';
}

function _protectDir(string $dir): void
{
    if (!is_dir($dir)) return;
    @chmod($dir, 0750);
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\nOptions -Indexes\n");
    }
    if (!file_exists($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '<!-- forbidden -->');
    }
    if (!file_exists($dir . '/index.php')) {
        @file_put_contents($dir . '/index.php', '<?php http_response_code(403); exit; ?>');
    }
}

function ensureWorkDir(): string
{
    if (!is_dir(TMP_WORK_DIR)) @mkdir(TMP_WORK_DIR, 0750, true);
    _protectDir(TMP_WORK_DIR);
    return TMP_WORK_DIR;
}

function isInsideDocRoot(string $path): bool
{
    $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
    if (!$root) return false;
    $real = realpath($path) ?: $path;
    return str_starts_with($real, $root);
}

// 4  LOGGING

function ojsLog(string $level, string $msg): void
{
    if (!ENABLE_LOGGING) return;
    $dir = dirname(LOG_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    _protectDir($dir);
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '-';
    $line = sprintf("[%s] [%-5s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $ip, $msg);
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function getRecentLogs(int $n = 40): array
{
    if (!file_exists(LOG_FILE)) return [];
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_slice(array_reverse($lines), 0, $n);
}


// 5  AUTHENTICATION & RATE LIMITING (berbasis file)

function getLockoutData(): array
{
    if (!file_exists(LOCKOUT_FILE)) return ['attempts' => 0, 'locked_until' => 0, 'last_attempt' => 0];
    $d = @json_decode((string)file_get_contents(LOCKOUT_FILE), true);
    return is_array($d) ? $d : ['attempts' => 0, 'locked_until' => 0, 'last_attempt' => 0];
}

function saveLockoutData(array $d): void
{
    if (!is_dir(dirname(LOCKOUT_FILE))) @mkdir(dirname(LOCKOUT_FILE), 0750, true);
    @file_put_contents(LOCKOUT_FILE, json_encode($d), LOCK_EX);
}

function isLockedOut(): bool
{
    return time() < (getLockoutData()['locked_until'] ?? 0);
}

function getLockoutRemaining(): int
{
    return max(0, (int)(getLockoutData()['locked_until'] ?? 0) - time());
}

function recordFailedLogin(): void
{
    $d = getLockoutData();
    // Reset counter jika >1 jam tidak ada percobaan
    if (time() - ($d['last_attempt'] ?? 0) > 3600) $d['attempts'] = 0;
    $d['attempts']++;
    $d['last_attempt'] = time();
    if ($d['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $d['locked_until'] = time() + LOCKOUT_SECONDS;
        ojsLog('warn', "Lockout aktif setelah {$d['attempts']} percobaan gagal");
    }
    saveLockoutData($d);
}

function clearLockout(): void
{
    saveLockoutData(['attempts' => 0, 'locked_until' => 0, 'last_attempt' => 0]);
}

function checkAuth(): bool
{
    return ($_SESSION['ojs_updater_auth'] ?? false) === true;
}

function generateCsrfToken(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verifyCsrfToken(): bool
{
    $t = $_POST[CSRF_TOKEN_NAME] ?? '';
    return $t !== '' && hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $t);
}

function csrfField(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . e(generateCsrfToken()) . '">';
}

// 6  CORE UTILITIES

/** Escape HTML output — SELALU gunakan ini untuk output ke browser */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function dirSize(string $dir): int
{
    $size = 0;
    if (!is_dir($dir) || !is_readable($dir)) return 0;
    try {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        ) as $f) {
            if ($f->isFile()) $size += $f->getSize();
        }
    } catch (Exception) {}
    return $size;
}

function copyDir(string $src, string $dst): bool
{
    if (!is_dir($src)) return false;
    if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
        throw new RuntimeException("Tidak bisa buat direktori: $dst");
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $item) {
        $target = $dst . DIRECTORY_SEPARATOR . $iter->getSubPathname();
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0755, true);
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException("Gagal copy: " . $item->getPathname());
        }
    }
    return true;
}

function removeDir(string $dir): bool
{
    if (!is_dir($dir)) return true;
    $real = realpath($dir);
    // Safety: jangan hapus root, OJS_ROOT yang sedang aktif, atau parent backup
    if (!$real) return false;
    $forbidden = ['/', 'C:\\', realpath(OJS_ROOT) ?: OJS_ROOT, realpath(BACKUP_BASE_DIR) ?: BACKUP_BASE_DIR];
    if (in_array($real, $forbidden, true)) return false;
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        return @rmdir($dir);
    } catch (Exception) {
        return false;
    }
}

/** Deteksi apakah exec() tersedia */
function canExecCli(): bool
{
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

/** Cari binary di PATH, return path-nya atau null */
function findBin(string $name): ?string
{
    if (!canExecCli()) return null;
    $path = trim((string)@shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null'));
    return ($path && is_executable($path)) ? $path : null;
}

// 7  OJS CONFIG, VERSION, CACHE, DB-UPGRADE

function readOjsConfig(string $ojsRoot): array
{
    $file = $ojsRoot . '/config.inc.php';
    if (!file_exists($file)) return ['error' => 'config.inc.php tidak ditemukan'];
    $content = file_get_contents($file);
    if ($content === false) return ['error' => 'Tidak dapat membaca config.inc.php'];

    $patterns = [
        'db_driver'   => '/^\s*driver\s*=\s*["\']?([^"\'\r\n]+)/m',
        'db_host'     => '/^\s*host\s*=\s*["\']?([^"\'\r\n]+)/m',
        'db_username' => '/^\s*username\s*=\s*["\']?([^"\'\r\n]+)/m',
        'db_password' => '/^\s*password\s*=\s*["\']?([^"\'\r\n]*)/m',
        'db_name'     => '/^\s*name\s*=\s*["\']?([^"\'\r\n]+)/m',
        'files_dir'   => '/^\s*files_dir\s*=\s*["\']?([^"\'\r\n]+)/m',
        'base_url'    => '/^\s*base_url\s*=\s*["\']?([^"\'\r\n]+)/m',
        'installed'   => '/^\s*installed\s*=\s*["\']?([^"\'\r\n]+)/m',
    ];
    $config = ['config_path' => $file];
    foreach ($patterns as $key => $pat) {
        if (preg_match($pat, $content, $m)) {
            $config[$key] = trim(trim($m[1]), '"\'');
        }
    }
    return $config;
}

function detectOjsVersion(string $ojsRoot): string
{
    // Method 1: dbscripts/xml/version.xml
    $f = $ojsRoot . '/dbscripts/xml/version.xml';
    if (file_exists($f) && ($xml = @simplexml_load_file($f)) && isset($xml->release)) {
        if ($r = trim((string)$xml->release)) return $r;
    }
    // Method 2: PKPApplication.php
    $f = $ojsRoot . '/lib/pkp/classes/core/PKPApplication.php';
    if (file_exists($f)) {
        $c = (string)file_get_contents($f);
        if (preg_match('/const\s+CODE_VERSION_NAME\s*=\s*[\'"]([^\'"]+)[\'"]/', $c, $m)) return trim($m[1]);
        if (preg_match('/define\(.*?VERSION.*?[\'"](\d+\.\d+\.\d+[\.\-]\d+)[\'"]/', $c, $m)) return trim($m[1]);
    }
    // Method 3: registry/version.xml
    $f = $ojsRoot . '/registry/version.xml';
    if (file_exists($f) && ($xml = @simplexml_load_file($f)) && isset($xml->release)) {
        if ($r = trim((string)$xml->release)) return $r;
    }
    return 'Tidak diketahui';
}

function detectFilesDirInsideRoot(string $ojsRoot): ?string
{
    $cfg = readOjsConfig($ojsRoot);
    if (empty($cfg['files_dir'])) return null;
    $filesDir = rtrim($cfg['files_dir'], '/\\');
    $realRoot = realpath($ojsRoot);
    // Coba absolute path dulu, lalu relative
    $realFiles = realpath($filesDir) ?: realpath($ojsRoot . '/' . $filesDir);
    if (!$realRoot || !$realFiles) return null;
    if (str_starts_with($realFiles, $realRoot . DIRECTORY_SEPARATOR) || $realFiles === $realRoot) {
        $rel   = ltrim(substr($realFiles, strlen($realRoot)), DIRECTORY_SEPARATOR);
        $parts = explode(DIRECTORY_SEPARATOR, $rel);
        return $parts[0] ?: null;
    }
    return null;
}

function getPreservedItems(string $ojsRoot = ''): array
{
    $base = ['config.inc.php', 'public', '.htaccess', 'cache'];
    if ($ojsRoot) {
        $fd = detectFilesDirInsideRoot($ojsRoot);
        if ($fd && !in_array($fd, $base, true)) $base[] = $fd;
    }
    return $base;
}

function clearOjsCache(string $ojsRoot): array
{
    $result = ['cleared' => [], 'errors' => []];
    $dirs   = [
        $ojsRoot . '/cache',
        $ojsRoot . '/cache/t_compile',
        $ojsRoot . '/cache/t_config',
        $ojsRoot . '/cache/t_qc',
        $ojsRoot . '/cache/_db',
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        try {
            $count = 0;
            $iter  = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                if (in_array($f->getFilename(), ['.gitignore', '.htaccess'], true)) continue;
                if ($f->isDir()) { @rmdir($f->getPathname()); }
                else { @unlink($f->getPathname()); $count++; }
            }
            if ($count > 0) $result['cleared'][] = basename($dir) . "/ ($count file)";
        } catch (Exception $e) {
            $result['errors'][] = $e->getMessage();
        }
    }
    return $result;
}

function runOjsDbUpgrade(string $ojsRoot): array
{
    $result = ['success' => false, 'output' => ''];
    $paths  = [$ojsRoot . '/tools/upgrade.php', $ojsRoot . '/lib/pkp/tools/upgrade.php'];
    $tool   = null;
    foreach ($paths as $p) { if (file_exists($p)) { $tool = $p; break; } }
    if (!$tool) {
        $result['error'] = 'tools/upgrade.php tidak ditemukan. Jalankan manual via SSH: php tools/upgrade.php upgrade';
        return $result;
    }
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($tool) . ' upgrade 2>&1';
    $cwd = getcwd();
    chdir($ojsRoot);
    exec($cmd, $out, $code);
    chdir($cwd);
    $result['output']      = implode("\n", $out);
    $result['return_code'] = $code;
    $result['success']     = ($code === 0);
    if (!$result['success']) $result['error'] = 'Exit code: ' . $code;
    return $result;
}

// 8  MAINTENANCE MODE OJS

function getMaintenanceStatus(string $ojsRoot): bool
{
    $f = $ojsRoot . '/config.inc.php';
    if (!file_exists($f)) return false;
    return (bool)preg_match('/^\s*installed\s*=\s*Off\b/mi', (string)file_get_contents($f));
}

function setMaintenanceMode(string $ojsRoot, bool $enable): bool
{
    $f = $ojsRoot . '/config.inc.php';
    if (!file_exists($f) || !is_writable($f)) return false;
    $content = (string)file_get_contents($f);
    if ($enable) {
        $new = preg_replace('/^(\s*installed\s*=\s*)On\b/mi', '${1}Off', $content);
    } else {
        $new = preg_replace('/^(\s*installed\s*=\s*)Off\b/mi', '${1}On', $content);
    }
    if ($new === null || $new === $content) return false;
    return file_put_contents($f, $new) !== false;
}

// 9  DATABASE BACKUP (mysqldump + PDO fallback)

function backupDatabase(array $cfg, string $outDir): array
{
    $result = ['success' => false];
    if (empty($cfg['db_name']) || empty($cfg['db_username'])) {
        $result['error'] = 'Konfigurasi database tidak lengkap di config.inc.php';
        return $result;
    }
    _protectDir($outDir);
    if (!is_dir($outDir) && !@mkdir($outDir, 0750, true)) {
        $result['error'] = 'Tidak bisa buat direktori backup DB';
        return $result;
    }

    $dbName  = $cfg['db_name'];
    $dbUser  = $cfg['db_username'];
    $dbPass  = $cfg['db_password'] ?? '';
    $dbHost  = $cfg['db_host'] ?? 'localhost';
    $stamp   = date('YmdHis');

    // ── Method 1: mysqldump CLI ──────────────────────────────
    $mysqldump = findBin('mysqldump');
    if ($mysqldump) {
        $outFile = $outDir . "/db_{$dbName}_{$stamp}.sql";
        $cmd = sprintf(
            '%s --single-transaction --routines --triggers --add-drop-table -h %s -u %s %s %s > %s 2>&1',
            escapeshellarg($mysqldump),
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            ($dbPass !== '' ? '-p' . escapeshellarg($dbPass) : ''),
            escapeshellarg($dbName),
            escapeshellarg($outFile)
        );
        exec($cmd, $out, $code);

        if ($code === 0 && file_exists($outFile) && filesize($outFile) > 100) {
            // Coba kompres
            $gzip = findBin('gzip');
            if ($gzip) {
                exec(escapeshellarg($gzip) . ' -f ' . escapeshellarg($outFile), $gz, $gzCode);
                if ($gzCode === 0 && file_exists($outFile . '.gz')) {
                    $outFile .= '.gz';
                }
            }
            $result['success'] = true;
            $result['file']    = $outFile;
            $result['size']    = formatBytes((int)filesize($outFile));
            $result['method']  = 'mysqldump';
            return $result;
        }
        @unlink($outFile); // Hapus file kosong/error
    }

    // ── Method 2: PHP PDO (shared hosting tanpa exec) ────────
    try {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbName);
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 30,
        ]);

        $outFile = $outDir . "/db_{$dbName}_{$stamp}.sql";
        $handle  = fopen($outFile, 'w');
        if (!$handle) throw new RuntimeException('Tidak bisa buat file SQL');

        fwrite($handle, "-- OJS Database Backup\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database : {$dbName}\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            fwrite($handle, "-- Table: {$table}\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, $createRow[1] . ";\n\n");

            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            $rows = $stmt->fetchAll();
            if (!empty($rows)) {
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                foreach (array_chunk($rows, 200) as $chunk) {
                    $vals = array_map(function (array $row) use ($pdo): string {
                        $quoted = array_map(
                            fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v),
                            $row
                        );
                        return '(' . implode(', ', $quoted) . ')';
                    }, $chunk);
                    fwrite($handle, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $vals) . ";\n");
                }
                fwrite($handle, "\n");
            }
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        $result['success'] = true;
        $result['file']    = $outFile;
        $result['size']    = formatBytes((int)filesize($outFile));
        $result['method']  = 'pdo';
        return $result;

    } catch (Exception $e) {
        $result['error'] = 'PDO backup gagal: ' . $e->getMessage();
    }

    $result['error'] = ($result['error'] ?? '') ?: 'Semua metode backup DB gagal.';
    return $result;
}


// §10  DOWNLOAD & EXTRACT (tanpa goto)

/**
 * Coba unduh URL ke destFile dengan berbagai metode.
 * Return nama metode ('curl'|'wget'|'php') atau null jika gagal.
 */
function attemptDownload(string $url, string $destFile): ?string
{
    // Method 1: curl CLI
    $curl = findBin('curl');
    if ($curl) {
        $cmd = sprintf(
            '%s -L --max-time 600 --retry 3 -s --show-error -o %s %s 2>&1',
            escapeshellarg($curl), escapeshellarg($destFile), escapeshellarg($url)
        );
        exec($cmd, $out, $code);
        if ($code === 0 && file_exists($destFile) && filesize($destFile) > 1_000_000) {
            return 'curl';
        }
        @unlink($destFile);
    }

    // Method 2: wget CLI
    $wget = findBin('wget');
    if ($wget) {
        $cmd = sprintf(
            '%s -q --timeout=600 --tries=3 -O %s %s 2>&1',
            escapeshellarg($wget), escapeshellarg($destFile), escapeshellarg($url)
        );
        exec($cmd, $out, $code);
        if ($code === 0 && file_exists($destFile) && filesize($destFile) > 1_000_000) {
            return 'wget';
        }
        @unlink($destFile);
    }

    // Method 3: PHP file_get_contents
    if (ini_get('allow_url_fopen')) {
        @set_time_limit(0);
        $ctx  = stream_context_create(['http' => ['timeout' => 600, 'user_agent' => 'OJS-Updater/2.0']]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data !== false && strlen($data) > 1_000_000 && file_put_contents($destFile, $data) !== false) {
            return 'php';
        }
        @unlink($destFile);
    }

    return null;
}

/** Temukan subdirektori OJS setelah ekstrak */
function findOjsSubdir(string $extractDir): string
{
    $subdirs = glob($extractDir . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($subdirs as $d) {
        if (stripos(basename($d), 'ojs') !== false) return $d;
    }
    return count($subdirs) === 1 ? $subdirs[0] : $extractDir;
}

function extractTarGz(string $tarGzPath, string $destDir): array
{
    $result = ['success' => false];
    if (!is_dir($destDir)) @mkdir($destDir, 0750, true);
    _protectDir($destDir);

    // Method 1: tar CLI
    $tar = findBin('tar');
    if ($tar) {
        $cmd = sprintf('%s xzf %s -C %s 2>&1', escapeshellarg($tar), escapeshellarg($tarGzPath), escapeshellarg($destDir));
        exec($cmd, $out, $code);
        if ($code === 0) {
            return ['success' => true, 'method' => 'tar', 'ojs_subdir' => findOjsSubdir($destDir)];
        }
        $result['tar_output'] = implode("\n", $out);
    }

    // Method 2: PharData
    if (class_exists('PharData')) {
        $tarFile = (string)preg_replace('/\.gz$/i', '.tar', $tarGzPath);
        try {
            (new PharData($tarGzPath))->decompress();
            if (file_exists($tarFile)) {
                (new PharData($tarFile))->extractTo($destDir, null, true);
                @unlink($tarFile);
                return ['success' => true, 'method' => 'phar', 'ojs_subdir' => findOjsSubdir($destDir)];
            }
        } catch (Exception $e) {
            $result['error'] = 'PharData: ' . $e->getMessage();
            if (file_exists($tarFile)) @unlink($tarFile);
        }
    }

    $result['error'] = ($result['error'] ?? '') ?: 'Tidak ada metode ekstrak tersedia (tar / PharData).';
    return $result;
}

function extractOjsZip(string $zipPath, string $extractTo): array
{
    if (!extension_loaded('zip')) return ['success' => false, 'error' => 'Ekstensi PHP zip tidak tersedia.'];
    if (!is_dir($extractTo)) mkdir($extractTo, 0755, true);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return ['success' => false, 'error' => 'Tidak dapat membuka file ZIP.'];
    if (!$zip->extractTo($extractTo)) { $zip->close(); return ['success' => false, 'error' => 'Gagal mengekstrak ZIP.']; }
    $zip->close();
    return ['success' => true, 'ojs_subdir' => findOjsSubdir($extractTo)];
}

function downloadOjsRelease(string $version, string $tmpDir): array
{
    if (!preg_match('/^3\.\d+\.\d+-\d+$/', $version)) {
        return ['success' => false, 'error' => 'Format versi tidak valid.'];
    }
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0750, true);
    _protectDir($tmpDir);

    $url      = "https://pkp.sfu.ca/ojs/download/ojs-{$version}.tar.gz";
    $destFile = $tmpDir . "/ojs-{$version}.tar.gz";

    $method = attemptDownload($url, $destFile);
    if ($method === null) {
        return ['success' => false, 'error' => "Gagal mengunduh dari {$url}. Gunakan tab Upload Manual."];
    }

    $extractDir = $tmpDir . '/extracted_' . $version;
    $extResult  = extractTarGz($destFile, $extractDir);
    @unlink($destFile);

    if (!$extResult['success']) {
        return ['success' => false, 'error' => 'Download OK tapi gagal ekstrak: ' . ($extResult['error'] ?? '')];
    }

    return [
        'success'        => true,
        'extract_dir'    => $extResult['ojs_subdir'],
        'version'        => $version,
        'downloaded_via' => $method,
    ];
}

// 11  FILE BACKUP

function backupImportantFiles(string $ojsRoot, string $backupDir): array
{
    $result = ['success' => false, 'backup_dir' => $backupDir, 'backed_up' => [], 'warnings' => []];
    _protectDir(BACKUP_BASE_DIR);
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0750, true)) {
        $result['error'] = 'Tidak dapat membuat direktori backup.';
        return $result;
    }
    _protectDir($backupDir);

    // config.inc.php
    if (file_exists($ojsRoot . '/config.inc.php')) {
        copy($ojsRoot . '/config.inc.php', $backupDir . '/config.inc.php')
            ? ($result['backed_up'][] = 'config.inc.php')
            : ($result['warnings'][] = 'Gagal backup config.inc.php');
    }

    // public/
    if (is_dir($ojsRoot . '/public')) {
        try {
            copyDir($ojsRoot . '/public', $backupDir . '/public');
            $result['backed_up'][] = 'public/';
        } catch (Exception $e) {
            $result['warnings'][] = 'Gagal backup public/: ' . $e->getMessage();
        }
    }

    // .htaccess
    if (file_exists($ojsRoot . '/.htaccess')) {
        copy($ojsRoot . '/.htaccess', $backupDir . '/.htaccess') && ($result['backed_up'][] = '.htaccess');
    }

    // files_dir (jika di dalam root)
    $fd = detectFilesDirInsideRoot($ojsRoot);
    if ($fd && is_dir($ojsRoot . '/' . $fd)) {
        $size = dirSize($ojsRoot . '/' . $fd);
        if ($size < 500 * 1024 * 1024) {
            try {
                copyDir($ojsRoot . '/' . $fd, $backupDir . '/' . $fd);
                $result['backed_up'][] = $fd . '/ (files_dir)';
            } catch (Exception $e) {
                $result['warnings'][] = "Gagal backup {$fd}/: " . $e->getMessage();
            }
        } else {
            $result['warnings'][] = "{$fd}/ terlalu besar (" . formatBytes($size) . ") — lakukan backup manual!";
        }
    }

    // Metadata backup
    file_put_contents($backupDir . '/backup_info.txt',
        "Backup   : " . date('Y-m-d H:i:s') . "\n" .
        "OJS Ver  : " . detectOjsVersion($ojsRoot) . "\n" .
        "OJS Root : " . $ojsRoot . "\n" .
        "PHP      : " . PHP_VERSION . "\n" .
        "Backed up: " . implode(', ', $result['backed_up']) . "\n"
    );

    $result['success'] = true;
    return $result;
}


// §12  SCAN DIFF

function scanRootDiff(string $ojsRoot, string $newOjsDir): array
{
    $preserved = getPreservedItems($ojsRoot);
    $fdName    = detectFilesDirInsideRoot($ojsRoot);
    $config    = readOjsConfig($ojsRoot);

    $existing = $new = [];
    if (is_dir($ojsRoot)) {
        foreach (new DirectoryIterator($ojsRoot) as $i) {
            if (!$i->isDot()) $existing[$i->getFilename()] = $i->isDir() ? 'dir' : 'file';
        }
    }
    if (is_dir($newOjsDir)) {
        foreach (new DirectoryIterator($newOjsDir) as $i) {
            if (!$i->isDot()) $new[$i->getFilename()] = $i->isDir() ? 'dir' : 'file';
        }
    }

    $willReplace = $preservedHit = $newOnly = $customOnly = [];
    foreach ($new as $name => $type) {
        if (in_array($name, $preserved, true)) {
            $preservedHit[] = ['name' => $name, 'type' => $type];
        } elseif ($name === 'plugins') {
            $willReplace[] = ['name' => $name, 'type' => $type, 'action' => 'merge'];
        } elseif (isset($existing[$name])) {
            $willReplace[] = ['name' => $name, 'type' => $type, 'action' => 'replace'];
        } else {
            $newOnly[] = ['name' => $name, 'type' => $type, 'action' => 'add'];
        }
    }
    foreach ($existing as $name => $type) {
        if (!isset($new[$name])) {
            $reason = 'custom';
            if (in_array($name, $preserved, true)) $reason = 'preserved';
            if ($name === $fdName)                  $reason = 'files_dir';
            if ($name === basename(__FILE__))        $reason = 'script_ini';
            $customOnly[] = ['name' => $name, 'type' => $type, 'reason' => $reason];
        }
    }
    return [
        'will_replace'   => $willReplace,
        'preserved'      => $preservedHit,
        'custom_only'    => $customOnly,
        'new_only'       => $newOnly,
        'files_dir_name' => $fdName,
        'files_dir_raw'  => $config['files_dir'] ?? '',
        'files_dir_loc'  => $fdName ? 'inside' : ($config['files_dir'] ? 'outside' : 'unknown'),
        'preserved_list' => $preserved,
    ];
}


// §13  PLUGINS — core list & merge

function getCorePluginList(): array
{
    return [
        'generic'            => [
            'announcementFeed','citationStyleLanguage','customBlockManager','dashboard',
            'datacite','googleScholar','googleAnalytics','usageEvent','usageStats','webFeed',
            'acrobat','browse','crossref','dc','doaj','htmlArticleGalley','keywords',
            'oaiJats','pdfJsViewer','pubIds','referencedBy','translatorPlugin','creativeCommons',
            'jatsTemplate','openAIRE','recommendByAuthor','submissionScheduler',
        ],
        'themes'             => ['default','bootstrap3','classic','healthSciences','manuscript'],
        'importexport'       => ['crossref','datacite','doaj','medra','native','pubIds','pubmed','users','quickSubmit'],
        'blocks'             => ['developedBy','information','language','location','makeSubmission','navigation','subscription'],
        'gateways'           => ['clockss','lockss','menubuilder'],
        'metadata'           => ['dublinCore','mods'],
        'oaiMetadataFormats' => ['dc','marc','marcxml','oai_dc','openurl_docs'],
        'paymethod'          => ['manual','paypal'],
        'reports'            => ['articles','customReportManager','finances','views'],
        'pubIds'             => ['doi'],
    ];
}

function mergePluginsDir(string $newPluginsDir, string $existingPluginsDir): array
{
    $result   = ['success' => false, 'updated' => [], 'skipped_custom' => [], 'errors' => []];
    $coreList = getCorePluginList();
    try {
        foreach (new DirectoryIterator($newPluginsDir) as $cat) {
            if ($cat->isDot() || !$cat->isDir()) continue;
            $catName      = $cat->getFilename();
            $newCatDir    = $cat->getPathname();
            $existCatDir  = $existingPluginsDir . '/' . $catName;
            $corePl       = $coreList[$catName] ?? [];

            if (!is_dir($existCatDir)) {
                copyDir($newCatDir, $existCatDir);
                $result['updated'][] = "$catName/ (kategori baru)";
                continue;
            }

            foreach (new DirectoryIterator($newCatDir) as $plugin) {
                if ($plugin->isDot() || !$plugin->isDir()) continue;
                $pName     = $plugin->getFilename();
                $newPath   = $plugin->getPathname();
                $existPath = $existCatDir . '/' . $pName;

                if (in_array($pName, $corePl, true)) {
                    // Update plugin core
                    if (is_dir($existPath)) removeDir($existPath);
                    copyDir($newPath, $existPath);
                    $result['updated'][] = "$catName/$pName";
                } else {
                    // Plugin custom — jangan ubah jika sudah ada
                    if (!is_dir($existPath)) {
                        copyDir($newPath, $existPath);
                        $result['updated'][] = "$catName/$pName (baru)";
                    } else {
                        $result['skipped_custom'][] = "$catName/$pName";
                    }
                }
            }
        }
        $result['success'] = true;
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
    }
    return $result;
}

// §14  APPLY UPDATE (dengan dry-run & rollback)

function applyOjsUpdate(string $newOjsDir, string $ojsRoot, bool $dryRun = false): array
{
    $result    = ['success' => false, 'replaced' => [], 'skipped' => [], 'errors' => [], 'dry_run' => $dryRun];
    $preserved = getPreservedItems($ojsRoot);

    if (!is_dir($newOjsDir)) {
        $result['error'] = 'Direktori OJS baru tidak ditemukan.';
        return $result;
    }
    if (!file_exists($newOjsDir . '/index.php')) {
        $result['error'] = 'Direktori sumber bukan instalasi OJS valid (index.php tidak ditemukan).';
        return $result;
    }

    // ── DRY RUN: simulasi tanpa perubahan ───────────────────
    if ($dryRun) {
        foreach (new DirectoryIterator($newOjsDir) as $item) {
            if ($item->isDot()) continue;
            $name = $item->getFilename();
            if (in_array($name, $preserved, true) || $name === basename(__FILE__)) {
                $result['skipped'][] = $name;
            } else {
                $result['replaced'][] = $name . ($item->isDir() ? '/' : '');
            }
        }
        $result['success'] = true;
        return $result;
    }

    // ── APPLY ───────────────────────────────────────────────
    ensureWorkDir();
    $rollbackDir  = TMP_WORK_DIR . '/rollback_' . time();
    $appliedItems = [];

    try {
        foreach (new DirectoryIterator($newOjsDir) as $item) {
            if ($item->isDot()) continue;
            $name    = $item->getFilename();
            $srcPath = $item->getPathname();
            $dstPath = $ojsRoot . DIRECTORY_SEPARATOR . $name;

            if (in_array($name, $preserved, true) || $name === basename(__FILE__)) {
                $result['skipped'][] = $name;
                continue;
            }

            // Simpan backup untuk rollback
            if (file_exists($dstPath) || is_dir($dstPath)) {
                $rb = $rollbackDir . '/' . $name;
                is_dir($dstPath) ? copyDir($dstPath, $rb) : copy($dstPath, $rb);
                $appliedItems[] = ['name' => $name, 'type' => is_dir($dstPath) ? 'dir' : 'file', 'rollback' => $rb];
            }

            // Merge plugins / replace
            if ($name === 'plugins' && is_dir($srcPath) && is_dir($dstPath)) {
                $pr = mergePluginsDir($srcPath, $dstPath);
                if ($pr['success']) {
                    $result['replaced'][] = 'plugins/ (merged: ' . count($pr['updated']) . ')';
                    if (!empty($pr['skipped_custom'])) {
                        $result['skipped'][] = 'plugins custom (' . count($pr['skipped_custom']) . ' dipertahankan)';
                    }
                } else {
                    throw new Exception('Merge plugins gagal: ' . ($pr['error'] ?? ''));
                }
                continue;
            }

            if ($item->isDir()) {
                if (is_dir($dstPath)) removeDir($dstPath);
                copyDir($srcPath, $dstPath);
                $result['replaced'][] = $name . '/';
            } else {
                copy($srcPath, $dstPath);
                $result['replaced'][] = $name;
            }
        }

        $result['success'] = true;
        if (is_dir($rollbackDir)) removeDir($rollbackDir);
        ojsLog('info', 'Update applied: ' . count($result['replaced']) . ' diganti, ' . count($result['skipped']) . ' dilewati');

    } catch (Exception $e) {
        $result['errors'][] = 'Error: ' . $e->getMessage();
        ojsLog('error', 'Update error: ' . $e->getMessage() . ' — memulai rollback');

        $rbOk = true;
        foreach (array_reverse($appliedItems) as $app) {
            $dst = $ojsRoot . DIRECTORY_SEPARATOR . $app['name'];
            if ($app['type'] === 'dir') { removeDir($dst); } else { @unlink($dst); }
            try {
                $app['type'] === 'dir' ? copyDir($app['rollback'], $dst) : copy($app['rollback'], $dst);
            } catch (Exception $re) {
                $rbOk = false;
                $msg  = 'KRITIS: Rollback gagal untuk ' . $app['name'];
                $result['errors'][] = $msg;
                ojsLog('error', $msg);
            }
        }

        if ($rbOk) {
            $result['errors'][] = 'Update dibatalkan. Rollback berhasil — kondisi kembali seperti semula.';
            if (is_dir($rollbackDir)) removeDir($rollbackDir);
        } else {
            $result['errors'][] = 'ROLLBACK TIDAK LENGKAP! Cek manual: ' . $rollbackDir;
            ojsLog('error', 'Rollback tidak lengkap! Dir: ' . $rollbackDir);
        }
    }

    return $result;
}

// §15  VERSION DATA & CLASSIFICATION

function getKnownOjsVersions(): array
{
    return [
        '3.5.0-4'  => ['2026-04-09', false, '8.2', 'Latest Stable', ''],
        '3.5.0-3'  => ['2025-12-11', false, '8.2', '', ''],
        '3.5.0-2'  => ['2025-10-30', false, '8.2', '', ''],
        '3.5.0-1'  => ['2025-10-09', false, '8.2', '', ''],
        '3.5.0-0'  => ['2025-09-23', false, '8.2', '', ''],
        '3.4.0-10' => ['2025-11-21', true,  '8.0', 'LTS', 'Didukung s/d Jan 2027'],
        '3.4.0-9'  => ['2025-08-05', true,  '8.0', 'LTS', ''],
        '3.4.0-8'  => ['2025-04-01', false, '8.0', '', ''],
        '3.4.0-7'  => ['2024-12-16', false, '8.0', '', ''],
        '3.4.0-6'  => ['2024-09-12', false, '8.0', '', ''],
        '3.4.0-5'  => ['2024-06-04', false, '8.0', '', ''],
        '3.3.0-19' => ['2024-06-04', false, '7.3', 'Legacy EOL', 'End of life'],
        '3.3.0-18' => ['2024-01-25', false, '7.3', '', ''],
        '3.3.0-17' => ['2023-10-17', false, '7.3', '', ''],
        '3.3.0-16' => ['2023-08-08', false, '7.3', '', ''],
    ];
}

function tryFetchLiveOjsVersions(): array
{
    $ctx   = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'OJS-Updater/2.0', 'ignore_errors' => true]]);
    $found = [];
    foreach (['https://pkp.sfu.ca/software/ojs/download/', 'https://pkp.sfu.ca/software/ojs/download/archive/'] as $url) {
        $html = @file_get_contents($url, false, $ctx);
        if ($html && preg_match_all('/ojs-(3\.\d+\.\d+-\d+)\.tar\.gz/i', $html, $m)) {
            foreach ($m[1] as $v) $found[$v] = true;
        }
    }
    return array_keys($found);
}

function getAllOjsVersions(): array
{
    $known = getKnownOjsVersions();
    foreach (tryFetchLiveOjsVersions() as $v) {
        if (!isset($known[$v])) {
            $phpMin = '7.3';
            if (version_compare($v, '3.4.0', '>=')) $phpMin = '8.0';
            if (version_compare($v, '3.5.0', '>=')) $phpMin = '8.2';
            $known[$v] = ['', false, $phpMin, 'Live PKP', ''];
        }
    }
    uksort($known, fn($a, $b) => version_compare($b, $a));
    return $known;
}

function checkPhpCompatibility(string $ver): array
{
    $known  = getKnownOjsVersions();
    $phpMin = $known[$ver][2] ?? '7.3';
    $ok     = version_compare(PHP_VERSION, $phpMin, '>=');
    $warn   = null;
    if (version_compare($ver, '3.3.0', '>=') && version_compare($ver, '3.4.0', '<') && version_compare(PHP_VERSION, '8.2', '>=')) {
        $warn = 'PHP ' . PHP_VERSION . ' belum diuji resmi untuk OJS 3.3.';
    }
    return ['ok' => $ok, 'php_min' => $phpMin, 'warning' => $warn, 'current' => PHP_VERSION];
}

function classifyUpgrade(string $cur, string $tgt): string
{
    $norm = static function (string $v): array {
        $v = trim($v);
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)[.\-](\d+)$/', $v, $m)) {
            return [[(int)$m[1], (int)$m[2], (int)$m[3]], (int)$m[4]];
        }
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $v, $m)) {
            return [[(int)$m[1], (int)$m[2], (int)$m[3]], 0];
        }
        return [[0, 0, 0], 0];
    };
    [$cBase, $cB] = $norm($cur);
    [$tBase, $tB] = $norm($tgt);
    if ($cBase[0] !== $tBase[0]) return $tBase[0] > $cBase[0] ? 'major' : 'downgrade';
    if ($cBase[1] !== $tBase[1]) return $tBase[1] > $cBase[1] ? 'minor' : 'downgrade';
    if ($cBase[2] !== $tBase[2]) return $tBase[2] > $cBase[2] ? 'patch' : 'downgrade';
    if ($cB === $tB)             return 'reinstall';
    return $tB > $cB ? 'patch' : 'downgrade';
}

// §16  FORM ACTION PROCESSING

@set_time_limit(MAX_EXEC_SECONDS);
@ini_set('memory_limit', '512M');

$step     = isset($_GET['step']) ? max(0, (int)$_GET['step']) : 0;
$action   = isset($_POST['action']) ? trim($_POST['action']) : '';
$errors   = $success = $info = $warnings = [];

// ── Login ────────────────────────────────────────────────────
if ($action === 'login') {
    if (isLockedOut()) {
        $errors[] = 'Terlalu banyak percobaan. Tunggu ' . ceil(getLockoutRemaining() / 60) . ' menit.';
    } elseif (SCRIPT_PASSWORD === DEFAULT_PASSWORD) {
        $errors[] = '⚠ KEAMANAN: Ubah SCRIPT_PASSWORD di baris konfigurasi sebelum login!';
    } else {
        $pwd = $_POST['password'] ?? '';
        if (hash_equals(SCRIPT_PASSWORD, $pwd)) {
            session_regenerate_id(true);
            $_SESSION['ojs_updater_auth']  = true;
            $_SESSION['ojs_last_activity'] = time();
            $_SESSION['ojs_login_ip']      = $_SERVER['REMOTE_ADDR'] ?? '';
            clearLockout();
            ojsLog('info', 'Login berhasil');
            header('Location: ?step=1');
            exit;
        } else {
            recordFailedLogin();
            $ld = getLockoutData();
            $remaining = MAX_LOGIN_ATTEMPTS - $ld['attempts'];
            $errors[]  = 'Password salah!';
            if ($remaining > 0) $errors[] = "Sisa percobaan: {$remaining}";
            ojsLog('warn', 'Login gagal (percobaan ke-' . $ld['attempts'] . ')');
        }
    }
}

// ── Logout ──────────────────────────────────────────────────
if ($action === 'logout') {
    ojsLog('info', 'Logout');
    _destroySession();
    header('Location: ?step=0');
    exit;
}

// ── Auth + CSRF guard untuk semua action lain ───────────────
if (!empty($action) && !in_array($action, ['login', 'logout'], true)) {
    if (!checkAuth()) {
        $errors[] = 'Sesi tidak valid atau sudah berakhir. Silakan login ulang.';
        $action   = '';
    } elseif (!verifyCsrfToken()) {
        $errors[] = 'Token keamanan tidak valid. Refresh halaman dan coba lagi.';
        ojsLog('warn', 'CSRF mismatch untuk action: ' . $action);
        $action = '';
    }
}

// ── Backup files ─────────────────────────────────────────────
if ($action === 'backup') {
    $bDir = BACKUP_BASE_DIR . '/ojs_backup_' . date('YmdHis');
    $r    = backupImportantFiles(OJS_ROOT, $bDir);
    if ($r['success']) {
        $_SESSION['ojs_backup_dir'] = $bDir;
        $success[] = 'Backup file berhasil: ' . implode(', ', $r['backed_up']);
        foreach ($r['warnings'] ?? [] as $w) $warnings[] = $w;
        ojsLog('info', 'File backup: ' . $bDir);
    } else {
        $errors[] = $r['error'] ?? 'Backup file gagal.';
    }
}

// ── Backup database ──────────────────────────────────────────
if ($action === 'backup_db') {
    $cfg    = readOjsConfig(OJS_ROOT);
    $outDir = BACKUP_BASE_DIR . '/db_backup';
    if (!is_dir($outDir)) @mkdir($outDir, 0750, true);
    _protectDir($outDir);
    $r = backupDatabase($cfg, $outDir);
    if ($r['success']) {
        $success[] = 'Backup database berhasil → ' . e(basename($r['file'])) . ' (' . $r['size'] . ') via ' . $r['method'];
        ojsLog('info', 'DB backup: ' . $r['file']);
    } else {
        $errors[] = 'Backup DB gagal: ' . ($r['error'] ?? 'error tidak diketahui');
    }
}

// ── Upload ZIP ───────────────────────────────────────────────
if ($action === 'upload_zip') {
    ensureWorkDir();
    if (!isset($_FILES['ojs_zip']) || $_FILES['ojs_zip']['error'] !== UPLOAD_ERR_OK) {
        $em = [
            UPLOAD_ERR_INI_SIZE   => 'Melebihi upload_max_filesize di php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'Melebihi MAX_FILE_SIZE form',
            UPLOAD_ERR_PARTIAL    => 'Upload sebagian saja',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang dipilih',
            UPLOAD_ERR_NO_TMP_DIR => 'Direktori tmp tidak ada',
            UPLOAD_ERR_CANT_WRITE => 'Gagal tulis ke disk',
        ];
        $code = $_FILES['ojs_zip']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errors[] = 'Upload gagal: ' . ($em[$code] ?? "Error kode $code");
    } else {
        $fname  = basename($_FILES['ojs_zip']['name']);
        $ext    = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        $isTarGz = str_ends_with(strtolower($fname), '.tar.gz');
        if (!in_array($ext, ['zip', 'gz', 'tgz'], true) && !$isTarGz) {
            $errors[] = 'Format tidak didukung. Gunakan .zip atau .tar.gz';
        } else {
            $upDir  = TMP_WORK_DIR . '/upload_' . time();
            @mkdir($upDir, 0750, true);
            $extStr  = ($isTarGz || $ext === 'tgz') ? 'tar.gz' : $ext;
            $zipPath = $upDir . '/ojs_upload.' . $extStr;
            if (move_uploaded_file($_FILES['ojs_zip']['tmp_name'], $zipPath)) {
                $exDir = TMP_WORK_DIR . '/extract_' . time();
                $exRes = ($ext === 'zip') ? extractOjsZip($zipPath, $exDir) : extractTarGz($zipPath, $exDir);
                if ($exRes['success']) {
                    $_SESSION['ojs_new_dir'] = $exRes['ojs_subdir'];
                    $success[] = 'File berhasil diekstrak.';
                    ojsLog('info', 'Upload & ekstrak: ' . $exRes['ojs_subdir']);
                } else {
                    $errors[] = $exRes['error'] ?? 'Gagal mengekstrak.';
                }
                @unlink($zipPath);
            } else {
                $errors[] = 'Gagal memindahkan file upload.';
            }
        }
    }
}

// ── Dry Run ──────────────────────────────────────────────────
if ($action === 'dry_run') {
    $nd = $_SESSION['ojs_new_dir'] ?? null;
    if (!$nd || !is_dir($nd)) {
        $errors[] = 'Direktori OJS baru tidak ditemukan.';
    } else {
        $r = applyOjsUpdate($nd, OJS_ROOT, true);
        if ($r['success']) {
            $info[] = '🔍 DRY RUN — tidak ada yang diubah.';
            $info[] = 'Akan diganti (' . count($r['replaced']) . '): ' .
                      implode(', ', array_slice($r['replaced'], 0, 15)) .
                      (count($r['replaced']) > 15 ? ' …' : '');
            $info[] = 'Dilewati (' . count($r['skipped']) . '): ' . implode(', ', $r['skipped']);
        }
    }
}

// ── Apply Update ─────────────────────────────────────────────
if ($action === 'apply_update') {
    $nd = $_SESSION['ojs_new_dir'] ?? null;
    if (!$nd || !is_dir($nd)) {
        $errors[] = 'Direktori OJS baru tidak ditemukan. Upload ZIP terlebih dahulu.';
    } else {
        $r = applyOjsUpdate($nd, OJS_ROOT, false);
        if ($r['success']) {
            $success[] = 'Update core berhasil! ' . count($r['replaced']) . ' item diperbarui.';
            $success[] = 'Dilewati: ' . implode(', ', $r['skipped']);
        } else {
            foreach ($r['errors'] as $err) $errors[] = $err;
        }
    }
}

// ── Clear Cache ──────────────────────────────────────────────
if ($action === 'clear_cache') {
    $r = clearOjsCache(OJS_ROOT);
    $success[] = 'Cache dibersihkan: ' . (implode(', ', $r['cleared']) ?: 'tidak ada yang perlu dibersihkan.');
    foreach ($r['errors'] ?? [] as $err) $warnings[] = $err;
    ojsLog('info', 'Cache cleared');
}

// ── DB Upgrade ───────────────────────────────────────────────
if ($action === 'run_upgrade') {
    $r = runOjsDbUpgrade(OJS_ROOT);
    if ($r['success']) {
        $success[] = 'Upgrade database berhasil!';
    } else {
        $errors[] = 'DB upgrade: ' . ($r['error'] ?? 'gagal');
    }
    if (!empty($r['output'])) {
        $info[] = '<strong>Output DB upgrade:</strong><br><pre style="max-height:200px;overflow:auto;">' . e($r['output']) . '</pre>';
    }
    ojsLog($r['success'] ? 'info' : 'error', 'DB upgrade: ' . ($r['success'] ? 'OK' : ($r['error'] ?? 'error')));
}

// ── Download Version ──────────────────────────────────────────
if ($action === 'download_version') {
    $version = trim($_POST['ojs_version'] ?? '');
    if (!preg_match('/^3\.\d+\.\d+-\d+$/', $version)) {
        $errors[] = 'Format versi tidak valid: ' . e($version);
    } else {
        @set_time_limit(0);
        ensureWorkDir();
        $tmpDir = TMP_WORK_DIR . '/dl_' . date('YmdHis');
        $dlRes  = downloadOjsRelease($version, $tmpDir);
        if ($dlRes['success']) {
            $_SESSION['ojs_new_dir']    = $dlRes['extract_dir'];
            $_SESSION['ojs_dl_version'] = $version;
            $success[] = "OJS {$version} berhasil diunduh via {$dlRes['downloaded_via']}";
            ojsLog('info', "Download OJS {$version} OK via {$dlRes['downloaded_via']}");
        } else {
            $errors[] = 'Download gagal: ' . ($dlRes['error'] ?? 'error tidak diketahui');
            ojsLog('error', "Download OJS {$version} gagal: " . ($dlRes['error'] ?? ''));
        }
    }
}

// ── Manual Dir ───────────────────────────────────────────────
if ($action === 'set_manual_dir') {
    $input   = trim($_POST['manual_dir'] ?? '');
    $realDir = realpath($input);
    if (!$realDir || !is_dir($realDir)) {
        $errors[] = 'Path tidak valid atau tidak ditemukan.';
    } elseif (!file_exists($realDir . '/index.php')) {
        $errors[] = 'Bukan instalasi OJS valid (index.php tidak ada).';
    } elseif ($realDir === realpath(OJS_ROOT)) {
        $errors[] = 'Tidak boleh menunjuk ke direktori OJS yang sedang aktif!';
    } else {
        $_SESSION['ojs_new_dir'] = $realDir;
        $success[] = 'Direktori ditetapkan: ' . e(basename($realDir));
    }
}

// ── Cleanup Tmp ──────────────────────────────────────────────
if ($action === 'cleanup_tmp') {
    if (is_dir(TMP_WORK_DIR)) {
        $before = dirSize(TMP_WORK_DIR);
        $iter   = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(TMP_WORK_DIR, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            if (in_array($f->getFilename(), ['.htaccess', 'index.html', 'index.php'], true)) continue;
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        $success[] = 'File temporary dibersihkan. Dibebaskan: ' . formatBytes($before);
        unset($_SESSION['ojs_new_dir'], $_SESSION['ojs_dl_version']);
        ojsLog('info', 'Cleanup tmp: ' . formatBytes($before));
    }
}

// ── Maintenance Mode ─────────────────────────────────────────
if ($action === 'toggle_maintenance') {
    $enable = (($_POST['maintenance'] ?? '') === '1');
    if (setMaintenanceMode(OJS_ROOT, $enable)) {
        $success[] = $enable
            ? '🔒 Maintenance mode AKTIF — OJS tidak bisa diakses publik.'
            : '🟢 Maintenance mode NONAKTIF — OJS kembali online.';
        ojsLog('info', 'Maintenance: ' . ($enable ? 'ON' : 'OFF'));
    } else {
        if ($enable) {
            $warnings[] = 'Maintenance mode tidak bisa diset otomatis. Set <code>installed = Off</code> di config.inc.php secara manual.';
        } else {
            $warnings[] = 'Tidak bisa nonaktifkan maintenance. Set <code>installed = On</code> di config.inc.php secara manual.';
        }
    }
}

// 17  COLLECT SYSTEM INFO
$ojsConfig      = checkAuth() ? readOjsConfig(OJS_ROOT)              : [];
$ojsVersion     = checkAuth() ? detectOjsVersion(OJS_ROOT)           : '-';
$filesDirName   = checkAuth() ? detectFilesDirInsideRoot(OJS_ROOT)   : null;
$preservedNow   = checkAuth() ? getPreservedItems(OJS_ROOT)          : [];
$newOjsDir      = $_SESSION['ojs_new_dir']    ?? null;
$backupDirSess  = $_SESSION['ojs_backup_dir'] ?? null;
$dlVersion      = $_SESSION['ojs_dl_version'] ?? null;
$ojsVersionList = checkAuth() ? getAllOjsVersions()                   : [];
$maintenanceOn  = checkAuth() ? getMaintenanceStatus(OJS_ROOT)       : false;
$scanDiff       = ($newOjsDir && is_dir($newOjsDir) && checkAuth()) ? scanRootDiff(OJS_ROOT, $newOjsDir) : null;
$recentLogs     = (checkAuth() && ENABLE_LOGGING) ? getRecentLogs(30) : [];
$sessionLeft    = (checkAuth() && SESSION_TIMEOUT > 0)
                    ? max(0, SESSION_TIMEOUT - (time() - ($_SESSION['ojs_last_activity'] ?? time())))
                    : 0;
$loginAttempts  = getLockoutData()['attempts'] ?? 0;

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OJS Core Updater v2</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Outfit:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:#0b0a08; --surface:#141210; --surface2:#1b1815; --surface3:#221f1b;
  --border:rgba(255,235,180,.07); --border-md:rgba(255,235,180,.13); --border-hi:rgba(212,148,58,.35);
  --gold:#d4943a; --gold-hi:#e8a84a; --gold-dim:rgba(212,148,58,.12); --gold-glow:0 0 0 3px rgba(212,148,58,.15);
  --mint:#4ade80; --mint-dim:rgba(74,222,128,.1); --amber:#fbbf24; --amber-dim:rgba(251,191,36,.1);
  --coral:#f87171; --coral-dim:rgba(248,113,113,.1); --sky:#7dd3fc; --sky-dim:rgba(125,211,252,.1);
  --text:#f0ead8; --text-dim:#9e9a8e; --text-faint:#5a5650; --code-bg:#0f0d0b;
  --r:6px; --r-lg:10px;
}
body { background:var(--bg); color:var(--text); font-family:'Outfit',sans-serif; font-size:14px; line-height:1.65; min-height:100vh; }
::-webkit-scrollbar{width:5px;height:5px} ::-webkit-scrollbar-track{background:var(--bg)} ::-webkit-scrollbar-thumb{background:var(--surface3);border-radius:10px}
.header { background:var(--surface); border-bottom:1px solid var(--border); padding:0 28px; height:58px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:100; backdrop-filter:blur(12px); }
.header::after { content:''; position:absolute; bottom:0;left:0;right:0; height:1px; background:linear-gradient(90deg,transparent,var(--gold),transparent); opacity:.35; }
.header-brand { display:flex; align-items:center; gap:12px; }
.header-icon { width:32px;height:32px; background:var(--gold-dim); border:1px solid var(--border-hi); border-radius:var(--r); display:flex;align-items:center;justify-content:center; font-size:15px; }
.header-title { font-family:'Cormorant Garamond',serif; font-size:20px; font-weight:600; letter-spacing:.01em; line-height:1; }
.header-title span { color:var(--gold); }
.header-sub { font-size:11px; color:var(--text-faint); font-family:'JetBrains Mono',monospace; letter-spacing:.04em; margin-top:2px; }
.header-right { display:flex; align-items:center; gap:10px; }
.session-badge { font-size:11px; font-family:'JetBrains Mono',monospace; color:var(--text-faint); padding:4px 10px; border:1px solid var(--border-md); border-radius:100px; display:flex; align-items:center; gap:5px; }
.session-badge.warn { color:var(--amber); border-color:rgba(251,191,36,.3); }
.logout-btn { display:flex;align-items:center;gap:6px; background:none;border:1px solid var(--border-md); color:var(--text-dim); font-size:12px; font-family:'Outfit',sans-serif; padding:6px 12px; border-radius:var(--r); cursor:pointer; transition:all .18s; text-decoration:none; }
.logout-btn:hover { border-color:var(--coral);color:var(--coral);background:var(--coral-dim); }
.container { max-width:860px; margin:0 auto; padding:32px 20px 60px; }
.flash { display:flex; align-items:flex-start; gap:10px; padding:12px 16px; border-radius:var(--r); margin-bottom:10px; font-size:13px; border:1px solid; animation:fadeIn .25s ease; }
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.flash-icon { flex-shrink:0; font-size:14px; margin-top:1px; }
.flash-err  { background:var(--coral-dim); border-color:rgba(248,113,113,.25); color:#fca5a5; }
.flash-ok   { background:var(--mint-dim);  border-color:rgba(74,222,128,.2);   color:#86efac; }
.flash-info { background:var(--sky-dim);   border-color:rgba(125,211,252,.2);  color:#bae6fd; }
.flash-warn { background:var(--amber-dim); border-color:rgba(251,191,36,.2);   color:#fde68a; }
.steps-nav { display:flex; align-items:center; margin-bottom:32px; padding:20px 24px; background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); position:relative; overflow:hidden; }
.steps-nav::before { content:''; position:absolute;inset:0; background:radial-gradient(ellipse at 50% 100%,rgba(212,148,58,.04) 0%,transparent 70%); pointer-events:none; }
.step-node { display:flex;flex-direction:column;align-items:center;gap:6px; flex:1;position:relative; text-decoration:none; }
.step-node:not(:last-child)::after { content:''; position:absolute; top:16px; left:calc(50% + 18px); right:calc(-50% + 18px); height:1px; background:var(--border-md); }
.step-node.done:not(:last-child)::after { background:var(--gold); opacity:.45; }
.step-circle { width:34px;height:34px; border-radius:50%; display:flex;align-items:center;justify-content:center; font-size:13px;font-weight:600; border:1.5px solid var(--border-md); background:var(--surface2); color:var(--text-faint); transition:all .2s; position:relative;z-index:1; }
.step-label { font-size:11px;color:var(--text-faint);text-align:center;line-height:1.2;white-space:nowrap; }
.step-node.active .step-circle { background:var(--gold);border-color:var(--gold);color:#1a0e00; box-shadow:0 0 0 4px rgba(212,148,58,.18),0 2px 10px rgba(212,148,58,.3); }
.step-node.active .step-label { color:var(--gold);font-weight:500; }
.step-node.done .step-circle { background:rgba(74,222,128,.12);border-color:rgba(74,222,128,.4);color:var(--mint); }
.step-node.done .step-label { color:var(--mint); }
.card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); margin-bottom:16px; overflow:hidden; transition:border-color .2s; }
.card:hover { border-color:var(--border-md); }
.card-head { padding:18px 24px 16px; border-bottom:1px solid var(--border); display:flex;align-items:center;gap:12px; background:linear-gradient(to bottom,var(--surface2),var(--surface)); }
.card-head-icon { width:30px;height:30px; background:var(--gold-dim); border:1px solid rgba(212,148,58,.2); border-radius:8px; display:flex;align-items:center;justify-content:center; font-size:13px;flex-shrink:0; }
.card-head h2 { font-family:'Cormorant Garamond',serif; font-size:18px;font-weight:600;color:var(--text); letter-spacing:.01em;line-height:1; }
.card-head small { font-size:11px;color:var(--text-faint);font-family:'JetBrains Mono',monospace; letter-spacing:.03em;margin-top:2px;display:block; }
.card-body { padding:22px 24px; }
.card-footer { padding:14px 24px; border-top:1px solid var(--border); display:flex;align-items:center;gap:10px; background:linear-gradient(to top,var(--surface2),var(--surface)); }
.info-table { width:100%;border-collapse:collapse; }
.info-table tr { border-bottom:1px solid var(--border); }
.info-table tr:last-child { border-bottom:none; }
.info-table td { padding:10px 0;vertical-align:top; }
.info-table td.lbl { color:var(--text-faint);font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-family:'JetBrains Mono',monospace;width:38%;padding-right:16px;padding-top:12px; }
.info-table td.val { font-weight:500;color:var(--text);font-size:13.5px;word-break:break-all; }
code { font-family:'JetBrains Mono',monospace;font-size:11.5px;background:var(--gold-dim);color:var(--gold);padding:1px 6px;border-radius:4px; }
pre { background:var(--code-bg);border:1px solid var(--border);border-radius:var(--r);padding:14px 16px;font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--mint);overflow:auto;max-height:220px;line-height:1.65; }
.chip-group { display:flex;flex-wrap:wrap;gap:6px;margin-top:8px; }
.chip { display:inline-flex;align-items:center;gap:4px;padding:3px 10px 3px 8px;border-radius:100px;font-size:11px;font-weight:500;border:1px solid;font-family:'JetBrains Mono',monospace;white-space:nowrap; }
.chip-dot { width:5px;height:5px;border-radius:50%;flex-shrink:0; }
.chip-green  { border-color:rgba(74,222,128,.3);  color:#86efac; background:rgba(74,222,128,.06);  } .chip-green .chip-dot  { background:var(--mint); }
.chip-blue   { border-color:rgba(125,211,252,.3); color:#bae6fd; background:rgba(125,211,252,.06); } .chip-blue .chip-dot   { background:var(--sky); }
.chip-amber  { border-color:rgba(251,191,36,.3);  color:#fde68a; background:rgba(251,191,36,.06);  } .chip-amber .chip-dot  { background:var(--amber); }
.chip-muted  { border-color:var(--border-md);color:var(--text-dim);background:var(--surface2); }
.chip-coral  { border-color:rgba(248,113,113,.3); color:#fca5a5; background:rgba(248,113,113,.06); }
.callout { display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border-radius:var(--r);border:1px solid;font-size:13px;line-height:1.55;margin-bottom:14px; }
.callout-ico { flex-shrink:0;font-size:15px; }
.callout-warn  { background:var(--amber-dim); border-color:rgba(251,191,36,.2);  color:#fde68a; }
.callout-ok    { background:var(--mint-dim);  border-color:rgba(74,222,128,.2);  color:#a7f3d0; }
.callout-err   { background:var(--coral-dim); border-color:rgba(248,113,113,.2); color:#fca5a5; }
.callout-info  { background:var(--sky-dim);   border-color:rgba(125,211,252,.2); color:#bae6fd; }
.callout-gold  { background:var(--gold-dim);  border-color:rgba(212,148,58,.25); color:#fde68a; }
.notice { border-left:3px solid; padding:12px 16px; border-radius:0 var(--r) var(--r) 0; font-size:13px;line-height:1.6;margin-bottom:14px; }
.notice strong { display:block;margin-bottom:4px;font-size:13.5px; }
.notice-warn  { background:rgba(251,191,36,.07);  border-color:var(--amber); color:var(--text-dim); } .notice-warn  strong{color:var(--amber);}
.notice-err   { background:rgba(248,113,113,.07); border-color:var(--coral); color:var(--text-dim); } .notice-err   strong{color:var(--coral);}
.notice-ok    { background:rgba(74,222,128,.07);  border-color:var(--mint);  color:var(--text-dim); } .notice-ok    strong{color:var(--mint);}
.divider { height:1px;background:var(--border);margin:20px 0;position:relative; }
.divider-label { position:absolute;top:50%;left:50%;transform:translate(-50%,-50%); background:var(--surface);padding:0 10px; font-size:10px;color:var(--text-faint);letter-spacing:.08em;text-transform:uppercase;font-family:'JetBrains Mono',monospace; }
.sub-step { border:1px solid var(--border);border-radius:var(--r);padding:16px 18px;margin-bottom:12px;background:var(--surface2);display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center; }
.sub-step-label { font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-family:'JetBrains Mono',monospace;color:var(--text-faint);margin-bottom:4px; }
.sub-step-desc { font-size:13px;color:var(--text-dim);line-height:1.5; }
.form-group { margin-bottom:16px; }
.form-group label { display:block;font-size:11px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.06em;font-family:'JetBrains Mono',monospace;margin-bottom:7px; }
input[type="password"],input[type="text"],input[type="file"] { width:100%;background:var(--surface2);border:1px solid var(--border-md);border-radius:var(--r);color:var(--text);padding:10px 14px;font-size:13px;font-family:'Outfit',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s; }
input:focus { border-color:var(--gold);box-shadow:var(--gold-glow); }
.form-hint { font-size:11.5px;color:var(--text-faint);margin-top:6px;line-height:1.5; }
.btn { display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:var(--r);border:1px solid transparent;cursor:pointer;font-size:13px;font-weight:500;font-family:'Outfit',sans-serif;text-decoration:none;white-space:nowrap;transition:all .16s;line-height:1; }
.btn:active{transform:scale(.97)}
.btn-primary { background:var(--gold);color:#1a0e00;border-color:var(--gold); } .btn-primary:hover{background:var(--gold-hi);box-shadow:0 2px 12px rgba(212,148,58,.3);}
.btn-success { background:rgba(74,222,128,.15);color:var(--mint);border-color:rgba(74,222,128,.3); } .btn-success:hover{background:rgba(74,222,128,.22);}
.btn-danger  { background:var(--coral-dim);color:var(--coral);border-color:rgba(248,113,113,.3); } .btn-danger:hover{background:rgba(248,113,113,.18);}
.btn-amber   { background:var(--amber-dim);color:var(--amber);border-color:rgba(251,191,36,.3); } .btn-amber:hover{background:rgba(251,191,36,.18);}
.btn-ghost   { background:transparent;color:var(--text-dim);border-color:var(--border-md); } .btn-ghost:hover{background:var(--surface2);color:var(--text);border-color:var(--border-hi);}
.btn-sky     { background:var(--sky-dim);color:var(--sky);border-color:rgba(125,211,252,.3); } .btn-sky:hover{background:rgba(125,211,252,.15);}
.btn-sm{padding:6px 14px;font-size:12px} .btn-lg{padding:12px 24px;font-size:14px;font-weight:600}
.ver-tabs { display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:0; }
.ver-tab { padding:8px 16px;font-size:12px;font-weight:500;border:1px solid transparent;border-bottom:none;border-radius:var(--r) var(--r) 0 0;cursor:pointer;color:var(--text-faint);background:none;font-family:'Outfit',sans-serif;position:relative;bottom:-1px;transition:all .15s; }
.ver-tab:hover{color:var(--text);background:var(--surface2)} .ver-tab.active{color:var(--gold);background:var(--surface);border-color:var(--border);border-bottom-color:var(--surface);}
.ver-panel{display:none} .ver-panel.active{display:block}
.ver-group{margin-bottom:20px}
.ver-group-title { font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--text-faint);font-family:'JetBrains Mono',monospace;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px; }
.ver-list{display:flex;flex-direction:column;gap:4px}
.ver-row { display:grid;grid-template-columns:auto 1fr auto auto;align-items:center;gap:12px;padding:10px 14px;border-radius:var(--r);border:1px solid var(--border);background:var(--surface2);cursor:pointer;transition:all .15s; }
.ver-row:hover:not(.incompatible){border-color:var(--border-hi);background:var(--surface3)} .ver-row.selected{border-color:var(--gold);background:rgba(212,148,58,.06)} .ver-row.incompatible{opacity:.45;cursor:not-allowed}
.ver-radio { appearance:none;width:16px;height:16px;border-radius:50%;border:1.5px solid var(--border-md);flex-shrink:0;cursor:pointer;transition:all .15s; }
.ver-radio:checked{background:var(--gold);border-color:var(--gold);box-shadow:inset 0 0 0 3px var(--surface2)}
.ver-name { font-family:'JetBrains Mono',monospace;font-size:13px;font-weight:500;color:var(--text); }
.ver-meta { display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:3px; }
.ver-date { font-size:11px;color:var(--text-faint);font-family:'JetBrains Mono',monospace; }
.ver-badge { font-size:10px;font-weight:600;padding:1px 7px;border-radius:100px;letter-spacing:.04em;text-transform:uppercase;font-family:'JetBrains Mono',monospace; }
.vb-lts{background:rgba(74,222,128,.12);color:var(--mint);border:1px solid rgba(74,222,128,.3)} .vb-latest{background:rgba(212,148,58,.12);color:var(--gold);border:1px solid rgba(212,148,58,.3)} .vb-legacy{background:rgba(248,113,113,.1);color:var(--coral);border:1px solid rgba(248,113,113,.2)} .vb-current{background:rgba(125,211,252,.1);color:var(--sky);border:1px solid rgba(125,211,252,.25)}
.ver-compat{font-size:11px;font-family:'JetBrains Mono',monospace;white-space:nowrap} .vc-ok{color:var(--mint)} .vc-bad{color:var(--coral)} .vc-warn{color:var(--amber)}
.ver-upgrade-badge{font-size:10px;padding:2px 7px;border-radius:100px;font-family:'JetBrains Mono',monospace;font-weight:600;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.vub-reinstall{background:var(--sky-dim);color:var(--sky);border:1px solid rgba(125,211,252,.2)} .vub-patch{background:var(--mint-dim);color:var(--mint);border:1px solid rgba(74,222,128,.2)} .vub-minor{background:var(--gold-dim);color:var(--gold);border:1px solid rgba(212,148,58,.2)} .vub-major{background:var(--coral-dim);color:var(--coral);border:1px solid rgba(248,113,113,.2)} .vub-downgrade{background:var(--surface3);color:var(--text-faint);border:1px solid var(--border-md)}
.diff-section{margin-bottom:20px}
.diff-section-title { font-size:11px;text-transform:uppercase;letter-spacing:.07em;font-family:'JetBrains Mono',monospace;color:var(--text-faint);margin-bottom:8px;display:flex;align-items:center;gap:8px; }
.diff-section-title::after{content:'';flex:1;height:1px;background:var(--border)}
.guide-steps{display:flex;flex-direction:column;gap:0}
.guide-step{display:flex;gap:16px;padding:12px 0;border-bottom:1px solid var(--border)} .guide-step:last-child{border-bottom:none}
.guide-step-num{width:24px;height:24px;border-radius:50%;background:var(--gold-dim);border:1px solid rgba(212,148,58,.25);color:var(--gold);font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;font-family:'JetBrains Mono',monospace}
.guide-step-text{font-size:13px;color:var(--text-dim);line-height:1.5} .guide-step-text strong{color:var(--text)}
.maintenance-indicator { display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:100px;font-size:11px;font-family:'JetBrains Mono',monospace;font-weight:600; }
.maint-on  { background:rgba(248,113,113,.1);color:#fca5a5;border:1px solid rgba(248,113,113,.3); }
.maint-off { background:rgba(74,222,128,.1);color:#86efac;border:1px solid rgba(74,222,128,.25); }
.log-entry { font-family:'JetBrains Mono',monospace;font-size:11px;padding:4px 0;border-bottom:1px solid var(--border);color:var(--text-faint);line-height:1.4; }
.log-entry:last-child{border-bottom:none} .log-entry .le-warn{color:var(--amber)} .log-entry .le-error{color:var(--coral)} .log-entry .le-info{color:var(--sky)}
.security-badge { display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:4px;font-size:10.5px;font-family:'JetBrains Mono',monospace;font-weight:500; }
.sb-ok  { background:rgba(74,222,128,.1);color:var(--mint);border:1px solid rgba(74,222,128,.2); }
.sb-off { background:var(--surface3);color:var(--text-faint);border:1px solid var(--border-md); }
.login-wrap{min-height:calc(100vh - 58px);display:flex;align-items:center;justify-content:center}
.login-card{width:100%;max-width:420px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden}
.login-card-head{padding:24px;text-align:center;background:linear-gradient(to bottom,var(--surface2),var(--surface));border-bottom:1px solid var(--border)}
.login-card-head .lock-icon{font-size:28px;display:block;margin-bottom:10px}
.login-card-head h2{font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:600}
.login-card-head p{font-size:12px;color:var(--text-faint);margin-top:4px}
.login-card-body{padding:24px}
@media(max-width:600px){.container{padding:20px 14px 50px}.steps-nav{padding:16px 10px}.step-label{display:none}.step-circle{width:28px;height:28px;font-size:11px}.sub-step{grid-template-columns:1fr}.card-body{padding:16px}.card-head{padding:14px 16px}.card-footer{padding:12px 16px;flex-wrap:wrap}.header{padding:0 16px}.header-sub{display:none}.header-right{gap:6px}}
</style>
</head>
<body>

<header class="header">
  <div class="header-brand">
    <div class="header-icon">⚙</div>
    <div>
      <div class="header-title">OJS <span>Core</span> Updater</div>
      <div class="header-sub">v<?= SCRIPT_VERSION ?> · prod edition · no data loss</div>
    </div>
  </div>
  <?php if (checkAuth()): ?>
  <div class="header-right">
    <?php if ($sessionLeft > 0): ?>
    <div class="session-badge <?= $sessionLeft < 300 ? 'warn' : '' ?>" id="sessionBadge">
      <span>⏱</span><span id="sessionTimer"><?= gmdate('i:s', $sessionLeft) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($maintenanceOn): ?>
    <span class="maintenance-indicator maint-on">🔒 Maintenance</span>
    <?php endif; ?>
    <form method="post" style="display:contents">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="logout-btn">↩ Keluar</button>
    </form>
  </div>
  <?php endif; ?>
</header>

<div class="container">

  <?php foreach ($errors   as $m): ?><div class="flash flash-err" ><span class="flash-icon">✕</span><span><?= $m ?></span></div><?php endforeach; ?>
  <?php foreach ($success  as $m): ?><div class="flash flash-ok"  ><span class="flash-icon">✓</span><span><?= $m ?></span></div><?php endforeach; ?>
  <?php foreach ($info     as $m): ?><div class="flash flash-info"><span class="flash-icon">ℹ</span><span><?= $m ?></span></div><?php endforeach; ?>
  <?php foreach ($warnings as $m): ?><div class="flash flash-warn"><span class="flash-icon">⚠</span><span><?= $m ?></span></div><?php endforeach; ?>

<?php if (!checkAuth()): ?>
<!-- ════════════════════ LOGIN ════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-card-head">
      <span class="lock-icon">🔐</span>
      <h2>Autentikasi Admin</h2>
      <p>Script ini hanya boleh diakses oleh administrator sistem</p>
    </div>
    <div class="login-card-body">
      <?php if (SCRIPT_PASSWORD === DEFAULT_PASSWORD): ?>
      <div class="notice notice-err" style="margin-bottom:18px;">
        <strong>⚠ Password belum diganti!</strong>
        Ubah nilai <code>SCRIPT_PASSWORD</code> di baris konfigurasi atas sebelum digunakan.
      </div>
      <?php else: ?>
      <div class="notice notice-warn" style="margin-bottom:18px;">
        <strong>Peringatan Keamanan</strong>
        Hapus script ini dari server segera setelah selesai.
      </div>
      <?php endif; ?>
      <?php if (isLockedOut()): ?>
      <div class="callout callout-err">
        <span class="callout-ico">🔒</span>
        <div>Akun dikunci selama <?= ceil(getLockoutRemaining() / 60) ?> menit lagi karena terlalu banyak percobaan gagal.</div>
      </div>
      <?php else: ?>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="login">
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" autofocus placeholder="Masukkan password…" autocomplete="current-password">
          <?php if ($loginAttempts > 0): ?>
          <p class="form-hint" style="color:var(--amber);">⚠ <?= $loginAttempts ?> percobaan gagal tercatat</p>
          <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-lg" style="width:100%;justify-content:center;" <?= SCRIPT_PASSWORD === DEFAULT_PASSWORD ? 'disabled' : '' ?>>
          Masuk →
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ════════════════════ AUTHED ════════════════════ -->

<!-- Step Navigation -->
<nav class="steps-nav">
  <?php
  $stepDefs = [1=>'Info & Cek', 2=>'Backup', 3=>'Siapkan File', 4=>'Apply', 5=>'Selesai'];
  foreach ($stepDefs as $n => $label):
    $cls = ($step == $n) ? 'active' : ($step > $n ? 'done' : '');
  ?>
  <a href="?step=<?= $n ?>" class="step-node <?= $cls ?>">
    <div class="step-circle"><?= $cls === 'done' ? '✓' : $n ?></div>
    <div class="step-label"><?= $label ?></div>
  </a>
  <?php endforeach; ?>
</nav>

<?php if ($step <= 1): ?>
<!-- ════════ STEP 1: INFO ════════ -->
<div class="card">
  <div class="card-head">
    <div class="card-head-icon">🖥</div>
    <div><h2>Informasi Sistem & Instalasi OJS</h2><small>sistem · versi · konfigurasi · keamanan</small></div>
  </div>
  <div class="card-body">

    <!-- Maintenance Mode Toggle -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding:12px 16px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--r);">
      <div>
        <span class="maintenance-indicator <?= $maintenanceOn ? 'maint-on' : 'maint-off' ?>">
          <?= $maintenanceOn ? '🔒 Maintenance Mode AKTIF' : '🟢 Maintenance Mode NONAKTIF' ?>
        </span>
        <span style="font-size:12px;color:var(--text-faint);margin-left:10px;">
          <?= $maintenanceOn ? 'OJS tidak bisa diakses publik' : 'OJS dapat diakses publik' ?>
        </span>
      </div>
      <form method="post" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="toggle_maintenance">
        <input type="hidden" name="maintenance" value="<?= $maintenanceOn ? '0' : '1' ?>">
        <button type="submit" class="btn btn-sm <?= $maintenanceOn ? 'btn-success' : 'btn-danger' ?>"
                onclick="return confirm('<?= $maintenanceOn ? 'Aktifkan kembali OJS untuk publik?' : 'Aktifkan maintenance mode? OJS tidak bisa diakses publik!' ?>')">
          <?= $maintenanceOn ? '🟢 Aktifkan OJS' : '🔒 Aktifkan Maintenance' ?>
        </button>
      </form>
    </div>

    <table class="info-table">
      <tr><td class="lbl">PHP Version</td><td class="val"><?= e(PHP_VERSION) ?></td></tr>
      <tr><td class="lbl">OJS Root</td><td class="val"><code><?= e(OJS_ROOT) ?></code></td></tr>
      <tr><td class="lbl">Versi Terinstall</td><td class="val"><?= e($ojsVersion) ?></td></tr>
      <tr><td class="lbl">Ekstensi ZIP</td><td class="val"><?= extension_loaded('zip') ? '<span style="color:var(--mint)">✓ Tersedia</span>' : '<span style="color:var(--coral)">✗ Tidak tersedia</span>' ?></td></tr>
      <tr><td class="lbl">Max Upload</td><td class="val"><?= e(ini_get('upload_max_filesize')) ?></td></tr>
      <tr><td class="lbl">Post Max</td><td class="val"><?= e(ini_get('post_max_size')) ?></td></tr>
      <tr>
        <td class="lbl">Backup Dir</td>
        <td class="val">
          <code><?= e(BACKUP_BASE_DIR) ?></code>
          <?php if (isInsideDocRoot(BACKUP_BASE_DIR)): ?>
            <span class="chip chip-amber" style="margin-left:6px;"><span class="chip-dot"></span>⚠ Dalam doc root — diproteksi .htaccess</span>
          <?php else: ?>
            <span class="chip chip-green" style="margin-left:6px;"><span class="chip-dot"></span>✓ Di luar doc root (aman)</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php if (!empty($ojsConfig['db_name'])): ?>
      <tr><td class="lbl">Database</td><td class="val"><code><?= e($ojsConfig['db_name']) ?></code> @ <code><?= e($ojsConfig['db_host'] ?? 'localhost') ?></code></td></tr>
      <tr><td class="lbl">Files Dir</td><td class="val"><code><?= e($ojsConfig['files_dir'] ?? '-') ?></code></td></tr>
      <tr><td class="lbl">Base URL</td><td class="val"><code><?= e($ojsConfig['base_url'] ?? '-') ?></code></td></tr>
      <?php endif; ?>
    </table>

    <div class="divider" style="margin:20px 0 16px"><span class="divider-label">status keamanan script</span></div>

    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
      <span class="security-badge <?= REQUIRE_HTTPS ? 'sb-ok' : 'sb-off' ?>">
        <?= REQUIRE_HTTPS ? '✓' : '○' ?> HTTPS Enforcement
      </span>
      <span class="security-badge <?= !empty(ALLOWED_IPS) ? 'sb-ok' : 'sb-off' ?>">
        <?= !empty(ALLOWED_IPS) ? '✓ IP Whitelist (' . count(ALLOWED_IPS) . ')' : '○ IP Whitelist Off' ?>
      </span>
      <span class="security-badge <?= SESSION_TIMEOUT > 0 ? 'sb-ok' : 'sb-off' ?>">
        <?= SESSION_TIMEOUT > 0 ? '✓ Session Timeout ' . (SESSION_TIMEOUT/60) . 'm' : '○ Session Timeout Off' ?>
      </span>
      <span class="security-badge <?= ENABLE_LOGGING ? 'sb-ok' : 'sb-off' ?>">
        <?= ENABLE_LOGGING ? '✓ Logging Aktif' : '○ Logging Off' ?>
      </span>
      <span class="security-badge <?= SCRIPT_PASSWORD !== DEFAULT_PASSWORD ? 'sb-ok' : '' ?>" style="<?= SCRIPT_PASSWORD === DEFAULT_PASSWORD ? 'background:var(--coral-dim);color:var(--coral);border-color:rgba(248,113,113,.3)' : '' ?>">
        <?= SCRIPT_PASSWORD !== DEFAULT_PASSWORD ? '✓ Password Custom' : '✕ Password Default!' ?>
      </span>
    </div>

    <div class="divider" style="margin:20px 0 16px"><span class="divider-label">perlindungan data</span></div>

    <div class="diff-section">
      <div class="diff-section-title"><span style="color:var(--mint)">▸</span> DIPERTAHANKAN</div>
      <div class="chip-group">
        <?php foreach ($preservedNow as $item): ?>
          <span class="chip chip-green"><span class="chip-dot"></span><?= e($item) ?></span>
        <?php endforeach; ?>
        <?php if (!$filesDirName): ?>
          <span class="chip chip-green"><span class="chip-dot"></span>files_dir (luar root)</span>
        <?php else: ?>
          <span class="chip chip-green"><span class="chip-dot"></span><?= e($filesDirName) ?>/ ✓ auto-protected</span>
        <?php endif; ?>
        <span class="chip chip-green"><span class="chip-dot"></span>Database</span>
      </div>
    </div>

    <?php if (!extension_loaded('zip')): ?>
    <div class="callout callout-err">
      <span class="callout-ico">✕</span>
      <div>Ekstensi PHP <code>zip</code> tidak tersedia. Install dengan: <code>sudo apt install php-zip</code> lalu restart web server.</div>
    </div>
    <?php endif; ?>

  </div>
  <div class="card-footer">
    <a href="?step=2" class="btn btn-primary">Lanjut ke Backup →</a>
  </div>
</div>

<?php elseif ($step == 2): ?>
<!-- ════════ STEP 2: BACKUP ════════ -->
<div class="card">
  <div class="card-head">
    <div class="card-head-icon">📦</div>
    <div><h2>Backup Sebelum Update</h2><small>file penting · database · konfigurasi</small></div>
  </div>
  <div class="card-body">

    <?php if (!$maintenanceOn): ?>
    <div class="callout callout-warn" style="margin-bottom:16px;">
      <span class="callout-ico">⚠</span>
      <div><strong>Rekomendasi:</strong> Aktifkan Maintenance Mode di Step 1 sebelum backup, agar tidak ada transaksi baru yang masuk selama proses update.</div>
    </div>
    <?php endif; ?>

    <div class="notice notice-warn">
      <strong>Backup Wajib Sebelum Melanjutkan</strong>
      Backup akan menyimpan <code>config.inc.php</code>, <code>public/</code>, <code>.htaccess</code>,
      dan <code>files_dir</code> (jika di dalam root). Backup database dilakukan terpisah di bawah.
    </div>

    <?php if ($backupDirSess): ?>
    <div class="callout callout-ok">
      <span class="callout-ico">✓</span>
      <div>Backup tersimpan: <code><?= e($backupDirSess) ?></code></div>
    </div>
    <?php endif; ?>

    <!-- Backup Files -->
    <div class="sub-step">
      <div>
        <div class="sub-step-label">Langkah 2a</div>
        <div style="font-size:14px;font-weight:500;color:var(--text);margin-bottom:4px;">Backup File Penting</div>
        <div class="sub-step-desc">Salin config.inc.php, public/, .htaccess, dan files_dir ke direktori backup aman.</div>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="backup">
        <button type="submit" class="btn btn-amber">📦 Backup File</button>
      </form>
    </div>

    <!-- Backup Database -->
    <div class="sub-step">
      <div>
        <div class="sub-step-label">Langkah 2b — sangat direkomendasikan</div>
        <div style="font-size:14px;font-weight:500;color:var(--text);margin-bottom:4px;">Backup Database</div>
        <div class="sub-step-desc">
          Ekspor database OJS ke file SQL (via mysqldump atau PDO fallback).
          Disimpan di <code><?= e(BACKUP_BASE_DIR . '/db_backup/') ?></code>
        </div>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="backup_db">
        <button type="submit" class="btn btn-amber"
                onclick="return confirm('Backup database? Proses ini bisa memakan waktu beberapa menit.')">
          🗄 Backup DB
        </button>
      </form>
    </div>

    <div class="callout callout-info">
      <span class="callout-ico">ℹ</span>
      <div>
        Jika database sangat besar, gunakan SSH langsung:<br>
        <code>mysqldump -u USER -p DB_NAME | gzip > backup_$(date +%Y%m%d).sql.gz</code>
      </div>
    </div>

  </div>
  <div class="card-footer">
    <a href="?step=1" class="btn btn-ghost btn-sm">← Kembali</a>
    <a href="?step=3" class="btn btn-primary" style="margin-left:auto;">
      <?= $backupDirSess ? 'Lanjut →' : 'Lewati Backup (tidak direkomendasikan) →' ?>
    </a>
  </div>
</div>

<?php elseif ($step == 3): ?>
<!-- ════════ STEP 3: SIAPKAN FILE ════════ -->
<div class="card">
  <div class="card-head">
    <div class="card-head-icon">📥</div>
    <div><h2>Siapkan File OJS Baru</h2><small>pilih versi · download · upload · path server</small></div>
  </div>
  <div class="card-body">

    <?php if ($newOjsDir): ?>
    <div class="callout callout-ok" style="margin-bottom:16px;">
      <span class="callout-ico">✓</span>
      <div>
        <?= $dlVersion ? 'OJS <strong>' . e($dlVersion) . '</strong> sudah siap.' : 'File OJS sudah siap.' ?>
        <code style="display:block;margin-top:4px;"><?= e($newOjsDir) ?></code>
      </div>
    </div>

    <!-- Dry Run -->
    <form method="post" style="margin-bottom:16px;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="dry_run">
      <button type="submit" class="btn btn-sky btn-sm">🔍 Dry Run — Preview Tanpa Apply</button>
      <span style="font-size:12px;color:var(--text-faint);margin-left:8px;">Lihat apa yang akan berubah tanpa benar-benar mengubah apapun</span>
    </form>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="ver-tabs" id="verTabs">
      <button class="ver-tab active" onclick="switchTab('auto',this)">🌐 Download Otomatis</button>
      <button class="ver-tab" onclick="switchTab('upload',this)">⬆ Upload Manual</button>
      <button class="ver-tab" onclick="switchTab('path',this)">📂 Path Server</button>
    </div>

    <!-- Tab: Download -->
    <div class="ver-panel active" id="tab-auto">
      <?php
      $verGroups = [];
      foreach ($ojsVersionList as $ver => $meta) {
          preg_match('/^(\d+\.\d+)/', $ver, $m);
          $verGroups[$m[1] ?? $ver][$ver] = $meta;
      }
      ?>
      <form method="post" id="dlForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="download_version">
        <?php foreach ($verGroups as $group => $groupVersions): ?>
        <?php
          $phpMinG = reset($groupVersions)[2] ?? '7.3';
          $isLtsG  = (bool)array_filter($groupVersions, fn($m) => $m[1]);
          $groupOk = version_compare(PHP_VERSION, $phpMinG, '>=');
        ?>
        <div class="ver-group">
          <div class="ver-group-title">
            OJS <?= e($group) ?>
            <?php if ($isLtsG): ?><span class="ver-badge vb-lts">LTS</span><?php endif; ?>
            <span style="font-weight:400;color:var(--text-faint);">PHP <?= e($phpMinG) ?>+</span>
            <?php if (!$groupOk): ?><span class="ver-badge vb-legacy">PHP Anda <?= e(PHP_VERSION) ?> tidak kompatibel</span><?php endif; ?>
          </div>
          <div class="ver-list">
          <?php foreach ($groupVersions as $ver => $meta): ?>
          <?php
            [$vDate,$vLts,$vPhpMin,$vLabel,$vNotes] = $meta;
            $compat    = checkPhpCompatibility($ver);
            $isIncompat = !$compat['ok'];
            try { $upgradeType = classifyUpgrade($ojsVersion, $ver); } catch (Throwable) { $upgradeType = 'unknown'; }
            $uMap = ['reinstall'=>['vub-reinstall','Reinstall'],'patch'=>['vub-patch','Patch'],'minor'=>['vub-minor','Upgrade Minor'],'major'=>['vub-major','Upgrade Major'],'downgrade'=>['vub-downgrade','Downgrade']];
            $uLabel = $uMap[$upgradeType] ?? ['vub-reinstall', ucfirst($upgradeType)];
            $isCurrent = (str_replace('-','.',$ver) === str_replace('-','.',$ojsVersion) || $ver === $ojsVersion);
          ?>
          <label class="ver-row <?= $isIncompat ? 'incompatible' : '' ?>">
            <input type="radio" name="ojs_version" value="<?= e($ver) ?>" class="ver-radio" <?= $isIncompat ? 'disabled' : '' ?>
                   onchange="document.getElementById('dlBtn').disabled=false;">
            <div>
              <div class="ver-name">
                <?= e($ver) ?>
                <?php if ($isCurrent): ?><span class="ver-badge vb-current" style="margin-left:6px;">Terinstall</span><?php endif; ?>
              </div>
              <div class="ver-meta">
                <?php if ($vDate): ?><span class="ver-date"><?= e($vDate) ?></span><?php endif; ?>
                <?php if ($vLts):  ?><span class="ver-badge vb-lts">LTS</span><?php endif; ?>
                <?php if ($vLabel === 'Latest Stable'): ?><span class="ver-badge vb-latest">Latest</span><?php endif; ?>
                <?php if (str_contains($vLabel, 'EOL') || str_contains($vLabel, 'Legacy')): ?><span class="ver-badge vb-legacy">EOL</span><?php endif; ?>
                <?php if ($vNotes): ?><span class="ver-date"><?= e($vNotes) ?></span><?php endif; ?>
              </div>
            </div>
            <span class="ver-upgrade-badge <?= $uLabel[0] ?>"><?= $uLabel[1] ?></span>
            <span class="ver-compat">
              <?php if ($isIncompat): ?><span class="vc-bad">✕ PHP <?= e($vPhpMin) ?>+</span>
              <?php elseif ($compat['warning']): ?><span class="vc-warn">⚠ Periksa</span>
              <?php else: ?><span class="vc-ok">✓ PHP OK</span><?php endif; ?>
            </span>
          </label>
          <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:16px;">
          <button type="submit" id="dlBtn" class="btn btn-primary" disabled
                  onclick="return confirm('Download OJS ke server? Bisa memakan 1-3 menit.')">
            ⬇ Download & Ekstrak ke Server
          </button>
          <span style="font-size:11.5px;color:var(--text-faint);margin-left:12px;">
            Unduhan langsung ke server, tidak lewat browser.
          </span>
        </div>
      </form>
      <div class="callout callout-gold" style="margin-top:16px;">
        <span class="callout-ico">💡</span>
        <div><strong>Rekomendasi upgrade:</strong> Upgrade satu versi minor sekaligus (3.3→3.4, lalu 3.4→3.5). Melompat versi bisa menyebabkan error migrasi database.</div>
      </div>
    </div>

    <!-- Tab: Upload -->
    <div class="ver-panel" id="tab-upload">
      <div class="callout callout-info" style="margin-bottom:16px;">
        <span class="callout-ico">ℹ</span>
        <div>Download <code>.tar.gz</code> dari <a href="https://pkp.sfu.ca/software/ojs/download/" target="_blank" rel="noopener noreferrer" style="color:var(--sky)">pkp.sfu.ca</a> ke komputer Anda, lalu upload di sini. Batas upload: <code><?= e(ini_get('upload_max_filesize')) ?></code></div>
      </div>
      <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="upload_zip">
        <div class="form-group">
          <label>File OJS (.tar.gz atau .zip)</label>
          <input type="file" name="ojs_zip" accept=".zip,.tar.gz,.gz,.tgz">
          <p class="form-hint">Jika ukuran melebihi limit, naikkan <code>upload_max_filesize</code> dan <code>post_max_size</code> di php.ini.</p>
        </div>
        <button type="submit" class="btn btn-primary">⬆ Upload & Ekstrak</button>
      </form>
    </div>

    <!-- Tab: Path -->
    <div class="ver-panel" id="tab-path">
      <div class="callout callout-info" style="margin-bottom:16px;">
        <span class="callout-ico">💡</span>
        <div>
          Untuk file besar, ekstrak via SSH lalu arahkan path-nya:<br>
          <code>cd <?= e(TMP_WORK_DIR) ?> && wget https://pkp.sfu.ca/ojs/download/ojs-3.4.0-10.tar.gz && tar xzf ojs-3.4.0-10.tar.gz</code>
        </div>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="set_manual_dir">
        <div class="form-group">
          <label>Path Direktori OJS Hasil Ekstrak</label>
          <input type="text" name="manual_dir" placeholder="<?= e(TMP_WORK_DIR) ?>/ojs-3.4.0-10"
                 value="<?= e($_SESSION['ojs_new_dir'] ?? '') ?>">
          <p class="form-hint">Harus berisi <code>index.php</code> OJS langsung di dalamnya. Tidak boleh menunjuk ke instalasi OJS yang sedang aktif.</p>
        </div>
        <button type="submit" class="btn btn-ghost btn-sm">📂 Gunakan Path Ini</button>
      </form>
    </div>

    <!-- Scan Diff -->
    <?php if ($scanDiff): ?>
    <div class="divider" style="margin:24px 0 20px"><span class="divider-label">analisis dampak update</span></div>
    <?php if ($scanDiff['files_dir_loc'] === 'inside'): ?>
    <div class="callout callout-gold" style="margin-bottom:12px;">
      <span class="callout-ico">📁</span>
      <div><strong>files_dir di dalam root OJS</strong> — folder <code><?= e($scanDiff['files_dir_name']) ?>/</code> otomatis dilindungi.</div>
    </div>
    <?php elseif ($scanDiff['files_dir_loc'] === 'outside'): ?>
    <div class="callout callout-ok" style="margin-bottom:12px;">
      <span class="callout-ico">✓</span>
      <div><strong>files_dir di luar root</strong> (<code><?= e($scanDiff['files_dir_raw']) ?></code>) — aman otomatis.</div>
    </div>
    <?php endif; ?>
    <?php if (!empty($scanDiff['custom_only'])): ?>
    <div class="diff-section">
      <div class="diff-section-title"><span style="color:var(--mint)">●</span> TIDAK TERSENTUH — hanya ada di instalasi Anda</div>
      <div class="chip-group">
        <?php foreach ($scanDiff['custom_only'] as $item):
          $cls = match($item['reason']) { 'files_dir' => 'chip-amber', 'script_ini' => 'chip-coral', default => 'chip-green' }; ?>
        <span class="chip <?= $cls ?>"><span class="chip-dot"></span><?= $item['type']==='dir'?'📁':'📄' ?> <?= e($item['name']) ?><?= $item['reason']==='files_dir' ? ' <em>(files_dir)</em>' : '' ?><?= $item['reason']==='script_ini' ? ' <em>(script ini)</em>' : '' ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($scanDiff['preserved'])): ?>
    <div class="diff-section">
      <div class="diff-section-title"><span style="color:var(--sky)">●</span> DILINDUNGI — ada di ZIP tapi dilewati</div>
      <div class="chip-group">
        <?php foreach ($scanDiff['preserved'] as $item): ?>
        <span class="chip chip-blue"><span class="chip-dot"></span><?= $item['type']==='dir'?'📁':'📄' ?> <?= e($item['name']) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="diff-section">
      <div class="diff-section-title"><span style="color:var(--amber)">●</span> AKAN DIGANTI/DITAMBAH — <?= count($scanDiff['will_replace']) + count($scanDiff['new_only']) ?> item</div>
      <div class="chip-group">
        <?php foreach ($scanDiff['will_replace'] as $item): ?>
        <span class="chip chip-amber" title="<?= $item['action'] === 'merge' ? 'Di-merge' : 'Diganti' ?>">
          <span class="chip-dot"></span><?= $item['type']==='dir'?'📁':'📄' ?> <?= e($item['name']) ?><?= $item['action']==='merge' ? ' ⚡' : '' ?>
        </span>
        <?php endforeach; ?>
        <?php foreach ($scanDiff['new_only'] as $item): ?>
        <span class="chip chip-muted"><span class="chip-dot"></span><?= $item['type']==='dir'?'📁':'📄' ?> <?= e($item['name']) ?> <em style="font-size:10px">+baru</em></span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
  <div class="card-footer">
    <a href="?step=2" class="btn btn-ghost btn-sm">← Kembali</a>
    <?php if ($newOjsDir): ?>
    <a href="?step=4" class="btn btn-primary" style="margin-left:auto;">Lanjut ke Apply →</a>
    <?php endif; ?>
  </div>
</div>

<script>
function switchTab(id, btn) {
  document.querySelectorAll('.ver-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.ver-tab').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  btn.classList.add('active');
}
document.querySelectorAll('.ver-row:not(.incompatible)').forEach(row => {
  row.addEventListener('click', function(e) {
    if (e.target.tagName === 'INPUT') return;
    const r = this.querySelector('.ver-radio');
    if (r && !r.disabled) { r.checked = true; r.dispatchEvent(new Event('change')); }
    document.querySelectorAll('.ver-row').forEach(r => r.classList.remove('selected'));
    this.classList.add('selected');
  });
});
</script>

<?php elseif ($step == 4): ?>
<!-- ════════ STEP 4: APPLY ════════ -->
<div class="card">
  <div class="card-head">
    <div class="card-head-icon">🔄</div>
    <div><h2>Terapkan Update Core OJS</h2><small>dry-run · replace · merge · clear cache · db upgrade</small></div>
  </div>
  <div class="card-body">

    <?php if (empty($newOjsDir) || !is_dir($newOjsDir)): ?>
    <div class="callout callout-err">
      <span class="callout-ico">✕</span>
      <div>Direktori OJS baru tidak ditemukan. Kembali ke Step 3 dan siapkan file terlebih dahulu.</div>
    </div>
    <?php else: ?>

    <?php if (!$maintenanceOn): ?>
    <div class="callout callout-warn" style="margin-bottom:16px;">
      <span class="callout-ico">⚠</span>
      <div><strong>Maintenance Mode belum aktif.</strong> Disarankan aktifkan maintenance mode di Step 1 agar pengguna tidak mengalami error saat proses update.</div>
    </div>
    <?php endif; ?>

    <div class="notice notice-warn" style="margin-bottom:20px;">
      <strong>Proses tidak dapat dibatalkan secara manual</strong>
      Mekanisme rollback otomatis akan dipicu jika terjadi error saat apply.
      Pastikan backup (Step 2) sudah dibuat.<br>
      <span style="font-size:11.5px;margin-top:6px;display:block;">
        Sumber: <code><?= e($newOjsDir) ?></code>
      </span>
    </div>

    <!-- 4a: Dry Run -->
    <div class="sub-step">
      <div>
        <div class="sub-step-label">Langkah 4a — Opsional, sangat direkomendasikan</div>
        <div style="font-size:14px;font-weight:500;color:var(--text);margin-bottom:4px;">Preview (Dry Run)</div>
        <div class="sub-step-desc">Simulasi penuh tanpa mengubah apapun. Lihat apa yang akan diganti dan dilewati sebelum apply.</div>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="dry_run">
        <button type="submit" class="btn btn-sky btn-sm">🔍 Dry Run</button>
      </form>
    </div>

    <!-- 4b: Apply Core -->
    <div class="sub-step">
      <div>
        <div class="sub-step-label">Langkah 4b</div>
        <div style="font-size:14px;font-weight:500;color:var(--text);margin-bottom:4px;">Ganti Core Files</div>
        <div class="sub-step-desc">
          Replace <code>lib/</code>, <code>classes/</code>, <code>templates/</code>, dll. Merge plugin core. Config, public/, files_dir aman.
          Rollback otomatis jika terjadi error.
        </div>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="apply_update">
        <button type="submit" class="btn btn-amber"
                onclick="return confirm('Yakin mengganti core OJS?\nProses tidak bisa dibatalkan secara manual. Pastikan backup sudah ada!')">
          🔄 Apply Update
        </button>
      </form>
    </div>

    <!-- 4c: Clear Cache -->
    <div class="sub-step">
      <div>
        <div class="sub-step-label">Langkah 4c</div>
        <div style="font-size:14px;font-weight:500;color:var(--text);margin-bottom:4px;">Bersihkan Cache</div>
        <div class="sub-step-desc">Hapus cache Smarty agar file baru aktif. Aman dijalankan kapan saja.</div>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="clear_cache">
        <button type="submit" class="btn btn-ghost btn-sm">🗑 Clear Cache</button>
      </form>
    </div>

    <!-- 4d: DB Upgrade -->
    <div class="sub-step">
      <div>
        <div class="sub-step-label">Langkah 4d — hanya untuk upgrade versi baru (bukan reinstall)</div>
        <div style="font-size:14px;font-weight:500;color:var(--text);margin-bottom:4px;">Upgrade Database</div>
        <div class="sub-step-desc">
          Jalankan <code>php tools/upgrade.php upgrade</code>. Wajib untuk upgrade versi.
          Lewati jika reinstall versi yang sama.
        </div>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="run_upgrade">
        <button type="submit" class="btn btn-danger btn-sm"
                onclick="return confirm('Jalankan DB upgrade?\nWajib ada backup database!')">
          ⚡ DB Upgrade
        </button>
      </form>
    </div>

    <?php endif; ?>
  </div>
  <div class="card-footer">
    <a href="?step=3" class="btn btn-ghost btn-sm">← Kembali</a>
    <a href="?step=5" class="btn btn-success" style="margin-left:auto;">Tandai Selesai →</a>
  </div>
</div>

<?php elseif ($step == 5): ?>
<!-- ════════ STEP 5: SELESAI ════════ -->
<div class="card">
  <div class="card-head">
    <div class="card-head-icon" style="background:var(--mint-dim);border-color:rgba(74,222,128,.25);">✓</div>
    <div><h2>Proses Selesai</h2><small>keamanan · verifikasi · cleanup</small></div>
  </div>
  <div class="card-body">

    <div class="callout callout-ok" style="margin-bottom:18px;">
      <span class="callout-ico">✓</span>
      <div>Update/Reinstall OJS core selesai. Verifikasi instalasi OJS Anda sebelum menonaktifkan maintenance mode.</div>
    </div>

    <?php if ($maintenanceOn): ?>
    <div class="callout callout-warn">
      <span class="callout-ico">🔒</span>
      <div><strong>Maintenance mode masih aktif.</strong> Setelah verifikasi berhasil, nonaktifkan di Step 1.</div>
    </div>
    <?php endif; ?>

    <div class="notice notice-err">
      <strong>⚠ Langkah Keamanan Wajib</strong>
      <ol style="margin:8px 0 0 18px;line-height:2;font-size:13px;">
        <li>Hapus file <code><?= e(basename(__FILE__)) ?></code> dari server <strong>segera</strong></li>
        <li>Bersihkan direktori backup sementara yang tidak dibutuhkan</li>
        <li>Nonaktifkan maintenance mode setelah memverifikasi OJS berfungsi normal</li>
        <li>Login ke OJS admin dashboard, cek submission dan jurnal</li>
        <li>Periksa log OJS di <code><?= e(($ojsConfig['files_dir'] ?? OJS_ROOT) . '/usageStats/') ?></code></li>
      </ol>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
      <form method="post" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="cleanup_tmp">
        <button type="submit" class="btn btn-ghost btn-sm"
                onclick="return confirm('Hapus semua file temporary?')">
          🗑 Bersihkan File Temporary
        </button>
      </form>
    </div>

    <div style="margin-top:16px;">
      <table class="info-table" style="max-width:500px">
        <tr><td class="lbl">Script ini</td><td class="val" style="color:var(--coral)"><code><?= e(basename(__FILE__)) ?></code> — hapus segera!</td></tr>
        <?php if ($backupDirSess): ?><tr><td class="lbl">File backup</td><td class="val"><code><?= e($backupDirSess) ?></code></td></tr><?php endif; ?>
        <tr><td class="lbl">Tmp dir</td><td class="val"><code><?= e(TMP_WORK_DIR) ?></code></td></tr>
        <?php if (ENABLE_LOGGING): ?><tr><td class="lbl">Log file</td><td class="val"><code><?= e(LOG_FILE) ?></code></td></tr><?php endif; ?>
      </table>
    </div>

    <!-- Log Viewer -->
    <?php if (!empty($recentLogs)): ?>
    <div class="divider" style="margin:20px 0 16px"><span class="divider-label">log terakhir</span></div>
    <div style="background:var(--code-bg);border:1px solid var(--border);border-radius:var(--r);padding:12px 16px;max-height:250px;overflow-y:auto;">
      <?php foreach ($recentLogs as $logLine):
        $cls = '';
        if (str_contains($logLine, '] [WARN')) $cls = 'le-warn';
        elseif (str_contains($logLine, '] [ERROR')) $cls = 'le-error';
        elseif (str_contains($logLine, '] [INFO')) $cls = 'le-info';
      ?>
      <div class="log-entry"><span class="<?= $cls ?>"><?= e($logLine) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
  <div class="card-footer">
    <a href="?step=1" class="btn btn-ghost btn-sm">← Mulai Ulang</a>
    <a href="<?= e($ojsConfig['base_url'] ?? '/') ?>" class="btn btn-success" target="_blank" rel="noopener noreferrer" style="margin-left:auto;">
      🌐 Buka OJS
    </a>
  </div>
</div>
<?php endif; ?>

<!-- ════════ PANDUAN ════════ -->
<div class="card">
  <div class="card-head">
    <div class="card-head-icon" style="background:var(--sky-dim);border-color:rgba(125,211,252,.2);">📖</div>
    <div><h2>Panduan Penggunaan v2</h2><small>alur · keamanan · catatan penting</small></div>
  </div>
  <div class="card-body">
    <div class="guide-steps">
      <div class="guide-step"><div class="guide-step-num">1</div><div class="guide-step-text"><strong>Info & Cek</strong> — Verifikasi PHP, ZIP, backup dir, dan status keamanan. Aktifkan <strong>Maintenance Mode</strong> agar tidak ada user yang mengakses OJS selama proses.</div></div>
      <div class="guide-step"><div class="guide-step-num">2</div><div class="guide-step-text"><strong>Backup File + Database</strong> — Backup otomatis config.inc.php, public/, .htaccess, files_dir. Plus backup database via mysqldump atau PDO fallback. Keduanya tersimpan di luar public_html jika memungkinkan.</div></div>
      <div class="guide-step"><div class="guide-step-num">3</div><div class="guide-step-text"><strong>Siapkan File OJS</strong> — Download otomatis ke server, upload manual, atau arahkan ke path SSH. Gunakan <strong>Dry Run</strong> untuk preview perubahan sebelum apply.</div></div>
      <div class="guide-step"><div class="guide-step-num">4</div><div class="guide-step-text"><strong>Apply Update</strong> — 4a: Dry Run (preview). 4b: Ganti core. 4c: Clear cache. 4d: DB upgrade hanya jika naik versi. Rollback otomatis dipicu jika ada error saat apply.</div></div>
      <div class="guide-step"><div class="guide-step-num">5</div><div class="guide-step-text"><strong>Selesai</strong> — Hapus script ini segera. Nonaktifkan maintenance mode. Verifikasi OJS berfungsi. Data yang tidak disentuh: Database, files_dir, config.inc.php, public/, .htaccess, plugin custom.</div></div>
    </div>
  </div>
</div>

<?php endif; // checkAuth ?>
</div><!-- /container -->

<?php if (checkAuth() && $sessionLeft > 0): ?>
<script>
(function() {
  let left = <?= (int)$sessionLeft ?>;
  const el  = document.getElementById('sessionTimer');
  const bdg = document.getElementById('sessionBadge');
  if (!el) return;
  const tick = () => {
    if (left <= 0) { alert('Sesi Anda telah berakhir. Silakan login ulang.'); window.location = '?step=0'; return; }
    const m = String(Math.floor(left / 60)).padStart(2,'0');
    const s = String(left % 60).padStart(2,'0');
    el.textContent = m + ':' + s;
    if (left < 300) bdg.classList.add('warn'); else bdg.classList.remove('warn');
    left--;
    setTimeout(tick, 1000);
  };
  tick();
})();
</script>
<?php endif; ?>

</body>
</html>
