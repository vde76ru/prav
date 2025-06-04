<?php
declare(strict_types=1);

// Критическая обработка ошибок
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Загружаем autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// ЕДИНСТВЕННАЯ инициализация через Bootstrap
try {
    \App\Core\Bootstrap::init();
} catch (\Exception $e) {
    error_log("Critical init error: " . $e->getMessage());
    http_response_code(500);
    die('System temporarily unavailable');
}

// Импорты контроллеров
use App\Core\Router;
use App\Controllers\LoginController;
use App\Controllers\AdminController;
use App\Controllers\CartController;
use App\Controllers\SpecificationController;
use App\Controllers\ProductController;
use App\Controllers\ApiController;
use App\Middleware\AuthMiddleware;

// Создаем роутер
$router = new Router();

// 🔧 ИСПРАВЛЕНО: Создаем экземпляры контроллеров
$apiController = new ApiController();
$productController = new ProductController();
$loginController = new LoginController();
$adminController = new AdminController();
$cartController = new CartController();
$specController = new SpecificationController();

// ===========================================
// 🌐 API МАРШРУТЫ (без middleware в роутере)
// ===========================================
$router->get('/api/test', [$apiController, 'testAction']);
$router->get('/api/availability', [$apiController, 'availabilityAction']);
$router->get('/api/search', [$apiController, 'searchAction']);
$router->get('/api/autocomplete', [$apiController, 'autocompleteAction']);
$router->get('/api/product/{id}/info', [$productController, 'ajaxProductInfoAction']);

// ===========================================
// 🔐 АВТОРИЗАЦИЯ
// ===========================================
$router->match(['GET', 'POST'], '/login', [$loginController, 'loginAction']);
$router->get('/logout', function() {
    \App\Services\AuthService::destroySession();
    header('Location: /login');
    exit;
});

// ===========================================
// 👨‍💼 АДМИН ПАНЕЛЬ (с проверкой прав)
// ===========================================
$router->get('/admin', function() use ($adminController) {
    AuthMiddleware::requireRole('admin');
    $adminController->indexAction();
});

$router->get('/admin/diagnost', function() use ($adminController) {
    AuthMiddleware::requireRole('admin');
    $adminController->diagnosticsAction();
});

$router->get('/admin/documentation', function() use ($adminController) {
    AuthMiddleware::requireRole('admin');
    $adminController->documentationAction();
});

// ===========================================
// 🛒 КОРЗИНА
// ===========================================
$router->match(['GET', 'POST'], '/cart/add', [$cartController, 'addAction']);
$router->get('/cart', [$cartController, 'viewAction']);
$router->post('/cart/update', [$cartController, 'updateAction']);
$router->post('/cart/clear', [$cartController, 'clearAction']);
$router->post('/cart/remove', [$cartController, 'removeAction']);
$router->get('/cart/json', [$cartController, 'getJsonAction']);
$router->get('/cart/count', [$cartController, 'getCountAction']);

// ===========================================
// 📋 СПЕЦИФИКАЦИИ (с проверкой прав)
// ===========================================
$router->match(['GET', 'POST'], '/specification/create', function() use ($specController) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        AuthMiddleware::handle(); // Требуем авторизации для создания
    }
    $specController->createAction();
});

$router->get('/specification/{id}', [$specController, 'viewAction']);

$router->get('/specifications', function() use ($specController) {
    AuthMiddleware::handle(); // Требуем авторизации для просмотра списка
    $specController->listAction();
});

// ===========================================
// 🛍️ МАГАЗИН И ТОВАРЫ
// ===========================================
$router->get('/shop/product', [$productController, 'viewAction']);
$router->get('/shop/product/{id}', [$productController, 'viewAction']); // Используем тот же метод
$router->get('/shop', function() {
    \App\Core\Layout::render('shop/index', []);
});

// ===========================================
// 🏠 ГЛАВНАЯ СТРАНИЦА
// ===========================================
$router->get('/', function() {
    \App\Core\Layout::render('home/index', []);
});

// ===========================================
// 📱 ДОПОЛНИТЕЛЬНЫЕ СТРАНИЦЫ
// ===========================================
$router->get('/calculator', function() {
    \App\Core\Layout::render('tools/calculator', []);
});

$router->get('/history', function() {
    AuthMiddleware::handle();
    \App\Core\Layout::render('user/history', []);
});

$router->get('/profile', function() {
    AuthMiddleware::handle();
    \App\Core\Layout::render('user/profile', []);
});

// ===========================================
// ❌ 404 ОБРАБОТЧИК
// ===========================================
$router->set404(function() {
    http_response_code(404);
    
    // Проверяем тип запроса
    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === 0;
    
    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Endpoint not found',
            'code' => 404
        ]);
    } else {
        \App\Core\Layout::render('errors/404', []);
    }
});

// ===========================================
// 🚀 ЗАПУСК РОУТЕРА
// ===========================================
try {
    $router->dispatch();
} catch (\Exception $e) {
    error_log("Router error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === 0;
    
    if ($isApi) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'error' => 'Internal server error',
            'debug' => [
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ]
        ]);
    } else {
        http_response_code(500);
        \App\Core\Layout::render('errors/500', [
            'message' => 'Внутренняя ошибка сервера',
            'debug' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
    }
}