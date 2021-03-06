<?php

/**
 * Function library read in upon startup
 *
 * Release: lib.inc.php,v 1.123 2008/04/06 01:10:35 xzilla Exp $
 */

defined('BASE_PATH') or define('BASE_PATH', dirname(__DIR__));

define('THEME_PATH', BASE_PATH . '/assets/themes');
// Enforce PHP environment
ini_set('arg_separator.output', '&amp;');

if (!is_writable(BASE_PATH . '/temp')) {
    die('Your temp folder must have write permissions (use chmod 777 temp -R on linux)');
}

// Check to see if the configuration file exists, if not, explain
if (file_exists(BASE_PATH . '/config.inc.php')) {
    $conf = [];
    include BASE_PATH . '/config.inc.php';
} else {
    die('Configuration error: Copy config.inc.php-dist to config.inc.php and edit appropriately.');
}

if (isset($conf['error_log'])) {
    ini_set('error_log', BASE_PATH . '/' . $conf['error_log']);
}

$debugmode = (!isset($conf['debugmode'])) ? false : boolval($conf['debugmode']);
define('DEBUGMODE', $debugmode);

require_once BASE_PATH . '/vendor/autoload.php';

if (!defined('ADODB_ERROR_HANDLER_TYPE')) {
    define('ADODB_ERROR_HANDLER_TYPE', E_USER_ERROR);
}

if (!defined('ADODB_ERROR_HANDLER')) {
    define('ADODB_ERROR_HANDLER', '\PHPPgAdmin\ADOdbException::adodb_throw');
}

// Start session (if not auto-started)
if (!ini_get('session.auto_start')) {
    session_name('PPA_ID');
    session_start();
}

function maybeRenderIframes($c, $response, $subject, $query_string)
{
    $in_test = $c->view->offsetGet('in_test');

    if ($in_test === '1') {
        $className  = '\PHPPgAdmin\Controller\\' . ucfirst($subject) . 'Controller';
        $controller = new $className($c);
        return $controller->render();
    }

    $viewVars = [
        'url'            => '/src/views/' . $subject . ($query_string ? '?' . $query_string : ''),
        'headertemplate' => 'header.twig',
    ];

    return $c->view->render($response, 'iframe_view.twig', $viewVars);
};

$handler             = PhpConsole\Handler::getInstance();
\Kint::$enabled_mode = DEBUGMODE;
ini_set('display_errors', intval(DEBUGMODE));
ini_set('display_startup_errors', intval(DEBUGMODE));
if (DEBUGMODE) {
    error_reporting(E_ALL);
}

if (isset($_SERVER['HTTP_USER_AGENT'])) {
    $useragent = $_SERVER['HTTP_USER_AGENT'];
    if (strpos($useragent, 'Chrome') === false) {
        $conf['php_console'] = false;
    }
}

if (!isset($conf['php_console']) || $conf['php_console'] !== true) {
    $handler->setHandleErrors(false); // disable errors handling
    $handler->setHandleExceptions(false); // disable exceptions handling
    $handler->setCallOldHandlers(true); // disable passing errors & exceptions to prviously defined handlers
}

$composerinfo = json_decode(file_get_contents(BASE_PATH . '/composer.json'));
$appVersion   = $composerinfo->version;

$config = [
    'msg'       => '',
    'appThemes' => [
        'default'    => 'Default',
        'cappuccino' => 'Cappuccino',
        'gotar'      => 'Blue/Green',
        'bootstrap'  => 'Bootstrap3',
    ],
    'settings'  => [
        'displayErrorDetails'               => DEBUGMODE,
        'determineRouteBeforeAppMiddleware' => true,
        'base_path'                         => BASE_PATH,
        'debug'                             => DEBUGMODE,

        'routerCacheFile'                   => BASE_PATH . '/temp/route.cache.php',

        // Configuration file version.  If this is greater than that in config.inc.php, then
        // the app will refuse to run.  This and $conf['version'] should be incremented whenever
        // backwards incompatible changes are made to config.inc.php-dist.
        'base_version'                      => 60,
        // Application version
        'appVersion'                        => 'v' . $appVersion,
        // Application name
        'appName'                           => 'phpPgAdmin6',

        // PostgreSQL and PHP minimum version
        'postgresqlMinVer'                  => '9.3',
        'phpMinVer'                         => '5.6',
        'displayErrorDetails'               => DEBUGMODE,
        'addContentLengthHeader'            => false,
    ],
];

$app = new \Slim\App($config);

// Fetch DI Container
$container = $app->getContainer();

if ($container instanceof \Psr\Container\ContainerInterface) {
    \PhpConsole\Helper::register(); // it will register global PC class
    if (PHP_SAPI == 'cli-server') {
        $subfolder = '/index.php';
    } elseif (isset($conf['subfolder']) && is_string($conf['subfolder'])) {
        $subfolder = $conf['subfolder'];
    } else {
        $normalized_php_self = str_replace('/src/views', '', $container->environment->get('PHP_SELF'));
        $subfolder           = str_replace('/' . basename($normalized_php_self), '', $normalized_php_self);
    }
    define('SUBFOLDER', $subfolder);
} else {
    trigger_error("App Container must be an instance of \Psr\Container\ContainerInterface", E_USER_ERROR);
}

