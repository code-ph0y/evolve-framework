<?php
/**
 * This file is part of the PPI Framework.
 *
 * @copyright  Copyright (c) 2011-2013 Paul Dragoonis <paul@ppi.io>
 * @license    http://opensource.org/licenses/mit-license.php MIT
 * @link       http://www.ppi.io
 */

namespace PPI;

use PPI\Config\ConfigLoader;
use PPI\Debug\ExceptionHandler;
use PPI\ServiceManager\ServiceManagerBuilder;
use Symfony\Component\ClassLoader\DebugClassLoader;
use Symfony\Component\Debug\ErrorHandler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Zend\Stdlib\ArrayUtils;

/**
 * The PPI App bootstrap class.
 *
 * This class sets various app settings, and allows you to override classes used in the bootup process.
 *
 * @author     Paul Dragoonis <paul@ppi.io>
 * @author     Vítor Brandão <vitor@ppi.io> <vitor@noiselabs.org>
 * @package    PPI
 * @subpackage Core
 */
class App implements AppInterface
{
    /**
     * Version string.
     * @var string
     */
    const VERSION = '2.1.0-DEV';

    /**
     * @var boolean
     */
    protected $booted = false;

    /**
     * @var array
     */
    protected $config = array();

    /**
     * @var boolean
     */
    protected $debug;

    /**
     * Application environment: "development" vs "production".
     * @var string
     */
    protected $environment;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Unix timestamp with microseconds.
     * @var float
     */
    protected $startTime;

    /**
     * Configuration loader.
     * @var \PPI\Config\ConfigLoader
     */
    protected $configLoader = null;

    /**
     * The Module Manager.
     * @var \Zend\ModuleManager\ModuleManager
     */
    protected $moduleManager;

    /**
     * @param integer $errorReportingLevel The level of error reporting you want
     */
    protected $errorReportingLevel;

    /**
     * @var null|array
     */
    protected $_matchedRoute = null;

    /**
     * The request object.
     * @var null
     */
    protected $request = null;

    /**
     * The response object.
     * @var null
     */
    protected $response = null;

    /**
     * @var \PPI\Module\Controller\ControllerResolver
     */
    protected $resolver;

    /**
     * @var string
     */
    protected $name;

    /**
     * Path to the application root dir aka the "app" directory.
     * @var null|string
     */
    protected $rootDir = null;

    /**
     * Service Manager.
     * @var \PPI\Module\ServiceManager\ServiceManager
     */
    protected $serviceManager = null;

    /**
     * App constructor.
     *
     * @param array $options
     */
    public function __construct(array $options = array())
    {
        // Default options
        $this->environment = isset($options['environment']) ? $options['environment'] : 'production';
        $this->debug = isset($options['debug']) ? (bool) $options['debug'] : 'debug';
        $this->rootDir = isset($options['root_dir']) ? $options['root_dir'] : $this->getRootDir();
        $this->name = isset($options['name']) ? $options['name'] : $this->getName();

        if ($this->debug) {
            $this->startTime = microtime(true);
            $this->enableDebug();
        } else {
            ini_set('display_errors', 0);
        }

        $this->booted = false;
    }

    /**
     * Set an App option.
     *
     * @param $option
     * @param $value
     * @return $this
     * @throws \RuntimeException
     */
    public function setOption($option, $value)
    {
        if (true === $this->booted) {
            throw new \RuntimeException('Setting App options after boot() is now allowed');
        }

        // "root_dir" to "rootDir"
        $property = preg_replace('/_(.?)/e',"strtoupper('$1')",$option);
        if (!property_exists($this, $property)) {
            throw new \RuntimeException(sprintf('App property "%s" (option "%s") does not exist', $property, $option));
        }

        $this->$property = $value;

        return $this;
    }

    /**
     * Get an App option.
     *
     * @param $option
     * @return string
     * @throws \RuntimeException
     */
    public function getOption($option)
    {
        // "root_dir" to "rootDir"
        $property = preg_replace('/_(.?)/e',"strtoupper('$1')",$option);
        if (!property_exists($this, $property)) {
            throw new \RuntimeException(sprintf('App property "%s" (option "%s") does not exist', $property, $option));
        }

        return $property;
    }

    public function __clone()
    {
        if ($this->debug) {
            $this->startTime = microtime(true);
        }

        $this->booted = false;
        $this->serviceManager = null;
    }

