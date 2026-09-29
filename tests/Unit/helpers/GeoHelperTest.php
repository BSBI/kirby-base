<?php

declare(strict_types=1);

namespace BSBI\WebBase\Tests\Unit\helpers;

use PHPUnit\Framework\TestCase;
use BSBI\WebBase\helpers\GeoHelper;

/**
 * Tests for GeoHelper: haversine distance, bounding box, and Irish grid ref conversion.
 */
final class GeoHelperTest extends TestCase
{
    // ── Haversine ─────────────────────────────────────────────────────────────

    public function testHaversineKnownLondonToEdinburgh(): void
    {
        // London (51.5074, -0.1278) to Edinburgh (55.9533, -3.1883) ≈ 332 miles
        $miles = GeoHelper::haversineDistanceMiles(51.5074, -0.1278, 55.9533, -3.1883);
        $this->assertEqualsWithDelta(332.0, $miles, 5.0);
    }

    public function testHaversineZeroDistanceSamePoint(): void
    {
        $miles = GeoHelper::haversineDistanceMiles(51.5, -0.1, 51.5, -0.1);
        $this->assertEqualsWithDelta(0.0, $miles, 0.001);
    }

    public function testHaversineShortDistance(): void
    {
        // About 1 mile north of (51.5, -0.1) is approximately (51.5145, -0.1)
        $miles = GeoHelper::haversineDistanceMiles(51.5, -0.1, 51.5145, -0.1);
        $this->assertEqualsWithDelta(1.0, $miles, 0.1);
    }

    // ── Bounding box ──────────────────────────────────────────────────────────

    public function testBoundingBoxLatitudesAreSymmetric(): void
    {
        $box = GeoHelper::boundingBoxForRadiusMiles(51.5, -0.1, 100.0);
        $this->assertArrayHasKey('minLat', $box);
        $this->assertArrayHasKey('maxLat', $box);
        $this->assertArrayHasKey('minLon', $box);
        $this->assertArrayHasKey('maxLon', $box);

        $this->assertLessThan(51.5, $box['minLat']);
        $this->assertGreaterThan(51.5, $box['maxLat']);
        $this->assertLessThan(-0.1, $box['minLon']);
        $this->assertGreaterThan(-0.1, $box['maxLon']);

        // Latitude span should be roughly symmetric around the centre
        $latSpan = $box['maxLat'] - $box['minLat'];
        $this->assertEqualsWithDelta(
            $box['maxLat'] - 51.5,
            51.5 - $box['minLat'],
            0.001
        );
        $this->assertGreaterThan(0, $latSpan);
    }

    public function testBoundingBoxContainsPointWithinRadius(): void
    {
        $centre = [51.5, -0.1];
        $box = GeoHelper::boundingBoxForRadiusMiles($centre[0], $centre[1], 50.0);

        // A point ~30 miles north should be inside the box
        $nearbyLat = 51.5 + (30.0 / 69.0);
        $this->assertGreaterThanOrEqual($box['minLat'], $nearbyLat);
        $this->assertLessThanOrEqual($box['maxLat'], $nearbyLat);
    }

    // ── Irish grid ref parsing ────────────────────────────────────────────────

    public function testParseValidDublinGridRef(): void
    {
        // O15743412 → E=315740, N=234120
        $result = GeoHelper::parseIrishGridRef('O15743412');
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(315740.0, $result[0], 5.0);
        $this->assertEqualsWithDelta(234120.0, $result[1], 5.0);
    }

    public function testParseGridRefLowercaseAccepted(): void
    {
        $upper = GeoHelper::parseIrishGridRef('O15743412');
        $lower = GeoHelper::parseIrishGridRef('o15743412');
        $this->assertEquals($upper, $lower);
    }

    public function testParseInvalidLetterIReturnsNull(): void
    {
        $this->assertNull(GeoHelper::parseIrishGridRef('I12345678'));
    }

    public function testParseEmptyStringReturnsNull(): void
    {
        $this->assertNull(GeoHelper::parseIrishGridRef(''));
    }

