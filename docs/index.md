# omnilab/gaia

## Installation

```sh
composer require omnilab/gaia
```

```php
use Omnilab\Gaia\GaiaSourceFactory;

$gaia = (new GaiaSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'throttle' => 0,                   // optional: seconds between two calls
]);
```

With the Symfony bundle, register `GaiaSourceFactory` as a service (it is autoconfigured as a
`SourceFactoryInterface`) until the bundle knows it by name. Give the HTTP client a timeout of 30 s
or more: the archive takes 7 to 13 s to answer a query, however small.

## Which API: the archive's TAP

The ESA Gaia Archive answers through its TAP+ service (`https://gea.esac.esa.int/tap-server/tap/sync`,
ADQL, `FORMAT=json`: the columns with their units in `metadata`, the rows in `data`), its
DataLink service (epoch photometry, spectra) and its pages. TAP is used, on Gaia DR3:
`gaiadr3.gaia_source` (the astrometry, photometry, radial velocity, GSP-Phot parameters and class
probabilities), joined for one source to `gaiadr3.astrophysical_parameters` (FLAME's radius, mass,
luminosity and age; the spectral type) and `gaiadr3.hipparcos2_best_neighbour` (its Hipparcos
number), in one query.

The archive's quotas for an anonymous caller (its "Gaia archive user quotas", read on 2026-10-15):
a synchronous query has **10 s** (the archive answers 408, "Job timeout/aborted", an
`UnavailableException` here), an asynchronous one 1 min, 50,000 rows a query, 100 active queries
for the pool of anonymous callers. No rate is published: no throttle by default.

## Calls

| Method | Reads | ADQL |
|---|---|---|
| `entity()` | a `source_id` (`4472832130942575872`), a designation (`Gaia DR3 4472832130942575872`, case aside), `simbad:Gaia DR3 ...`, an `Identifier` of the `simbad` scheme whose value is a designation | `SELECT ... FROM gaiadr3.gaia_source AS g LEFT JOIN gaiadr3.astrophysical_parameters AS a ... LEFT JOIN gaiadr3.hipparcos2_best_neighbour AS h ... WHERE g.source_id = ...` |
| `cone()` | outside the contract: a right ascension and a declination (degrees, ICRS), a radius (degrees), a page size (2,000 at most), a faintest G magnitude, a cursor | `SELECT TOP n+1 ..., DISTANCE(POINT(ra, dec), POINT(g.ra, g.dec)) AS separation FROM gaiadr3.gaia_source AS g WHERE 1 = CONTAINS(POINT(g.ra, g.dec), CIRCLE(ra, dec, r)) [AND g.phot_g_mean_mag <= m] ORDER BY separation ASC, g.source_id ASC [OFFSET k]` |

One query an entity, one a cone's page. Another name - a star's (`* alf Ori`, `HIP 87937`), Gaia
DR2's (`Gaia DR2 ...`: DR2 and DR3 ids differ for a part of the sources), a number beyond a 64-bit
integer, another base's identifier - is refused before any call: the archive resolves no name, so
the `Collector` offers it SIMBAD's other designations for nothing. Unknown is `null` (no row).

**Why the cone is outside the contract**: the core's `Point` and `BBox` are places on the Earth
(WGS 84 latitude and longitude, refused beyond ±180°), and `occurrences()` asks each answer for a
`time` and a `place` there - a cone on the sky is neither, and a source of a catalogue has no time
of its own. So `cone()` is a method of this source, as `planets()` is of omnilab/exoplanets.

The cone reads `gaia_source` alone: with the two joins, a cone of 0.1° toward the Galactic centre
ran past the 10 s (408), without them a cone of 2° there answered in 13 s. A source of a cone
therefore has no FLAME parameters, spectral type nor Hipparcos number (`entity($source->id)` reads
them), and has its `separation` from the centre. Pages: `Page::$next` is an offset (the archive's
ADQL takes `OFFSET`), the order made stable by the `source_id`; `total` is known on the last page.

## What an entity holds

`id` (its designation in the `simbad` scheme: `simbad:Gaia DR3 4472832130942575872`), `label`
(the designation), `domain` astronomy, `kind` `source` (Gaia's word: a star, most often, but also a
quasar, a galaxy, a component of a double - the class probabilities say which), `url` (its VizieR
record, the page Wikidata's P13228 links to: the archive has no page per source), `synonyms` (its
Hipparcos name), no description, no image, no `place` (a position on the sky is not a place on the
Earth), and these properties:

