<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

/**
 * Geodetic utility methods: haversine distance, bounding boxes, and GB / Irish
 * National Grid conversion to WGS84.
 *
 * Grid → WGS84 is an inverse Transverse Mercator onto the grid's own datum
 * (OSGB36 on Airy 1830; TM75 on Airy Modified), then a 7-parameter Helmert
 * shift to WGS84. Helmert is accurate to a few metres — plenty for placing
 * squares on a map, not for survey work (that needs OSTN15 / the OSi grid).
 */
class GeoHelper
{
    private const float EARTH_RADIUS_MILES = 3958.8;

    /** Miles per degree of latitude (approximate) */
    private const float MILES_PER_DEGREE_LAT = 69.0;

    // ── Haversine ─────────────────────────────────────────────────────────────

    /**
     * Returns the great-circle distance in miles between two WGS84 points.
     *
     * @param float $lat1 Latitude of point 1 (degrees)
     * @param float $lon1 Longitude of point 1 (degrees)
     * @param float $lat2 Latitude of point 2 (degrees)
     * @param float $lon2 Longitude of point 2 (degrees)
     * @return float Distance in miles
     */
    public static function haversineDistanceMiles(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $lat1R = deg2rad($lat1);
        $lat2R = deg2rad($lat2);
        $dLat  = deg2rad($lat2 - $lat1);
        $dLon  = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos($lat1R) * cos($lat2R) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_MILES * asin(sqrt($a));
    }

    /**
     * Returns a lat/lon bounding box large enough to contain all points within
     * the given radius of the centre. The box is an over-approximation; use
     * haversineDistanceMiles() for exact filtering after the SQL pre-filter.
     *
     * @param float $lat    Centre latitude (degrees)
     * @param float $lon    Centre longitude (degrees)
     * @param float $radiusMiles Radius in miles
     * @return array{minLat: float, maxLat: float, minLon: float, maxLon: float}
     */
    public static function boundingBoxForRadiusMiles(
        float $lat,
        float $lon,
        float $radiusMiles
    ): array {
        $deltaLat = $radiusMiles / self::MILES_PER_DEGREE_LAT;

        // Longitude degrees per mile depends on latitude
        $cosLat    = cos(deg2rad($lat));
        $deltaLon  = $cosLat > 0.0 ? $radiusMiles / (self::MILES_PER_DEGREE_LAT * $cosLat) : 360.0;

        return [
            'minLat' => $lat - $deltaLat,
            'maxLat' => $lat + $deltaLat,
            'minLon' => $lon - $deltaLon,
            'maxLon' => $lon + $deltaLon,
        ];
    }

    // ── Grid definitions ─────────────────────────────────────────────────────

    /**
     * British National Grid on OSGB36: Airy 1830 ellipsoid, and the OSGB36 → WGS84
     * Helmert parameters (EPSG:1314, position-vector convention).
     */
    private const array GB_GRID = [
        'a' => 6377563.396, 'b' => 6356256.909,
        'f0' => 0.9996012717, 'lat0' => 49.0, 'lon0' => -2.0,
        'e0' => 400000.0, 'n0' => -100000.0,
        'tx' => 446.448, 'ty' => -125.157, 'tz' => 542.060,
        'rx' => 0.1502, 'ry' => 0.2470, 'rz' => 0.8421, 'sPpm' => -20.4894,
    ];

    /**
     * Irish Grid on TM75: Airy Modified ellipsoid, and the TM75 → WGS84 Helmert
     * parameters published by Ordnance Survey Ireland (position-vector convention).
     */
    private const array IRISH_GRID = [
        'a' => 6377340.189, 'b' => 6356034.447,
        'f0' => 1.000035, 'lat0' => 53.5, 'lon0' => -8.0,
        'e0' => 200000.0, 'n0' => 250000.0,
        'tx' => 482.530, 'ty' => -130.596, 'tz' => 564.557,
        'rx' => -1.042, 'ry' => -0.214, 'rz' => -0.631, 'sPpm' => 8.150,
    ];

    /** Grid letters: A–Z without I, 5×5, read west→east then north→south. */
    private const string GRID_LETTERS = 'ABCDEFGHJKLMNOPQRSTUVWXYZ';

    /** Tetrad letters: A–Z without O, 5×5, read south→north then west→east (DINTY). */
    private const string TETRAD_LETTERS = 'ABCDEFGHIJKLMNPQRSTUVWXYZ';

    // ── Irish National Grid → WGS84 ───────────────────────────────────────────

