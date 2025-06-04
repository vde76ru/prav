<?php
declare(strict_types=1);

// Загружаем autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Единственная инициализация
try {
    \App\Core\Bootstrap::init();
} catch (\Exception $e) {
    error_log("Bootstrap failed: " . $e->getMessage());
    http_response_code(500);
    die('System temporarily unavailable');
}

// Импорты
use App\Core\Router;
use App\Controllers\{
    LoginController,
    AdminController,
    CartController,
    SpecificationController,
    ProductController,
    ApiController,
    DiagnosticsController
};
use App\Middleware\AuthMiddleware;

// Создаем роутер и контроллеры
$router = new Router();

$apiController = new ApiController();
$productController = new ProductController();
$loginController = new LoginController();
$adminController = new AdminController();
$cartController = new CartController();
$specController = new SpecificationController();
$diagnosticsController = new DiagnosticsController();

// === API МАРШРУТЫ ===
$router->get('/api/test', [$apiController, 'testAction']);
$router->get('/api/availability', [$apiController, 'availabilityAction']);
$router->get('/api/search', [$apiController, 'searchAction']);
$router->get('/api/autocomplete', [$apiController, 'autocompleteAction']);
$router->get('/api/product/{id}/info', [$productController, 'ajaxProductInfoAction']);

// === ДИАГНОСТИКА (для админов) ===
$router->get('/api/monitoring/check', [$diagnosticsController, 'runAction']);

// === АВТОРИЗАЦИЯ ===
$router->match(['GET', 'POST'], '/login', [$loginController, 'loginAction']);
$router->get('/logout', function() {
    \App\Services\AuthService::destroySession();
    header('Location: /login');
    exit;
});

// === АДМИН ПАНЕЛЬ ===
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

// === КОРЗИНА ===
$router->match(['GET', 'POST'], '/cart/add', [$cartController, 'addAction']);
$router->get('/cart', [$cartController, 'viewAction']);
$router->post('/cart/update', [$cartController, 'updateAction']);
$router->post('/cart/clear', [$cartController, 'clearAction']);
$router->post('/cart/remove', [$cartController, 'removeAction']);
$router->get('/cart/json', [$cartController, 'getJsonAction']);
$router->get('/cart/count', [$cartController, 'getCountAction']);

// === СПЕЦИФИКАЦИИ ===
$router->match(['GET', 'POST'], '/specification/create', function() use ($specController) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        AuthMiddleware::handle();
    }
    $specController->createAction();
});

$router->get('/specification/{id}', [$specController, 'viewAction']);

$router->get('/specifications', function() use ($specController) {
    AuthMiddleware::handle();
    $specController->listAction();
});

// === МАГАЗИН ===
$router->get('/shop/product', [$productController, 'viewAction']);
$router->get('/shop/product/{id}', [$productController, 'viewAction']);
$router->get('/shop', function() {
    \App\Core\Layout::render('shop/index', []);
});

// === ГЛАВНАЯ ===
$router->get('/', function() {
    \App\Core\Layout::render('home/index', []);
});

// === ДОПОЛНИТЕЛЬНЫЕ СТРАНИЦЫ ===
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

// === 404 ===
$router->set404(function() {
    http_response_code(404);
    
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

// === ЗАПУСК ===
try {
    $router->dispatch();
} catch (\Exception $e) {
    \App\Core\Logger::error("Router error", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === 0;
    
    if ($isApi) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error'
        ]);
    } else {
        http_response_code(500);
        \App\Core\Layout::render('errors/500', [
            'message' => 'Внутренняя ошибка сервера'
        ]);
    }
}