    /**
     * Run the boot process, load our modules and their dependencies.
     *
     * This method is automatically called by dispatch(), but you can use it
     * to build all services when not handling a request.
     *
     * @return $this
     */
    public function boot()
    {
        if (true === $this->booted) {
            return;
        }

        $this->serviceManager = $this->buildServiceManager();
        $this->log('debug', sprintf('Booting %s ...', $this->name));

        // Loading our Modules
        $this->getModuleManager()->loadModules();
        if ($this->debug) {
            $modules = $this->getModuleManager()->getModules();
            $this->log('debug', sprintf('All modules online (%d): "%s"', count($modules), implode('", "', $modules)));
        }

        // Lets get all the services our of our modules and start setting them in the ServiceManager
        $moduleServices = $this->serviceManager->get('ModuleDefaultListener')->getServices();
        foreach ($moduleServices as $key => $service) {
            $this->serviceManager->setFactory($key, $service);
        }

        $this->booted = true;
        if ($this->debug) {
            $this->log('debug', sprintf('%s has booted (in %.3f secs)', $this->name, microtime(true) - $this->startTime));
        }

        return $this;
    }

    /**
     * Decide on a route to use and dispatch our module's controller action.
     *
     * @return \PPI\Http\RequestInterface
     */
    public function dispatch()
    {
        if (false === $this->booted) {
            $this->boot();
        }

        // Routing
        $this->handleRouting();

        // load controller
        $resolver = $this->serviceManager->get('ControllerResolver');
        $request = $this->getRequest();
        if (false === $controller = $resolver->getController($request)) {
            throw new NotFoundHttpException(sprintf('Unable to find the controller for path "%s". Maybe you forgot to add the matching route in your routing configuration?', $request->getPathInfo()));
        }

        // Set the options for our controller
        $controller[0]->setOptions(array(
            'environment' => $this->getEnv()
        ));

        // Lets create the routing helper for the controller, we unset() reserved keys & what's left are route params
        $routeParams = $this->request->attributes->all();
        $activeRoute = $routeParams['_route'];
        $moduleName = $routeParams['_module'];
        $controllerName = $routeParams['_controller'];
        unset($routeParams['_module'], $routeParams['_controller'], $routeParams['_route']);

        // Pass in the routing params, set the active route key
        $routingHelper = $this->serviceManager->get('RoutingHelper');
        $routingHelper
            ->setParams($routeParams)
            ->setActiveRouteName($activeRoute);

        // Register our routing helper into the controller
        $controller[0]->setHelper('routing', $routingHelper);

        // Prep our module for dispatch
        $module = $this->getModuleManager()->getModuleByAlias($moduleName);
        $module
            ->setControllerName($controllerName)
            ->setActionName($controller[1])
            ->setController($controller[0]);

        // Dispatch our action, return the content from the action called.
        $controller = $module->getController();
        $this->serviceManager = $controller->getServiceLocator();
        $result = $module->dispatch();

        switch (true) {
            // If the controller is just returning HTML content then that becomes our body response.
            case is_string($result):
                $response = $controller->getServiceLocator()->get('Response');
                break;

            // The controller action didn't bother returning a value, just grab the response object from SM
            case is_null($result):
                $response = $controller->getServiceLocator()->get('Response');
                break;

            // Anything else is unpredictable so we safely rely on the SM
            default:
                $response = $result;
                break;
        }

        $this->response = $response;
        $this->response->setContent($result);

        return $this->response;
    }

    /**
     * Gets the name of the application.
     *
     * @return string The application name
     *
     * @api
     */
    public function getName()
    {
        if (null === $this->name) {
            $this->name = get_class($this);
        }

        return $this->name;
    }

    /**
     * Gets the version of the application.
     *
     * @return string The application version
     *
     * @api
     */
    public function getVersion()
    {
        return self::VERSION;
    }

    /**
     * Setter for the environment, passing in options determining how the app will behave
     *
     * @param array $options The options
     *
     * @return void
     */
    public function setEnv(array $options)
    {
        // If we pass in a bad sitemode, lets just default to 'development' gracefully.
        if (isset($options['siteMode'])) {
            if (!in_array($options['siteMode'], array('development', 'production'))) {
                unset($options['siteMode']);
            }
        }

        // Any further options passed, eg: it maps; 'errorLevel' to $this->_errorLevel
        foreach ($options as $optionName => $option) {
            $this->_envOptions[$optionName] = $option;
        }
    }

