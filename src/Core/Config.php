<?php
namespace App\Core;

/**
 * ЭКСТРЕННЫЙ УПРОЩЕННЫЙ Config
 * Без автоматической загрузки и циклических зависимостей
 */
class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    /**
     * Получить значение конфигурации
     */
    public static function get(string $key, $default = null)
    {
        if (!self::$loaded) {
            self::loadBasic(); // Загружаем только базовые настройки
        }

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Загрузка ТОЛЬКО базовой конфигурации БД
     */
    private static function loadBasic(): void
    {
        if (self::$loaded) {
            return;
        }

        // ПРЯМОЕ указание конфигурации для экстренного запуска
        self::$config = [
            'database' => [
                'mysql' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'user' => 'adminkjg',
                    'password' => 'adQw67Ffl',
                    'database' => 'mysql7648xk-yrsuqk7klp9fujg',
                    'charset' => 'utf8mb4'
                ]
            ],
            'app' => [
                'debug' => true,
                'timezone' => 'Europe/Moscow'
            ]
        ];

        self::$loaded = true;
    }

    /**
     * Полная загрузка из файлов (вызывается вручную при необходимости)
     */
    public static function loadFull(): void
    {
        $configPath = '/etc/vdestor/config';
        
        if (is_dir($configPath)) {
            // Загружаем database.ini
            $dbFile = $configPath . '/database.ini';
            if (file_exists($dbFile)) {
                $dbConfig = parse_ini_file($dbFile, true);
                if ($dbConfig) {
                    self::$config['database'] = $dbConfig;
                }
            }
            
            // Загружаем app.ini
            $appFile = $configPath . '/app.ini';
            if (file_exists($appFile)) {
                $appConfig = parse_ini_file($appFile, true);
                if ($appConfig) {
                    self::$config['app'] = $appConfig;
                }
            }
        }
        
        self::$loaded = true;
    }

    public static function all(): array
    {
        if (!self::$loaded) {
            self::loadBasic();
        }
        return self::$config;
    }
}