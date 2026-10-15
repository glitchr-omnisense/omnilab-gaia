<?php

namespace Omnilab\Gaia\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Gaia\GaiaSource;
use Omnilab\Gaia\GaiaSourceFactory;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from gea.esac.esa.int/tap-server/tap on 2026-10-15
 * (Gaia DR3): Barnard's star (4472832130942575872) and 51 Peg
 * (2835207319109249920) with their astrophysical parameters and
 * Hipparcos numbers, a source_id DR3 does not have (1), the cone of 0.02°
 * around Sgr A* in two pages of five, the sources of G 9 or brighter
 * within 1° of 51 Peg, and the archive's 408 when an anonymous query
 * runs past 10 s.
 */
final class GaiaSourceTest extends TestCase
{
    /** @var list<string> the ADQL queries asked */
    private array $calls = [];

    private function source(?MockResponse $response = null): GaiaSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertSame('/tap-server/tap/sync', parse_url($url, \PHP_URL_PATH));
            self::assertSame(['doQuery', 'ADQL', 'json'], [$query['REQUEST'], $query['LANG'], $query['FORMAT']]);
            $this->calls[] = $adql = $query['QUERY'];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                str_ends_with($adql, 'WHERE g.source_id = 4472832130942575872') => 'source-4472832130942575872.json',
                str_ends_with($adql, 'WHERE g.source_id = 2835207319109249920') => 'source-2835207319109249920.json',
                str_contains($adql, 'CIRCLE(266.4168, -29.0078, 0.02)') => str_ends_with($adql, 'OFFSET 5') ? 'cone-sgr-a-2.json' : 'cone-sgr-a-1.json',
                str_contains($adql, 'CIRCLE(344.3675708, 20.7691043, 1)') => 'cone-51-peg-bright.json',
                default => 'source-none.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new GaiaSourceFactory($http))->create();
        self::assertInstanceOf(GaiaSource::class, $source);

