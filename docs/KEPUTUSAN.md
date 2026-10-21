# Keputusan Desain Sistem (berlaku sejak 20 Oktober 2026)

Terakhir diperbarui: 21 Oktober 2026 malam (keputusan Tahap 8).
Detail implementasi (skema, angka, bug): `CATATAN-IMPLEMENTASI.md`. Perintah operasional: `PERINTAH.md`.
Versi induk disimpan di Project claude.ai (`claude/keputusan-sistem-laravel.md`); salinan di repo `docs/` diperbarui setiap tahap selesai.

## Ruang lingkup
- Skripsi mengikuti judul yang sudah di-ACC: "Rancang Bangun Sistem Monitoring Resource Server dan Kondisi Lingkungan Ruang Server Berbasis Laravel, Telegraf, dan ESP32 dengan Notifikasi".
- Perbandingan dengan Sistem A (Telegraf + InfluxDB + Grafana) DIHAPUS sepenuhnya dari skripsi. Grafana dan InfluxDB tidak dipakai.
- Urutan pengerjaan: backend Laravel + Telegraf lebih dulu; ESP32 dikerjakan setelah backend berjalan.

## Arsitektur
- Backend: Laravel 13 (PHP 8.4) + MySQL (DBngin), dikelola dengan DBeaver. Proyek di `D:\SKRIPSI\PROJECT\monitoring-server`.
- Frontend: Blade + Tailwind CSS v4 + Alpine.js 3 + Chart.js 4 (Vite).
- Host H1 (PC Windows sendiri): Telegraf 1.40.2 (service `telegraf-agent`, berkas di `D:\SKRIPSI\PROJECT\telegraf\`) dengan config khusus skripsi; input `win_perf_counters` (CPU, disk per volume), `mem`, `win_services` (Spooler, WSearch); output hanya `outputs.http` (JSON Telegraf, header `Authorization: Bearer <token>`) ke `POST /api/ingest/telegraf`; `collection_offset = "5s"` agar tidak bertabrakan dengan jadwal evaluator.
- Layanan yang dipantau (Spooler, WSearch) adalah objek uji terkendali untuk mensimulasikan kegagalan layanan; aman dihentikan/dinyalakan tanpa mengganggu sistem. Di server produksi, daftar `service_names` diganti dengan layanan kritis (web server, database, aplikasi).
- Telegraf lama di `D:\telegraf` (v1.37.0, output PostgreSQL) bukan bagian sistem; dibiarkan mati.
- ESP32 + DHT22: rencananya mengirim langsung ke `POST /api/ingest/esp32` dengan token sendiri dan field `ts` (epoch UTC, detik) sebagai waktu pembacaan sensor.
- Tahap lanjut: host kedua (Windows Server di VirtualBox); skema mendukung banyak host sejak awal.

## Parameter aturan (spesifikasi sistem)
- Interval Telegraf 10 dtk (tetap; dinilai paling tepat untuk aturan terpendek 20 dtk dan heartbeat 30 dtk); interval kirim ESP32 30 dtk; evaluasi aturan tiap 10 dtk.
- CPU > 85% selama 60 dtk, pulih < 80%.
- Memori terpakai > 90% selama 60 dtk, pulih < 85%.
- Ruang disk bebas < 10% per volume selama 60 dtk, pulih > 12%. (Diputuskan 21 Okt 2026; sebelumnya tanpa durasi/0 dtk. Alasan: ruang bebas C: berfluktuasi 0,07–4,4% akibat pagefile, durasi 60 dtk mencegah alarm palsu; keterlambatan ≤ 1 menit tidak berarti untuk kondisi disk yang berubah lambat.)
- Layanan Windows tidak running (state != 4) selama 20 dtk, pulih saat running (== 4).
- Suhu > 27 °C selama 60 dtk, pulih < 26 °C.
- Kelembaban > 60% RH selama 60 dtk, pulih < 58% RH.
- Heartbeat: host tanpa data > 30 dtk; ESP32 tanpa data > 90 dtk.
- Cooldown pengulangan pesan 10 menit; kirim saat FIRING dan RESOLVED.
- Waktu disimpan dalam UTC di database (kolom DATETIME), Telegraf, laporan latensi, dan CSV; ditampilkan dalam WIB (UTC+7) di dashboard dan pesan Telegram.
- Polling dashboard bisa dipilih pengguna (10 dtk, 30 dtk, 60 dtk, 5 mnt, 10 mnt; default 10 dtk). Tidak memengaruhi evaluasi aturan atau notifikasi, yang berjalan di server.

## Perilaku aktif/nonaktif aturan (diputuskan 21 Oktober 2026)
- Aturan nonaktif = periode itu TIDAK dipantau. Saat diaktifkan kembali, state di-reset ke NORMAL dan penanda `alert_states.last_sample_at` digeser ke waktu aktivasi (dipotong ke detik), sehingga sampel selama masa nonaktif tidak dievaluasi ulang (mencegah FIRING/RESOLVED basi). Diimplementasikan di `RuleController::resetStates()`, juga untuk tombol reset default.
- Mengubah status aktif/nonaktif HARUS lewat halaman Aturan. UPDATE SQL langsung melewati logika reset (bug replay terbukti 21 Okt: notifikasi 56–57 terkirim untuk kejadian saat aturan nonaktif; setelah perbaikan, uji ulang lolos).
- Notifikasi yang tidak terkirim dalam 30 menit (`retryUntil`), misalnya karena worker antrean mati, ditandai `failed` dan tidak dikirim terlambat. Catat sebagai batasan; worker antrean harus berjalan permanen (Tahap 9).

## Rencana pengujian (usulan, perlu disetujui pembimbing)
- Pengujian fungsional (black-box) tiap aturan dan transisi NORMAL, PENDING, FIRING, RESOLVED.
- Latensi notifikasi `t_sent - t_breach` dikurangi durasi tahan (rata-rata, median, p95, maksimum) terhadap target yang ditetapkan di awal; perintah `php artisan alerts:latency --since=... --csv`; minimal 20 percobaan per skenario. Catatan: pada event FIRING, `t_breach` = awal pelanggaran, bukan saat FIRING.
- Keandalan pengiriman (persentase terkirim, perilaku saat HTTP 429 atau jaringan putus).
- Overhead sumber daya Laravel, MySQL, dan Telegraf di H1.
- Pengujian otomatis (feature test, Tahap 8) sebagai bukti regresi bug yang sudah diperbaiki.

## Pengujian otomatis (diputuskan 21 Oktober 2026)
- Tahap 8 dikerjakan LENGKAP (8a–8f) dengan Pest; database tes MySQL terpisah `servermonitoring_test` (opsi A), bukan SQLite, demi validitas (perilaku DATETIME/presisi/enum sama dengan sistem yang diuji).
- Tes tidak boleh menyentuh database pengembangan maupun Telegram asli (guard `_test`, token palsu, blokir HTTP).
- Rate limit: batas per IP diperiksa SEBELUM token (lapisan pertama terhadap banjir permintaan), batas per sumber SESUDAH token. Konsekuensi: token salah ikut menghabiskan kuota IP.
- Token/chat Telegram kosong = error sementara (diulang ≤ 30 menit), bukan gagal permanen.

## Fase ESP32 (diputuskan 21 Oktober 2026)
- Perangkat: ESP32 Dev Module (ESP32-WROOM-32), DHT22 modul 3 pin (DATA → GPIO4, VCC 3V3), Arduino IDE dengan core "esp32 by Espressif Systems" 3.3.12. OLED 0.96" I2C 4 pin (chip belum diketahui; default SSD1306, opsi SH1106).
- Sensor tetap DHT22 sesuai rancangan (SHT31/SHT40 lebih akurat, tetapi mengganti sensor berarti mengubah proposal).
- Firmware: baca tiap 30 dtk (median 3 bacaan berjarak 2,5 dtk), waktu NTP UTC (tidak mengirim sebelum tersinkron), HTTP POST JSON `{temperature, humidity, ts}` + Bearer token, buffer RAM 20 data (±10 menit) dikirim ulang dari yang tertua, sambung ulang WiFi dan restart bila gagal > 5 menit, rahasia di `secrets.h` (gitignore), kode di `firmware/esp32-dht22/`.
- Keamanan: server dibuka ke LAN (`--host=0.0.0.0`) tetapi dashboard dibatasi ke komputer server (opsi 1: middleware `RestrictToLocal`, `DASHBOARD_ALLOWED_IPS`). Batasan: ESP32 mengirim lewat HTTP tanpa TLS di LAN (token tidak terenkripsi di jaringan lokal); data buffer yang tiba > 2 menit terlambat disimpan tetapi tidak dievaluasi alarm.
- OLED (disetujui 21 Okt): satu informasi per halaman agar terbaca di layar kecil — SUHU (ikon termometer beranimasi) → KELEMBABAN (ikon tetes beranimasi) → STATUS, masing-masing 5 dtk; teks terkecil ±11 px, angka ±24 px. OLED hanya menampilkan nilai mentah + status koneksi, TIDAK status alarm (agar tidak ada dua sumber kebenaran dengan ambang di server). Task tampilan terpisah (FreeRTOS) agar animasi dan pengiriman tidak saling menghambat. Bisa dimatikan (`USE_OLED 0`). Alasan menambah OLED (di luar rancangan awal): pembacaan lokal di ruang server tetap tersedia saat jaringan/server bermasalah; perlu dicantumkan di daftar perangkat Bab 3.
- Saran operasional: IP tetap untuk laptop (DHCP reservation) karena alamat server ditulis di firmware.

## Proses permanen / otomatis (Tahap 9) — DITUNDA (diputuskan 21 Oktober 2026)
- Untuk tahap sempro (fokus Bab 1–3), sistem tetap dijalankan manual (4 terminal). Otomatisasi baru dikerjakan menjelang seminar hasil (semhas) dan sidang.
- Rancangan yang sudah dianalisis (belum diputuskan): web server Apache + mod_php (PHP TS) sebagai service, scheduler dan worker antrean sebagai service lewat WinSW, MySQL harus jalan tanpa login. Alasan: `artisan serve` menurut dokumentasi PHP tidak untuk produksi, single-thread, dan multi-worker tidak didukung di Windows; antrean yang mati > 30 menit menggagalkan notifikasi.
- Konsekuensi selama ditunda: pengukuran latensi resmi (Bab 4) jangan diambil dengan `artisan serve` + terminal manual; ambil setelah Tahap 9 selesai agar kondisi uji sama dengan sistem final.

## TUGAS TERTUNDA sebelum pengujian resmi
- Aturan memori (`mem_high`, 90/85) dan disk (`disk_low`, 10/12, 60 dtk) SENGAJA DINONAKTIFKAN selama pengembangan (sejak 20–21 Oktober 2026) karena memori H1 sekitar 90–95% dan disk hampir penuh, sehingga terus memicu pesan Telegram.
- Selesaikan Tahap 9 (proses permanen) sebelum mengambil data latensi resmi.
- Sebelum pengujian resmi: kosongkan disk C: dan D: sampai ruang bebas di atas 12%, kurangi pemakaian memori sampai di bawah 85%, lalu aktifkan kembali kedua aturan LEWAT HALAMAN ATURAN (jangan dengan UPDATE SQL, karena akan memicu evaluasi ulang data lama). Jangan tekan tombol reset default sebelum siap, karena tombol itu mengaktifkan semua aturan.

## Catatan kondisi H1 (20–21 Oktober 2026)
- Memori terpakai sekitar 90–95%, disk C: bebas sekitar 1%, disk D: bebas sekitar 5%. Kosongkan ruang dan kurangi beban sebelum pengujian agar kondisi awal NORMAL.
- State `disk_low` (C:, D:) yang membeku di FIRING akibat dinonaktifkan lewat SQL sudah dirapikan ke NORMAL (21 Okt).