    /**
     * Converts an Irish National Grid reference (TM75) to WGS84 lat/lng.
     *
     * Accepts 2–10 digit references (e.g. "O15743412" for 10m precision), giving
     * the south-west corner of the square the reference names.
     * Returns null if the reference cannot be parsed.
     *
     * @param string $gridRef Irish National Grid reference (e.g. "O15743412")
     * @return array{0: float, 1: float}|null [latitude, longitude] in WGS84 degrees, or null
     */
    public static function irishGridRefToLatLng(string $gridRef): ?array
    {
        $en = self::parseIrishGridRef($gridRef);

        return $en === null ? null : self::gridToWgs84($en[0], $en[1], self::IRISH_GRID);
    }

    /**
     * Parses an Irish National Grid reference string into metric easting and northing.
     *
     * The letter covers a 100km square; the digits (even count, 2–10) give the
     * position within that square at 50km, 10km, 1km, 100m, or 1m precision.
     *
     * @param string $gridRef Grid reference (e.g. "O15743412")
     * @return array{0: float, 1: float}|null [easting, northing] in metres, or null on failure
     */
    public static function parseIrishGridRef(string $gridRef): ?array
    {
        $gridRef = strtoupper(trim($gridRef));

        if (!preg_match('/^([A-HJ-Z])(\d+)$/', $gridRef, $m)) {
            return null;
        }

        $digits = $m[2];
        $len    = strlen($digits);

        if ($len % 2 !== 0 || $len < 2 || $len > 10) {
            return null;
        }

        $half   = intdiv($len, 2);
        $scale  = 10 ** (5 - $half);       // scale digits to 1m within the 100km square
        $eLocal = (int) substr($digits, 0, $half) * $scale;
        $nLocal = (int) substr($digits, $half) * $scale;

        // Letter grid: ABCDEFGHJKLMNOPQRSTUVWXYZ (no I), north→south, west→east, 5×5
        $letters = 'ABCDEFGHJKLMNOPQRSTUVWXYZ';
        $pos     = strpos($letters, $m[1]);
        if ($pos === false) {
            return null;
        }

        $col     = $pos % 5;
        $rowFromN = intdiv($pos, 5);         // 0 = northernmost row

        $easting  = (float) ($col * 100000 + $eLocal);
        $northing = (float) ((4 - $rowFromN) * 100000 + $nLocal);

        return [$easting, $northing];
    }

    // ── GB National Grid → WGS84 ──────────────────────────────────────────────

    /**
     * Converts a British National Grid reference (OSGB36) to WGS84 lat/lng.
     *
     * Accepts 2–10 digit references (e.g. "TG5140913177" for 1m precision), giving
     * the south-west corner of the square the reference names.
     * Returns null if the reference cannot be parsed.
     *
     * @param string $gridRef British National Grid reference (e.g. "SJ41", "TG5140913177")
     * @return array{0: float, 1: float}|null [latitude, longitude] in WGS84 degrees, or null
     */
    public static function gbGridRefToLatLng(string $gridRef): ?array
    {
        $en = self::parseGbGridRef($gridRef);

        return $en === null ? null : self::gridToWgs84($en[0], $en[1], self::GB_GRID);
    }

    /**
     * Parses a British National Grid reference string into metric easting and northing.
     *
     * The first letter names a 500km square and the second a 100km square within it
     * (each a 5×5 grid of A–Z without I, false origin at SV); the digits (even count,
     * 2–10) give the position within the 100km square. Only the 500km squares that
     * cover Great Britain (H, J, N, O, S, T) are accepted, so Channel Islands refs
     * (WA/WV, a different grid) are rejected rather than placed in the sea.
     *
     * @param string $gridRef Grid reference (e.g. "TG5140913177")
     * @return array{0: float, 1: float}|null [easting, northing] in metres, or null on failure
     */
    public static function parseGbGridRef(string $gridRef): ?array
    {
        if (!preg_match('/^([HJNOST])([A-HJ-Z])(\d{2,10})$/D', strtoupper(trim($gridRef)), $m)) {
            return null;
        }

        $digits = $m[3];
        if (strlen($digits) % 2 !== 0) {
            return null;
        }

        $first  = (int) strpos(self::GRID_LETTERS, $m[1]);
        $second = (int) strpos(self::GRID_LETTERS, $m[2]);

        // 500km square, relative to S (the false origin sits at SV)
        $e500 = ($first % 5) - 2;
        $n500 = 3 - intdiv($first, 5);

        $half  = intdiv(strlen($digits), 2);
        $scale = 10 ** (5 - $half);

        $easting  = $e500 * 500000 + ($second % 5) * 100000 + (int) substr($digits, 0, $half) * $scale;
        $northing = $n500 * 500000 + (4 - intdiv($second, 5)) * 100000 + (int) substr($digits, $half) * $scale;

        return [(float) $easting, (float) $northing];
    }