    /**
     * Get the environment mode the application is in.
     *
     * @return string The current environment
     *
     * @api
     */
    public function getEnvironment()
    {
        return $this->environment;
    }

    /**
     * Get the environment mode the application is in.
     *
     * @return string The current environment
     */
    public function getEnv()
    {
        return $this->getEnvironment();
    }

    /**
     * Check if the application is in development mode.
     *
     * @return boolean
     */
    public function isDevMode()
    {
        return $this->getEnvironment() === 'development';
    }

    /**
     * Checks if debug mode is enabled.
     *
     * @return boolean true if debug mode is enabled, false otherwise
     *
     * @api
     */
    public function isDebug()
    {
        return $this->debug;
    }

    /**
     * Gets the application root dir.
     *
     * @return string The application root dir
     *
     * @api
     */
    public function getRootDir()
    {
        if (null === $this->rootDir) {
            $this->rootDir = realpath(getcwd() . '/app');
        }

        return $this->rootDir;
    }

    /**
     * Get the service manager
     *
     * @return ServiceManager\ServiceManager
     */
    public function getServiceManager()
    {
        return $this->serviceManager;
    }

    /**
     * @note Added for compatiblity with Symfony's HttpKernel\Kernel.
     *
     * @return null|Module\ServiceManager\ServiceManager
     */
    public function getContainer()
    {
        return $this->serviceManager;
    }

    /**
     * Returns the Module Manager.
     *
     * @return \Zend\ModuleManager\ModuleManager
     */
    public function getModuleManager()
    {
        if (null === $this->moduleManager) {
            $this->moduleManager = $this->serviceManager->get('ModuleManager');
        }

        return $this->moduleManager;
    }

    /**
     * Get an array of the loaded modules.
     *
     * @return array An array of Module objects, keyed by module name
     */
    public function getModules()
    {
        return $this->getModuleManager()->getLoadedModules(true);
    }

    /**
     * @see PPI\Module\ModuleManager::locateResource()
     *
     * @param string  $name  A resource name to locate
     * @param string  $dir   A directory where to look for the resource first
     * @param Boolean $first Whether to return the first path or paths for all matching bundles
     *
     * @return string|array The absolute path of the resource or an array if $first is false
     *
     * @throws \InvalidArgumentException if the file cannot be found or the name is not valid
     * @throws \RuntimeException         if the name contains invalid/unsafe
     * @throws \RuntimeException         if a custom resource is hidden by a resource in a derived bundle
     */
    public function locateResource($name, $dir = null, $first = true)
    {
        return $this->getModuleManager()->locateResource($name, $dir, $first);
    }

    /**
     * Get the request object
     *
     * @return object
     */
    public function getRequest()
    {
        if (null === $this->request) {
            $this->request = $this->serviceManager->get('Request');
        }

        return $this->request;
    }

    /**
     * Get the response object
     *
     * @return object
     */
    public function getResponse()
    {
        if (null === $this->response) {
            $this->response = $this->serviceManager->get('Response');
        }

        return $this->response;
    }

    /**
     * Gets the request start time (not available if debug is disabled).
     *
     * @return integer The request start timestamp
     *
     * @api
     */
    public function getStartTime()
    {
        return $this->debug ? $this->startTime : -INF;
    }

    /**
     * Gets the cache directory.
     *
     * @return string The cache directory
     *
     * @api
     */
    public function getCacheDir()
    {
        return $this->rootDir.'/cache/'.$this->environment;
    }

    /**
     * Gets the log directory.
     *
     * @return string The log directory
     *
     * @api
     */
    public function getLogDir()
    {
        return $this->rootDir.'/logs';
    }

    /**
     * Gets the charset of the application.
     *
     * @return string The charset
     *
     * @api
     */
    public function getCharset()
    {
        return 'UTF-8';
    }

    /**
     * Returns a ConfigLoader instance.
     *
     * @return \PPI\Config\ConfigLoader
     */
    public function getConfigLoader()
    {
        if (null === $this->configLoader) {
            $this->configLoader = new ConfigLoader($this->rootDir . '/config');
        }

        return $this->configLoader;
    }

    /**
     * Merges configuration.
     */
    public function mergeConfig(array $config)
    {
        $this->config = ArrayUtils::merge($this->config, $config);
    }

