<?php

use App\Services\ServerAddress;

/*
| Deteksi IP jaringan utama server: penguraian alamat lokal socket.
| (Deteksi sebenarnya bergantung pada jaringan mesin, sehingga di tes Feature dipalsukan.)
*/

it('mengambil IP dari alamat lokal socket', function (string $name, ?string $expected) {
    expect(ServerAddress::fromSocketName($name))->toBe($expected);
})->with([
    'WiFi' => ['192.168.1.36:54321', '192.168.1.36'],
    'adapter lain' => ['10.20.30.40:5000', '10.20.30.40'],
    'loopback' => ['127.0.0.1:5000', null],
    'tanpa rute' => ['0.0.0.0:0', null],
    'IPv6' => ['[fe80::1]:5000', null],
    'bukan IP' => ['bukan-ip:80', null],
]);
