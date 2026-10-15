<?php

namespace Omnilab\Gaia;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Gaia DR3 from the ESA Gaia Archive: a source's astrometry, photometry,
 * radial velocity and astrophysical parameters by its source_id, and the
 * sources of a cone on the sky - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: whom the archive writes to before it blocks a caller
 *     throttle: 0                      # seconds between two calls: the archive limits active queries, not their rate
 *     base_uri: 'https://gea.esac.esa.int/tap-server/tap/'
 */
final class GaiaSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'gaia',
            'omnilab.required_options' => [],
            'throttle' => 0.0,
            'base_uri' => GaiaSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new GaiaSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri', GaiaSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
