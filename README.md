# omnilab/gaia

Gaia DR3 for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a source of ESA's Gaia
mission (1.8 billion of them) by its `source_id` or its designation - `Gaia DR3
4472832130942575872`, Barnard's star - with its position at epoch J2016.0, parallax, proper
motion and RUWE, its G, BP, RP and RVS magnitudes, its radial velocity and line broadening, its
astrophysical parameters when DR3 has them (GSP-Phot's temperature, gravity, metallicity,
distance and extinction; FLAME's radius, mass, luminosity and age; Apsis's spectral type), the
probabilities that it is a star, a quasar or a galaxy, and its Hipparcos number; and the sources
of a cone on the sky, the nearest first. Through the ESA Gaia Archive's TAP service, in ADQL, no
key.

```php
$gaia = (new GaiaSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$star = $gaia->entity('Gaia DR3 4472832130942575872');
$star->id;                               // simbad:Gaia DR3 4472832130942575872
$star->property('parallax');             // 546.9759397309 ± 0.040116355 mas
$star->property('proper_motion_dec');    // 10362.3942065466 ± 0.036070455 mas/yr
$star->property('radial_velocity');      // -110.46822 ± 0.13125522 km/s
$star->property('temperature');          // 3099.6335 ± 1.9983 K (GSP-Phot)
$star->identifiers()->keys();            // simbad:Gaia DR3 4472832130942575872, simbad:HIP 87937
$gaia->cone(266.4168, -29.0078, 0.02, limit: 5);       // around Sgr A*, the nearest first (outside the contract)
```

```yaml
omnilab:
    sources:
        gaia:
            factory: gaia
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
```

The core has no Gaia scheme: a source is named in the `simbad` scheme by its designation, the
name SIMBAD resolves for the sources it lists. SIMBAD gives a star's Gaia DR3 designation among
its identifiers, so the `Collector` follows a star from SIMBAD into Gaia
(`$collector->collect('simbad', "Barnard's star")`); omnilab/exoplanets gives a host star's.

## Terms of use

Gaia data are distributed under [CC BY-NC 3.0 IGO](https://www.cosmos.esa.int/web/gaia-users/license)
(commercial use under ESA's terms for its space science archives). Acknowledge them ("This work
has made use of data from the European Space Agency (ESA) mission Gaia
(https://www.cosmos.esa.int/gaia), processed by the Gaia Data Processing and Analysis Consortium
(DPAC, https://www.cosmos.esa.int/web/gaia/dpac/consortium). Funding for the DPAC has been
provided by national institutions, in particular the institutions participating in the Gaia
Multilateral Agreement.") and cite the mission paper (Gaia Collaboration, Prusti et al. 2016,
A&A 595, A1) and the DR3 summary (Gaia Collaboration, Vallenari et al. 2023, A&A 674, A1). An
anonymous query has 10 s on the archive's side and returns 50,000 rows at most; the archive
answers 408 beyond, an `UnavailableException` here.

See [docs/](docs/index.md) for what is read, how it maps, and what the archive does not give here.

License: MIT.
