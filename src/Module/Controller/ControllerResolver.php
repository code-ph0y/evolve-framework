<?php
/**
 * This file is part of the PPI Framework.
 *
 * @copyright   Copyright (c) 2011-2016 Paul Dragoonis <paul@ppi.io>
 * @license     http://opensource.org/licenses/mit-license.php MIT
 *
 * @link        http://www.ppi.io
 */

namespace PPI\Framework\Module\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolver as BaseControllerResolver;
use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * ControllerResolver.
 *
 * @see Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver
 *
 * @author     Fabien Potencier <fabien@symfony.com>
 * @author     Vítor Brandão <vitor@ppi.io>
 * @author     Paul Dragoonis <paul@ppi.io>
 */
class ControllerResolver extends BaseControllerResolver
{
    protected $request;

    /**
     * Constructor.
     *
     * @param ServiceLocatorInterface $serviceManager A ServiceLocatorInterface instance
     * @param ControllerNameParser    $parser         A ControllerNameParser instance
     * @param LoggerInterface         $logger         A LoggerInterface instance
     */
    public function __construct(ServiceLocatorInterface $serviceManager, ControllerNameParser $parser, LoggerInterface $logger = null)
    {
        $this->serviceManager = $serviceManager;
        $this->parser = $parser;
        parent::__construct($logger);
    }

    /**
     * Returns a callable for the given controller.
     *
     * @param string $controller A Controller string
     *
     * @throws \LogicException           When the name could not be parsed
     * @throws \InvalidArgumentException When the controller class does not exist
     *
     * @return mixed A PHP callable
     */
    protected function createController($controller)
    {
        if (false === strpos($controller, '::')) {
            $count = substr_count($controller, ':');
            if (2 == $count) {
                // controller in the a:b:c notation then
                $controller = $this->parser->parse($controller);
            } elseif (1 == $count) {
                // controller in the service:method notation
                list($service, $method) = explode(':', $controller, 2);

                return array($this->serviceManager->get($service), $method);
            } elseif ($this->serviceManager->has($controller) && method_exists($service = $this->serviceManager->get($controller), '__invoke')) {
                return $service;
            } else {
                throw new \LogicException(sprintf('Unable to parse the controller name "%s".', $controller));
            }
        }

        return parent::createController($controller);
    }

    /**
     * @param string $class
     *
     * @return object
     */
    protected function instantiateController($class)
    {
        $controller = parent::instantiateController($class);

        if ($controller instanceof ServiceLocatorAwareInterface) {
            $controller->setServiceLocator($this->serviceManager);
        }

        return $controller;
    }
}
