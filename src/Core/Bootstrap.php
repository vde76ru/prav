<?php
namespace App\Core;

/**
 * ЭКСТРЕННЫЙ УПРОЩЕННЫЙ Bootstrap
 * Устраняет проблемы с циклическими зависимостями
 */
class Bootstrap 
{
    private static bool $initialized = false;
    
    public static function init(): void 
    {
        if (self::$initialized) {
            return; // Просто возвращаем, не бросаем исключение
        }
        
        self::$initialized = true;
        
        try {
            // 1. Базовая настройка PHP
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
            ini_set('error_log', '/var/log/php/error.log');
            
            // 2. Временная зона
            date_default_timezone_set('Europe/Moscow');
            
            // 3. Запуск сессии ПРОСТЫМ способом
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            
            // 4. НЕ инициализируем сложные компоненты автоматически
            // Они будут инициализированы по требованию
            
        } catch (\Exception $e) {
            error_log("Bootstrap error: " . $e->getMessage());
            throw $e;
        }
    }
    
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }
}