$container['version']     = 'v' . $appVersion;
$container['errors']      = [];
$container['requestobj']  = $container['request'];
$container['responseobj'] = $container['response'];
$container['php_console'] = $handler;

// This should be deprecated once we're sure no php scripts are required directly
$container->offsetSet('server', isset($_REQUEST['server']) ? $_REQUEST['server'] : null);
$container->offsetSet('database', isset($_REQUEST['database']) ? $_REQUEST['database'] : null);
$container->offsetSet('schema', isset($_REQUEST['schema']) ? $_REQUEST['schema'] : null);

$container['flash'] = function () {
    return new \Slim\Flash\Messages();
};

$container['utils'] = function ($c) {
    $utils = new \PHPPgAdmin\ContainerUtils($c);
    return $utils;
};

$container['conf'] = function ($c) use ($conf) {

    //\Kint::dump($conf);
    // Plugins are removed
    $conf['plugins'] = [];

    foreach ($conf['servers'] as &$server) {
        if (!isset($server['port'])) {
            $server['port'] = 5432;
        }
        if (!isset($server['sslmode'])) {
            $server['sslmode'] = 'unspecified';
        }
    }
    return $conf;
};

$container['lang'] = function ($c) {
    $translations = new \PHPPgAdmin\Translations($c);

    return $translations->lang;
};

$container['plugin_manager'] = function ($c) {
    $plugin_manager = new \PHPPgAdmin\PluginManager($c);
    return $plugin_manager;
};

$container['serializer'] = function ($c) {
    $serializerbuilder = \JMS\Serializer\SerializerBuilder::create();
    $serializer        = $serializerbuilder
        ->setCacheDir(BASE_PATH . '/temp/jms')
        ->setDebug($c->get('settings')['debug'])
        ->build();
    return $serializer;
};

// Create Misc class references
$container['misc'] = function ($c) {
    $misc = new \PHPPgAdmin\Misc($c);

    $conf = $c->get('conf');

    // 4. Check for theme by server/db/user
    $_server_info = $misc->getServerInfo();

    //\PC::debug($_server_info, 'server info');

    /* starting with PostgreSQL 9.0, we can set the application name */
    if (isset($_server_info['pgVersion']) && $_server_info['pgVersion'] >= 9) {
        putenv('PGAPPNAME=' . $c->get('settings')['appName'] . '_' . $c->get('settings')['appVersion']);
    }

    $themefolders = [];
    if ($gestor = opendir(THEME_PATH)) {

        /* This is the right way to iterate on a folder */
        while (false !== ($foldername = readdir($gestor))) {
            if ($foldername == '.' || $foldername == '..') {
                continue;
            }

            $folderpath = THEME_PATH . DIRECTORY_SEPARATOR . $foldername;

            // if $folderpath if indeed a folder and contains a global.css file, then it's a theme
            if (is_dir($folderpath) && is_file($folderpath . DIRECTORY_SEPARATOR . 'global.css')) {
                $themefolders[$foldername] = $folderpath;
            }
        }

        closedir($gestor);
    }

    //\PC::debug($themefolders, 'themefolders');
    /* select the theme */
    unset($_theme);

    // List of themes
    if (!isset($conf['theme'])) {
        $conf['theme'] = 'default';
    }
    // 1. Check for the theme from a request var.
    // This happens when you use the selector in the intro screen
    if (isset($_REQUEST['theme']) && array_key_exists($_REQUEST['theme'], $themefolders)) {
        $_theme = $_REQUEST['theme'];
    } elseif (!isset($_theme) && isset($_SESSION['ppaTheme']) && array_key_exists($_SESSION['ppaTheme'], $themefolders)) {
        // 2. Check for theme session var
        $_theme = $_SESSION['ppaTheme'];
    } elseif (!isset($_theme) && isset($_COOKIE['ppaTheme']) && array_key_exists($_COOKIE['ppaTheme'], $themefolders)) {
        // 3. Check for theme in cookie var
        $_theme = $_COOKIE['ppaTheme'];
    }

    if (!isset($_theme) && !is_null($_server_info) && array_key_exists('theme', $_server_info)) {
        $server_theme = $_server_info['theme'];

        if (isset($server_theme['default']) && array_key_exists($server_theme['default'], $themefolders)) {
            $_theme = $server_theme['default'];
        }

        if (isset($_REQUEST['database'])
            && isset($server_theme['db'][$_REQUEST['database']])
            && array_key_exists($server_theme['db'][$_REQUEST['database']], $themefolders)
        ) {
            $_theme = $server_theme['db'][$_REQUEST['database']];
        }

        if (isset($_server_info['username'])
            && isset($server_theme['user'][$_server_info['username']])
            && array_key_exists($server_theme['user'][$_server_info['username']], $themefolders)
        ) {
            $_theme = $server_theme['user'][$_server_info['username']];
        }
    }
    // if any of the above conditions had set the $_theme variable
    // then we store it in the session and a cookie
    // and we overwrite $conf['theme'] with its value
    if (isset($_theme)) {
        /* save the selected theme in cookie for a year */
        setcookie('ppaTheme', $_theme, time() + 31536000, '/');
        $_SESSION['ppaTheme'] = $_theme;
        $conf['theme']        = $_theme;
    }

    $misc->setConf('theme', $conf['theme']);

    return $misc;
};

