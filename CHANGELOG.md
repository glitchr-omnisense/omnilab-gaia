# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab Gaia: a source of ESA's Gaia DR3 by its source_id or designation (Gaia DR3
  4472832130942575872) - its position at epoch J2016.0, parallax, proper motion and RUWE, its G, BP,
  RP and RVS magnitudes, radial velocity and line broadening, its astrophysical parameters (GSP-Phot
  temperature, gravity, metallicity, distance, extinction; FLAME radius, mass, luminosity, age;
  Apsis spectral type), its class probabilities and Hipparcos number - and the sources of a cone on
  the sky, through the ESA Gaia Archive's TAP service in ADQL, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/gaia` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