| Key | Column | Value |
|---|---|---|
| `ra`, `dec` | ra ± ra_error, dec ± dec_error | Quantity, deg (the error, given in mas, in degrees), ICRS at epoch J2016.0 (`ref_epoch`), not J2000 as SIMBAD's |
| `coordinates` | the same, sexagesimal | text: `17 57 47.6406 +04 44 21.912` |
| `parallax` | parallax ± parallax_error | Quantity, mas |
| `proper_motion`, `proper_motion_ra`, `proper_motion_dec` | pm; pmra ± pmra_error (μα cos δ); pmdec ± pmdec_error | Quantity, mas/yr |
| `ruwe` | ruwe | number (above 1.4, the astrometry of a single star fits badly) |
| `astrometric_solution` | astrometric_params_solved (3, 31, 95) | text: position only, 5 or 6 parameters |
| `magnitude_G`, `magnitude_BP`, `magnitude_RP`, `colour_bp_rp`, `magnitude_RVS` | phot_g_mean_mag, phot_bp_mean_mag, phot_rp_mean_mag, bp_rp, grvs_mag ± grvs_mag_error | Quantity, mag |
| `radial_velocity`, `line_broadening` | radial_velocity ± its error, vbroad ± its error | Quantity, km/s |
| `galactic_longitude`, `galactic_latitude` | l, b | Quantity, deg |
| `temperature`, `surface_gravity`, `metallicity`, `distance`, `extinction_G` | GSP-Phot: teff, logg, mh, distance, ag `_gspphot` | Quantity, K, log(cm/s²), dex, pc, mag |
| `radius`, `mass`, `luminosity`, `age` | FLAME: radius, mass, lum, age `_flame` | Quantity, R☉, M☉, L☉, Gyr |
| `spectral_type` | spectraltype_esphs | text: `M`, `G`... |
| `probability_star`, `probability_quasar`, `probability_galaxy` | classprob_dsc_combmod_* | number |
| `quasar_candidate`, `galaxy_candidate` | in_qso_candidates, in_galaxy_candidates | boolean |
| `variable` | phot_variable_flag = VARIABLE (only then: DR3 flags no source constant) | boolean |
| `non_single_star`, `duplicated_source` | non_single_star > 0, duplicated_source | boolean |
| `separation` | a cone's only: the distance from its centre | Quantity, arcsec |

**Uncertainties**: the astrometry and the velocities have their standard errors; the
astrophysical parameters their 16th and 84th percentiles, of which a `Quantity` keeps the larger
distance from the value (Barnard's star's temperature, 3,099.63 K between 3,098.76 and 3,101.63:
± 1.9983).

`identifiers`: its own, and its Hipparcos best neighbour (`simbad:HIP 87937`): the same star, by a
name SIMBAD resolves. No `related`.

## The scheme: what the core does not have

The core has no scheme for Gaia (Wikidata holds Gaia DR3 ids in P13228, "Gaia ID", on 4,606,263
items on 2026-10-15). A source is named by its designation - `Gaia DR3 <source_id>`, the name the
DR3 catalogue gives it in its `designation` column, "unique across all Data Releases" - in the
`simbad` scheme, the core's scheme for an astronomical object's name: SIMBAD resolves it for the
sources it lists (Barnard's star, 51 Peg, Proxima Centauri: checked on 2026-10-15), not for every
one of the 1.8 billion (SIMBAD lists 22 million objects). When the core has a Gaia scheme, the id
moves to it.

## The Collector: Barnard's star from SIMBAD

omnilab/simbad keeps a star's Gaia DR3 designation among its identifiers (the rest of its
designations are synonyms). Against the real services on 2026-10-15:

```php
$collector->collect('simbad', "Barnard's star", 1, ['gaia', 'exoplanets']);
```

2 calls in 8.7 s - SIMBAD's query, then Gaia's for `4472832130942575872` (omnilab/exoplanets
refuses SIMBAD's names of a star before any call) - and `sources` = simbad, gaia; identifiers
`simbad:NAME Barnard's star`, `simbad:Gaia DR3 4472832130942575872`, `simbad:HIP 87937`:

```
object_type      BY Dra Variable [simbad]
parallax         546.9759 ± 0.0401 mas [simbad]
parallax         546.9759397309 ± 0.040116355 mas [gaia]
radial_velocity  -110.11 ± 0.0002 km/s [simbad]
radial_velocity  -110.46822 ± 0.13125522 km/s [gaia]
magnitude_G      8.193974 mag [simbad]
magnitude_G      8.1939745 mag [gaia]
temperature      3099.6335 ± 1.9983 K [gaia]
radius           0.20216417 ± 0.0040433 R☉ [gaia]
distance         1.8275 ± 0.001 pc [gaia]
spectral_type    M4V [simbad]
spectral_type    M [gaia]
```

From an exoplanet: omnilab/exoplanets relates a planet's host star by its Gaia DR3 designation
among its others; the one Gaia reads is found by `GaiaSource::sourceId()`:

```php
$planet = $archive->entity('51 Peg b');
foreach ($planet->related('host') as $name) {
    if (null !== GaiaSource::sourceId($name)) {
        $star = $collector->collect('gaia', $name);   // Gaia DR3 2835207319109249920, then SIMBAD's 51 Peg
    }
}
```

## What the archive does not give here

The other releases (DR2, EDR3, the Focused Product Release; DR2's neighbourhood table is not
read), the epoch photometry, the BP/RP and RVS spectra (DataLink), the non-single-star orbits, the
variability tables, the other cross-matches (Tycho-2, 2MASS, AllWISE... beside Hipparcos), the
solar system objects, GSP-Spec's parameters, the asynchronous queries (an anonymous one has a
minute) and the authenticated quotas are not read.

## Verified, and not

Against the real service on 2026-10-15 (Gaia DR3): Barnard's star by its designation (FLAME's
radius but no mass), 51 Peg by its `source_id` (FLAME's mass and age, its line broadening, HIP
113357), a `source_id` DR3 does not have (1: no row); the cone of 0.02° around Sgr A* in two pages
of five (the nearest 11.3 arcsec away), the ten sources of G 9 or brighter within 1° of 51 Peg (51
Peg first, 0.0002 arcsec from the centre given at its position), a cone of 2° toward the Galactic
centre without the joins (13 s), with them a cone of 0.1° there (408 after 20 s); the Collector's
walk above. The fixtures in `Tests/Fixtures/` are its answers (the 408's body copied from that
answer). Each query took 7 to 13 s from asking to reading.

Not verified: the pool of 100 active anonymous queries met; a cone of more than 50,000 rows
(`limit` is capped at 2,000 a page; a page deep past 50,000 rows by `OFFSET` was not asked); a
mirror (`base_uri`).