    // ── Tetrads ───────────────────────────────────────────────────────────────

    /**
     * Returns the four corners of a tetrad (2km square) in WGS84, for drawing it on a map.
     *
     * Accepts a GB tetrad (two letters, e.g. "SJ41R") or an Irish one (one letter,
     * e.g. "O13K"). The tetrad letter (A–Z without O) numbers the 25 squares of the
     * 10km square column by column from the south-west. Anything else — including
     * Channel Islands refs, which use a different grid — returns null.
     *
     * @param string $tetradRef Tetrad reference (e.g. "SJ41R")
     * @return list<array{0: float, 1: float}>|null [lat, lng] corners SW, SE, NE, NW, or null
     */
    public static function tetradCornersLatLng(string $tetradRef): ?array
    {
        if (!preg_match('/^([A-HJ-Z]{1,2})(\d)(\d)([A-NP-Z])$/D', strtoupper(trim($tetradRef)), $m)) {
            return null;
        }

        $isGb   = strlen($m[1]) === 2;
        $square = $isGb ? self::parseGbGridRef($m[1] . $m[2] . $m[3])
                        : self::parseIrishGridRef($m[1] . $m[2] . $m[3]);
        if ($square === null) {
            return null;
        }

        $index = (int) strpos(self::TETRAD_LETTERS, $m[4]);
        $west  = $square[0] + intdiv($index, 5) * 2000;
        $south = $square[1] + ($index % 5) * 2000;
        $grid  = $isGb ? self::GB_GRID : self::IRISH_GRID;

        return [
            self::gridToWgs84($west, $south, $grid),
            self::gridToWgs84($west + 2000, $south, $grid),
            self::gridToWgs84($west + 2000, $south + 2000, $grid),
            self::gridToWgs84($west, $south + 2000, $grid),
        ];
    }

    // ── Projection and datum maths ────────────────────────────────────────────

    /**
     * Grid easting/northing → WGS84 lat/lng, for one of the grid definitions above.
     *
     * @param float $easting
     * @param float $northing
     * @param array<string, float> $grid One of GB_GRID / IRISH_GRID
     * @return array{0: float, 1: float} [latitude, longitude] in WGS84 degrees
     */
    private static function gridToWgs84(float $easting, float $northing, array $grid): array
    {
        [$lat, $lon] = self::inverseTransverseMercator($easting, $northing, $grid);

        return self::helmertToWgs84($lat, $lon, $grid);
    }

    /**
     * Inverse Transverse Mercator: grid easting/northing → geographic on the grid's datum.
     *
     * Ordnance Survey's formulae ("A guide to coordinate systems in Great Britain", C.2).
     *
     * @param float $E Easting in metres
     * @param float $N Northing in metres
     * @param array<string, float> $grid Ellipsoid and projection parameters
     * @return array{0: float, 1: float} [latitude, longitude] in degrees on the grid's datum
     */
    private static function inverseTransverseMercator(float $E, float $N, array $grid): array
    {
        $a    = $grid['a'];
        $b    = $grid['b'];
        $f0   = $grid['f0'];
        $lat0 = deg2rad($grid['lat0']);
        $lon0 = deg2rad($grid['lon0']);

        $e2 = 1.0 - ($b ** 2) / ($a ** 2);
        $n  = ($a - $b) / ($a + $b);

        // Iterate to find latitude from northing
        $Np  = $N - $grid['n0'];
        $lat = $lat0 + $Np / ($a * $f0);

        do {
            $M          = self::meridionalArc($b, $f0, $n, $lat0, $lat);
            $correction = ($Np - $M) / ($a * $f0);
            $lat       += $correction;
        } while (abs($correction) > 1e-12);

        $sinLat = sin($lat);
        $cosLat = cos($lat);
        $tanLat = tan($lat);
        $tan2   = $tanLat ** 2;
        $tan4   = $tanLat ** 4;
        $tan6   = $tanLat ** 6;

        $nu   = $a * $f0 / sqrt(1.0 - $e2 * $sinLat ** 2);
        $rho  = $a * $f0 * (1.0 - $e2) / (1.0 - $e2 * $sinLat ** 2) ** 1.5;
        $eta2 = $nu / $rho - 1.0;

        $VII  = $tanLat / (2.0  * $rho * $nu);
        $VIII = $tanLat / (24.0 * $rho * $nu ** 3) * (5.0 + 3.0 * $tan2 + $eta2 - 9.0 * $eta2 * $tan2);
        $IX   = $tanLat / (720.0 * $rho * $nu ** 5) * (61.0 + 90.0 * $tan2 + 45.0 * $tan4);
        $X    = 1.0 / ($cosLat * $nu);
        $XI   = 1.0 / ($cosLat * 6.0   * $nu ** 3) * ($nu / $rho + 2.0 * $tan2);
        $XII  = 1.0 / ($cosLat * 120.0 * $nu ** 5) * (5.0 + 28.0 * $tan2 + 24.0 * $tan4);
        $XIIA = 1.0 / ($cosLat * 5040.0 * $nu ** 7) * (61.0 + 662.0 * $tan2 + 1320.0 * $tan4 + 720.0 * $tan6);

        $dE = $E - $grid['e0'];

        $latResult = $lat
            - $VII  * $dE ** 2
            + $VIII * $dE ** 4
            - $IX   * $dE ** 6;

        $lonResult = $lon0
            + $X    * $dE
            - $XI   * $dE ** 3
            + $XII  * $dE ** 5
            - $XIIA * $dE ** 7;

        return [rad2deg($latResult), rad2deg($lonResult)];
    }

