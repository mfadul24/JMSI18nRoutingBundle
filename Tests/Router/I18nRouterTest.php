<?php

/*
 * Copyright 2012 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\I18nRoutingBundle\Tests\Router;

use JMS\I18nRoutingBundle\Exception\NotAcceptableLanguageException;
use JMS\I18nRoutingBundle\Router\DefaultPatternGenerationStrategy;
use JMS\I18nRoutingBundle\Router\DefaultRouteExclusionStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Translation\Loader\YamlFileLoader as TranslationLoader;
use Symfony\Component\Translation\MessageSelector;
use Symfony\Component\Translation\Translator;
use JMS\I18nRoutingBundle\Router\I18nLoader;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use JMS\I18nRoutingBundle\Router\I18nRouter;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\HttpFoundation\Request;

class I18nRouterTest extends TestCase
{
    public function testGenerate(): void
    {
        $router = $this->getRouter();
        self::assertEquals('/welcome-on-our-website', $router->generate('welcome'));

        $context = new RequestContext();
        $context->setParameter('_locale', 'en');
        $router->setContext($context);

        self::assertEquals('/welcome-on-our-website', $router->generate('welcome'));
        self::assertEquals('/willkommen-auf-unserer-webseite', $router->generate('welcome', array('_locale' => 'de')));
        self::assertEquals('/welcome-on-our-website', $router->generate('welcome', array('_locale' => 'fr')));

        // test homepage
        self::assertEquals('/', $router->generate('homepage', array('_locale' => 'en')));
        self::assertEquals('/', $router->generate('homepage', array('_locale' => 'de')));
    }

    public function testGenerateWithHostMap(): void
    {
        $router = $this->getRouter();
        $router->setHostMap(array(
            'de' => 'de.host',
            'en' => 'en.host',
            'fr' => 'fr.host',
        ));

        self::assertEquals('/welcome-on-our-website', $router->generate('welcome'));
        self::assertEquals('http://en.host/welcome-on-our-website', $router->generate('welcome', array(), UrlGeneratorInterface::ABSOLUTE_URL));

        $context = new RequestContext();
        $context->setParameter('_locale', 'en');
        $router->setContext($context);

        self::assertEquals('/welcome-on-our-website', $router->generate('welcome'));
        self::assertEquals('http://en.host/welcome-on-our-website', $router->generate('welcome', array(), UrlGeneratorInterface::ABSOLUTE_URL));
        self::assertEquals('http://de.host/willkommen-auf-unserer-webseite', $router->generate('welcome', array('_locale' => 'de')));
        self::assertEquals('http://de.host/willkommen-auf-unserer-webseite', $router->generate('welcome', array('_locale' => 'de'), UrlGeneratorInterface::ABSOLUTE_URL));
    }

    public function testGenerateDoesUseCorrectHostWhenSchemeChanges(): void
    {
        $router = $this->getRouter();

        $router->setHostMap(array(
            'en' => 'en.test',
            'de' => 'de.test',
        ));

        $context = new RequestContext();
        $context->setHost('en.test');
        $context->setScheme('http');
        $context->setParameter('_locale', 'en');
        $router->setContext($context);

        self::assertEquals('https://en.test/login', $router->generate('login'));
        self::assertEquals('https://de.test/einloggen', $router->generate('login', array('_locale' => 'de')));
    }

    public function testGenerateDoesNotI18nInternalRoutes(): void
    {
        $router = $this->getRouter();

        self::assertEquals('/internal?_locale=de', $router->generate('_internal', array('_locale' => 'de')));
    }

    public function testGenerateWithNonI18nRoute(): void
    {
        $router = $this->getRouter('routing.yml', new IdentityTranslator());
        self::assertEquals('/this-is-used-for-checking-login', $router->generate('login_check'));
    }

    public function testMatch(): void
    {
        $router = $this->getRouter();
        $router->setHostMap(array(
            'en' => 'en.test',
            'de' => 'de.test',
            'fr' => 'fr.test',
        ));

        $context = new RequestContext('', 'GET', 'en.test');
        $context->setParameter('_locale', 'en');
        $router->setContext($context);

        self::assertEquals(array('_controller' => 'foo', '_locale' => 'en', '_route' => 'welcome'), $router->match('/welcome-on-our-website'));

        self::assertEquals(array(
            '_controller' => 'JMS\I18nRoutingBundle\Controller\RedirectController::redirectAction',
            'path'        => '/willkommen-auf-unserer-webseite',
            'host'        => 'de.test',
            'permanent'   => true,
            'scheme'      => 'http',
            'httpPort'    => 80,
            'httpsPort'   => 443,
            '_route'      => 'welcome',
        ), $router->match('/willkommen-auf-unserer-webseite'));
    }

    public function testRouteNotFoundForActiveLocale(): void
    {
        $router = $this->getNonRedirectingHostMapRouter();
        $context = new RequestContext();
        $context->setParameter('_locale', 'en_US');
        $context->setHost('us.test');
        $router->setContext($context);

        // The route should be available for both en_UK and en_US
        self::assertEquals(array('_route' => 'news_overview', '_locale' => 'en_US'), $router->match('/news'));

        $context->setParameter('_locale', 'en_UK');
        $context->setHost('uk.test');
        $router->setContext($context);

        // The route should be available for both en_UK and en_US
        self::assertEquals(array('_route' => 'news_overview', '_locale' => 'en_UK'), $router->match('/news'));

        // Tests whether generating a route to a different locale works
        self::assertEquals('http://nl.test/nieuws', $router->generate('news_overview', array('_locale' => 'nl_NL')));

        self::assertEquals(array('_route' => 'english_only', '_locale' => 'en_UK'), $router->match('/english-only'));
    }

    /**
     * Tests whether sublocales are properly translated (en_UK and en_US can use different patterns)
     */
    public function testSubLocaleTranslation(): void
    {
        // Note that the default is set to en_UK by getDoubleLocaleRouter()
        $router = $this->getNonRedirectingHostMapRouter();
        $context = new RequestContext();
        $context->setParameter('_locale', 'en_US');
        $context->setHost('us.test');
        $router->setContext($context);

        // Test overwrite
        self::assertEquals(array('_route' => 'sub_locale', '_locale' => 'en_US'), $router->match('/american'));

        $context->setParameter('_locale', 'en_UK');
        $context->setHost('uk.test');
        $router->setContext($context);
        self::assertEquals(array('_route' => 'enUK_only', '_locale' => 'en_UK'), $router->match('/enUK-only'));
    }

    /**
     * @dataProvider getMatchThrowsExceptionFixtures
     */
    public function testMatchThrowsException($locale, $host, $pattern): void
    {
        $this->expectException(ResourceNotFoundException::class);

        $router = $this->getNonRedirectingHostMapRouter();
        $context = new RequestContext();
        $context->setParameter('_locale', $locale);
        $context->setHost($host);
        $router->setContext($context);

        $router->match($pattern);
    }

    public function getMatchThrowsExceptionFixtures(): array
    {
        return array(
            array('en_UK', 'uk.tests', '/nieuws'),
            array('en_UK', 'uk.tests', '/dutch_only'),
            array('en_US', 'us.tests', '/enUK-only'),
            array('en_US', 'us.tests', '/english'),
        );
    }

    /**
     * @dataProvider getGenerateThrowsExceptionFixtures
     */
    public function testGenerateThrowsException($locale, $host, $route): void
    {
        $this->expectException(RouteNotFoundException::class);

        $router = $this->getNonRedirectingHostMapRouter();
        $context = new RequestContext();
        $context->setParameter('_locale', $locale);
        $context->setHost($host);
        $router->setContext($context);

        $router->generate($route);
    }

    public function getGenerateThrowsExceptionFixtures(): array
    {
        return array(
            array('en_UK', 'uk.tests', 'dutch_only'),
            array('en_US', 'us.tests', 'enUK_only'),
        );
    }

    public function testMatchThrowsResourceNotFoundWhenRouteIsUsedByMultipleLocalesOnDifferentHost(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->expectExceptionMessage('The route "sub_locale" is not available on the current host "us.test", but only on these hosts "uk.test, nl.test, be.test".');

        $router = $this->getNonRedirectingHostMapRouter();

        $context = $router->getContext();
        $context->setParameter('_locale', 'en_US');

        $router->match('/english');
    }

    public function testMatchThrowsNotAcceptableLanguageWhenRouteIsUsedByMultipleOtherLocalesOnSameHost(): void
    {
        $this->expectException(NotAcceptableLanguageException::class);
        $this->expectExceptionMessage('The requested language "en_US" was not available. Available languages: "en_UK, nl_NL, nl_BE"');

        $router = $this->getNonRedirectingHostMapRouter();
        $router->setHostMap(array(
            'en_US' => 'foo.com',
            'en_UK' => 'foo.com',
            'nl_NL' => 'nl.test',
            'nl_BE' => 'be.test',
        ));

        $context = $router->getContext();
        $context->setParameter('_locale', 'en_US');

        $router->match('/english');
    }

    public function testMatchCallsLocaleResolverIfRouteSupportsMultipleLocalesAndContextHasNoLocale(): void
    {
        $localeResolver = $this->createMock('JMS\I18nRoutingBundle\Router\LocaleResolverInterface');

        $router = $this->getRouter('routing.yml', null, $localeResolver);
        $context = $router->getContext();
        $context->setParameter('_locale', null);

        $ref = new \ReflectionProperty($router, 'container');
        $ref->setAccessible(true);
        $container = $ref->getValue($router);
        $request = Request::create('/');

        $requestStack = new \Symfony\Component\HttpFoundation\RequestStack();
        $requestStack->push($request);
        $container->set('request_stack', $requestStack);

        $localeResolver->expects($this->once())
            ->method('resolveLocale')
            ->with($request, array('en', 'de', 'fr'))
            ->will($this->returnValue('de'));

        $params = $router->match('/');
        self::assertSame('de', $params['_locale']);
    }

    private function getRouter($config = 'routing.yml', $translator = null, $localeResolver = null): I18nRouter
    {
        $container = new Container();
        $container->set('routing.loader', new YamlFileLoader(new FileLocator(__DIR__.'/Fixture')));

        if (null === $translator) {
            $translator = new Translator('en');
            $translator->setFallbackLocales(array('en'));
            $translator->addLoader('yml', new TranslationLoader());
            $translator->addResource('yml', __DIR__.'/Fixture/routes.de.yml', 'de', 'routes');
            $translator->addResource('yml', __DIR__.'/Fixture/routes.en.yml', 'en', 'routes');
        }

        $container->set('i18n_loader', new I18nLoader(new DefaultRouteExclusionStrategy(), new DefaultPatternGenerationStrategy('custom', $translator, array('en', 'de', 'fr'), sys_get_temp_dir())));

        $router = new I18nRouter($container, $config);
        $router->setI18nLoaderId('i18n_loader');
        $router->setDefaultLocale('en');

        if (null !== $localeResolver) {
            $router->setLocaleResolver($localeResolver);
        }

        return $router;
    }

    /**
     * Gets the translator required for checking the DoubleLocale tests (en_UK etc)
     */
    private function getNonRedirectingHostMapRouter($config = 'routing.yml'): I18nRouter
    {
        $container = new Container();
        $container->set('routing.loader', new YamlFileLoader(new FileLocator(__DIR__.'/Fixture')));

        $translator = new Translator('en_UK');
        $translator->setFallbackLocales(array('en'));
        $translator->addLoader('yml', new TranslationLoader());
        $translator->addResource('yml', __DIR__.'/Fixture/routes.en_UK.yml', 'en_UK', 'routes');
        $translator->addResource('yml', __DIR__.'/Fixture/routes.en_US.yml', 'en_US', 'routes');
        $translator->addResource('yml', __DIR__.'/Fixture/routes.nl.yml', 'nl', 'routes');
        $translator->addResource('yml', __DIR__.'/Fixture/routes.en.yml', 'en', 'routes');

        $container->set('i18n_loader', new I18nLoader(new DefaultRouteExclusionStrategy(), new DefaultPatternGenerationStrategy('custom', $translator, array('en_UK', 'en_US', 'nl_NL', 'nl_BE'), sys_get_temp_dir(), 'routes', 'en_UK')));

        $router = new I18nRouter($container, $config);
        $router->setRedirectToHost(false);
        $router->setI18nLoaderId('i18n_loader');
        $router->setDefaultLocale('en_UK');
        $router->setHostMap(array(
            'en_UK' => 'uk.test',
            'en_US' => 'us.test',
            'nl_NL' => 'nl.test',
            'nl_BE' => 'be.test',
        ));

        return $router;
    }
}