    /**
     * Loads a configuration file or PHP array.
     *
     * @param $resource
     * @param  null  $type
     * @return array
     */
    public function loadConfig($resource, $type = null)
    {
        $config = $this->getConfigLoader()->load($resource, $type);
        $this->mergeConfig($config);

        return $config;
    }

    /**
     * Returns the application configuration.
     *
     * @return array|object
     */
    public function getConfig()
    {
        return true === $this->booted ? $this->serviceManager->get('Config') : $this->config;
    }

    public function serialize()
    {
        return serialize(array($this->environment, $this->debug));
    }

    public function unserialize($data)
    {
        list($environment, $debug) = unserialize($data);

        $this->__construct($environment, $debug);
    }

    /**
     * Returns the application parameters.
     *
     * @return array An array of application parameters
     */
    protected function getAppParameters()
    {
        return array_merge(
            array(
                'app.root_dir'        => $this->rootDir,
                'app.environment'     => $this->environment,
                'app.debug'           => $this->debug,
                'app.name'            => $this->name,
                'app.cache_dir'       => $this->getCacheDir(),
                'app.logs_dir'        => $this->getLogDir(),
                'app.charset'         => $this->getCharset(),
            ),
            $this->getEnvParameters()
        );
    }

    /**
     * Gets the environment parameters.
     *
     * Only the parameters starting with "PPI__" are considered.
     *
     * @return array An array of parameters
     */
    protected function getEnvParameters()
    {
        $parameters = array();
        foreach ($_SERVER as $key => $value) {
            if (0 === strpos($key, 'PPI__')) {
                $parameters[strtolower(str_replace('__', '.', substr($key, 5)))] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Creates and initializes a ServiceManager instance.
     *
     * @return ServiceManager The compiled service manager
     */
    protected function buildServiceManager()
    {
        $this->mergeConfig(array(
            'parameters'    => $this->getAppParameters()
        ));

        // ServiceManager creation
        $serviceManager = new ServiceManagerBuilder($this->config);
        $serviceManager->build();
        $serviceManager->set('app', $this);

        return $serviceManager;
    }

    /**
     * @throws \Exception
     */
    protected function handleRouting()
    {
        $router = $this->serviceManager->get('Router');
        $hasMatch = false;

        try {
            // Lets load up our router and match the appropriate route
            $router->warmUp();
            $this->serviceManager->get('RouterListener')->match($this->getRequest());
            $hasMatch = true;
        } catch (\Exception $e) {
            if ($this->debug) {
                $this->log('critical', $e);
                throw ($e);
            }
        }

        // Lets grab the 'Framework 404' route and dispatch it.
        if ($hasMatch === false) {
            try {
                $baseUrl  = $router->getContext()->getBaseUrl();
                $routeUri = $router->generate($this->options['404RouteName']);

                // We need to strip /myapp/public/404 down to /404, so our matchRoute() to work.
                if (!empty($baseUrl) && ($pos = strpos($routeUri, $baseUrl)) !== false ) {
                    $routeUri = substr_replace($routeUri, '', $pos, strlen($baseUrl));
                }

                $this->matchRoute($routeUri);

                // @todo handle a 502 here
            } catch (\Exception $e) {
                throw new \Exception('Unable to load 404 page. An internal error occurred');
            }
        }
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param  mixed  $level
     * @param  string $message
     * @param  array  $context
     * @return null
     */
    protected function log($level, $message, array $context = array())
    {
        if (null === $this->logger) {
            $this->logger = $this->getServiceManager()->has('logger') ? $this->getServiceManager()->get('logger') : false;
        }

        if ($this->logger) {
           $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Enables the debug tools.
     *
     * This method registers an error handler and an exception handler.
     *
     * If the Symfony ClassLoader component is available, a special
     * class loader is also registered.
     */
    protected function enableDebug()
    {
        error_reporting(-1);

        ErrorHandler::register($this->errorReportingLevel);
        if ('cli' !== php_sapi_name()) {
            $handler = ExceptionHandler::register();
            $handler->setAppVersion($this->getVersion());
        } elseif (!ini_get('log_errors') || ini_get('error_log')) {
            ini_set('display_errors', 1);
        }

        if (class_exists('Symfony\Component\ClassLoader\DebugClassLoader')) {
            DebugClassLoader::enable();
        }
    }
}