    /**
     * Meridional arc distance from latitude of origin to given latitude.
     *
     * @param float $b   Semi-minor axis
     * @param float $f0  Scale factor
     * @param float $n   Third flattening (a-b)/(a+b)
     * @param float $lat0 Latitude of true origin (radians)
     * @param float $lat  Current latitude (radians)
     * @return float Meridional arc in metres
     */
    private static function meridionalArc(
        float $b,
        float $f0,
        float $n,
        float $lat0,
        float $lat
    ): float {
        return $b * $f0 * (
            (1.0 + $n + 1.25 * $n ** 2 + 1.25 * $n ** 3)
                * ($lat - $lat0)
            - (3.0 * $n + 3.0 * $n ** 2 + 2.625 * $n ** 3)
                * sin($lat - $lat0) * cos($lat + $lat0)
            + (1.875 * $n ** 2 + 1.875 * $n ** 3)
                * sin(2.0 * ($lat - $lat0)) * cos(2.0 * ($lat + $lat0))
            - (35.0 / 24.0 * $n ** 3)
                * sin(3.0 * ($lat - $lat0)) * cos(3.0 * ($lat + $lat0))
        );
    }

    /**
     * Geographic coordinates on a grid's datum → WGS84, by a 7-parameter Helmert
     * transformation (position-vector convention) through geocentric Cartesians.
     *
     * @param float $lat Latitude in degrees on the grid's datum
     * @param float $lon Longitude in degrees on the grid's datum
     * @param array<string, float> $grid Ellipsoid and Helmert parameters
     * @return array{0: float, 1: float} [latitude, longitude] in WGS84 degrees
     */
    private static function helmertToWgs84(float $lat, float $lon, array $grid): array
    {
        $a  = $grid['a'];
        $e2 = 1.0 - ($grid['b'] ** 2) / ($a ** 2);

        $latR = deg2rad($lat);
        $lonR = deg2rad($lon);
        $nu   = $a / sqrt(1.0 - $e2 * sin($latR) ** 2);

        // Geographic → geocentric Cartesian (height assumed 0)
        $X = $nu * cos($latR) * cos($lonR);
        $Y = $nu * cos($latR) * sin($lonR);
        $Z = $nu * (1.0 - $e2) * sin($latR);

        $rx = deg2rad($grid['rx'] / 3600.0);
        $ry = deg2rad($grid['ry'] / 3600.0);
        $rz = deg2rad($grid['rz'] / 3600.0);
        $s  = 1.0 + $grid['sPpm'] / 1e6;

        $X2 = $grid['tx'] + $s * $X  - $rz * $Y + $ry * $Z;
        $Y2 = $grid['ty'] + $rz * $X + $s * $Y  - $rx * $Z;
        $Z2 = $grid['tz'] - $ry * $X + $rx * $Y + $s * $Z;

        // WGS84 ellipsoid
        $a2  = 6378137.000;
        $b2  = 6356752.3142;
        $e22 = 1.0 - ($b2 ** 2) / ($a2 ** 2);
        $p   = sqrt($X2 ** 2 + $Y2 ** 2);

        // Geocentric Cartesian → geographic (iterative)
        $lat2 = atan2($Z2, $p * (1.0 - $e22));
        do {
            $prev = $lat2;
            $nu2  = $a2 / sqrt(1.0 - $e22 * sin($lat2) ** 2);
            $lat2 = atan2($Z2 + $e22 * $nu2 * sin($lat2), $p);
        } while (abs($lat2 - $prev) > 1e-12);

        $lon2 = atan2($Y2, $X2);

        return [rad2deg($lat2), rad2deg($lon2)];
    }
}