    public function testParseOddDigitCountReturnsNull(): void
    {
        $this->assertNull(GeoHelper::parseIrishGridRef('O12345'));
    }

    public function testParseTwoLetterPrefixReturnsNull(): void
    {
        $this->assertNull(GeoHelper::parseIrishGridRef('TQ12345678'));
    }

    // ── Irish grid ref → WGS84 ───────────────────────────────────────────────

    public function testIrishGridRefToLatLngDublin(): void
    {
        // D02XY45 postcode → BSBI API returns O15743412
        // Expected approximately 53.34°N, 6.26°W
        $result = GeoHelper::irishGridRefToLatLng('O15743412');
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(53.34, $result[0], 0.02);
        $this->assertEqualsWithDelta(-6.26, $result[1], 0.02);
    }

    public function testIrishGridRefToLatLngGalway(): void
    {
        // Galway grid ref M29772500 → ~53.27°N, 9.05°W
        $result = GeoHelper::irishGridRefToLatLng('M29772500');
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(53.27, $result[0], 0.02);
        $this->assertEqualsWithDelta(-9.05, $result[1], 0.03);
    }

    public function testIrishGridRefToLatLngCork(): void
    {
        // T12Y337 Eircode → BSBI API returns W65977114, Cork area ~51.89°N, 8.47°W
        $result = GeoHelper::irishGridRefToLatLng('W65977114');
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(51.89, $result[0], 0.02);
        $this->assertEqualsWithDelta(-8.47, $result[1], 0.05);
    }

    public function testIrishGridRefToLatLngReturnsNullForInvalidRef(): void
    {
        $this->assertNull(GeoHelper::irishGridRefToLatLng('INVALID'));
        $this->assertNull(GeoHelper::irishGridRefToLatLng(''));
        $this->assertNull(GeoHelper::irishGridRefToLatLng('I12345678'));
    }

    /**
     * Reference values below are from pyproj (EPSG:29903 / EPSG:27700 → EPSG:4326,
     * Helmert transformation). A 0.0001° delta is about 11m north–south and 7m
     * east–west — tight enough to catch a wrong constant, loose enough for Helmert.
     */
    private const float REF_DELTA = 0.0001;

    public function testIrishGridRefToLatLngMatchesReferenceClosely(): void
    {
        // O15743412 → easting 315740, northing 234120
        $result = GeoHelper::irishGridRefToLatLng('O15743412');
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(53.344883, $result[0], self::REF_DELTA);
        $this->assertEqualsWithDelta(-6.262911, $result[1], self::REF_DELTA);
    }

    // ── GB grid ref → WGS84 ──────────────────────────────────────────────────

