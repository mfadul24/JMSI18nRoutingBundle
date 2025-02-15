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

namespace JMS\I18nRoutingBundle\Tests\Functional;

class PrefixStrategyTest extends BaseTestCase
{
    protected static $class = PrefixStrategyKernel::class;

    /**
     * @dataProvider getLocaleChoosingTests
     */
    public function testLocaleIsChoosenWhenHomepageIsRequested($acceptLanguages, $expectedLocale): void
    {
        // workaround to avoid overwriting static::$class = null;
        static::$class = PrefixStrategyKernel::class;
        $client = static::createClient(array(), array(
            'HTTP_ACCEPT_LANGUAGE' => $acceptLanguages,
        ));
        $client->insulate();

        $client->request('GET', '/?extra=params');

        self::assertTrue($client->getResponse()->isRedirect('/'.$expectedLocale.'/?extra=params'), (string) $client->getResponse());
    }

    public function getLocaleChoosingTests(): array
    {
        return array(
            array('en-us,en;q=0.5', 'en'),
            array('de-de,de;q=0.8,en-us;q=0.5,en;q=0.3', 'de'),
            array('fr;q=0.5', 'en'),
        );
    }

    public function testLanguageCookieIsSet(): void
    {
        // workaround to avoid overwriting static::$class = null;
        static::$class = PrefixStrategyKernel::class;
        $client = static::createClient(array());
        $client->insulate();

        $client->request('GET', '/?hl=de');

        $response = $client->getResponse();
        self::assertTrue($response->isRedirect('/de/'), (string) $response);

        $cookies = $response->headers->getCookies();
        self::assertSame(1, count($cookies));
        self::assertSame('de', $cookies[0]->getValue());
    }

    public function testNoCookieOnError(): void
    {
        // workaround to avoid overwriting static::$class = null;
        static::$class = PrefixStrategyKernel::class;
        $client = static::createClient(array());
        $client->insulate();

        $client->request('GET', '/nonexistent');

        $response = $client->getResponse();
        self::assertTrue($response->isClientError(), (string) $response);

        $cookies = $response->headers->getCookies();
        self::assertSame(0, count($cookies));
    }
}