        return $source;
    }

    public function testBarnardsStarByItsDesignationInOneQuery(): void
    {
        $star = $this->source()->entity('Gaia DR3 4472832130942575872');

        self::assertSame('gaia:4472832130942575872', $star->id->key(), 'its source_id, in the core\'s gaia scheme');
        self::assertSame('Gaia DR3 4472832130942575872', $star->label);
        self::assertSame(Domain::ASTRONOMY, $star->domain);
        self::assertSame('source', $star->kind);
        self::assertSame('https://vizier.cds.unistra.fr/viz-bin/VizieR-S?Gaia%20DR3%204472832130942575872', $star->url);
        self::assertSame('Right ascension (ICRS, epoch J2016.0)', $star->property('ra')->label);
        self::assertSame('deg', $star->property('ra')->unit());
        self::assertEqualsWithDelta(269.4485025254, $star->property('ra')->number(), 1e-9);
        self::assertEqualsWithDelta(0.0073 / 1e6 * 1.0, $star->value('ra')->uncertainty, 1e-9, 'its error in mas, given in degrees');
        self::assertSame('17 57 47.6406 +04 44 21.912', $star->value('coordinates'));
        self::assertSame('546.9759397309 ± 0.040116355 mas', (string) $star->property('parallax'));
        self::assertSame('10362.3942065466 ± 0.036070455 mas/yr', (string) $star->property('proper_motion_dec'));
        self::assertSame('-110.46822 ± 0.13125522 km/s', (string) $star->property('radial_velocity'));
        self::assertEquals(new Quantity(8.1939745, 'mag'), $star->value('magnitude_G'));
        self::assertEquals(new Quantity(2.8336968, 'mag'), $star->value('colour_bp_rp'));
        self::assertSame('position, parallax and proper motion (5 parameters)', $star->value('astrometric_solution'));
        self::assertSame('3099.6335 ± 1.9983 K', (string) $star->property('temperature'), 'the larger of the distances to its 16th and 84th percentiles');
        self::assertSame('1.8275 ± 0.001 pc', (string) $star->property('distance'));
        self::assertSame('R☉', $star->property('radius')->unit());
        self::assertNull($star->property('mass'), 'FLAME gives no mass for it');
        self::assertSame('M', $star->value('spectral_type'));
        self::assertFalse($star->value('non_single_star'));
        self::assertSame('gaia', $star->property('parallax')->source);
        self::assertSame(['gaia:4472832130942575872', 'simbad:Gaia DR3 4472832130942575872', 'simbad:HIP 87937'], $star->identifiers()->keys(), 'its designation and its Hipparcos best neighbour as SIMBAD names them: the same star');
        self::assertSame(['HIP 87937'], $star->synonyms);
        self::assertNull($star->place, 'a position on the sky is not a place on the Earth');
        self::assertCount(1, $this->calls);
        self::assertStringContainsString('FROM gaiadr3.gaia_source AS g LEFT JOIN gaiadr3.astrophysical_parameters AS a ON a.source_id = g.source_id LEFT JOIN gaiadr3.hipparcos2_best_neighbour AS h ON h.source_id = g.source_id WHERE g.source_id = 4472832130942575872', $this->calls[0]);
    }

    public function testFiftyOnePegasiBySourceIdAndBySimbadsName(): void
    {
        $star = $this->source()->entity('2835207319109249920');

        self::assertSame('Gaia DR3 2835207319109249920', $star->label);
        self::assertSame('0.9423888 ± 0.0400029 M☉', (string) $star->property('mass'));
        self::assertSame('12.131496 ± 1.195469 Gyr', (string) $star->property('age'));
        self::assertSame('5.365536 ± 0.60640866 km/s', (string) $star->property('line_broadening'));
        self::assertContains('simbad:HIP 113357', $star->identifiers()->keys());

        self::assertSame($star->label, $this->source()->entity(Identifier::of(Scheme::SIMBAD, 'Gaia DR3 2835207319109249920'))?->label, 'what SIMBAD and omnilab/exoplanets give');
        self::assertSame($star->label, $this->source()->entity('simbad:Gaia DR3 2835207319109249920')?->label);
        self::assertSame($star->label, $this->source()->entity(Identifier::of(Scheme::GAIA, '2835207319109249920'))?->label, 'the core\'s gaia scheme');
        self::assertSame($star->label, $this->source()->entity('gaia:2835207319109249920')?->label);
    }

    public function testAnUnknownSourceIsNull(): void
    {
        self::assertNull($this->source()->entity('Gaia DR3 1'));
        self::assertCount(1, $this->calls);
    }

    public function testAnotherNameIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::of(Scheme::SIMBAD, '* alf Ori'), Identifier::of(Scheme::SIMBAD, 'HIP 87937'), Identifier::of(Scheme::WIKIDATA, 'Q242309'), 'Gaia DR2 4472832130942575872', 'Betelgeuse', 'Q242309', '99999999999999999999'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail(\sprintf('%s should be refused', $id));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
        self::assertSame('9223372036854775807', GaiaSource::sourceId('9223372036854775807'));
        self::assertNull(GaiaSource::sourceId('9223372036854775808'));
        self::assertSame('4472832130942575872', GaiaSource::sourceId('gaia dr3 4472832130942575872'));
    }

    public function testAConeOnTheSkyInPages(): void
    {
        $source = $this->source();
        $first = $source->cone(266.4168, -29.0078, 0.02, 5);

        self::assertCount(5, $first);
        self::assertSame('5', $first->next);
        self::assertNull($first->total);
        self::assertSame('Gaia DR3 4057481825312714240', $first->first()->label);
        self::assertSame('arcsec', $first->first()->property('separation')->unit());
        self::assertEqualsWithDelta(11.32304, $first->first()->property('separation')->number(), 1e-4);
        self::assertNull($first->first()->property('radius'), 'a cone reads gaia_source alone');
        self::assertStringContainsString('SELECT TOP 6 ', $this->calls[0]);
        self::assertStringContainsString(', DISTANCE(POINT(266.4168, -29.0078), POINT(g.ra, g.dec)) AS separation FROM gaiadr3.gaia_source AS g WHERE 1 = CONTAINS(POINT(g.ra, g.dec), CIRCLE(266.4168, -29.0078, 0.02)) ORDER BY separation ASC, g.source_id ASC', $this->calls[0]);
        self::assertStringNotContainsString('JOIN', $this->calls[0]);

        $second = $source->cone(266.4168, -29.0078, 0.02, 5, cursor: $first->next);
        self::assertStringEndsWith('OFFSET 5', $this->calls[1]);
        self::assertSame('Gaia DR3 4057481790956615552', $second->first()->label);
        $separations = array_map(static fn ($e) => $e->property('separation')->number(), [...$first->items, ...$second->items]);
        $sorted = $separations;
        sort($sorted);
        self::assertSame($sorted, $separations, 'the nearest first, across pages');
    }

    public function testTheBrightSourcesOfACone(): void
    {
        $page = $this->source()->cone(344.3675708, 20.7691043, 1, 10, maxMagnitude: 9);

        self::assertStringContainsString('AND g.phot_g_mean_mag <= 9 ORDER BY', $this->calls[0]);
        self::assertCount(10, $page);
        self::assertNull($page->next);
        self::assertSame(10, $page->total);
        self::assertSame('Gaia DR3 2835207319109249920', $page->first()->label, '51 Peg itself, at its centre');
        foreach ($page as $star) {
            self::assertLessThanOrEqual(9, $star->property('magnitude_G')->number());
        }
    }

    public function testAConeOutsideTheSkyIsRefused(): void
    {
        foreach ([[360.0, 0.0, 1.0], [10.0, 91.0, 1.0], [10.0, 0.0, 0.0]] as [$ra, $dec, $radius]) {
            try {
                $this->source()->cone($ra, $dec, $radius);
                self::fail('refused');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testTheArchivesTimeoutAndErrors(): void
    {
        try {
            $this->source(new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/timeout.xml'), ['http_code' => 408]))->cone(266.4168, -29.0078, 2);
            self::fail('a 408');
        } catch (UnavailableException $e) {
            self::assertSame(408, $e->status);
            self::assertStringContainsString('10 s', $e->getMessage());
        }
        try {
            $this->source(new MockResponse('<VOTABLE><INFO name="QUERY_STATUS" value="ERROR">Cannot parse query</INFO></VOTABLE>', ['http_code' => 400]))->entity('4472832130942575872');
            self::fail('a 400');
        } catch (ProviderException $e) {
            self::assertNotInstanceOf(UnavailableException::class, $e);
            self::assertStringContainsString('Cannot parse query', $e->getMessage());
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('4472832130942575872');
    }

    public function testTheFactory(): void
    {
        $factory = new GaiaSourceFactory(new MockHttpClient());
        $source = $factory->create(['mailto' => 'support@glitchr.io']);

        self::assertSame('gaia', $factory->getName());
        self::assertSame('gaia', $source->getName());
        self::assertSame([Capability::ENTITY], $source->capabilities(), 'no search: Gaia names nothing');
        $this->expectException(NotSupportedException::class);
        $source->search(new Query('Barnard'));
    }
}