    /**
     * @return array<string, array{string, float, float}>
     */
    public static function gbGridRefProvider(): array
    {
        return [
            '10km square'          => ['SJ41', 340000.0, 310000.0],
            '1m (OS worked example)' => ['TG5140913177', 651409.0, 313177.0],
            'lowercase'            => ['tg5140913177', 651409.0, 313177.0],
            'south-west origin'    => ['SV00', 0.0, 0.0],
            'Shetland'             => ['HU44', 440000.0, 1140000.0],
            'Cornwall'             => ['SW73', 170000.0, 30000.0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gbGridRefProvider')]
    public function testParseGbGridRef(string $gridRef, float $easting, float $northing): void
    {
        $this->assertSame([$easting, $northing], GeoHelper::parseGbGridRef($gridRef));
    }

    public function testParseGbGridRefRejectsInvalidRefs(): void
    {
        $this->assertNull(GeoHelper::parseGbGridRef(''));
        $this->assertNull(GeoHelper::parseGbGridRef('SJ4'));            // odd digit count
        $this->assertNull(GeoHelper::parseGbGridRef('SI41'));           // no I in the grid
        $this->assertNull(GeoHelper::parseGbGridRef('O15743412'));      // Irish (one letter)
        $this->assertNull(GeoHelper::parseGbGridRef('SJ123456789012')); // too many digits
        $this->assertNull(GeoHelper::parseGbGridRef('SJ41 x'));
        $this->assertNull(GeoHelper::parseGbGridRef('WV55'));           // off the GB grid (Channel Islands letters)
    }

    public function testGbGridRefToLatLngMatchesOsWorkedExample(): void
    {
        $result = GeoHelper::gbGridRefToLatLng('TG5140913177');
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(52.657977, $result[0], self::REF_DELTA);
        $this->assertEqualsWithDelta(1.716038, $result[1], self::REF_DELTA);
    }

    public function testGbGridRefToLatLngBenNevisAndShetland(): void
    {
        $benNevis = GeoHelper::gbGridRefToLatLng('NN1667171266');
        $this->assertNotNull($benNevis);
        $this->assertEqualsWithDelta(56.796708, $benNevis[0], self::REF_DELTA);
        $this->assertEqualsWithDelta(-5.003599, $benNevis[1], self::REF_DELTA);

        $shetland = GeoHelper::gbGridRefToLatLng('HU44');
        $this->assertNotNull($shetland);
        $this->assertEqualsWithDelta(60.142561, $shetland[0], self::REF_DELTA);
        $this->assertEqualsWithDelta(-1.281583, $shetland[1], self::REF_DELTA);
    }

    public function testGbGridRefToLatLngReturnsNullForInvalidRef(): void
    {
        $this->assertNull(GeoHelper::gbGridRefToLatLng('INVALID'));
        $this->assertNull(GeoHelper::gbGridRefToLatLng('O15743412'));
    }

    // ── Tetrad corners ───────────────────────────────────────────────────────

    /**
     * Asserts the corners are SW, SE, NE, NW and each matches the reference point.
     *
     * @param list<array{0: float, 1: float}> $expected
     * @param list<array{0: float, 1: float}>|null $actual
     */
    private function assertCorners(array $expected, ?array $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertCount(4, $actual);
        foreach ($expected as $i => [$lat, $lng]) {
            $this->assertEqualsWithDelta($lat, $actual[$i][0], self::REF_DELTA, "corner $i latitude");
            $this->assertEqualsWithDelta($lng, $actual[$i][1], self::REF_DELTA, "corner $i longitude");
        }
    }

    public function testTetradCornersForGbTetrad(): void
    {
        // SJ41R: R is index 16 (A–Z without O) → column 3, row 1 → SW corner 346000, 312000
        $this->assertCorners([
            [52.702964, -2.800591],
            [52.703160, -2.770996],
            [52.721138, -2.771313],
            [52.720942, -2.800921],
        ], GeoHelper::tetradCornersLatLng('SJ41R'));
    }

    public function testTetradCornersForIrishTetrad(): void
    {
        // O13K: K is index 10 → column 2, row 0 → SW corner 314000, 230000 (Irish Grid)
        $this->assertCorners([
            [53.308259, -6.290505],
            [53.307825, -6.260516],
            [53.325787, -6.259785],
            [53.326221, -6.289787],
        ], GeoHelper::tetradCornersLatLng('O13K'));
    }

    public function testTetradCornersAcceptsLowercase(): void
    {
        $this->assertSame(
            GeoHelper::tetradCornersLatLng('SJ41R'),
            GeoHelper::tetradCornersLatLng('sj41r')
        );
    }

    public function testTetradCornersReturnsNullForUnplaceableRefs(): void
    {
        $this->assertNull(GeoHelper::tetradCornersLatLng(''));
        $this->assertNull(GeoHelper::tetradCornersLatLng('SJ41'));    // 10km square, not a tetrad
        $this->assertNull(GeoHelper::tetradCornersLatLng('SJ41O'));   // no O in tetrad letters
        $this->assertNull(GeoHelper::tetradCornersLatLng('WV55A'));   // Channel Islands (UTM, not GB grid)
        $this->assertNull(GeoHelper::tetradCornersLatLng('SJ4150'));  // 1km grid ref
    }
}
