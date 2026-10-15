<?php

namespace Omnilab\Gaia;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gaia DR3, the third data release of ESA's Gaia mission (1.8 billion
 * sources), through the ESA Gaia Archive's TAP service in ADQL: a source by
 * its source_id or its designation ("Gaia DR3 4472832130942575872") - its
 * astrometry (position at epoch J2016.0, parallax, proper motion, RUWE),
 * its photometry (G, BP, RP, G_RVS), its radial velocity and line
 * broadening, its astrophysical parameters when DR3 has them (GSP-Phot's
 * temperature, gravity, metallicity, distance, extinction; FLAME's radius,
 * mass, luminosity and age; Apsis's spectral type), the probabilities
 * that it is a star, a quasar or a galaxy, and its Hipparcos number;
 * cone() gives the sources around a point of the sky (outside the
 * contract: the core's places are on the Earth).
 *
 * The core has no scheme for Gaia: a source is named in the simbad scheme
 * by its designation, "Gaia DR3 <source_id>", the name SIMBAD resolves for
 * the sources it lists - see docs/index.md.
 *
 * No key, no account. An anonymous synchronous query has 10 s on the
 * archive's side (an answer takes 7 to 13 s from asking to reading):
 * beyond, the archive answers 408, an UnavailableException here. One call
 * an entity, one a cone's page.
 */
final class GaiaSource extends HttpSource
{
    public const BASE_URI = 'https://gea.esac.esa.int/tap-server/tap/';

    /** The most sources a cone's page holds (an anonymous query returns 50,000 rows at most). */
    public const MAX_LIMIT = 2000;

    /** gaia_source's columns read: key => [column, label, unit, uncertainty column (same unit)]. */
    private const MEASURES = [
        'parallax' => ['parallax', 'Parallax', 'mas', 'parallax_error'],
        'proper_motion' => ['pm', 'Total proper motion', 'mas/yr', null],
        'proper_motion_ra' => ['pmra', 'Proper motion in right ascension (μα cos δ)', 'mas/yr', 'pmra_error'],
        'proper_motion_dec' => ['pmdec', 'Proper motion in declination', 'mas/yr', 'pmdec_error'],
        'ruwe' => ['ruwe', 'Renormalised unit weight error (RUWE)', null, null],
        'magnitude_G' => ['phot_g_mean_mag', 'G magnitude', 'mag', null],
        'magnitude_BP' => ['phot_bp_mean_mag', 'BP magnitude', 'mag', null],
        'magnitude_RP' => ['phot_rp_mean_mag', 'RP magnitude', 'mag', null],
        'colour_bp_rp' => ['bp_rp', 'BP − RP colour', 'mag', null],
        'magnitude_RVS' => ['grvs_mag', 'G_RVS magnitude', 'mag', 'grvs_mag_error'],
        'radial_velocity' => ['radial_velocity', 'Radial velocity', 'km/s', 'radial_velocity_error'],
        'line_broadening' => ['vbroad', 'Spectral line broadening (vbroad)', 'km/s', 'vbroad_error'],
        'galactic_longitude' => ['l', 'Galactic longitude', 'deg', null],
        'galactic_latitude' => ['b', 'Galactic latitude', 'deg', null],
    ];

    /** The astrophysical parameters given with their 16th and 84th percentiles: key => [table alias.column, label, unit]. */
    private const PERCENTILED = [
        'temperature' => ['g.teff_gspphot', 'Effective temperature (GSP-Phot)', 'K'],
        'surface_gravity' => ['g.logg_gspphot', 'Surface gravity, log g (GSP-Phot)', 'log(cm/s²)'],
        'metallicity' => ['g.mh_gspphot', 'Metallicity [M/H] (GSP-Phot)', 'dex'],
        'distance' => ['g.distance_gspphot', 'Distance (GSP-Phot)', 'pc'],
        'extinction_G' => ['g.ag_gspphot', 'Extinction in G (GSP-Phot)', 'mag'],
        'radius' => ['a.radius_flame', 'Radius (FLAME)', 'R☉'],
        'mass' => ['a.mass_flame', 'Mass (FLAME)', 'M☉'],
        'luminosity' => ['a.lum_flame', 'Luminosity (FLAME)', 'L☉'],
        'age' => ['a.age_flame', 'Age (FLAME)', 'Gyr'],
    ];

    private const OTHER = ['g.source_id', 'g.designation', 'g.ref_epoch', 'g.ra', 'g.ra_error', 'g.dec', 'g.dec_error', 'g.astrometric_params_solved', 'g.phot_variable_flag', 'g.non_single_star', 'g.duplicated_source', 'g.classprob_dsc_combmod_star', 'g.classprob_dsc_combmod_quasar', 'g.classprob_dsc_combmod_galaxy', 'g.in_qso_candidates', 'g.in_galaxy_candidates'];

    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'gaia';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY];
    }

    /**
     * A source by its source_id ("4472832130942575872") or its designation
     * ("Gaia DR3 4472832130942575872", "simbad:Gaia DR3 ...", an Identifier
     * of the simbad scheme); another name - a star's ("* alf Ori",
     * "HIP 87937"), Gaia DR2's - is refused before any call (the archive
     * resolves no name). Null when DR3 has no such source.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $sourceId = self::sourceId($id) ?? throw NotSupportedException::identifier('gaia', (string) $id);
        $columns = [...self::OTHER, ...self::columns(false), 'a.spectraltype_esphs', 'h.original_ext_source_id AS hip'];
        $rows = $this->query('SELECT '.implode(', ', $columns).' FROM gaiadr3.gaia_source AS g'
            .' LEFT JOIN gaiadr3.astrophysical_parameters AS a ON a.source_id = g.source_id'
            .' LEFT JOIN gaiadr3.hipparcos2_best_neighbour AS h ON h.source_id = g.source_id'
            .' WHERE g.source_id = '.$sourceId);

        return null === ($row = $rows[0] ?? null) ? null : $this->toEntity($row);
    }

    /**
     * The sources within a radius of a point of the sky, the nearest first,
     * one page at a time (outside the contract: the core's Point and BBox
     * are places on the Earth, and occurrences() asks each answer for a
     * time and a place there - a cone on the sky is neither). Each entity
     * has what gaia_source holds (no FLAME parameters, no Hipparcos number:
     * entity() reads them) and its `separation` from the point.
     *
     *   $gaia->cone(266.4168, -29.0078, 0.02, limit: 10);   // around Sgr A*
     *
     * @param float      $ra           right ascension, degrees (ICRS)
     * @param float      $dec          declination, degrees (ICRS)
     * @param float      $radius       degrees
     * @param float|null $maxMagnitude only the sources at least this bright in G
     * @param string|null $cursor      the Page::$next of the page before
     *
     * @return Page<Entity>
     */
    public function cone(float $ra, float $dec, float $radius, int $limit = 50, ?float $maxMagnitude = null, ?string $cursor = null): Page
    {
        if ($ra < 0 || $ra >= 360 || $dec < -90 || $dec > 90) {
            throw new \InvalidArgumentException(\sprintf('%F, %F is not a right ascension and a declination in degrees.', $ra, $dec));
        }
        if ($radius <= 0 || $radius > 180) {
            throw new \InvalidArgumentException(\sprintf('A radius of %F degrees.', $radius));
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, (int) $cursor);
        $point = \sprintf('POINT(%s, %s)', self::number($ra), self::number($dec));
        $rows = $this->query('SELECT TOP '.($limit + 1).' '.implode(', ', [...self::OTHER, ...self::columns(true)]).", DISTANCE($point, POINT(g.ra, g.dec)) AS separation"
            .' FROM gaiadr3.gaia_source AS g'
            ." WHERE 1 = CONTAINS(POINT(g.ra, g.dec), CIRCLE(".self::number($ra).', '.self::number($dec).', '.self::number($radius).'))'
            .(null === $maxMagnitude ? '' : ' AND g.phot_g_mean_mag <= '.self::number($maxMagnitude))
            .' ORDER BY separation ASC, g.source_id ASC'
            .($offset > 0 ? ' OFFSET '.$offset : ''));
        $more = \count($rows) > $limit;
        $items = array_map(fn (array $row) => $this->toEntity($row), \array_slice($rows, 0, $limit));

        return new Page($items, $more ? (string) ($offset + $limit) : null, !$more ? $offset + \count($items) : null);
    }

    /**
     * The source_id an identifier gives: digits, "Gaia DR3 " and digits, an
     * Identifier of the gaia scheme, or its designation in the simbad
     * scheme (as SIMBAD names it).
     */
    public static function sourceId(Identifier|string $id): ?string
    {
        if (\is_string($id)) {
            $id = Identifier::parse($id) ?? $id;
        }
        if ($id instanceof Identifier) {
            if (Scheme::GAIA === $id->scheme) {
                return $id->value;
            }
            // Its designation as SIMBAD names it: simbad:Gaia DR3 ...
            if (Scheme::SIMBAD !== $id->scheme) {
                return null;
            }
            $id = $id->value;
        }

        // A source_id is a signed 64-bit integer: at most 9223372036854775807.
        return preg_match('~^\s*(?:Gaia\s+DR3\s+)?0*([1-9]\d{0,18})\s*$~i', $id, $m) && (\strlen($m[1]) < 19 || strcmp($m[1], (string) \PHP_INT_MAX) <= 0) ? $m[1] : null;
    }

    /**
     * Runs an ADQL query, synchronously; the rows keyed by column. The
     * archive's 408 (the 10 s of an anonymous query spent) is an
     * UnavailableException.
     *
     * @return list<array<string, mixed>>
     */
    private function query(string $adql): array
    {
        try {
            $data = $this->getJson('sync', ['REQUEST' => 'doQuery', 'LANG' => 'ADQL', 'FORMAT' => 'json', 'QUERY' => $adql]) ?? [];
        } catch (UnavailableException $e) {
            throw $e;
        } catch (ProviderException $e) {
            if (408 === $e->status) {
                throw new UnavailableException('gaia', 'the query took longer than the 10 s the archive gives an anonymous synchronous query (408): narrow it', 408, $e);
            }
            throw $e;
        }
        $columns = array_map(static fn (array $c) => (string) $c['name'], $data['metadata'] ?? []);
        $rows = [];
        foreach ($data['data'] ?? [] as $values) {
            if (\is_array($values) && \count($values) === \count($columns)) {
                $rows[] = array_combine($columns, $values);
            }
        }

        return $rows;
    }

    /** @return list<string> the measured columns, gaia_source's alone for a cone */
    private static function columns(bool $cone): array
    {
        $columns = [];
        foreach (self::MEASURES as [$column, , , $error]) {
            array_push($columns, 'g.'.$column, ...(null === $error ? [] : ['g.'.$error]));
        }
        foreach (self::PERCENTILED as [$column]) {
            if (!$cone || str_starts_with($column, 'g.')) {
                array_push($columns, $column, $column.'_lower', $column.'_upper');
            }
        }

        return $columns;
    }

    /** @param array<string, mixed> $row */
    private function toEntity(array $row): Entity
    {
        $sourceId = (string) $row['source_id'];
        $designation = self::text($row['designation'] ?? null) ?? 'Gaia DR3 '.$sourceId;
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'gaia');
            }
        };
        $epoch = is_numeric($row['ref_epoch'] ?? null) ? 'J'.number_format((float) $row['ref_epoch'], 1, '.', '') : 'J2016.0';
        // The position's errors are in mas: in degrees here, as the position.
        $add('ra', "Right ascension (ICRS, epoch $epoch)", is_numeric($row['ra'] ?? null) ? new Quantity((float) $row['ra'], 'deg', is_numeric($row['ra_error'] ?? null) ? $row['ra_error'] / 3.6e6 : null) : null);
        $add('dec', "Declination (ICRS, epoch $epoch)", is_numeric($row['dec'] ?? null) ? new Quantity((float) $row['dec'], 'deg', is_numeric($row['dec_error'] ?? null) ? $row['dec_error'] / 3.6e6 : null) : null);
        $add('coordinates', "Coordinates (ICRS, epoch $epoch, sexagesimal)", is_numeric($row['ra'] ?? null) && is_numeric($row['dec'] ?? null) ? self::sexagesimal((float) $row['ra'], (float) $row['dec']) : null);
        foreach (self::MEASURES as $key => [$column, $label, $unit, $error]) {
            $value = $row[$column] ?? null;
            if (is_numeric($value)) {
                $add($key, $label, new Quantity(0 + $value, $unit, null !== $error && is_numeric($row[$error] ?? null) ? (float) $row[$error] : null));
            }
        }
        $add('astrometric_solution', 'Astrometric solution', match ((int) ($row['astrometric_params_solved'] ?? 0)) {
            3 => 'position only (2 parameters)',
            31 => 'position, parallax and proper motion (5 parameters)',
            95 => 'position, parallax, proper motion and pseudo-colour (6 parameters)',
            default => null,
        });
        foreach (self::PERCENTILED as $key => [$column, $label, $unit]) {
            $name = substr($column, 2);
            $value = $row[$name] ?? null;
            if (is_numeric($value)) {
                $spread = array_filter([is_numeric($row[$name.'_upper'] ?? null) ? $row[$name.'_upper'] - $value : null, is_numeric($row[$name.'_lower'] ?? null) ? $value - $row[$name.'_lower'] : null], static fn ($v) => null !== $v);
                $add($key, $label, new Quantity(0 + $value, $unit, $spread ? (float) max(array_map('abs', $spread)) : null));
            }
        }
        $add('spectral_type', 'Spectral type (Apsis, spectraltype_esphs)', self::text($row['spectraltype_esphs'] ?? null));
        $add('probability_star', 'Probability of being a single star (DSC-Combmod)', is_numeric($row['classprob_dsc_combmod_star'] ?? null) ? (float) $row['classprob_dsc_combmod_star'] : null);
        $add('probability_quasar', 'Probability of being a quasar (DSC-Combmod)', is_numeric($row['classprob_dsc_combmod_quasar'] ?? null) ? (float) $row['classprob_dsc_combmod_quasar'] : null);
        $add('probability_galaxy', 'Probability of being a galaxy (DSC-Combmod)', is_numeric($row['classprob_dsc_combmod_galaxy'] ?? null) ? (float) $row['classprob_dsc_combmod_galaxy'] : null);
        $add('quasar_candidate', 'Among the quasar candidates', isset($row['in_qso_candidates']) ? (bool) $row['in_qso_candidates'] : null);
        $add('galaxy_candidate', 'Among the galaxy candidates', isset($row['in_galaxy_candidates']) ? (bool) $row['in_galaxy_candidates'] : null);
        // DR3 flags the variable sources only; the others are NOT_AVAILABLE, not "constant".
        $add('variable', 'Variable', 'VARIABLE' === ($row['phot_variable_flag'] ?? null) ? true : null);
        $add('non_single_star', 'Non-single star (DR3\'s orbital, acceleration or eclipsing solution)', isset($row['non_single_star']) ? 0 !== (int) $row['non_single_star'] : null);
        $add('duplicated_source', 'Duplicated source', isset($row['duplicated_source']) ? (bool) $row['duplicated_source'] : null);
        $add('separation', 'Separation from the cone\'s centre', is_numeric($row['separation'] ?? null) ? new Quantity($row['separation'] * 3600, 'arcsec') : null);

        $id = Identifier::of(Scheme::GAIA, (string) $sourceId);
        $hip = is_numeric($row['hip'] ?? null) ? Identifier::tryOf(Scheme::SIMBAD, 'HIP '.(int) $row['hip']) : null;

        return new Entity(
            id: $id,
            label: $designation,
            domain: Domain::ASTRONOMY,
            kind: 'source',
            synonyms: null === $hip ? [] : [$hip->value],
            properties: $properties,
            // Its designation and Gaia's best neighbour in Hipparcos 2: the same star, by the names SIMBAD resolves.
            identifiers: new Identifiers([Identifier::tryOf(Scheme::SIMBAD, $designation), $hip]),
            url: $id->url(),
            source: 'gaia',
        );
    }

    /** "05 55 10.3053 +07 24 25.430", as SIMBAD writes it. */
    private static function sexagesimal(float $ra, float $dec): string
    {
        $hours = $ra / 15;
        $h = (int) floor($hours);
        $m = (int) floor(($hours - $h) * 60);
        $s = (($hours - $h) * 60 - $m) * 60;
        if (round($s, 4) >= 60) {
            [$s, $m] = [0.0, $m + 1];
        }
        if ($m >= 60) {
            [$m, $h] = [0, ($h + 1) % 24];
        }
        $sign = $dec < 0 ? '-' : '+';
        $abs = abs($dec);
        $d = (int) floor($abs);
        $am = (int) floor(($abs - $d) * 60);
        $as = (($abs - $d) * 60 - $am) * 60;
        if (round($as, 3) >= 60) {
            [$as, $am] = [0.0, $am + 1];
        }
        if ($am >= 60) {
            [$am, $d] = [0, $d + 1];
        }

        return \sprintf('%02d %02d %07.4f %s%02d %02d %06.3f', $h, $m, $s, $sign, $d, $am, $as);
    }

    /** A number as ADQL takes it, never in exponent form. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.10F', $value), '0'), '.');
    }
}
