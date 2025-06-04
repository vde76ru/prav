<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Cache;
use App\Core\Config;
use App\Services\AuthService;
use OpenSearch\ClientBuilder;

class DiagnosticsController extends BaseController
{
    private array $diagnostics = [];
    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * GET /admin/diagnostics/run - Запустить диагностику
     */
    public function runAction(): void
    {
        // Проверка прав доступа
        if (!AuthService::checkRole('admin')) {
            $this->error('Access denied', 403);
            return;
        }

        try {
            // Запускаем все проверки
            $this->checkSystem();
            $this->checkPHP();
            $this->checkMemory();
            $this->checkFilesystem();
            $this->checkDatabase();
            $this->checkOpenSearch();
            $this->checkCache();
            $this->checkSessions();
            $this->checkQueues();
            $this->checkMetrics();
            $this->checkAPI();
            $this->checkSecurity();
            $this->checkLogs();
            $this->checkProcesses();
            $this->checkCron();
            $this->checkEmail();
            $this->checkIntegrations();

            // Подсчитываем health score
            $healthScore = $this->calculateHealthScore();

            // Формируем итоговый отчет
            $report = [
                'timestamp' => date('c'),
                'health_score' => $healthScore,
                'execution_time' => microtime(true) - $this->startTime,
                'diagnostics' => $this->diagnostics
            ];

            $this->success($report);

        } catch (\Exception $e) {
            Logger::error('Diagnostics failed', ['error' => $e->getMessage()]);
            $this->error('Diagnostics failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Проверка базы данных (ИСПРАВЛЕНО)
     */
    private function checkDatabase(): void
    {
        $data = [
            'title' => '🗄️ База данных',
            'status' => '❌ Error',
            'info' => []
        ];

        try {
            $pdo = Database::getConnection();
            
            // Основная информация
            $version = $pdo->query("SELECT VERSION()")->fetchColumn();
            $data['info']['Version'] = $version;
            
            // Проверяем подключение и получаем имя БД
            $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
            
            // Статус сервера
            $uptime = $pdo->query("SHOW STATUS LIKE 'Uptime'")->fetch();
            $data['info']['Uptime'] = $this->formatUptime($uptime['Value'] ?? 0);
            
            // Активные соединения
            $threads = $pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch();
            $maxConn = $pdo->query("SHOW VARIABLES LIKE 'max_connections'")->fetch();
            $data['info']['Active Connections'] = ($threads['Value'] ?? 0) . ' / ' . ($maxConn['Value'] ?? 100);
            
            // Размер БД - используем правильное имя БД
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(data_length + index_length) as size,
                    COUNT(*) as tables_count
                FROM information_schema.tables 
                WHERE table_schema = ?
            ");
            $stmt->execute([$dbName]);
            $dbInfo = $stmt->fetch();
            
            $data['info']['Database Size'] = $this->formatBytes($dbInfo['size'] ?? 0);
            $data['info']['Tables Count'] = $dbInfo['tables_count'] ?? 0;
            
            // Проверяем наличие важных таблиц
            $requiredTables = [
                'products', 'users', 'carts', 'prices', 'stock_balances',
                'categories', 'brands', 'series', 'cities', 'warehouses',
                'sessions', 'audit_logs', 'application_logs', 'metrics', 
                'job_queue', 'specifications'
            ];
            
            $stmt = $pdo->prepare("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = ? 
                AND table_name IN (" . implode(',', array_fill(0, count($requiredTables), '?')) . ")
            ");
            
            $params = array_merge([$dbName], $requiredTables);
            $stmt->execute($params);
            
            $existingTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $missingTables = array_diff($requiredTables, $existingTables);
            
            $data['info']['Missing Tables'] = count($missingTables) > 0 
                ? implode(', ', $missingTables) 
                : 'None';
            
            // Медленные запросы
            $slowQueries = $pdo->query("SHOW STATUS LIKE 'Slow_queries'")->fetch();
            $data['info']['Slow Queries'] = $slowQueries['Value'] ?? 0;
            
            // Статус - все ОК если нет пропущенных таблиц
            $data['status'] = count($missingTables) === 0 ? '✅ Connected' : '⚠️ Warning';
            
            // Информация о таблицах
            $stmt = $pdo->prepare("
                SELECT 
                    TABLE_NAME,
                    TABLE_ROWS,
                    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb,
                    ENGINE,
                    TABLE_COLLATION
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = ?
                ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
            ");
            $stmt->execute([$dbName]);
            
            $data['tables'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
        } catch (\Exception $e) {
            $data['status'] = '❌ Error';
            $data['error'] = $e->getMessage();
        }

        $this->diagnostics['database'] = $data;
    }

    /**
     * Проверка API endpoints (ИСПРАВЛЕНО)
     */
    private function checkAPI(): void
    {
        $data = [
            'title' => '🌐 API Endpoints',
            'checks' => []
        ];

        $endpoints = [
            'Test API' => '/api/test',
            'Search API' => '/api/search?q=test&limit=1',
            'Availability API' => '/api/availability?product_ids=1&city_id=1',
            'Autocomplete API' => '/api/autocomplete?q=авт&limit=5'
        ];

        foreach ($endpoints as $name => $endpoint) {
            $startTime = microtime(true);
            
            try {
                // Используем полный URL для избежания редиректов
                $url = 'https://vdestor.ru' . $endpoint;
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => false, // Не следовать редиректам
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER => [
                        'Accept: application/json',
                        'X-Requested-With: XMLHttpRequest'
                    ]
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                $responseTime = round((microtime(true) - $startTime) * 1000, 2);
                
                $check = [
                    'endpoint' => $endpoint,
                    'status' => '❌',
                    'http_code' => $httpCode,
                    'response_time' => $responseTime . 'ms'
                ];
                
                if ($error) {
                    $check['error'] = $error;
                } else {
                    // Проверяем, что это JSON
                    $json = json_decode($response, true);
                    if ($httpCode === 200 && $json !== null) {
                        $check['status'] = '✅';
                        $check['response_preview'] = substr($response, 0, 100) . '...';
                    } else {
                        $check['response_preview'] = substr($response, 0, 200) . '...';
                    }
                }
                
                $data['checks'][$name] = $check;
                
            } catch (\Exception $e) {
                $data['checks'][$name] = [
                    'endpoint' => $endpoint,
                    'status' => '❌',
                    'error' => $e->getMessage()
                ];
            }
        }

        $this->diagnostics['api'] = $data;
    }

    /**
     * Проверка OpenSearch (ИСПРАВЛЕНО)
     */
    private function checkOpenSearch(): void
    {
        $data = [
            'title' => '🔍 OpenSearch/Elasticsearch',
            'status' => '❌ Error',
            'info' => []
        ];

        try {
            $client = ClientBuilder::create()
                ->setHosts(['localhost:9200'])
                ->setConnectionParams([
                    'timeout' => 5,
                    'connect_timeout' => 3
                ])
                ->build();

            // Информация о кластере
            $info = $client->info();
            $data['info']['Version'] = $info['version']['number'] ?? 'Unknown';

            // Здоровье кластера
            $health = $client->cluster()->health();
            $data['info']['Cluster Name'] = $health['cluster_name'] ?? 'Unknown';
            $data['info']['Status'] = $health['status'] ?? 'unknown';
            $data['info']['Nodes'] = $health['number_of_nodes'] ?? 0;
            $data['info']['Active Shards'] = $health['active_shards'] ?? 0;

            // Статус
            if ($health['status'] === 'green') {
                $data['status'] = '✅ Healthy';
            } elseif ($health['status'] === 'yellow') {
                $data['status'] = '⚠️ Warning';
            } else {
                $data['status'] = '❌ Critical';
            }

            // Индексы
            try {
                $indices = $client->cat()->indices(['format' => 'json']);
                $data['info']['Indices Count'] = count($indices);
                
                // Проверяем алиас products_current
                try {
                    $aliases = $client->indices()->getAlias(['name' => 'products_current']);
                    $data['info']['Current Alias'] = implode(', ', array_keys($aliases));
                } catch (\Exception $e) {
                    $data['info']['Current Alias'] = 'Not configured';
                }
                
                // Количество документов в основном индексе
                $totalDocs = 0;
                foreach ($indices as $index) {
                    if (strpos($index['index'], 'products') !== false) {
                        $totalDocs += (int)($index['docs.count'] ?? 0);
                    }
                }
                $data['info']['Total Documents'] = $totalDocs;
                
            } catch (\Exception $e) {
                $data['info']['Indices'] = 'Error: ' . $e->getMessage();
            }

        } catch (\Exception $e) {
            $data['error'] = $e->getMessage();
        }

        $this->diagnostics['opensearch'] = $data;
    }

    /**
     * Проверка безопасности (ИСПРАВЛЕНО)
     */
    private function checkSecurity(): void
    {
        $data = [
            'title' => '🔒 Безопасность',
            'checks' => []
        ];

        // Проверка HTTPS
        $data['checks']['HTTPS'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '✅' : '❌';

        // Проверка заголовков безопасности
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        
        $securityHeaders = [
            'X-Content-Type-Options' => 'x-content-type-options',
            'X-Frame-Options' => 'x-frame-options', 
            'X-XSS-Protection' => 'x-xss-protection',
            'Strict-Transport-Security' => 'strict-transport-security'
        ];

        foreach ($securityHeaders as $name => $header) {
            $data['checks'][$name] = isset($headers[$header]) ? '✅' : '❌';
        }

        // Проверка конфигурации
        $configPath = Config::getConfigPath();
        if ($configPath && is_dir($configPath)) {
            $perms = fileperms($configPath) & 0777;
            // 750 или меньше - это нормально для конфига
            $data['checks']['Config Directory Protected'] = ($perms <= 0750) ? '✅' : '❌';
        }

        // Проверка проблем конфигурации
        $data['config_issues'] = Config::validateSecurity();

        $this->diagnostics['security'] = $data;
    }

    /**
     * Вспомогательные методы
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "{$days}d {$hours}h {$minutes}m";
    }

    private function calculateHealthScore(): float
    {
        $totalChecks = 0;
        $passedChecks = 0;

        // Проверяем все диагностики
        foreach ($this->diagnostics as $section => $data) {
            if (isset($data['status'])) {
                $totalChecks++;
                if (strpos($data['status'], '✅') !== false) {
                    $passedChecks++;
                } elseif (strpos($data['status'], '⚠️') !== false) {
                    $passedChecks += 0.5;
                }
            }
            
            if (isset($data['checks'])) {
                foreach ($data['checks'] as $check) {
                    $totalChecks++;
                    if ((is_array($check) && isset($check['status']) && strpos($check['status'], '✅') !== false) ||
                        (is_string($check) && $check === '✅')) {
                        $passedChecks++;
                    }
                }
            }
        }

        return $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 2) : 0;
    }

    // Остальные методы проверки...
    private function checkSystem(): void
    {
        $data = [
            'title' => '🖥️ Информация о системе',
            'data' => [
                'Hostname' => gethostname(),
                'Server IP' => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
                'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'Server Time' => date('Y-m-d H:i:s'),
                'Timezone' => date_default_timezone_get(),
                'OS' => php_uname('s') . ' ' . php_uname('r'),
                'Server Load' => implode(', ', sys_getloadavg()),
                'Uptime' => shell_exec('uptime') ?? 'Unknown'
            ]
        ];
        $this->diagnostics['system'] = $data;
    }

    private function checkPHP(): void
    {
        $data = [
            'title' => '🐘 PHP Конфигурация',
            'checks' => [],
            'extensions' => []
        ];

        // Проверка версий и лимитов
        $checks = [
            'version' => ['current' => PHP_VERSION, 'required' => '7.4.0', 'check' => version_compare(PHP_VERSION, '7.4.0', '>=')],
            'memory_limit' => ['current' => ini_get('memory_limit'), 'required' => '256M', 'check' => true],
            'max_execution_time' => ['current' => ini_get('max_execution_time'), 'required' => '300', 'check' => true],
            'post_max_size' => ['current' => ini_get('post_max_size'), 'required' => '32M', 'check' => true],
            'upload_max_filesize' => ['current' => ini_get('upload_max_filesize'), 'required' => '32M', 'check' => true],
            'session.gc_maxlifetime' => ['current' => ini_get('session.gc_maxlifetime'), 'required' => '1440', 'check' => true],
            'error_reporting' => ['current' => error_reporting(), 'required' => 'E_ALL', 'check' => true],
            'display_errors' => ['current' => ini_get('display_errors'), 'required' => '0 (production)', 'check' => true]
        ];
        $data['checks'] = $checks;

        // Проверка расширений
        $requiredExtensions = ['PDO', 'PDO MySQL', 'JSON', 'cURL', 'Mbstring', 'OpenSSL', 'GD', 'Zip', 'Session', 'Redis', 'APCu'];
        foreach ($requiredExtensions as $ext) {
            $extName = str_replace(' ', '_', strtolower($ext));
            $data['extensions'][$ext] = extension_loaded($extName);
        }

        $this->diagnostics['php'] = $data;
    }

    private function checkMemory(): void
    {
        $data = [
            'title' => '💾 Память и ресурсы',
            'data' => [
                'Current Memory Usage' => $this->formatBytes(memory_get_usage(true)),
                'Peak Memory Usage' => $this->formatBytes(memory_get_peak_usage(true)),
                'Memory Limit' => ini_get('memory_limit'),
                'Memory Usage %' => round((memory_get_usage(true) / $this->parseMemoryLimit(ini_get('memory_limit'))) * 100, 2) . '%',
                'Free System Memory' => $this->formatBytes($this->getFreeMemory()),
                'Total System Memory' => $this->formatBytes($this->getTotalMemory()),
                'CPU Cores' => shell_exec('nproc') ?? 'Unknown'
            ]
        ];
        $this->diagnostics['memory'] = $data;
    }

    private function checkFilesystem(): void
    {
        $data = [
            'title' => '📁 Файловая система',
            'paths' => [],
            'disk' => []
        ];

        $paths = [
            'Root' => $_SERVER['DOCUMENT_ROOT'],
            'Logs' => '/var/log/vdestor',
            'Cache' => '/tmp/vdestor_cache',
            'Config' => Config::getConfigPath() ?? '/etc/vdestor/config',
            'Uploads' => $_SERVER['DOCUMENT_ROOT'] . '/uploads',
            'Assets' => $_SERVER['DOCUMENT_ROOT'] . '/assets/dist',
            'Sessions' => ini_get('session.save_path')
        ];

        foreach ($paths as $name => $path) {
            $info = [
                'path' => $path,
                'exists' => file_exists($path),
                'readable' => is_readable($path),
                'writable' => is_writable($path),
                'permissions' => file_exists($path) ? substr(sprintf('%o', fileperms($path)), -4) : 'N/A',
                'size' => file_exists($path) && is_dir($path) ? $this->getDirectorySize($path) : '0 B',
                'status' => '❌'
            ];

            // Определяем статус
            if ($info['exists'] && $info['readable']) {
                if ($name === 'Config') {
                    // Конфиг должен быть защищен от записи
                    $info['status'] = !$info['writable'] ? '✅' : '❌';
                } else {
                    // Остальные папки должны быть доступны для записи
                    $info['status'] = $info['writable'] ? '✅' : '❌';
                }
            }

            $data['paths'][$name] = $info;
        }

        // Информация о диске
        $data['disk'] = [
            'Total Space' => $this->formatBytes(disk_total_space('/')),
            'Used Space' => $this->formatBytes(disk_total_space('/') - disk_free_space('/')),
            'Free Space' => $this->formatBytes(disk_free_space('/')),
            'Usage %' => round(((disk_total_space('/') - disk_free_space('/')) / disk_total_space('/')) * 100, 2) . '%'
        ];

        $this->diagnostics['filesystem'] = $data;
    }

    private function checkCache(): void
    {
        $data = [
            'title' => '⚡ Кеш система',
            'status' => '❌ Error'
        ];

        try {
            $stats = Cache::getStats();
            $data['stats'] = $stats;
            $data['status'] = $stats['enabled'] ? '✅ Working' : '❌ Disabled';
        } catch (\Exception $e) {
            $data['error'] = $e->getMessage();
        }

        $this->diagnostics['cache'] = $data;
    }

    private function checkSessions(): void
    {
        $data = [
            'title' => '🔐 Сессии',
            'data' => [
                'Handler' => ini_get('session.save_handler'),
                'Save Path' => ini_get('session.save_path'),
                'GC Lifetime' => ini_get('session.gc_maxlifetime') . ' seconds',
                'Current Session ID' => session_id() ?: 'None',
                'Session Status' => session_status() === PHP_SESSION_ACTIVE ? '✅ Active' : '❌ Inactive'
            ]
        ];

        // Количество сессий в БД
        try {
            $count = Database::query("SELECT COUNT(*) FROM sessions")->fetchColumn();
            $data['data']['Sessions in DB'] = $count;
        } catch (\Exception $e) {
            $data['data']['Sessions in DB'] = 'Error';
        }

        $this->diagnostics['sessions'] = $data;
    }

    private function checkQueues(): void
    {
        $data = [
            'title' => '📋 Очереди задач'
        ];

        try {
            $stats = \App\Services\QueueService::getStats();
            $data['stats'] = $stats;
        } catch (\Exception $e) {
            $data['error'] = $e->getMessage();
        }

        $this->diagnostics['queues'] = $data;
    }

    private function checkMetrics(): void
    {
        $data = [
            'title' => '📊 Метрики (последние 24 часа)'
        ];

        try {
            $stats = \App\Services\MetricsService::getStats('day');
            $data['summary'] = $stats['summary'] ?? [];
            $data['performance'] = $stats['performance'] ?? [];
            $data['errors'] = count($stats['errors'] ?? []);
        } catch (\Exception $e) {
            $data['error'] = $e->getMessage();
        }

        $this->diagnostics['metrics'] = $data;
    }

    private function checkLogs(): void
    {
        $data = [
            'title' => '📜 Логи и ошибки',
            'files' => []
        ];

        $logDir = '/var/log/vdestor';
        if (is_dir($logDir)) {
            $files = glob($logDir . '/*.log');
            foreach ($files as $file) {
                $data['files'][] = [
                    'name' => basename($file),
                    'size' => $this->formatBytes(filesize($file)),
                    'modified' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
        }

        // Последние ошибки из БД
        try {
            $stmt = Database::query("
                SELECT level, message, created_at 
                FROM application_logs 
                WHERE level IN ('error', 'critical') 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $data['last_errors'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $data['last_errors'] = [];
        }

        $this->diagnostics['logs'] = $data;
    }

    private function checkProcesses(): void
    {
        $data = [
            'title' => '⚙️ Процессы и сервисы',
            'running' => []
        ];

        // Проверяем процессы
        $processes = [
            'PHP-FPM' => 'ps aux | grep php-fpm | grep -v grep | wc -l',
            'MySQL' => 'ps aux | grep mysql | grep -v grep | wc -l',
            'Nginx' => 'ps aux | grep nginx | grep -v grep | wc -l',
            'Redis' => 'ps aux | grep redis | grep -v grep | wc -l',
            'Queue Workers' => 'ps aux | grep queue:work | grep -v grep | wc -l'
        ];

        foreach ($processes as $name => $cmd) {
            $count = trim(shell_exec($cmd) ?? '0');
            $data['running'][$name] = $count;
        }

        // Детальная информация о PHP процессах
        $data['php_processes'] = shell_exec('ps aux | grep php-fpm | grep -v grep') ?? '';

        $this->diagnostics['processes'] = $data;
    }

    private function checkCron(): void
    {
        $data = [
            'title' => '⏰ Cron задачи'
        ];

        $cronJobs = shell_exec('crontab -l 2>/dev/null | grep -v "^#"') ?? '';
        $data['jobs'] = $cronJobs ?: 'No cron jobs or unable to read';

        $this->diagnostics['cron'] = $data;
    }

    private function checkEmail(): void
    {
        $data = [
            'title' => '📧 Email система',
            'stats' => []
        ];

        try {
            $stmt = Database::query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened,
                    MAX(sent_at) as last_sent
                FROM email_logs
                WHERE sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $emailStats = $stmt->fetch();
            
            $data['stats'] = [
                'Emails sent (7 days)' => $emailStats['total'] ?? 0,
                'Emails opened' => $emailStats['opened'] ?? 0,
                'Last sent' => $emailStats['last_sent'] ?? 'Never',
                'Mail function' => function_exists('mail') ? '✅ Available' : '❌ Not available'
            ];
        } catch (\Exception $e) {
            $data['error'] = $e->getMessage();
        }

        $this->diagnostics['email'] = $data;
    }

    private function checkIntegrations(): void
    {
        $data = [
            'title' => '🔗 Интеграции',
            'services' => []
        ];

        // Проверяем настроенные интеграции
        $integrations = Config::get('integrations', []);
        if (empty($integrations)) {
            $data['services'][] = 'None configured';
        } else {
            foreach ($integrations as $name => $config) {
                $data['services'][] = $name . ': ' . ($config['enabled'] ?? false ? '✅' : '❌');
            }
        }

        $this->diagnostics['integrations'] = $data;
    }

    // Вспомогательные методы
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int)$limit;
        
        switch ($last) {
            case 'g': $value *= 1024 * 1024 * 1024; break;
            case 'm': $value *= 1024 * 1024; break;
            case 'k': $value *= 1024; break;
        }
        
        return $value;
    }

    private function getFreeMemory(): int
    {
        $free = shell_exec("free -b | grep Mem | awk '{print $4}'");
        return (int)trim($free);
    }

    private function getTotalMemory(): int
    {
        $total = shell_exec("free -b | grep Mem | awk '{print $2}'");
        return (int)trim($total);
    }

    private function getDirectorySize(string $path): string
    {
        if (!is_dir($path)) {
            return '0 B';
        }
        
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $this->formatBytes($size);
    }
}