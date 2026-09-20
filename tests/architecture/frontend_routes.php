<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routesPath = $root . '/symfony/config/routes.yaml';
if (!is_file($routesPath)) {
    throw new RuntimeException('Canonical Symfony route configuration is missing.');
}
$routes = (string) file_get_contents($routesPath);

foreach ([
    'cos_symfony_home:',
    'cos_web_cabinet:',
    'cos_web_cabinet_submission_retired:',
    'cos_web_sales_root:',
    'cos_web_sales_dashboard:',
    'cos_web_sales_today:',
    'cos_web_sales_pipeline:',
    'cos_web_sales_leads:',
    'cos_web_sales_deals:',
    'cos_web_sales_deal:',
    'cos_web_sales_director:',
    'cos_web_sales_admin:',
    'cos_api_v1_public_properties:',
    'cos_api_v1_public_properties_featured:',
    'cos_api_v1_public_property:',
    'cos_web_robots:',
    'cos_web_sitemap:',
    'cos_web_blog_index:',
    'cos_web_blog_article:',
    'cos_web_guide:',
    'cos_web_public_analytics:',
    'cos_web_n8n_content_webhook:',
] as $route) {
    if (!str_contains($routes, $route)) {
        throw new RuntimeException('Canonical Symfony route is missing: ' . $route);
    }
}

foreach ([
    'app/Interfaces/Web/Routing/FrontendRoutes.php',
    'app/config/routes.php',
    'app/bootstrap_web.php',
    'app/Interfaces/Web/Module.php',
] as $retired) {
    if (file_exists($root . '/' . $retired)) {
        throw new RuntimeException('Retired Phalcon route composition returned: ' . $retired);
    }
}

foreach ([
    '/api/property/favourites',
    'path: /client-case',
    '/cos/control-center',
    '/api/property/:action',
    '/api/sales/',
    '/api/health',
] as $legacyPath) {
    if (str_contains($routes, $legacyPath)) {
        throw new RuntimeException('Legacy route leaked into Symfony route configuration: ' . $legacyPath);
    }
}

$legacyModulesDir = $root . '/app/modules';
if (is_dir($legacyModulesDir)) {
    throw new RuntimeException('Legacy app/modules directory must not be restored.');
}

echo "Frontend route declaration contract passed on Symfony-only routing.\n";
