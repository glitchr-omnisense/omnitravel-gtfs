# Changelog

Every version of omnitravel/gtfs, newest first ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
[Semantic Versioning](https://semver.org/)). A release's notes are its section here: the release
workflow refuses a tag without one.

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/omnitravel` `^1.0` with no stability flag, so `composer require omnitravel/gtfs` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

Omnitravel's GTFS, under any PHP host: any network that publishes a static GTFS feed, imported into a store of its own (SQLite or MySQL through PDO) - its stops near a point or by name, its lines with their colours and their stops in order, a stop's next departures and arrivals from the feed's calendars - and, where the network publishes GTFS-Realtime, its trip updates (delays, cancellations, skipped stops) laid over them and its vehicles' positions, read from Protocol Buffers by hand.