// Register Twig View helper
$container['view'] = function ($c) {
    $conf = $c->get('conf');
    $misc = $c->misc;

    $view = new \Slim\Views\Twig(BASE_PATH . '/assets/templates', [
        'cache'       => BASE_PATH . '/temp/twigcache',
        'auto_reload' => $c->get('settings')['debug'],
        'debug'       => $c->get('settings')['debug'],
    ]);
    $environment              = $c->get('environment');
    $base_script_trailing_str = substr($environment['SCRIPT_NAME'], 1);
    $request_basepath         = $c['request']->getUri()->getBasePath();
    // Instantiate and add Slim specific extension
    $basePath = rtrim(str_ireplace($base_script_trailing_str, '', $request_basepath), '/');

    $view->addExtension(new Slim\Views\TwigExtension($c['router'], $basePath));

    $view->offsetSet('subfolder', SUBFOLDER);
    $view->offsetSet('theme', $c->misc->getConf('theme'));
    $view->offsetSet('Favicon', $c->misc->icon('Favicon'));
    $view->offsetSet('Introduction', $c->misc->icon('Introduction'));
    $view->offsetSet('lang', $c->lang);

    $view->offsetSet('applangdir', $c->lang['applangdir']);

    $view->offsetSet('appName', $c->get('settings')['appName']);

    $misc->setView($view);

    //\PC::debug($c->conf, 'conf');
    //\PC::debug($c->view->offsetGet('subfolder'), 'subfolder');
    //\PC::debug($c->view->offsetGet('theme'), 'theme');

    return $view;
};

$container['haltHandler'] = function ($c) {
    return function ($request, $response, $exits, $status = 500) use ($c) {
        $title = 'PHPPgAdmin Error';

        $html = '<p>The application could not run because of the following error:</p>';

        $output = sprintf(
            "<html><head><meta http-equiv='Content-Type' content='text/html; charset=utf-8'>" .
            '<title>%s</title><style>' .
            'body{margin:0;padding:30px;font:12px/1.5 Helvetica,Arial,Verdana,sans-serif;}' .
            'h3{margin:0;font-size:28px;font-weight:normal;line-height:30px;}' .
            'span{display:inline-block;font-size:16px;}' .
            '</style></head><body><h3>%s</h3><p>%s</p><span>%s</span></body></html>',
            $title,
            $title,
            $html,
            implode('<br>', $exits)
        );

        $body = $response->getBody(); //new \Slim\Http\Body(fopen('php://temp', 'r+'));
        $body->write($output);

        return $response
            ->withStatus($status)
            ->withHeader('Content-type', 'text/html')
            ->withBody($body);
    };
};

// Set the requestobj and responseobj properties of the container
// as the value of $request and $response, which already contain the route
$app->add(
    function ($request, $response, $next) use ($handler) {
        $handler->start(); // initialize handlers*/

        $this['requestobj']  = $request;
        $this['responseobj'] = $response;

        $this['server']   = $request->getParam('server');
        $this['database'] = $request->getParam('database');
        $this['schema']   = $request->getParam('schema');
        $misc             = $this->get('misc');

        $misc->setHREF();
        $misc->setForm();

        $this->view->offsetSet('METHOD', $request->getMethod());
        if ($request->getAttribute('route')) {
            $this->view->offsetSet('subject', $request->getAttribute('route')->getArgument('subject'));
        }

        $query_string = $request->getUri()->getQuery();
        $this->view->offsetSet('query_string', $query_string);
        $path = (SUBFOLDER ? (SUBFOLDER . '/') : '') . $request->getUri()->getPath() . ($query_string ? '?' . $query_string : '');
        $this->view->offsetSet('path', $path);

        $params = $request->getParams();

        $viewparams = [];

        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $viewparams[$key] = $value;
            }
        }

        if (isset($_COOKIE['IN_TEST'])) {
            $in_test = (string) $_COOKIE['IN_TEST'];
        } else {
            $in_test = '0';
        }

        // remove tabs and linebreaks from query
        if (isset($params['query'])) {
            $viewparams['query'] = str_replace(["\r", "\n", "\t"], ' ', $params['query']);
        }
        $this->view->offsetSet('params', $viewparams);
        $this->view->offsetSet('in_test', $in_test);

        if (count($this['errors']) > 0) {
            return ($this->haltHandler)($this->requestobj, $this->responseobj, $this['errors'], 412);
        }

        $messages = $this->flash->getMessages();
        if (!empty($messages)) {
            foreach ($messages as $key => $message) {
                \PC::debug($message, 'Flash: ' . $key);
            }
        }
        // First execute anything else
        $response = $next($request, $response);

        // Any other request, pass on current response
        return $response;
    }
);

$container['action'] = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

if (!isset($msg)) {
    $msg = '';
}

$container['msg'] = $msg;
