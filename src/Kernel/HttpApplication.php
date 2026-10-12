<?php

declare(strict_types=1);

namespace App\Kernel;

use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ContainerControllerResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Minimal, hand-assembled HTTP front controller: routing + a DI container +
 * `symfony/http-kernel`'s `HttpKernel`, with no framework bundle involved.
 * Shared by `public/index.php` and the functional test suite, so both exercise
 * the exact same request/response cycle.
 */
final class HttpApplication
{
    private readonly HttpKernel $kernel;

    public function __construct(ContainerInterface $container, RouteCollection $routes)
    {
        $requestContext = new RequestContext();
        $matcher = new UrlMatcher($routes, $requestContext);
        $requestStack = new RequestStack();

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RouterListener($matcher, $requestStack, $requestContext));

        $this->kernel = new HttpKernel(
            $dispatcher,
            new ContainerControllerResolver($container),
            $requestStack,
            new ArgumentResolver(),
        );
    }

    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }
}
