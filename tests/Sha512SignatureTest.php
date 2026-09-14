<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use MarioDevv\RedsysSubscriptions\Sha512Signature;
use PHPUnit\Framework\TestCase;

final class Sha512SignatureTest extends TestCase
{
    /**
     * Vector de prueba oficial de Redsys: clave, pedido y parametros publicados
     * en su documentacion, con la firma que ellos dan por buena. La clave es la
     * del entorno de pruebas, no un secreto; cambiarla por otra convierte este
     * test en una comprobacion de que el codigo coincide consigo mismo.
     */
    private const KEY    = 'sq7HjrUOBfKmC576ILgskD5srU870gJ7';
    private const ORDER  = '1234567890';
    private const PARAMS = 'eyJEU19NRVJDSEFOVF9BTU9VTlQiOiI5OTkiLCJEU19NRVJDSEFOVF9PUkRFUiI6IjEyMzQ1Njc4OTAiLCJEU19NRVJDSEFOVF9NRVJDSEFOVENPREUiOiI5OTkwMDg4ODEiLCJEU19NRVJDSEFOVF9DVVJSRU5DWSI6Ijk3OCIsIkRTX01FUkNIQU5UX1RSQU5TQUNUSU9OVFlQRSI6IjAiLCJEU19NRVJDSEFOVF9URVJNSU5BTCI6IjEiLCJEU19NRVJDSEFOVF9NRVJDSEFOVFVSTCI6Imh0dHA6XC9cL3d3dy5wcnVlYmEuY29tXC91cmxOb3RpZmljYWNpb24ucGhwIiwiRFNfTUVSQ0hBTlRfVVJMT0siOiJodHRwOlwvXC93d3cucHJ1ZWJhLmNvbVwvdXJsT0sucGhwIiwiRFNfTUVSQ0hBTlRfVVJMS08iOiJodHRwOlwvXC93d3cucHJ1ZWJhLmNvbVwvdXJsS08ucGhwIn0';
    private const EXPECT = 'Vjo02eSWq249IeZZp3R-ArFnGLhKY0OuzDDlx1BuVtZDC2yhczA7_11uZhsYzLZBCMFAz8u8uzGDX3AErHKmmw';

    public function test_matches_official_redsys_vector(): void
    {
        $this->assertSame(self::EXPECT, Sha512Signature::sign(self::PARAMS, self::ORDER, self::KEY));
    }

    public function test_verifies_its_own_signature(): void
    {
        $this->assertTrue(Sha512Signature::verify(self::EXPECT, self::PARAMS, self::ORDER, self::KEY));
    }

    public function test_rejects_a_tampered_signature(): void
    {
        $this->assertFalse(Sha512Signature::verify('AAAA' . substr(self::EXPECT, 4), self::PARAMS, self::ORDER, self::KEY));
    }

    public function test_rejects_tampered_parameters(): void
    {
        $this->assertFalse(Sha512Signature::verify(self::EXPECT, self::PARAMS . 'x', self::ORDER, self::KEY));
    }
}
