<?php
namespace App\Core;

/**
 * Унифицированный менеджер сессий
 * Исправляет конфликты между DB и файловыми сессиями
 */
class Session
{
    private static bool $started = false;
    private static array $config = [];
    
    /**
     * Единая точка запуска сессий
     */
    public static function start(): void
    {
        // Защита от повторного запуска
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        
        // Проверяем, не отправлены ли уже заголовки
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Cannot start session, headers already sent in $file:$line");
        }

        try {
            // Загружаем конфигурацию сессий
            self::loadConfig();
            
            // Настраиваем параметры сессии
            self::configureSession();
            
            // Настраиваем обработчик сессий
            self::setupHandler();
            
            // Запускаем сессию
            if (!session_start()) {
                throw new \RuntimeException("Failed to start session");
            }
            
            // Проверяем безопасность сессии
            self::validateSecurity();
            
            self::$started = true;
            
        } catch (\Exception $e) {
            error_log("Session start failed: " . $e->getMessage());
            // Fallback на файловые сессии
            self::fallbackToFiles();
        }
    }
    
    /**
     * Загрузка конфигурации сессий
     */
    private static function loadConfig(): void
    {
        // Получаем конфигурацию из Config
        self::$config = [
            'save_handler' => Config::get('app.session.save_handler', 'files'),
            'gc_maxlifetime' => Config::get('app.session.gc_maxlifetime', 1800),
            'name' => Config::get('app.session.name', 'VDE_SESSION'),
            'cookie_secure' => Config::get('app.session.cookie_secure', true),
            'cookie_httponly' => Config::get('app.session.cookie_httponly', true),
            'cookie_samesite' => Config::get('app.session.cookie_samesite', 'Lax'),
            'cookie_domain' => Config::get('app.session.cookie_domain', ''),
        ];
    }
    
    /**
     * Настройка параметров сессии
     */
    private static function configureSession(): void
    {
        // Устанавливаем имя сессии
        session_name(self::$config['name']);
        
        // Параметры безопасности
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string)self::$config['gc_maxlifetime']);
        
        // Параметры cookie
        $domain = self::$config['cookie_domain'];
        if (empty($domain)) {
            $domain = $_SERVER['HTTP_HOST'] ?? '';
        }
        
        session_set_cookie_params([
            'lifetime' => self::$config['gc_maxlifetime'],
            'path' => '/',
            'domain' => $domain,
            'secure' => self::$config['cookie_secure'],
            'httponly' => self::$config['cookie_httponly'],
            'samesite' => self::$config['cookie_samesite']
        ]);
    }
    
    /**
     * Настройка обработчика сессий
     */
    private static function setupHandler(): void
    {
        $handler = self::$config['save_handler'];
        
        switch ($handler) {
            case 'db':
                self::setupDatabaseHandler();
                break;
                
            case 'files':
            default:
                self::setupFileHandler();
                break;
        }
    }
    
    /**
     * Настройка DB обработчика
     */
    private static function setupDatabaseHandler(): void
    {
        try {
            $pdo = Database::getConnection();
            $handler = new DBSessionHandler($pdo, self::$config['gc_maxlifetime']);
            session_set_save_handler($handler, true);
        } catch (\Exception $e) {
            error_log("Database session handler failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Настройка файлового обработчика
     */
    private static function setupFileHandler(): void
    {
        // Определяем путь для сессий
        $sessionPath = Config::get('app.session.save_path', '/var/www/www-root/data/mod-tmp');
        
        // Создаем директорию если не существует
        if (!is_dir($sessionPath)) {
            if (!@mkdir($sessionPath, 0755, true)) {
                $sessionPath = sys_get_temp_dir();
            }
        }
        
        // Проверяем права записи
        if (!is_writable($sessionPath)) {
            $sessionPath = sys_get_temp_dir();
        }
        
        ini_set('session.save_handler', 'files');
        ini_set('session.save_path', $sessionPath);
    }
    
    /**
     * Fallback на файловые сессии при ошибке
     */
    private static function fallbackToFiles(): void
    {
        try {
            ini_set('session.save_handler', 'files');
            ini_set('session.save_path', sys_get_temp_dir());
            
            if (!session_start()) {
                throw new \RuntimeException("Even file session fallback failed");
            }
            
            self::$started = true;
            error_log("Session started with file fallback");
            
        } catch (\Exception $e) {
            throw new \RuntimeException("Session initialization completely failed: " . $e->getMessage());
        }
    }
    
    /**
     * Проверка безопасности сессии
     */
    private static function validateSecurity(): void
    {
        $now = time();
        
        // Проверка времени неактивности
        if (isset($_SESSION['LAST_ACTIVITY'])) {
            $inactive = $now - $_SESSION['LAST_ACTIVITY'];
            if ($inactive > self::$config['gc_maxlifetime']) {
                self::destroy();
                session_start();
                return;
            }
        }
        $_SESSION['LAST_ACTIVITY'] = $now;
        
        // Проверка fingerprint
        $fingerprint = self::generateFingerprint();
        if (!isset($_SESSION['FINGERPRINT'])) {
            $_SESSION['FINGERPRINT'] = $fingerprint;
        } elseif ($_SESSION['FINGERPRINT'] !== $fingerprint) {
            error_log("Session fingerprint mismatch detected");
            self::destroy();
            session_start();
            return;
        }
        
        // Регенерация ID сессии каждые 30 минут
        if (!isset($_SESSION['REGENERATED'])) {
            $_SESSION['REGENERATED'] = $now;
        } elseif ($now - $_SESSION['REGENERATED'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['REGENERATED'] = $now;
        }
    }
    
    /**
     * Генерация отпечатка браузера
     */
    private static function generateFingerprint(): string
    {
        $data = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            // Используем только первые 3 октета IP для защиты от смены IP в подсети
            implode('.', array_slice(explode('.', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 3))
        ];
        
        return hash('sha256', implode('|', $data));
    }
    
    /**
     * Полное уничтожение сессии
     */
    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Очищаем данные сессии
            $_SESSION = [];
            
            // Удаляем cookie сессии
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'] ?? '',
                    $params['secure'],
                    $params['httponly']
                );
            }
            
            // Уничтожаем сессию
            session_destroy();
            self::$started = false;
        }
    }
    
    /**
     * Регенерация ID сессии
     */
    public static function regenerate(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $result = session_regenerate_id(true);
            if ($result) {
                $_SESSION['REGENERATED'] = time();
            }
            return $result;
        }
        return false;
    }
    
    /**
     * Получить ID текущей сессии
     */
    public static function getId(): string
    {
        return session_id();
    }
    
    /**
     * Проверить активна ли сессия
     */
    public static function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && self::$started;
    }
    
    /**
     * Получить информацию о сессии
     */
    public static function getInfo(): array
    {
        return [
            'id' => session_id(),
            'name' => session_name(),
            'status' => session_status(),
            'started' => self::$started,
            'handler' => self::$config['save_handler'] ?? 'unknown',
            'save_path' => session_save_path(),
            'cookie_params' => session_get_cookie_params(),
            'last_activity' => $_SESSION['LAST_ACTIVITY'] ?? null,
            'regenerated' => $_SESSION['REGENERATED'] ?? null
        ];
    }
    
    /**
     * Установить значение в сессию
     */
    public static function set(string $key, $value): void
    {
        if (!self::isActive()) {
            self::start();
        }
        $_SESSION[$key] = $value;
    }
    
    /**
     * Получить значение из сессии
     */
    public static function get(string $key, $default = null)
    {
        if (!self::isActive()) {
            return $default;
        }
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Удалить значение из сессии
     */
    public static function remove(string $key): void
    {
        if (self::isActive() && isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * Проверить существование ключа в сессии
     */
    public static function has(string $key): bool
    {
        return self::isActive() && isset($_SESSION[$key]);
    }
}